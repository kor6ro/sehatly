<?php

declare(strict_types=1);

namespace App\Services\Invoice;

use App\Models\MasterPromo;
use App\Models\PromoRedemption;
use App\Support\Uang\Uang;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The promo rule set: what makes a code usable, and what it is worth.
 *
 * ## A promo is a RULE SET, not a lookup
 *
 * `master_promo` (telemedicine_test.sql:985-998) is not a row that is either
 * valid or not. Six columns each say something, and a row can fail any
 * combination of them:
 *
 * | rule | column | DDL |
 * | --- | --- | --- |
 * | the code exists at all | `kode` UNIQUE | :987 |
 * | the promo is switched on | `status_aktif` TINYINT(1) NOT NULL DEFAULT 1 | :997 |
 * | the window contains now | `mulai_at`, `selesai_at` DATETIME NOT NULL | :995, :996 |
 * | the purchase is big enough | `min_transaksi` DECIMAL(12,2) NOT NULL DEFAULT 0 | :991 |
 * | the total allowance is left | `kuota_total` INT UNSIGNED **NULL** | :993 |
 * | this patient's own allowance is left | `kuota_per_user` TINYINT UNSIGNED NOT NULL DEFAULT 1 | :994 |
 *
 * Every rule is evaluated and every failure is reported. A rule set that stopped
 * at the first failure would be a rule set in name only, and the case that
 * proves the difference is a promo that is in-window but over-quota: reporting
 * only the quota would be correct by luck, while reporting the quota for a
 * promo that is merely out of window would be a lie.
 *
 * ## The nullable columns, and the three different things NULL and 0 mean
 *
 * `kuota_total IS NULL` (:993) is UNLIMITED; `kuota_total = 0` is EXHAUSTED.
 * `kuota_per_user` is `NOT NULL` (:994), so 0 is a real ceiling nobody meets.
 * `maks_diskon IS NULL` (:992) is UNCAPPED, and 0.00 is a cap of nothing, which
 * is a usable "no discount" promo and a different thing again. A rule that read
 * NULL as 0 would refuse every unlimited promo; one that read 0 as NULL would
 * hand out unlimited use of an exhausted one.
 *
 * ## Quota consumption is ATOMIC, and this is the decision
 *
 * `promo_redemption` (:1000-1010) declares **no UNIQUE key and no INDEX
 * statement anywhere in its eleven lines**; the only indexes MySQL creates on it
 * are the three single-column ones it synthesises for the three foreign keys
 * (:1007-1009). Nothing in the schema can stop two transactions from both
 * counting two redemptions and both inserting a third, and the plan forbids
 * adding a unique index or a column. The guard therefore has to be the lock:
 *
 * 1. `SELECT ... FROM master_promo WHERE id = ? FOR UPDATE` is the FIRST
 *    statement of the apply transaction. The promo row always exists, so there
 *    is a single row to serialise on, and every consumer of this promo - any
 *    patient, any service - passes through it.
 * 2. The two counting reads are LOCKING reads too, and that is not decoration.
 *    The isolation level is REPEATABLE READ, where a non-locking read still
 *    resolves against the transaction's own snapshot: a second transaction that
 *    blocked on the promo lock would wake up counting a snapshot taken BEFORE
 *    the first committed, and would happily overspend. `SELECT ... FOR UPDATE`
 *    reads the LATEST committed version, which is what makes the count correct.
 * 3. The counts are `pluck('id')->count()` and never `->count()` with a lock,
 *    because the query grammar does not apply a lock to an aggregate -
 *    `compileAggregate()` skips `compileLock()`. A `->count()->lockForUpdate()`
 *    is a SILENT no-op, and a silent no-op on a quota check is the worst
 *    possible failure for one. `PromoQuotaConcurrencyTest` asserts the emitted
 *    SQL carries `for update` and that the second transaction really collides.
 * 4. The counts are bounded by the quota itself (`limit($kuota)`), so the query
 *    is a range probe over the index synthesised on `promo_id` and the answer is
 *    exact: "have I reached the ceiling" is the same question as "are there at
 *    least N of these". This also caps the work at O(quota) rather than
 *    O(all redemptions), which matters for a promo with a large allowance.
 *
 * What this does NOT give: idempotency. A redelivered request consumes a second
 * quota, because `promo_redemption` has no `dihapus_at`, no `dibatalkan` and no
 * unique key to key an idempotency token on. Stated here rather than glossed;
 * it is the same limitation the plan records for `pembayaran.nomor_referensi`
 * in todo 45.
 *
 * ## The window is read in Asia/Jakarta, not UTC
 *
 * `mulai_at` and `selesai_at` are operator-authored Indonesian local times.
 * `config('app.timezone')` is `'UTC'` and `now()` is a UTC instant, so
 * comparing the stored literal against `now()` puts every promo out by seven
 * hours: a code going live at 09:00 WIB would read as live from 02:00 WIB and
 * lapse at 02:00 WIB the next day. So the instant is converted to
 * {@see InvoiceService::ZONA_WALL_CLOCK} before the comparison - the plan's
 * todo 51 rule, and the one place the conversion lives. WIB has no daylight
 * saving, so a fixed named zone is exact and no offset table is needed.
 *
 * The clock is read ONCE per evaluation and used for both ends, so a window
 * closing between two comparisons cannot produce a self-contradictory verdict.
 *
 * ## There is deliberately no CHECK on `mulai_at <= selesai_at`
 *
 * An inverted window is legal storage, and at any instant between the two it
 * satisfies both window rules at once. Evaluating both rather than
 * short-circuiting is what makes the 422 report that fact under one key with
 * two messages instead of one arbitrary message.
 */
class PromoService
{
    /**
     * The authoritative, LOCKED evaluation. Writes the redemption when the promo
     * is usable and refuses - with every reason - when it is not.
     *
     * MUST be called inside a transaction whose FIRST statement is the promo
     * lock this method takes. See the class docblock for why that ordering is
     * the whole correctness argument.
     *
     * @param  string  $subtotal  the purchase total before any discount
     * @param  string  $biayaPengiriman  the shipping charge before any waiver
     * @param  int  $pasienId  whose own quota is checked
     * @param  int  $invoiceId  the invoice the redemption is attached to;
     *                          `promo_redemption.invoice_id` is NOT NULL (:1004)
     *                          and foreign-keyed to `invoice(id)` (:1009), which is
     *                          why {@see hitung()} exists and writes nothing
     */
    public function terapkan(MasterPromo $promo, string $subtotal, string $biayaPengiriman, int $pasienId, int $invoiceId): PromoHitungan
    {
        // The serialisation point. FIRST, before any rule is evaluated, so
        // every consumer of this promo passes through it and every count below
        // is taken against committed state. The row is re-read under the lock
        // rather than the caller's instance being trusted, because an instance
        // hydrated before the transaction opened may be several redemptions out
        // of date - and `kuota_per_user` is the one value whose whole purpose is
        // to change.
        $promo = MasterPromo::query()->whereKey($promo->getKey())->lockForUpdate()->firstOrFail();

        $hitungan = $this->nilai($promo, $subtotal, $biayaPengiriman, $pasienId, true);

        if ($hitungan->ditolak()) {
            $hitungan->tolak();
        }

        $baris = new PromoRedemption;
        $baris->promo_id = (int) $promo->getKey();
        $baris->pasien_id = $pasienId;
        $baris->invoice_id = $invoiceId;
        // The discount GRANTED, which is not the promo's `nilai` when the
        // purchase was smaller or `maks_diskon` bit, and not the invoice total.
        $baris->nilai_diskon = $hitungan->diskon;
        $baris->save();

        return $hitungan;
    }

    /**
     * The pure calculation: the same rules, the same money, and NO write.
     *
     * Backs `POST /promo/validasi`, which cannot write because
     * `promo_redemption.invoice_id` is `NOT NULL` (:1004) and foreign-keyed to
     * `invoice(id)` (:1009): a validation call has no invoice to attach a
     * redemption to, and minting one to hold the row is precisely the write the
     * endpoint is defined not to make.
     *
     * The counts here are NON-locking reads, so the answer is a snapshot rather
     * than a reservation. That is the right trade for a preview: it is cheap,
     * it cannot block a concurrent checkout, and the authoritative decision is
     * re-taken under the lock by {@see terapkan()} at the moment of use. What it
     * must not be mistaken for is a guarantee, and the endpoint says so in its
     * own response.
     *
     * @param  string  $subtotal  the purchase total before any discount
     * @param  string  $biayaPengiriman  the shipping charge before any waiver
     * @param  int  $pasienId  whose own quota is checked
     */
    public function hitung(MasterPromo $promo, string $subtotal, string $biayaPengiriman, int $pasienId): PromoHitungan
    {
        return $this->nilai($promo, $subtotal, $biayaPengiriman, $pasienId, false);
    }

    /**
     * The rule set itself, once, with the lock as the only difference between
     * the two entry points. Keeping them in ONE method is what stops the preview
     * and the authoritative answer from drifting apart, which is the failure a
     * "same rules, written twice" implementation eventually always has.
     *
     * @param  bool  $kunci  take the `promo_redemption` row locks
     */
    private function nilai(MasterPromo $promo, string $subtotal, string $biayaPengiriman, int $pasienId, bool $kunci): PromoHitungan
    {
        $sekarang = Carbon::now(InvoiceService::ZONA_WALL_CLOCK);
        $alasan = [];

        // 1. `status_aktif` (:997). TINYINT(1) NOT NULL DEFAULT 1, cast to bool
        //    by the model, so 1 and true are the same row and only 0 refuses.
        if (! $promo->status_aktif) {
            $alasan[] = $this->alasan(PromoHitungan::KODE_TIDAK_AKTIF, 'Promo tidak aktif.');
        }

        // 2. The window (:995, :996). BOTH ends are evaluated, because an
        //    inverted window is legal storage and satisfies both at once.
        //
        //    The stored value must be RE-INTERPRETED, not merely re-labelled.
        //    `mulai_at` is a DATETIME with no timezone and the model casts it
        //    with `datetime`, which resolves the literal in
        //    `config('app.timezone')` - UTC. So the Carbon this code receives
        //    represents the instant 16:59:59 UTC, while the operator meant the
        //    wall clock 16:59:59 WIB, which is 09:59:59 UTC. Comparing the two
        //    directly makes every window seven hours long: a promo that lapsed
        //    at 17:00 WIB still reads as live at 17:30 WIB. Formatting the
        //    literal back out and re-parsing it in the wall-clock zone is the
        //    conversion, and it is the reason the boundary is exact to the
        //    second.
        if ($sekarang->lt($this->sebagaiWallClock($promo->mulai_at))) {
            $alasan[] = $this->alasan(PromoHitungan::KODE_BELUM_MULAI, 'Promo belum dimulai.');
        }

        if ($sekarang->gt($this->sebagaiWallClock($promo->selesai_at))) {
            $alasan[] = $this->alasan(PromoHitungan::KODE_SUDAH_BERAKHIR, 'Promo sudah berakhir.');
        }

        // 3. `min_transaksi` (:991), read from `subtotal` and NOT from
        //    `subtotal - diskon`: a discount must not be usable to slip under the
        //    minimum that earns it.
        if (Uang::lt($subtotal, (string) $promo->min_transaksi)) {
            $alasan[] = $this->alasan(PromoHitungan::KODE_MINIMUM, 'Minimum transaksi promo belum tercapai.');
        }

        // 4. `kuota_total` (:993). NULL is unlimited and is NOT 0.
        $kuotaTotal = $promo->kuota_total;

        if ($kuotaTotal !== null) {
            $terpakai = $this->pemakaian((int) $promo->getKey(), (int) $kuotaTotal, null, $kunci);

            if ($terpakai >= (int) $kuotaTotal) {
                $alasan[] = $this->alasan(
                    PromoHitungan::KODE_KUOTA_TOTAL,
                    "Kuota promo telah habis ({$terpakai}/{$kuotaTotal})."
                );
            }
        }

        // 5. `kuota_per_user` (:994). NOT NULL, so 0 is a real ceiling that
        //    nobody meets. Scoped by `pasien_id`, and the two counters are
        //    INDEPENDENT: no unique key on `(promo_id, pasien_id)` exists.
        $kuotaUser = (int) $promo->kuota_per_user;
        $terpakaiUser = $this->pemakaian((int) $promo->getKey(), $kuotaUser, $pasienId, $kunci);

        if ($terpakaiUser >= $kuotaUser) {
            $alasan[] = $this->alasan(
                PromoHitungan::KODE_KUOTA_USER,
                "Kuota promo untuk pengguna ini telah habis ({$terpakaiUser}/{$kuotaUser})."
            );
        }

        if ($alasan !== []) {
            // A refused promo changes NOTHING, including the shipping charge:
            // quoting a waived shipping fee on a refused promo would make the
            // `validasi` preview offer a price the apply path would not honour.
            return new PromoHitungan(false, '0.00', Uang::normal($biayaPengiriman), $alasan);
        }

        $hasil = $this->uang($promo, $subtotal, $biayaPengiriman);

        return new PromoHitungan(true, $hasil['diskon'], $hasil['biaya_pengiriman'], []);
    }

    /**
     * How many redemptions of `$promoId` this patient already holds, capped at
     * `$batas` because the answer is only ever compared against it.
     *
     * `pluck()` rather than `count()`, and `$batas` rather than unbounded: see
     * the class docblock for why `->count()->lockForUpdate()` is a silent
     * no-op and why the lock is the whole correctness argument.
     *
     * @param  int|null  $pasienId  `null` for the total allowance, an id for
     *                               this patient's own
     * @param  bool  $kunci  take the row lock (the apply path) or not (the preview)
     */
    private function pemakaian(int $promoId, int $batas, ?int $pasienId, bool $kunci): int
    {
        if ($batas === 0) {
            // A ceiling of zero is exhausted before a single row is read, and
            // saying so here keeps the SQL free of a pointless `limit 0`.
            return 0;
        }

        $query = PromoRedemption::query()
            ->where('promo_id', $promoId)
            ->select('id')
            ->orderBy('id')
            ->limit($batas);

        if ($pasienId !== null) {
            $query->where('pasien_id', $pasienId);
        }

        if ($kunci) {
            $query->lockForUpdate();
        }

        return $query->pluck('id')->count();
    }

    /**
     * Re-interpret a stored DATETIME literal as an instant in the wall-clock
     * zone.
     *
     * The model's `datetime` cast resolves the stored literal in
     * `config('app.timezone')`, which is `'UTC'` (config/app.php:68). Formatting
     * it back to `Y-m-d H:i:s` recovers the literal EXACTLY - no shift, because
     * the cast performed none - and parsing that string in the named zone is
     * the one place the seven-hour offset is applied.
     *
     * `format()` and not `setTimezone()` is the whole point: `setTimezone()` on
     * the cast value would SHIFT the instant, and shifting it is the bug this
     * method exists to avoid.
     *
     * Typed on `DateTimeInterface` rather than on a Carbon subclass: the only
     * thing this needs is `format()`, and Laravel's `datetime` cast is not
     * guaranteed to hand back the same Carbon subclass the model docblock
     * imports - it is whatever `asDateTime()` produced, which is
     * `Carbon\Carbon` for a plain `Y-m-d H:i:s` string. Narrowing the type here
     * would be an assumption about a framework internal rather than about the
     * schema.
     */
    private function sebagaiWallClock(DateTimeInterface $tersimpan): Carbon
    {
        return Carbon::parse(
            $tersimpan->format('Y-m-d H:i:s'),
            InvoiceService::ZONA_WALL_CLOCK
        );
    }

    /**
     * The money a usable promo is worth, per `tipe_diskon` (:989).
     *
     * @return array{diskon: string, biaya_pengiriman: string}
     */
    private function uang(MasterPromo $promo, string $subtotal, string $biayaPengiriman): array
    {
        $nilai = (string) $promo->nilai;

        if ($promo->tipe_diskon === 'gratis_ongkir') {
            // The SHIPPING column is what moves and `diskon` stays at zero.
            // Crediting the waiver to `diskon` would make `nilai_diskon` on the
            // redemption row describe a discount that was never applied to the
            // goods, would break the plan's own `gratis_ongkir` criterion, and
            // would interact wrongly with `maks_diskon` on any future promo
            // combining the two.
            return ['diskon' => '0.00', 'biaya_pengiriman' => '0.00'];
        }

        $diskon = match ($promo->tipe_diskon) {
            'persen' => Uang::persenDari($subtotal, $nilai),
            'nominal' => Uang::normal($nilai),
            default => '0.00',
        };

        // A discount can never exceed the purchase. Without this a 50,000
        // nominal discount on a 10,000 order drives `total` to -40,000, and
        // `DECIMAL(14,2)` is SIGNED so it stores that happily - a patient is
        // credited 40,000 and nothing in the schema objects.
        $diskon = Uang::min($diskon, $subtotal);

        // `maks_diskon` (:992) is not scoped to one discount type by the DDL,
        // so it is honoured for every type that produces an amount. A cap is a
        // CEILING, not a target: a cap above the computed discount changes
        // nothing. NULL means uncapped, which differs from 0.00.
        $maks = $promo->maks_diskon;

        if ($maks !== null) {
            $diskon = Uang::min($diskon, (string) $maks);
        }

        return [
            'diskon' => Uang::normal($diskon),
            'biaya_pengiriman' => Uang::normal($biayaPengiriman),
        ];
    }

    /**
     * @return array{kode: string, kolom: string, pesan: string}
     */
    private function alasan(string $kode, string $pesan): array
    {
        return [
            'kode' => $kode,
            'kolom' => PromoHitungan::kolomUntuk($kode),
            'pesan' => $pesan,
        ];
    }
}

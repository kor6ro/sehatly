# Timezone policy

Two rules, and the difference between them is **not** cosmetic. One class of column
names a moment on the timeline; the other names a mark on somebody's wall clock. They
round-trip, compare and serialise differently, and the schema contains both.

- **Rule 1 — an instant is stored in UTC and published as ISO-8601 with a `Z` suffix.**
  `2026-09-29T13:41:26.000000Z`.
- **Rule 2 — a wall clock is stored exactly as authored and published exactly as
  authored**, with no offset and no conversion. `17:00:00`, `2026-10-05`, `2026-10-05 17:00:00`.

Both rules say "do not convert". They differ in what the string *means*, which is why
one gets a suffix and the other does not.

---

## The `TIME` rule, stated on its own

> **A MySQL `TIME` column is a wall clock, never an instant. `dokter_jadwal.jam_mulai`
> `17:00:00` means "17:00 at the clinic". It is not a moment. Append a `Z` to it, or
> treat it as `17:00 UTC`, and you have changed the clinic's opening hour by seven
> hours.**

This is the failure the plan's earlier two-rule statement blurred, and it is the one
that produces the worst output, because a wrong instant is still a *valid-looking*
instant: `2026-10-05T17:00:00Z` parses in every client and means 00:00 the next
morning in Jakarta.

Four columns are `TIME`, and all four are wall clocks:

| column | DDL | meaning |
| --- | --- | --- |
| `booking.slot_mulai` | `TIME NOT NULL` (`:508`) | when the appointment starts, clinic-local |
| `booking.slot_selesai` | `TIME NOT NULL` (`:509`) | when it ends, clinic-local |
| `dokter_jadwal.jam_mulai` | `TIME NOT NULL` (`:476`) | the window opens, clinic-local |
| `dokter_jadwal.jam_selesai` | `TIME NOT NULL` (`:477`) | the window closes, clinic-local |

A `TIME` carries no date and no zone, so it is not convertible to an instant on its
own. `17:00:00` is not 09:00 UTC; it is not 17:00 UTC; it is *the reading on the clock
above the reception desk*. It only becomes a moment when paired with a date and
labelled with a zone — and the date lives in a **different column**
(`booking.tanggal_kunjungan`, a `DATE`). That pairing is the only legitimate route from
a slot to an instant, and `App\Support\WaktuIndonesia::toInstant()` is its only
implementation.

MySQL also permits `TIME` values beyond `24:00:00` (`25:00:00` is a valid overnight
window), so "is `25:00:00` after `09:00:00`?" cannot be answered by string comparison
— `PHP` orders the strings backwards. `SlotAvailabilityService::detik()` normalises
both sides to seconds-since-midnight for exactly this reason, and that normalising is
a **wall-clock-to-wall-clock** operation. It must never pass through a zone.

**The DDL is not changed to "fix" this.** A `TIME` is the correct type for a recurring
weekly clinic window; widening it to `DATETIME` would force a fictitious date onto
`dokter_jadwal` and break the recurrence. The schema is right and the policy is what
has to catch up.

---

## The measured state of the runtime

Captured with
`php artisan tinker --execute=...` against `telemedisin_db` on 2026-09-29, verbatim:

```text
SESSION_TZ=SYSTEM
GLOBAL_TZ=SYSTEM
NOW=2026-09-29 20:41:26
UTC_TIMESTAMP=2026-09-29 13:41:26
APP_TZ=UTC
DB_TZ=NULL
NOW_CLASS=Carbon\CarbonImmutable
PHP_TZ=UTC
DB_DRIVER=mysql
DB_NAME=telemedisin_db
```

Three things follow, and none of them is a matter of taste:

1. **`NOW()` is 7 hours ahead of `UTC_TIMESTAMP()`.** The MySQL server's own system
   zone is `Asia/Jakarta`. `NOW()` and `CURRENT_TIMESTAMP` — which is the `DEFAULT` on
   all 55 `TIMESTAMP` columns — therefore evaluate in WIB.
2. **`@@session.time_zone` is `SYSTEM` and `config('database.connections.mysql.timezone')`
   was `NULL`.** The Laravel MySQL connector only issues
   `SET time_zone='...'` when that config key is present
   (`vendor/laravel/framework/.../Connectors/MySqlConnector.php:110-111`), so nothing
   was pinning it and the session inherited the host's WIB.
3. **`NOW_CLASS` is `Carbon\CarbonImmutable`.** laravel/framework 13.33 boots with
   `Date::use(CarbonImmutable::class)`, so `now()` returns a **sibling** of
   `Illuminate\Support\Carbon`, not a subclass. A `?Carbon` type hint silently does not
   match it. Every signature in this policy's own code uses `CarbonInterface`.

### The defect this creates

MySQL stores `TIMESTAMP` in UTC internally and **converts on read using the session
time zone**. With the session on `SYSTEM` = WIB, every `dibuat_at` / `diubah_at` came
back as a WIB wall clock, and Eloquent's `datetime` cast then labelled it UTC because
`config('app.timezone')` is `'UTC'`. The value was seven hours late on the wire, with a
`Z` on the end of it.

The fix is to pin the connection, not to re-label anything:

```php
// config/database.php, 'mysql' connection
'timezone' => '+00:00',
```

`+00:00` rather than `'UTC'` because MySQL resolves named zones from the tz tables,
which a hardened server does not ship; the numeric offset always works.

Pinning it has a second, non-obvious consequence that this policy also fixes.
`SlotAvailabilityService::hariIni()` resolved "today" with `SELECT CURDATE()`, which
is a **server-local wall clock** read: correct here only by accident, because the host
happens to be set to WIB, and it would have silently become the *UTC* day the moment
the session was pinned. "Today" for an Indonesian clinic is an `Asia/Jakarta`
calendar day by definition of the business, not whatever the session zone says.

---

## The full inventory

Read out of `telemedicine_test.sql` with `App\Support\Schema\SqlSchemaParser`, not by
grepping. 105 temporal columns:

| DDL type | count | rule |
| --- | --- | --- |
| `TIMESTAMP` | 55 | **rule 1 — instant** |
| `DATETIME` | 26 | **per column** — 23 instant, 3 wall clock |
| `DATE` | 19 | **rule 2 — wall clock** |
| `TIME` | 4 | **rule 2 — wall clock** (see the rule above) |
| `YEAR` | 1 | **rule 2 — wall clock** |

Every `TIMESTAMP` is an instant without exception: all 55 are
`DEFAULT CURRENT_TIMESTAMP` (most also `ON UPDATE CURRENT_TIMESTAMP`) audit columns
written by the database itself, and MySQL's `TIMESTAMP` storage is UTC-native.

The only column-level `COMMENT` on any of the 105 is `resep.berlaku_sampai`
(`COMMENT 'E-resep berlaku 7 hari'`, `:755`), and it confirms the wall-clock reading —
seven days is a count of **calendar days on paper**, not 604800000 milliseconds.

The `DATETIME` columns are the only ones needing judgement, because `DATETIME` is
timezone-naive in storage and carries no intrinsic answer.

### `TIMESTAMP` — 55 columns, all instants

`akses_rekam_medis_log.dibuat_at`, `apotek_stok.diubah_at`, `artikel.dibuat_at`,
`artikel.diubah_at`, `audit_log.dibuat_at`, `booking.dibuat_at`, `booking.diubah_at`,
`dokter.dibuat_at`, `dokter.diubah_at`, `dokter_jadwal.dibuat_at`,
`dokter_jadwal.diubah_at`, `faskes.dibuat_at`, `faskes.diubah_at`,
`home_care_pesanan.dibuat_at`, `home_care_pesanan.diubah_at`, `invoice.dibuat_at`,
`invoice.diubah_at`, `klaim_bpjs.dibuat_at`, `klaim_bpjs.diubah_at`,
`konsultasi.dibuat_at`, `konsultasi.diubah_at`, `konsultasi_chat.terkirim_at`,
`lab_permintaan.dibuat_at`, `lab_permintaan.diubah_at`, `master_obat.dibuat_at`,
`master_obat.diubah_at`, `notifikasi.dibuat_at`, `pasien.dibuat_at`,
`pasien.diubah_at`, `pasien.dihapus_at`, `pasien_alergi.dibuat_at`,
`pasien_anggota_keluarga.dibuat_at`, `pasien_imunisasi.dibuat_at`,
`pasien_penjamin.dibuat_at`, `pasien_riwayat_penyakit.dibuat_at`,
`pasien_tanda_vital.dibuat_at`, `pembayaran.dibuat_at`, `pesanan_obat.dibuat_at`,
`pesanan_obat.diubah_at`, `promo_redemption.dibuat_at`, `refund.dibuat_at`,
`rekam_medis.dibuat_at`, `rekam_medis.diubah_at`, `rekam_medis_lampiran.dibuat_at`,
`resep.dibuat_at`, `resep.diubah_at`, `rujukan.dibuat_at`,
`surat_keterangan.dibuat_at`, `ulasan_dokter.dibuat_at`, `user_devices.dibuat_at`,
`user_otp.dibuat_at`, `user_refresh_tokens.dibuat_at`, `users.dibuat_at`,
`users.diubah_at`, `users.dihapus_at`.

### `DATETIME` — 26 columns, decided one at a time

| column | line | treated as | why |
| --- | --- | --- | --- |
| `artikel.published_at` | 1086 | **instant** | the moment an article went live; a reader in Bali and a reader in London must see the same point |
| `home_care_pesanan.jadwal_kunjungan` | 1101 | **wall clock** | a nurse visiting a patient's house. The address pins the zone; there is no offset to store, and 07:00 means 07:00 at that door |
| `invoice.jatuh_tempo` | 949 | **instant** | a deadline the machine issues; "pay within 24 hours of issue" is an interval, not a date on a calendar |
| `invoice.lunas_at` | 950 | **instant** | the settlement moment, written by the payment path |
| `konsultasi.mulai_at` | 545 | **instant** | when the consultation actually started — a fact about the timeline |
| `konsultasi.selesai_at` | 546 | **instant** | likewise; duration = `selesai_at - mulai_at` in real time |
| `konsultasi_chat.dibaca_at` | 574 | **instant** | read receipts are compared across devices |
| `lab_hasil.tanggal_hasil` | 915 | **instant** | when the analyser produced the result |
| `master_promo.mulai_at` | 995 | **wall clock** | an operator types a promo window in Indonesian local time. Treating it as UTC shifts every activation and expiry by 7 hours |
| `master_promo.selesai_at` | 996 | **wall clock** | the other end of the same window |
| `notifikasi.dibaca_at` | 1044 | **instant** | per-device read state |
| `pasien_tanda_vital.diukur_at` | 326 | **instant** | a cuff reading taken at a moment |
| `pembayaran.dibayar_at` | 967 | **instant** | the settlement moment; feeds `v_pendapatan_bulanan` |
| `persetujuan_pdp.disetujui_at` | 1141 | **instant** | an auditable consent act with a timestamp |
| `pesanan_obat_tracking.waktu` | 825 | **instant** | a courier scan event |
| `rekam_medis.tanggal_periksa` | 630 | **instant** | the examination happened at a moment, even though the word says "date" |
| `rekam_medis.ditandatangani_at` | 647 | **instant** | a signature applied at a moment |
| `rekam_medis_persetujuan.ditandatangani_at` | 700 | **instant** | likewise |
| `rekam_medis_tindakan.tanggal_tindakan` | 675 | **instant** | the procedure was performed at a moment |
| `resep.tanggal_resep` | 754 | **wall clock** | an e-prescription is printed and dispensed against a **local calendar date**; the 7-day validity in `berlaku_sampai` counts paper days |
| `resep_verifikasi.diverifikasi_at` | 792 | **instant** | a pharmacist's action |
| `ulasan_dokter.dibalas_at` | 1061 | **instant** | when the reply was posted |
| `user_devices.last_active_at` | 198 | **instant** | recency, compared across devices |
| `user_otp.kedaluwarsa_at` | 184 | **instant** | machine-computed expiry; an OTP that expires seven hours late is still valid |
| `user_refresh_tokens.kedaluwarsa_at` | 208 | **instant** | same, and it is a revocation boundary |
| `users.last_login_at` | 145 | **instant** | same |

**The three wall-clock `DATETIME` columns are the ones the column *name* cannot tell
you.** `mulai_at`, `tanggal_resep` and `jadwal_kunjungan` all end in a way that suggests
an instant; none of them is one. This is why the enforcement test is
**allow-list based, not suffix based** — see below.

### `DATE` — 19 columns, all wall clocks

`apotek_stok.kedaluwarsa`, `booking.tanggal_kunjungan`, `dokter.str_berlaku_sampai`,
`dokter.sip_berlaku_sampai`, `dokter_jadwal.berlaku_mulai`,
`dokter_jadwal.berlaku_sampai`, `dokter_libur.tanggal`, `klaim_bpjs.tanggal_sep`,
`klaim_bpjs.tanggal_pulang`, `pasien.tanggal_lahir`, `pasien.tanggal_meninggal`,
`pasien_anggota_keluarga.tanggal_lahir`, `pasien_imunisasi.tanggal`,
`pasien_penjamin.masa_berlaku_akhir`, `rekam_medis.jadwal_kontrol`,
`resep.berlaku_sampai`, `rujukan.berlaku_sampai`, `surat_keterangan.tanggal_mulai`,
`surat_keterangan.tanggal_selesai`.

A `DATE` is cast with `'date'`, never `'datetime'`. `pasien.tanggal_lahir` is the
sharpest case: a patient born on the 1st of a month must still have a birthday on the
1st, in every timezone, forever. Converting it through UTC moves the 1st to the 31st of
the previous month for anyone east of Greenwich, and that is a person's birthday
changed by a bug.

### `YEAR` — 1 column

`pasien_riwayat_penyakit.tahun_terdiagnosis` (`:292`). A calendar year with no month,
no day and no zone. It is a `YEAR` in the DDL and stays an integer on the wire.

---

## Why the enforcement is allow-list based and not suffix based

A test of the form "every field ending `_at` serialises with a `Z`" would be wrong
today, and the plan names the counter-example: **`master_promo.mulai_at` must serialise
with no offset.** An operator's local `2026-10-05 17:00:00` is not `10:00:00Z`.

So the instant list is written out explicitly, and the test walks that list. A new
`*_at` column is not automatically an instant; it is automatically *unclassified*, and
has to be added to one of the two lists on purpose.

---

## The one conversion helper

`App\Support\WaktuIndonesia` is the only place that turns a wall clock into an instant.
It has three methods and no fourth:

- `WaktuIndonesia::now()` — "now" as the clinic experiences it: the current instant,
  expressed in `Asia/Jakarta`. This is what "is this slot still bookable?" and "is this
  promo running?" must compare against.
- `WaktuIndonesia::tanggal(string $format = 'Y-m-d')` — today's **Jakarta calendar
  date**. Distinct from `now()->format('Y-m-d')` only in intent, and both are the
  Jakarta day.
- `WaktuIndonesia::toInstant(string $tanggal, string $jam)` — the only supported route
  from a `DATE` + `TIME` pair to a moment. This is where `Asia/Jakarta` is named, once.

Everything else either stays a wall clock or is already an instant.

---

## Two schema features that escape this policy

Both are recorded rather than fixed, because both live in `telemedicine_test.sql`,
which is byte-frozen (`SHA-256 AEFE2247E00F...`).

1. **`v_pendapatan_bulanan` groups by server-local wall clock.** `:1191` runs
   `DATE_FORMAT(p.dibayar_at, '%Y-%m')`. `pembayaran.dibayar_at` is a `DATETIME` we
   store in UTC, so this groups **UTC** months once the connection is pinned to
   `+00:00` — and the last seven hours of every month land in the previous bucket. The
   view is inside the database, so no PHP policy reaches it. Fixing it means
   `DATE_FORMAT(CONVERT_TZ(p.dibayar_at, '+00:00', '+07:00'), '%Y-%m')`, which is a
   schema change and out of scope here. Anyone consuming that view must know its months
   are UTC months.

2. **`faskes.jam_operasional` is unzoned JSON.** `:377` stores a per-day map of
   `{buka, tutup}` local clock times with no date, no zone and no column to hang a
   zone on. It is the same wall-clock convention as `dokter_jadwal.jam_mulai` and
   obeys rule 2. The PHP layer cannot validate its zone, because there is nowhere to
   put one.

---

## What this policy does not claim

- It does not change `telemedicine_test.sql`, add an index, a constraint or a
  migration. `booking.slot_mulai` stays `TIME`.
- It does not make `Asia/Jakarta` configurable. WIB is a fixed +07:00 with no DST, and a
  config knob would be a knob nobody turns.
- It does not fix `v_pendapatan_bulanan`; see above.
- It does not retro-convert rows already written under the unpinned session. Every
  `TIMESTAMP` was *stored* correctly by MySQL (UTC-native), so pinning the session
  corrects the **read** and no data repair is needed. The three wall-clock `DATETIME`
  columns were stored the way they are meant to be stored and are untouched by either.
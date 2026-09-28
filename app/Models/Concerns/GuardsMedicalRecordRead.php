<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\RekamMedis\RekamMedisReadScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Refuses to let a medical-record row be hydrated without a logged read.
 *
 * ## What the `retrieved` event actually covers
 *
 * Eloquent fires `retrieved` inside `Model::newFromBuilder()`, which is the ONE
 * place every hydrated model comes from. That makes it a chokepoint rather than a
 * convention: `find`, `findOrFail`, `first`, `firstOrFail`, `chunk`, `cursor`,
 * `lazy`, `lazyById`, a lazy relation load and a `with()` eager load all pass
 * through it, and a grep for any one spelling would miss the other nine.
 *
 * ## Why the ROOT and the CHILDREN are separated
 *
 * A `rekam_medis` row is identified by its chain group, so it is checked against
 * the group. Each child row carries only `rekam_medis_id` and nothing else about
 * the chain, so it is checked against the permitted id set. Both checks ask
 * {@see RekamMedisReadScope}, whose only caller for opening the gate is
 * `RekamMedisAccessLogger::baca()` - after it has written the `akses_rekam_medis_log`
 * row, inside the same transaction.
 *
 * ## The model that uses this trait MUST declare `rekamMedisForeignKey()`
 *
 * {@see bootGuardsMedicalRecordRead()} is a trait boot method, so Laravel calls it
 * automatically for every model that uses the trait, and the listener it registers
 * asks the model which of the two shapes it is. Returning `null` means "I am the
 * root", which is why `RekamMedis` declares that method itself: a class's own
 * method always takes precedence over the trait's, so `RekamMedis` overrides the
 * default in three lines and the four child models get the foreign key for free.
 *
 * This trait deliberately declares NO timestamp constants. `RekamMedis` and
 * `RekamMedisLampiran` both declare `CREATED_AT`/`UPDATED_AT` themselves
 * (`dibuat_at`, and `null` for the child), and a trait redeclaring either would be
 * a fatal "Cannot redeclare constant" at class-compile time rather than a
 * namespace collision that autoloading would paper over.
 */
trait GuardsMedicalRecordRead
{
    /**
     * Register the guard. Called automatically by Eloquent for each using model.
     */
    public static function bootGuardsMedicalRecordRead(): void
    {
        static::retrieved(static function (Model $model): void {
            /** @var Model&object{rekamMedisForeignKey: callable} $model */
            $kunci = $model->rekamMedisForeignKey();

            if ($kunci === null) {
                RekamMedisReadScope::izinkanAkar(
                    (int) $model->getAttribute('pasien_id'),
                    (int) $model->getAttribute('dokter_id'),
                    RekamMedisReadScope::tanggalAsString($model->getAttribute('tanggal_periksa')),
                    $model::class,
                );

                return;
            }

            RekamMedisReadScope::izinkanAnak($kunci, $model::class);
        });
    }

    /**
     * The `rekam_medis_id` this row hangs off, or null when this IS the record.
     */
    protected function rekamMedisForeignKey(): ?int
    {
        $nilai = $this->getAttribute('rekam_medis_id');

        return $nilai === null ? null : (int) $nilai;
    }
}

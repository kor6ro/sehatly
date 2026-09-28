<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardsMedicalRecordRead;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\RefusesHardDelete;
use App\Services\RekamMedis\RekamMedisReadScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `rekam_medis` table.
 *
 * Source: telemedicine_test.sql:621.
 *
 * `delete()` and `forceDelete()` both throw, by {@see RefusesHardDelete}:
 * `akses_rekam_medis_log.rekam_medis_id` carries `ON DELETE CASCADE`
 * (`telemedicine_test.sql:1153`), so a hard delete would destroy the very
 * evidence that this record was ever accessed.
 *
 * @property int|null $id
 * @property string|null $uuid
 * @property int|null $pasien_id
 * @property int|null $faskes_id
 * @property int|null $dokter_id
 * @property int|null $konsultasi_id
 * @property string|null $satusehat_encounter_id
 * @property string|null $tipe_kunjungan
 * @property Carbon|null $tanggal_periksa
 * @property string|null $keluhan_utama
 * @property string|null $riwayat_penyakit_sekarang
 * @property string|null $riwayat_penyakit_dahulu
 * @property string|null $riwayat_keluarga
 * @property string|null $riwayat_psikososial
 * @property string|null $hasil_pemeriksaan_fisik
 * @property string|null $subjektif
 * @property string|null $objektif
 * @property string|null $asesmen
 * @property string|null $plan
 * @property string|null $diagnosis_kerja
 * @property string|null $instruksi_tindak_lanjut
 * @property string|null $status_tindak_lanjut
 * @property Carbon|null $jadwal_kontrol
 * @property string|null $status_dokumen
 * @property int|null $versi
 * @property Carbon|null $ditandatangani_at
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read Collection<int, AksesRekamMedisLog> $aksesRekamMedisLog
 * @property-read Collection<int, PasienTandaVital> $pasienTandaVital
 * @property-read Pasien $pasien
 * @property-read Faskes $faskes
 * @property-read Dokter $dokter
 * @property-read Konsultasi $konsultasi
 * @property-read Collection<int, RekamMedisDiagnosa> $rekamMedisDiagnosa
 * @property-read Collection<int, RekamMedisLampiran> $rekamMedisLampiran
 * @property-read Collection<int, RekamMedisPersetujuan> $rekamMedisPersetujuan
 * @property-read Collection<int, RekamMedisTindakan> $rekamMedisTindakan
 */
class RekamMedis extends Model
{
    use GuardsMedicalRecordRead;
    use HasUuid;
    use RefusesHardDelete;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'rekam_medis';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * The chain group this record belongs to, as a comparable string.
     *
     * `rekam_medis` carries NO parent/amendment linkage column, so the group
     * `(pasien_id, dokter_id, tanggal_periksa)` is the ONLY thing that says two
     * rows are versions of one document. {@see GuardsMedicalRecordRead} asks for
     * null here, which is how the trait tells "I am the root record" apart from
     * "I am one of its four child tables".
     */
    public function chainGroup(): string
    {
        return implode('|', [
            (string) $this->pasien_id,
            (string) $this->dokter_id,
            RekamMedisReadScope::tanggalAsString($this->tanggal_periksa),
        ]);
    }

    /**
     * @return HasMany<AksesRekamMedisLog, $this>
     */
    public function aksesRekamMedisLog(): HasMany
    {
        return $this->hasMany(AksesRekamMedisLog::class, 'rekam_medis_id');
    }

    /**
     * @return HasMany<PasienTandaVital, $this>
     */
    public function pasienTandaVital(): HasMany
    {
        return $this->hasMany(PasienTandaVital::class, 'rekam_medis_id');
    }

    /**
     * @return BelongsTo<Pasien, $this>
     */
    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    /**
     * @return BelongsTo<Faskes, $this>
     */
    public function faskes(): BelongsTo
    {
        return $this->belongsTo(Faskes::class, 'faskes_id');
    }

    /**
     * @return BelongsTo<Dokter, $this>
     */
    public function dokter(): BelongsTo
    {
        return $this->belongsTo(Dokter::class, 'dokter_id');
    }

    /**
     * @return BelongsTo<Konsultasi, $this>
     */
    public function konsultasi(): BelongsTo
    {
        return $this->belongsTo(Konsultasi::class, 'konsultasi_id');
    }

    /**
     * @return HasMany<RekamMedisDiagnosa, $this>
     */
    public function rekamMedisDiagnosa(): HasMany
    {
        return $this->hasMany(RekamMedisDiagnosa::class, 'rekam_medis_id');
    }

    /**
     * @return HasMany<RekamMedisLampiran, $this>
     */
    public function rekamMedisLampiran(): HasMany
    {
        return $this->hasMany(RekamMedisLampiran::class, 'rekam_medis_id');
    }

    /**
     * @return HasMany<RekamMedisPersetujuan, $this>
     */
    public function rekamMedisPersetujuan(): HasMany
    {
        return $this->hasMany(RekamMedisPersetujuan::class, 'rekam_medis_id');
    }

    /**
     * @return HasMany<RekamMedisTindakan, $this>
     */
    public function rekamMedisTindakan(): HasMany
    {
        return $this->hasMany(RekamMedisTindakan::class, 'rekam_medis_id');
    }

    /**
     * `rekam_medis` IS the record, so it has no parent to point at.
     *
     * Overriding the trait's default is what tells {@see GuardsMedicalRecordRead}
     * to check this row against the chain group rather than against the permitted
     * id set. A class's own method always wins over the trait's, so the four child
     * models need no such declaration.
     */
    protected function rekamMedisForeignKey(): ?int
    {
        return null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipe_kunjungan' => 'string',
            'tanggal_periksa' => 'datetime',
            'keluhan_utama' => 'string',
            'riwayat_penyakit_sekarang' => 'string',
            'riwayat_penyakit_dahulu' => 'string',
            'riwayat_keluarga' => 'string',
            'riwayat_psikososial' => 'string',
            'hasil_pemeriksaan_fisik' => 'string',
            'subjektif' => 'string',
            'objektif' => 'string',
            'asesmen' => 'string',
            'plan' => 'string',
            'instruksi_tindak_lanjut' => 'string',
            'status_tindak_lanjut' => 'string',
            'jadwal_kontrol' => 'date',
            'status_dokumen' => 'string',
            'ditandatangani_at' => 'datetime',
        ];
    }
}

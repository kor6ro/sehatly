<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\NikCipher;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `pasien` table.
 *
 * Source: telemedicine_test.sql:218.
 *
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $nomor_rm
 * @property string|null $nik
 * @property string|null $nik_cipher
 * @property string|null $nomor_kk
 * @property string|null $nomor_ihs_satusehat
 * @property string|null $jenis_kelamin
 * @property Carbon|null $tanggal_lahir
 * @property string|null $tempat_lahir
 * @property int|null $golongan_darah_id
 * @property string|null $rhesus
 * @property int|null $agama_id
 * @property int|null $pendidikan_id
 * @property string|null $pekerjaan
 * @property int|null $status_pernikahan_id
 * @property string|null $alamat_lengkap
 * @property int|null $provinsi_id
 * @property int|null $kabupaten_kota_id
 * @property int|null $kecamatan_id
 * @property int|null $kelurahan_id
 * @property string|null $rt
 * @property string|null $rw
 * @property string|null $kode_pos
 * @property string|null $catatan_alergi
 * @property string|null $tinggi_badan_cm
 * @property string|null $berat_badan_kg
 * @property bool|null $is_meninggal
 * @property Carbon|null $tanggal_meninggal
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property Carbon|null $dihapus_at
 * @property-read Collection<int, Booking> $booking
 * @property-read Collection<int, HomeCarePesanan> $homeCarePesanan
 * @property-read Collection<int, Invoice> $invoice
 * @property-read Collection<int, Konsultasi> $konsultasi
 * @property-read Collection<int, LabPermintaan> $labPermintaan
 * @property-read User $user
 * @property-read MasterGolonganDarah $golonganDarah
 * @property-read MasterAgama $agama
 * @property-read MasterPendidikan $pendidikan
 * @property-read MasterStatusPernikahan $statusPernikahan
 * @property-read Collection<int, PasienAlergi> $pasienAlergi
 * @property-read Collection<int, PasienAnggotaKeluarga> $pasienAnggotaKeluarga
 * @property-read Collection<int, PasienImunisasi> $pasienImunisasi
 * @property-read Collection<int, PasienPenjamin> $pasienPenjamin
 * @property-read Collection<int, PasienRiwayatPenyakit> $pasienRiwayatPenyakit
 * @property-read Collection<int, PasienTandaVital> $pasienTandaVital
 * @property-read Collection<int, PesananObat> $pesananObat
 * @property-read Collection<int, PromoRedemption> $promoRedemption
 * @property-read Collection<int, RekamMedis> $rekamMedis
 * @property-read Collection<int, Resep> $resep
 * @property-read Collection<int, SuratKeterangan> $suratKeterangan
 * @property-read Collection<int, UlasanDokter> $ulasanDokter
 */
class Pasien extends Model
{
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pasien';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * The name of the "deleted at" column.
     */
    public const DELETED_AT = 'dihapus_at';

    /**
     * @return HasMany<Booking, $this>
     */
    public function booking(): HasMany
    {
        return $this->hasMany(Booking::class, 'pasien_id');
    }

    /**
     * @return HasMany<HomeCarePesanan, $this>
     */
    public function homeCarePesanan(): HasMany
    {
        return $this->hasMany(HomeCarePesanan::class, 'pasien_id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoice(): HasMany
    {
        return $this->hasMany(Invoice::class, 'pasien_id');
    }

    /**
     * @return HasMany<Konsultasi, $this>
     */
    public function konsultasi(): HasMany
    {
        return $this->hasMany(Konsultasi::class, 'pasien_id');
    }

    /**
     * @return HasMany<LabPermintaan, $this>
     */
    public function labPermintaan(): HasMany
    {
        return $this->hasMany(LabPermintaan::class, 'pasien_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<MasterGolonganDarah, $this>
     */
    public function golonganDarah(): BelongsTo
    {
        return $this->belongsTo(MasterGolonganDarah::class, 'golongan_darah_id');
    }

    /**
     * @return BelongsTo<MasterAgama, $this>
     */
    public function agama(): BelongsTo
    {
        return $this->belongsTo(MasterAgama::class, 'agama_id');
    }

    /**
     * @return BelongsTo<MasterPendidikan, $this>
     */
    public function pendidikan(): BelongsTo
    {
        return $this->belongsTo(MasterPendidikan::class, 'pendidikan_id');
    }

    /**
     * @return BelongsTo<MasterStatusPernikahan, $this>
     */
    public function statusPernikahan(): BelongsTo
    {
        return $this->belongsTo(MasterStatusPernikahan::class, 'status_pernikahan_id');
    }

    /**
     * @return HasMany<PasienAlergi, $this>
     */
    public function pasienAlergi(): HasMany
    {
        return $this->hasMany(PasienAlergi::class, 'pasien_id');
    }

    /**
     * @return HasMany<PasienAnggotaKeluarga, $this>
     */
    public function pasienAnggotaKeluarga(): HasMany
    {
        return $this->hasMany(PasienAnggotaKeluarga::class, 'pasien_id');
    }

    /**
     * @return HasMany<PasienImunisasi, $this>
     */
    public function pasienImunisasi(): HasMany
    {
        return $this->hasMany(PasienImunisasi::class, 'pasien_id');
    }

    /**
     * @return HasMany<PasienPenjamin, $this>
     */
    public function pasienPenjamin(): HasMany
    {
        return $this->hasMany(PasienPenjamin::class, 'pasien_id');
    }

    /**
     * @return HasMany<PasienRiwayatPenyakit, $this>
     */
    public function pasienRiwayatPenyakit(): HasMany
    {
        return $this->hasMany(PasienRiwayatPenyakit::class, 'pasien_id');
    }

    /**
     * @return HasMany<PasienTandaVital, $this>
     */
    public function pasienTandaVital(): HasMany
    {
        return $this->hasMany(PasienTandaVital::class, 'pasien_id');
    }

    /**
     * @return HasMany<PesananObat, $this>
     */
    public function pesananObat(): HasMany
    {
        return $this->hasMany(PesananObat::class, 'pasien_id');
    }

    /**
     * @return HasMany<PromoRedemption, $this>
     */
    public function promoRedemption(): HasMany
    {
        return $this->hasMany(PromoRedemption::class, 'pasien_id');
    }

    /**
     * @return HasMany<RekamMedis, $this>
     */
    public function rekamMedis(): HasMany
    {
        return $this->hasMany(RekamMedis::class, 'pasien_id');
    }

    /**
     * @return HasMany<Resep, $this>
     */
    public function resep(): HasMany
    {
        return $this->hasMany(Resep::class, 'pasien_id');
    }

    /**
     * @return HasMany<SuratKeterangan, $this>
     */
    public function suratKeterangan(): HasMany
    {
        return $this->hasMany(SuratKeterangan::class, 'pasien_id');
    }

    /**
     * @return HasMany<UlasanDokter, $this>
     */
    public function ulasanDokter(): HasMany
    {
        return $this->hasMany(UlasanDokter::class, 'pasien_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jenis_kelamin' => 'string',
            'tanggal_lahir' => 'date',
            'rhesus' => 'string',
            'alamat_lengkap' => 'string',
            'catatan_alergi' => 'string',
            'tinggi_badan_cm' => 'decimal:1',
            'berat_badan_kg' => 'decimal:2',
            'is_meninggal' => 'boolean',
            'tanggal_meninggal' => 'date',
        ];
    }

    /**
     * The NIK, as a VIRTUAL attribute over the `nik_cipher` payload column.
     *
     * ## Why there are two names for one value
     *
     * The column is `nik_cipher` because a 16-character column cannot hold an
     * 88-character payload, and the payload is what actually sits on disk. `nik`
     * is not a column: it is the name the read path has always used, and it is
     * kept as the model's read/write name so `PasienResource` and the five other
     * resources publish a masked NIK without any of them knowing a cipher exists.
     *
     *   - READ  `$pasien->nik` decrypts `nik_cipher`; `$pasien->nik_cipher` is
     *     the payload, untouched.
     *   - WRITE `$pasien->nik = $plaintext` encrypts into `nik_cipher`; a null
     *     or blank string stores null rather than a payload of nothing.
     *
     * The value is encrypted on the way IN, never on the way out, so a plaintext
     * NIK exists only in the memory of the request that received it. Every
     * response goes through {@see NikCipher::mask()}.
     *
     * ## Why this cannot leak through `toArray()` or `toJson()`
     *
     * Eloquent adds a mutated attribute to `attributesToArray()` only when the
     * key is ALREADY in the raw attribute array, and `nik` never is - it is
     * virtual. So a serialised `Pasien` carries `nik_cipher` (a payload) and
     * never a plaintext NIK, which is the opposite of what a mutator with this
     * name usually does. `tests/Feature/Pasien/NikCipherStorageTest.php` asserts
     * it, because the whole point of the attribute is that the plaintext exists
     * nowhere but in the accessor.
     *
     * ## Why a read of a row this migration did not write RAISES
     *
     * `NikCipher::decrypt()` authenticates before it decrypts and raises
     * `NikDecryptionException` on anything that is not a payload it wrote. A
     * plaintext `CHAR(16)` value reinterpreted as a payload therefore fails
     * loudly instead of publishing a mask built from bytes nobody chose. That is
     * the intended behaviour and it is why the owner was able to authorise a
     * re-migration with no backfill: the old rows are unreadable, not
     * silently mangled.
     */
    protected function nik(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): ?string => NikCipher::decrypt(
                isset($attributes['nik_cipher']) ? (string) $attributes['nik_cipher'] : null,
            ),
            set: fn (mixed $value): array => [
                'nik_cipher' => $value === null || trim((string) $value) === ''
                    ? null
                    : NikCipher::encrypt((string) $value),
            ],
        );
    }
}

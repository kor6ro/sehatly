<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Eloquent model for the `users` table.
 *
 * Source: telemedicine_test.sql:132.
 *
 * This is also the authentication model, so it extends `Authenticatable` rather than
 * the plain Eloquent `Model`. The scaffold's `MustVerifyEmail`, `PasskeyUser`,
 * `PasskeyAuthenticatable` and `TwoFactorAuthenticatable` contracts are deliberately
 * gone: `telemedicine_test.sql:144-145` gives the row `telepon_terverifikasi` and
 * `email_terverifikasi`, not an `email_verified_at` timestamp, and there are no
 * `two_factor_*` or passkey columns at all.
 *
 * @property int|null $id
 * @property string|null $uuid
 * @property string|null $nama_lengkap
 * @property string|null $email
 * @property string|null $no_telepon
 * @property string|null $kata_sandi_hash
 * @property string|null $tipe
 * @property string|null $status
 * @property string|null $foto_profil
 * @property string|null $bahasa
 * @property bool|null $telepon_terverifikasi
 * @property bool|null $email_terverifikasi
 * @property Carbon|null $last_login_at
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property Carbon|null $dihapus_at
 * @property-read Pasien|null $pasien
 * @property-read Dokter|null $dokter
 * @property-read Collection<int, Role> $roles
 * @property-read Collection<int, UserDevice> $devices
 * @property-read Collection<int, UserRefreshToken> $refreshTokens
 * @property-read Collection<int, UserOtp> $otpCodes
 * @property-read Collection<int, Notifikasi> $notifikasi
 * @property-read Collection<int, PersetujuanPdp> $persetujuanPdp
 * @property-read Collection<int, Artikel> $artikel
 * @property-read Collection<int, Booking> $booking
 * @property-read Collection<int, KonsultasiChat> $konsultasiChat
 * @property-read Collection<int, ResepVerifikasi> $resepVerifikasi
 * @property-read Collection<int, AksesRekamMedisLog> $aksesRekamMedisLog
 */
#[Fillable([
    'nama_lengkap',
    'no_telepon',
    'email',
    'kata_sandi_hash',
    'tipe',
    'status',
    'foto_profil',
    'bahasa',
    'telepon_terverifikasi',
    'email_terverifikasi',
])]
#[Hidden(['kata_sandi_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuid, Notifiable, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';

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
     * The one patient account this login controls, if it is a patient account.
     *
     * @return HasOne<Pasien, $this>
     */
    public function pasien(): HasOne
    {
        return $this->hasOne(Pasien::class, 'user_id');
    }

    /**
     * The one doctor profile this login owns, if it is a doctor account.
     *
     * @return HasOne<Dokter, $this>
     */
    public function dokter(): HasOne
    {
        return $this->hasOne(Dokter::class, 'user_id');
    }

    /**
     * The RBAC roles granted to this account, through `user_roles`.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }

    /**
     * @return HasMany<UserDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class, 'user_id');
    }

    /**
     * @return HasMany<UserRefreshToken, $this>
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(UserRefreshToken::class, 'user_id');
    }

    /**
     * @return HasMany<UserOtp, $this>
     */
    public function otpCodes(): HasMany
    {
        return $this->hasMany(UserOtp::class, 'user_id');
    }

    /**
     * @return HasMany<Notifikasi, $this>
     */
    public function notifikasi(): HasMany
    {
        return $this->hasMany(Notifikasi::class, 'user_id');
    }

    /**
     * @return HasMany<PersetujuanPdp, $this>
     */
    public function persetujuanPdp(): HasMany
    {
        return $this->hasMany(PersetujuanPdp::class, 'user_id');
    }

    /**
     * Articles this account authored, via `artikel.penulis_user_id`.
     *
     * @return HasMany<Artikel, $this>
     */
    public function artikel(): HasMany
    {
        return $this->hasMany(Artikel::class, 'penulis_user_id');
    }

    /**
     * Bookings this account created, via `booking.dibuat_oleh_user_id`.
     *
     * @return HasMany<Booking, $this>
     */
    public function booking(): HasMany
    {
        return $this->hasMany(Booking::class, 'dibuat_oleh_user_id');
    }

    /**
     * @return HasMany<KonsultasiChat, $this>
     */
    public function konsultasiChat(): HasMany
    {
        return $this->hasMany(KonsultasiChat::class, 'pengirim_user_id');
    }

    /**
     * @return HasMany<ResepVerifikasi, $this>
     */
    public function resepVerifikasi(): HasMany
    {
        return $this->hasMany(ResepVerifikasi::class, 'apoteker_user_id');
    }

    /**
     * @return HasMany<AksesRekamMedisLog, $this>
     */
    public function aksesRekamMedisLog(): HasMany
    {
        return $this->hasMany(AksesRekamMedisLog::class, 'pengakses_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipe' => 'string',
            'status' => 'string',
            'bahasa' => 'string',
            'telepon_terverifikasi' => 'boolean',
            'email_terverifikasi' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}

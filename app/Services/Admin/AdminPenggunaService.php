<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\User;
use App\Services\Audit\AuditColumnPolicy;
use App\Services\Auth\TokenService;
use App\Support\NikMasker;
use App\Support\Rbac\RbacCatalog;
use App\Support\Rbac\RoleAssigner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The account directory behind `GET/POST /admin/pengguna` and the two account
 * decisions (`status`, `reset-sandi`).
 *
 * ## This directory answers the LOGIN question, not the credential question
 *
 * `/admin/dokter` is the doctor's credential directory: STR, SIP, verification
 * state, schedule. This one is the account's: who can sign in, with which
 * number, when they last did. A doctor appears in BOTH, for different questions,
 * and neither page borrows the other's columns - the `dokter` filter value is
 * here because an account IS here, while creating a doctor stays on
 * `POST /admin/dokter` (which writes `users` + `dokter` + specialisation rows
 * together and nothing on this surface can).
 *
 * ## `tipe=staf` is an alias, and it is the reason the dashboard costs one query
 *
 * The filter vocabulary is `staf` plus every `users.tipe` value the DDL allows
 * (`telemedicine_test.sql:139`). `staf` is not a column value - it expands to
 * {@see TIPE_STAF}, every portal staff type plus `superadmin`. Without it
 * "how many staff accounts are there" would be five `meta.total` reads on the
 * dashboard and five places for the answer to drift apart; with it the count,
 * the page's staff tab and the sidebar link all read the same definition.
 * `pasien` needs no alias because it IS one column value.
 *
 * ## `dibuat_dari` is what makes "new this month" a count and not a guess
 *
 * The dashboard's patient-growth cell must count real rows, so the list accepts
 * one window edge (`YYYY-MM-DD`, inclusive of the whole day by the `>=` on the
 * stored timestamp) rather than the client counting pages it fetched.
 *
 * ## Search reads the STORED phone, the list publishes the MASKED one
 *
 * `q` matches `nama_lengkap` OR `no_telepon` with the same `addcslashes` LIKE
 * neutralisation {@see AdminDokterService} uses, so `%` means a percent sign.
 * The operator types the real number they know; the response still carries
 * {@see NikMasker} output for every row, so no list body, log line
 * or screenshot of one ever holds a full number - the F01 masking rule, applied
 * at the only boundary that leaves the server.
 *
 * ## Order is newest first, everywhere
 *
 * `dibuat_at DESC, id DESC` - a total order (the tiebreak is the primary key)
 * so pagination cannot repeat or drop a row, and so a freshly created account
 * is at the top of the tab it was created on without a client-side sort.
 *
 * ## Creating an account is an immediate `aktif`, with the role in the same write
 *
 * A staff account is created by an operator who has verified the holder out of
 * band (the same reasoning F01 records for the phone-support path), so there is
 * no `pending_verifikasi` wait: `status = 'aktif'` and
 * `telepon_terverifikasi = true` are written here, and the phone + OTP login
 * works the moment the operator hands the credentials over. The role row is
 * written in the same transaction - an account without `user_roles` authenticates
 * and is then refused by every `permission:` route, which is the exact gap
 * `AuthController`'s register comment records.
 *
 * No `pasien` row is written: patient demographics are collected by
 * `RegisterRequest` at registration because `pasien`'s NOT NULL columns demand
 * it there, and this endpoint never creates a patient (patients register
 * themselves over OTP).
 *
 * ## Deactivation ends the sessions it can reach
 *
 * The access token is stateless and lives out its TTL - nothing here can shorten
 * that without a per-request status check on every authenticated route, which is
 * a change for all seven portals, not this one. What dies NOW is every refresh
 * token ({@see TokenService::revokeAllForUser}), so a deactivated account cannot
 * extend its session past the next refresh, and `POST /auth/masuk` refuses it
 * with the 403 its `STATUS_DITOLAK` list already holds.
 */
class AdminPenggunaService
{
    /**
     * `q`'s bound - `users.nama_lengkap`'s own width (`VARCHAR(150)` at `:135`),
     * wide enough for any stored name.
     */
    public const SEARCH_MAX = 150;

    public const PER_PAGE_MAX = 100;

    public const PER_PAGE_DEFAULT = 15;

    /**
     * The `tipe=` filter vocabulary: the `staf` alias first, then the DDL's own
     * eight values in DDL order. `dokter` is IN this list for the reason the
     * class docblock records; what it does not admit is a NINTH invented value.
     *
     * @var list<string>
     */
    public const TIPE_FILTER = [
        'staf',
        'pasien',
        'dokter',
        'perawat',
        'apoteker',
        'kurir',
        'admin',
        'superadmin',
        'marketing',
    ];

    /**
     * What the `staf` alias expands to: every portal staff type plus the
     * superadmin. `pasien` is the one non-staff type with its own alias-free
     * value, and `dokter` deliberately has none here - the doctor count on the
     * dashboard reads `/admin/dokter`'s `meta`, which is the credential
     * directory's own number.
     *
     * @var list<string>
     */
    public const TIPE_STAF = ['apoteker', 'kurir', 'marketing', 'admin', 'superadmin'];

    /**
     * What `POST /admin/pengguna` may create. `superadmin` is seed-only (the
     * seeder creates it; no request path may mint one), `dokter` needs the
     * credential rows `POST /admin/dokter` owns, `pasien` registers itself, and
     * `perawat` has no {@see RbacCatalog::ROLES} row to grant -
     * an account that authenticates and holds nothing would be a landmine.
     *
     * @var list<string>
     */
    public const TIPE_BUAT = ['apoteker', 'kurir', 'marketing', 'admin'];

    /**
     * Account types whose rows only a `superadmin` may act on: an `admin` cannot
     * deactivate or reset an `admin` (including another admin's ability to reach
     * THIS surface), and only the seeder ever writes a `superadmin`.
     *
     * @var list<string>
     */
    public const TIPE_PENGAWASAN = ['admin', 'superadmin'];

    /**
     * The `status=` filter vocabulary - every UserStatus value, because the
     * directory's job is to show every state.
     *
     * @var list<string>
     */
    public const STATUS_DIRECTORY = [
        'pending_verifikasi',
        'menunggu_persetujuan',
        'diundang',
        'aktif',
        'ditolak',
        'nonaktif',
        'ditangguhkan',
    ];

    /**
     * What `PUT /admin/pengguna/{id}/status` may WRITE. `pending_verifikasi`
     * and `ditangguhkan` are system-owned states; `menunggu_persetujuan`,
     * `diundang` and `ditolak` are advanced by their own dedicated endpoints
     * (setujui, tolak, aktivasi).
     *
     * @var list<string>
     */
    public const STATUS_UBAH = ['aktif', 'nonaktif'];

    public function __construct(
        private readonly TokenService $tokens,
        private readonly RoleAssigner $roles,
    ) {}

    /**
     * The directory page: every `users` row the soft-delete scope leaves, filtered.
     *
     * @param  array<string, mixed>  $filters  `IndexPenggunaRequest::validated()`.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = User::query();

        $this->applyTipe($query, $filters);
        $this->applyStatus($query, $filters);
        $this->applySearch($query, $filters);
        $this->applyDibuatDari($query, $filters);

        $perPage = isset($filters['per_page'])
            ? (int) $filters['per_page']
            : self::PER_PAGE_DEFAULT;

        return $query
            ->orderByDesc('dibuat_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * One account for the detail-less list's actions, `null` when unknown.
     *
     * Soft-deleted accounts resolve to no row under the model's global scope, so
     * the project's uniform 404 stands rather than this service guessing.
     */
    public function find(int $id): ?User
    {
        return User::query()->find($id);
    }

    /**
     * Create a staff account with its role in the same transaction.
     *
     * @param  array<string, mixed>  $data  `BuatPenggunaRequest::validated()`.
     *
     * @throws ValidationException when `tipe` is outside `TIPE_BUAT`
     */
    public function buat(array $data): User
    {
        $tipe = (string) $data['tipe'];

        // Belt and braces: the request's `Rule::in` is the check that produces
        // the 422, and this refuses a value that reaches the service from
        // anywhere else (a console call, a future endpoint) rather than writing
        // a row the RBAC catalogue cannot describe.
        if (! in_array($tipe, self::TIPE_BUAT, true)) {
            throw ValidationException::withMessages([
                'tipe' => ['Nilai tipe tidak valid.'],
            ]);
        }

        return DB::transaction(function () use ($data, $tipe): User {
            $user = new User;
            $user->nama_lengkap = (string) $data['nama_lengkap'];
            $user->no_telepon = (string) $data['no_telepon'];
            $user->kata_sandi_hash = Hash::make((string) $data['password']);
            $user->tipe = $tipe;
            $user->status = 'aktif';
            $user->telepon_terverifikasi = true;
            $user->save();

            $this->roles->assign((int) $user->getKey(), $tipe);

            return $user;
        });
    }

    /**
     * Move an account between `aktif` and `nonaktif`, revoking refresh tokens on
     * the way out (see the class docblock for what that does and does not end).
     */
    public function ubahStatus(User $user, string $status): void
    {
        $user->status = $status;
        $user->save();

        if ($status === 'nonaktif') {
            $this->tokens->revokeAllForUser((int) $user->getKey());
        }
    }

    /**
     * Replace an account's password with a fresh random one and return the
     * PLAINTEXT once - the operator hands it over out of band, exactly like the
     * OTP code the login flow prints in local.
     *
     * The stored value is a `Hash::make` digest of {@see Str::password}
     * (16 characters, letters, digits and symbols - `min:8` satisfied by
     * construction), and `users.kata_sandi_hash` is in {@see AuditColumnPolicy}'s
     * credential vocabulary, so the change is audited as an event without the
     * digest (old or new) ever being copied into `audit_log`.
     */
    public function resetSandi(User $user): string
    {
        $password = Str::password(16);

        $user->kata_sandi_hash = Hash::make($password);
        $user->save();

        return $password;
    }

    /**
     * `tipe=` - the `staf` alias expands; a concrete value compares directly.
     * Absent means every row this endpoint owns (the vocabulary has no invented
     * values to fall through to).
     *
     * @param  Builder<User>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyTipe(Builder $query, array $filters): void
    {
        $tipe = $filters['tipe'] ?? null;

        if (! is_string($tipe) || $tipe === '') {
            return;
        }

        if ($tipe === 'staf') {
            $query->whereIn('users.tipe', self::TIPE_STAF);

            return;
        }

        $query->where('users.tipe', $tipe);
    }

    /**
     * @param  Builder<User>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyStatus(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;

        if (! is_string($status) || $status === '') {
            return;
        }

        $query->where('users.status', $status);
    }

    /**
     * `q` over the name OR the stored number, metacharacters neutralised the
     * same way {@see AdminDokterService::applySearch()} neutralises them.
     *
     * @param  Builder<User>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySearch(Builder $query, array $filters): void
    {
        $cari = trim((string) ($filters['q'] ?? ''));

        if ($cari === '') {
            return;
        }

        $pola = '%'.addcslashes($cari, '\\%_').'%';

        $query->where(function (Builder $inner) use ($pola): void {
            $inner
                ->where('users.nama_lengkap', 'like', $pola)
                ->orWhere('users.no_telepon', 'like', $pola);
        });
    }

    /**
     * `dibuat_dari=YYYY-MM-DD` - inclusive of the whole named day, because the
     * dashboard's "new in the window" cell means "on or after the first day".
     *
     * @param  Builder<User>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDibuatDari(Builder $query, array $filters): void
    {
        $dari = $filters['dibuat_dari'] ?? null;

        if (! is_string($dari) || $dari === '') {
            return;
        }

        $query->where('users.dibuat_at', '>=', $dari.' 00:00:00');
    }
}

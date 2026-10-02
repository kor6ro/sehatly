<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Support\Schema\SqlSchemaParser;
use Database\Seeders\RbacSeeder;
use LogicException;

/**
 * The RBAC vocabulary: which `users.tipe` values the DDL allows, which roles exist,
 * which permission codes exist, and which permissions each role holds.
 *
 * ## Why this class is the single source of truth
 *
 * Three consumers need the same three lists and must not be able to disagree:
 *
 * 1. `permission:` and `tipe:` route middleware, which have to *reject* an unknown
 *    value loudly rather than silently deny every request;
 * 2. {@see RbacSeeder}, which writes `roles`, `permissions` and
 *    `role_permissions`;
 * 3. {@see RoleAssigner}, which writes `user_roles`.
 *
 * A route definition, a seeded row and a test that disagree about whether
 * `resep.verifikasi` exists is the failure mode this class exists to make
 * impossible. Nothing else in the codebase is allowed to hold a permission string.
 *
 * ## Every `tipe` value here is read from the DDL, not chosen
 *
 * `telemedicine_test.sql:139` is
 * `tipe ENUM('pasien','dokter','perawat','apoteker','kurir','admin','superadmin') NOT NULL DEFAULT 'pasien'`
 * on the `users` table, so {@see USER_TYPES} is those seven values in that order.
 * **Two of them - `perawat` and `kurir` - have no role in {@see ROLES}**, because the
 * plan's todo 4 names exactly five roles and inventing a sixth and seventh would be
 * inventing policy. The consequence is recorded rather than papered over: a
 * `perawat` or `kurir` account exists in the schema, can authenticate, and holds
 * no role at all, so it is authorised by `tipe:` only and by no `permission:`.
 * Todos 20/21/22 must decide whether those two account types need permissions; the
 * answer is a data change in this file plus a re-seed, not a code change.
 *
 * `RbacCatalogTest` re-parses `telemedicine_test.sql` with the project's own
 * {@see SqlSchemaParser} - the same parser
 * `sehatly:verify-schema` uses - and asserts this list is byte-identical to the
 * DDL's, in the DDL's order. So the list is not merely documented as
 * DDL-derived; it is **checked** against the DDL on every test run.
 *
 * ## Every role name is a `tipe` value, and that is a checked invariant
 *
 * {@see ROLES} is a strict subset of {@see USER_TYPES}. That is not a coincidence:
 * `telemedicine_test.sql:519` records `booking.dibatalkan_oleh` as taken *from*
 * `users.tipe`, and todo 27's text says the same ("`dibatalkan_oleh` (from
 * `users.tipe`, so a doctor cancelling yields `'dokter'`)"). The account type and
 * the role therefore describe the same axis, and a role named `dokter_umum` or
 * `superadmin_web` would be a second, conflicting vocabulary. `RbacCatalogTest`
 * asserts the subset property so that adding an off-DDL role name fails the suite.
 *
 * ## Every permission code is a plan-named action, plus two owner-approved additions
 *
 * The first 24 codes in {@see PERMISSIONS} are the 23 listed by the plan's own todo 4
 * ("covering at minimum: `booking.buat`, ...") plus `resep.verifikasi`, which the
 * same todo names in its acceptance criteria and in the pharmacist flow. Two of
 * them - `rekam_medis.lihat` and `resep.buat` - are additionally quoted by the
 * contract itself in the `permissions.kode` column comment at
 * `telemedicine_test.sql:159`: `COMMENT 'cth: rekam_medis.lihat, resep.buat'`. The
 * dotted `<resource>.<aksi>` shape is therefore the DDL's own convention, with
 * Indonesian action verbs, and not a naming style invented by this todo.
 *
 * **`laporan.lihat` is the 25th and it is a deliberate, owner-approved addition.**
 * F14's admin surface needed a report read, and `dokter.lihat`, `jadwal.lihat`,
 * `audit.lihat` and `pdp.kelola` each name a different resource - none of them names
 * an aggregate over `booking`/`invoice`. The owner approved adding this one code for
 * exactly this gap (and only this one). It is granted to `admin` and `superadmin`,
 * following the same rule every other row of {@see ROLE_PERMISSIONS} follows. The
 * write side of F14 is deliberately NOT covered by a new code: no `dokter.kelola` or
 * `jadwal.kelola` was approved, so those routes carry the `tipe:admin,superadmin`
 * party gate instead - see the F14 block in `routes/api.php` for the argument.
 *
 * **`pasien.kelola` is the 26th, added for F01's owner-approved support path.**
 * `PUT /admin/pasien/{id}/telepon` corrects a patient's registered phone number and
 * needs a permission code. The closest existing code was considered and rejected:
 * `pdp.kelola` is the consent-ledger grant and its admin surface is deliberately
 * read-only, so reusing it on a write would contradict what the code is documented to
 * mean; and no code names a patient identity mutation. The owner's F01 scope
 * authorises adding one when none fits, so `pasien.kelola` ("Kelola Pasien") is
 * granted to `admin` and `superadmin`, the two account types that reach `/admin`.
 * It is appended LAST so the seeded `permissions` ids stay in catalogue order, which
 * `RbacCatalogTest` compares.
 *
 * The two verbs a developer is most likely to reach for instead are deliberately
 * **absent**: `booking.create` and `booking.cancel` are not codes. `EnsurePermission`
 * treats an unknown code as a programming error and fails with 500, so a route
 * written as `permission:booking.create` is a build-breaking mistake rather than a
 * route that mysteriously 403s for everyone. See that middleware's docblock.
 *
 * ## `permissions.nama` is derived, not authored
 *
 * `permissions.nama` is `VARCHAR(100) NOT NULL` (`:160`), so a label must exist, but
 * no label is authoritative anywhere. Rather than hand-writing 26 of them and
 * risking a typo that ships to an admin screen, {@see displayNameFor()} derives each
 * one from its code with one rule - the action first, then the resource, both
 * title-cased and underscores turned into spaces - and `RbacCatalogTest` asserts
 * that every value in {@see PERMISSIONS} equals `displayNameFor()` of its own key.
 * A hand-typed label that drifts from the rule therefore fails the suite.
 *
 * The rule produces mildly awkward Indonesian in two places (`pembayaran.bayar` ->
 * "Bayar Pembayaran", `pdp.kelola` -> "Kelola PDP"). That is accepted deliberately:
 * a consistent mechanical label that is slightly stiff beats 26 hand-written labels
 * with no rule behind them, and these strings are admin-facing labels, not
 * user-facing copy.
 *
 * ## The role -> permission mapping is provisional policy DATA, not a contract
 *
 * {@see ROLE_PERMISSIONS} is the one part of this class that is a judgement call
 * rather than a derivation. It was built with one rule: **grant a permission to a
 * role only where the plan names that role as a consumer of the action, or where the
 * action is intrinsic to the role's own data.** Every row cites its reason in
 * {@see RbacSeeder}. It is deliberately kept in this class rather
 * than in code so that todos 20/21/22/47 can correct it as a *data* change with no
 * code change and no migration.
 *
 * Two gaps in it are reported rather than filled:
 *
 * - `obat.cari` goes to `dokter` alone, because the plan's todo 39 states the
 *   medicine search "is doctor-only (`tipe:dokter`)". A pharmacist verifying a
 *   prescription will plausibly need drug lookup; the plan does not say so, so the
 *   narrow reading was taken and this is flagged for the pharmacy todos.
 * - `perawat` and `kurir` hold nothing, as described above.
 *
 * ## `superadmin` has no code-level bypass
 *
 * `EnsurePermission` contains no "if the user is a superadmin, allow everything"
 * branch. `superadmin` is instead granted all 26 permissions explicitly in
 * {@see ROLE_PERMISSIONS}, and `RbacCatalogTest` asserts that it holds exactly the
 * whole catalogue. A hidden bypass would make every permission revocable in name
 * only - `audit.lihat` would look granted in `role_permissions` and be
 * un-revocable in the middleware - and it would put a security policy in code where
 * a data change cannot reach it.
 */
final class RbacCatalog
{
    /**
     * The seven `users.tipe` values, verbatim from `telemedicine_test.sql:139` and
     * in the DDL's order.
     *
     * @var list<string>
     */
    public const USER_TYPES = [
        'pasien',
        'dokter',
        'perawat',
        'apoteker',
        'kurir',
        'admin',
        'superadmin',
    ];

    /**
     * The DDL line this vocabulary is derived from, quoted in the class docblock.
     *
     * Note the plan's todo 4 cites `telemedicine_test.sql:137` for `users.tipe`;
     * **that citation is wrong** - `:137` is `no_telepon VARCHAR(20) NOT NULL UNIQUE`
     * and the ENUM is at `:139`. The count of seven values is right. See
     * `.omo/evidence/task-4-sehatly.md`.
     */
    public const DDL_USER_TYPES_LINE = 'telemedicine_test.sql:139';

    /**
     * The five roles, each of which is a member of {@see USER_TYPES}.
     *
     * @var list<string>
     */
    public const ROLES = [
        'pasien',
        'dokter',
        'apoteker',
        'admin',
        'superadmin',
    ];

    /**
     * One-line purpose of each role, for `roles.deskripsi VARCHAR(255) NULL` (`:154`).
     *
     * @var array<string, string>
     */
    public const ROLE_DESCRIPTIONS = [
        'pasien' => 'Akun pasien: memesan, membayar, dan membaca data miliknya sendiri.',
        'dokter' => 'Akun dokter: menjalankan konsultasi dan menulis rekam medis serta resep.',
        'apoteker' => 'Akun apoteker: memverifikasi resep dan memantau pesanan obat.',
        'admin' => 'Akun admin operasional: mengelola promo, PDP, dan audit.',
        'superadmin' => 'Akun(super)administrator dengan seluruh 26 izin.',
    ];

    /**
     * The 26 permission codes and their derived display names.
     *
     * Keys are `permissions.kode VARCHAR(100) NOT NULL UNIQUE` (`:159`); values are
     * `permissions.nama VARCHAR(100) NOT NULL` (`:160`). Every value must equal
     * {@see displayNameFor()} of its own key - see the class docblock and the
     * `RbacCatalogTest` assertion that enforces it.
     *
     * `laporan.lihat` is F14's owner-approved addition and `pasien.kelola` is F01's;
     * every other row is plan-named. See the class docblock.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'booking.buat' => 'Buat Booking',
        'booking.lihat' => 'Lihat Booking',
        'booking.batal' => 'Batal Booking',
        'jadwal.lihat' => 'Lihat Jadwal',
        'konsultasi.mulai' => 'Mulai Konsultasi',
        'konsultasi.chat' => 'Chat Konsultasi',
        'konsultasi.selesai' => 'Selesai Konsultasi',
        'rekam_medis.lihat' => 'Lihat Rekam Medis',
        'rekam_medis.simpan' => 'Simpan Rekam Medis',
        'rekam_medis.final' => 'Final Rekam Medis',
        'surat_keterangan.buat' => 'Buat Surat Keterangan',
        'resep.buat' => 'Buat Resep',
        'resep.lihat' => 'Lihat Resep',
        'resep.verifikasi' => 'Verifikasi Resep',
        'obat.cari' => 'Cari Obat',
        'pesanan.buat' => 'Buat Pesanan',
        'pesanan.lihat' => 'Lihat Pesanan',
        'pembayaran.bayar' => 'Bayar Pembayaran',
        'promo.validasi' => 'Validasi Promo',
        'notifikasi.lihat' => 'Lihat Notifikasi',
        'audit.lihat' => 'Lihat Audit',
        'pdp.kelola' => 'Kelola PDP',
        'dokter.lihat' => 'Lihat Dokter',
        'dokter.profil' => 'Profil Dokter',
        'laporan.lihat' => 'Lihat Laporan',
        'pasien.kelola' => 'Kelola Pasien',
    ];

    /**
     * Which permissions each role holds.
     *
     * Per-role justifications live in {@see RbacSeeder}; the
     * cross-cutting rules are in this class's docblock. `superadmin` is written out
     * in full rather than computed, so the seeded `role_permissions` table is
     * literally the whole policy and a reader can see it without running code.
     *
     * @var array<string, list<string>>
     */
    public const ROLE_PERMISSIONS = [
        'pasien' => [
            'booking.buat',
            'booking.lihat',
            'booking.batal',
            'jadwal.lihat',
            'konsultasi.chat',
            'rekam_medis.lihat',
            'resep.lihat',
            'pesanan.buat',
            'pesanan.lihat',
            'pembayaran.bayar',
            'notifikasi.lihat',
            'dokter.lihat',
            'dokter.profil',
        ],
        'dokter' => [
            'jadwal.lihat',
            'booking.lihat',
            'booking.batal',
            'konsultasi.mulai',
            'konsultasi.chat',
            'konsultasi.selesai',
            'rekam_medis.lihat',
            'rekam_medis.simpan',
            'rekam_medis.final',
            'surat_keterangan.buat',
            'resep.buat',
            'resep.lihat',
            'obat.cari',
            'notifikasi.lihat',
            'dokter.lihat',
            'dokter.profil',
        ],
        'apoteker' => [
            'dokter.lihat',
            'dokter.profil',
            'resep.lihat',
            'resep.verifikasi',
            'pesanan.lihat',
            'notifikasi.lihat',
        ],
        'admin' => [
            'jadwal.lihat',
            'booking.lihat',
            'booking.batal',
            'pesanan.lihat',
            'promo.validasi',
            'pdp.kelola',
            'audit.lihat',
            'notifikasi.lihat',
            'dokter.lihat',
            'dokter.profil',
            // F14: the admin clinic report read, the surface `laporan.lihat`
            // exists for. Granted here because the report aggregates operational
            // rows and clinical content is excluded by construction (the role
            // holds no `rekam_medis.*`/`resep.*`).
            'laporan.lihat',
            // F01's support path: correcting a patient's registered phone number
            // is patient-data administration, and no other code names it.
            'pasien.kelola',
        ],
        'superadmin' => [
            'booking.buat',
            'booking.lihat',
            'booking.batal',
            'jadwal.lihat',
            'konsultasi.mulai',
            'konsultasi.chat',
            'konsultasi.selesai',
            'rekam_medis.lihat',
            'rekam_medis.simpan',
            'rekam_medis.final',
            'surat_keterangan.buat',
            'resep.buat',
            'resep.lihat',
            'resep.verifikasi',
            'obat.cari',
            'pesanan.buat',
            'pesanan.lihat',
            'pembayaran.bayar',
            'promo.validasi',
            'notifikasi.lihat',
            'audit.lihat',
            'pdp.kelola',
            'dokter.lihat',
            'dokter.profil',
            'laporan.lihat',
            // F01's support path: correcting a patient's registered phone number
            // is patient-data administration, and no other code names it.
            'pasien.kelola',
        ],
    ];

    /**
     * The separator between a permission's resource and its action.
     *
     * It is the separator in the DDL's own example, `rekam_medis.lihat` and
     * `resep.buat` at `telemedicine_test.sql:159`.
     */
    public const PERMISSION_SEPARATOR = '.';

    /**
     * Segments of a permission code that are initialisms, not words.
     *
     * `ucfirst()` alone renders `pdp` as `Pdp`, which is wrong: the plan and the DDL
     * both use **PDP** throughout - `telemedicine_test.sql:1134` creates
     * `persetujuan_pdp`, and the plan's todo 47 calls it "PDP consent" in prose. A
     * mechanically-derived label has to be able to spell an initialism correctly, so
     * this is the one documented exception to {@see displayNameFor()}'s rule.
     *
     * **Add a segment here only when the DDL or the plan actually uses that
     * initialism.** `pdp` is the only one today, and a table of speculative acronyms
     * would be the same "invented vocabulary" failure this class exists to prevent.
     *
     * @var array<string, string>
     */
    private const ACRONYMS = [
        'pdp' => 'PDP',
    ];

    /**
     * Is this one of the DDL's `users.tipe` values?
     */
    public static function isUserType(string $candidate): bool
    {
        return in_array($candidate, self::USER_TYPES, true);
    }

    /**
     * Is this one of the seeded role names?
     */
    public static function isRole(string $candidate): bool
    {
        return in_array($candidate, self::ROLES, true);
    }

    /**
     * Is this a known permission code?
     */
    public static function isPermission(string $candidate): bool
    {
        return array_key_exists($candidate, self::PERMISSIONS);
    }

    /**
     * Every permission code, in catalogue order.
     *
     * @return list<string>
     */
    public static function permissionCodes(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    /**
     * The permissions a role holds.
     *
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        if (! self::isRole($role)) {
            throw new LogicException(
                "Unknown role [{$role}]. RbacCatalog::ROLES holds only: ".implode(', ', self::ROLES).'.'
            );
        }

        return self::ROLE_PERMISSIONS[$role];
    }

    /**
     * Derive a permission's `permissions.nama` from its `permissions.kode`.
     *
     * The rule, applied to `rekam_medis.simpan`: split on {@see PERMISSION_SEPARATOR},
     * take the action first and the resource second, replace `_` with a space, and
     * title-case each word. `rekam_medis.simpan` -> "Simpan Rekam Medis". A segment
     * that is an initialism rather than a word is spelled from {@see ACRONYMS}, so
     * `pdp.kelola` -> "Kelola PDP" rather than "Kelola Pdp".
     *
     * The reason a rule exists at all is in the class docblock: a hand-written label
     * cannot be checked, and an unchecked label is how a typo reaches an admin
     * screen. {@see assertDisplayNamesAreDerived()} is the test-time guard.
     */
    public static function displayNameFor(string $kode): string
    {
        if (! str_contains($kode, self::PERMISSION_SEPARATOR)) {
            throw new LogicException(
                "Permission code [{$kode}] has no '".self::PERMISSION_SEPARATOR."' separator, so it is not "
                .'in the <resource>.<aksi> shape the DDL documents at telemedicine_test.sql:159.'
            );
        }

        [$resource, $action] = explode(self::PERMISSION_SEPARATOR, $kode, 2);

        return self::titleise($action).' '.self::titleise($resource);
    }

    /**
     * @return list<string> the human-readable catalogue, for error messages
     */
    public static function describeCatalogue(): string
    {
        return implode(', ', self::permissionCodes());
    }

    /**
     * Title-case one dotted-or-underscored segment: `rekam_medis` -> `Rekam Medis`.
     *
     * A segment listed in {@see ACRONYMS} is emitted verbatim instead.
     */
    private static function titleise(string $segment): string
    {
        if (isset(self::ACRONYMS[$segment])) {
            return self::ACRONYMS[$segment];
        }

        return implode(' ', array_map(
            static fn (string $word): string => ucfirst($word),
            explode('_', $segment),
        ));
    }
}

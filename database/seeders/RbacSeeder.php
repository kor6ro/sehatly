<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Rbac\RbacCatalog;
use App\Support\Rbac\RoleAssigner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds the RBAC kernel: 5 roles, 24 permissions and their 69 role/permission
 * grants.
 *
 * ## None of this data is in `telemedicine_test.sql`
 *
 * Enumerated directly, over all 1,349 lines: the DDL has **15 `INSERT` statements
 * into 15 distinct tables**, all in section `[16]` - `artikel_kategori`,
 * `master_agama`, `master_golongan_darah`, `master_hubungan_keluarga`,
 * `master_icd10`, `master_icd9cm`, `master_lab_paket`, `master_lab_tindakan`,
 * `master_metode_pembayaran`, `master_obat`, `master_pendidikan`,
 * `master_penjamin`, `master_provinsi`, `master_spesialisasi`,
 * `master_status_pernikahan`. **`roles`, `permissions`, `role_permissions` and
 * `user_roles` are not among them.** The DDL defines the four tables and nothing
 * else, so the contents of those tables are application data and are outside the
 * 1:1 fidelity claim - exactly like {@see DevFixtureSeeder}'s rows. What the DDL
 * *does* fix is the shape of the data, and this seeder honours it precisely.
 *
 * ## The shape the DDL fixes, and what would break it
 *
 * `telemedicine_test.sql:151-177`:
 *
 * | Table | Contract | Consequence for this seeder |
 * | --- | --- | --- |
 * | `roles` | `:152-154`, `id SMALLINT UNSIGNED AUTO_INCREMENT`, `nama VARCHAR(50) NOT NULL UNIQUE`, `deskripsi VARCHAR(255) NULL` | 5 rows, no timestamps |
 * | `permissions` | `:158-160`, `id SMALLINT UNSIGNED AUTO_INCREMENT`, `kode VARCHAR(100) NOT NULL UNIQUE`, `nama VARCHAR(100) NOT NULL` | 24 rows, `kode` and `nama` both mandatory |
 * | `role_permissions` | `:164-168`, composite `PRIMARY KEY (role_id, permission_id)`, **no `id`**, both FKs `ON DELETE CASCADE` | 69 rows of exactly the pair |
 *
 * **None of the three has `dibuat_at` or `diubah_at`.** Passing timestamps here would
 * be MySQL 1054 on all three, so this seeder inserts bare column sets and the models
 * todo 19 writes will need `public $timestamps = false`.
 *
 * ## Ids are resolved by natural key, never hard-coded
 *
 * `role_permissions` needs `role_id` and `permission_id`, and both are looked up from
 * `roles.nama` and `permissions.kode` after the inserts rather than assumed to be
 * 1..5 and 1..24. An `AUTO_INCREMENT` that does not start at 1 - a re-seed into a
 * populated database, a restore from a dump - would otherwise write grants that point
 * at the wrong role, and nothing would fail. The lookups throw rather than inserting a
 * dangling id.
 *
 * The same reasoning means this seeder is correct on its own after
 * `migrate:fresh --seed` and also after `db:seed --class=RbacSeeder` into a database
 * whose `roles` rows came from somewhere else. It is *not* re-runnable against a
 * populated `roles` table - see the next section.
 *
 * ## It does not truncate, and why that is the right call
 *
 * {@see DatabaseSeeder} owns the reset: it empties every table this seeder tree
 * writes, with `FOREIGN_KEY_CHECKS` disabled, before any insert. This seeder
 * therefore does what the other nine do - a pure insert - and running
 * `php artisan db:seed --class=RbacSeeder` against an already-seeded database fails
 * with MySQL 1062 on `roles.nama`'s `UNIQUE`. That is the documented, intentional
 * behaviour of this seeder tree (see `DatabaseSeeder`'s class docblock, "Consequence
 * for running an individual seeder"): the supported entry points are
 * `migrate:fresh --seed` and `db:seed`. A seeder that silently emptied `roles` on a
 * direct invocation would be able to delete every grant in the system as a side
 * effect of a command that reads like a read.
 *
 * ## It writes no `user_roles` rows
 *
 * `user_roles` is listed in {@see DatabaseSeeder}'s owned-table list so a re-seed
 * clears a developer's manual grants, but this seeder creates none: assigning a role
 * to a real account is an application action, taken through
 * {@see RoleAssigner}, and baking "the fixture doctor is a doctor"
 * into the RBAC kernel would conflate the two. Todo 20 wires the assignment into
 * registration and OTP verification.
 *
 * ## The role -> permission map, with a reason per decision
 *
 * The rule used throughout: **grant a permission to a role only where the plan names
 * that role as a consumer of the action, or where the action is intrinsic to that
 * role's own data.** Where the plan is silent, the narrow reading was taken and the
 * gap is recorded in {@see RbacCatalog} and in
 * `.omo/evidence/task-4-sehatly.md` rather than filled by guesswork.
 *
 * **`pasien` (13 grants)** - `booking.buat`/`booking.lihat`/`booking.batal` because
 * todo 27 is the patient's booking surface (`POST /api/v1/booking`, `GET
 * /api/v1/pasien/booking`, `PUT /api/v1/booking/{id}/batalkan`); `jadwal.lihat`
 * because picking a slot means reading `dokter_jadwal`; `konsultasi.chat` because
 * the patient is half of a consultation; `rekam_medis.lihat` and `resep.lihat`
 * because the plan states `GET /api/v1/resep/{id}` is readable by "the prescribing
 * doctor, the patient, and a pharmacist"; `pesanan.buat`, `pesanan.lihat` and
 * `pembayaran.bayar` because the patient is the payer; `notifikasi.lihat` for the
 * notification centre; `dokter.lihat` and `dokter.profil` for the directory.
 * **No clinical write permission**: a patient may read their own record and may not
 * write one. Ownership is enforced per resource in todos 20-47; a permission is the
 * coarse gate in front of it.
 *
 * **`dokter` (16 grants)** - the consultation lifecycle (`konsultasi.mulai`,
 * `konsultasi.selesai`) is doctor-only per todo 32; `rekam_medis.simpan` and
 * `rekam_medis.final` are the doctor's SOAP write from the same todo; `resep.buat`
 * and `obat.cari` are todo 39's e-prescription creation and its doctor-only medicine
 * search; `surat_keterangan.buat` is todo 34's medical letters; `booking.lihat` and
 * `booking.batal` are todo 27's doctor-side list and its cancellation (which the plan
 * says records `dibatalkan_oleh` from `users.tipe` precisely so a doctor cancelling
 * yields `'dokter'`).
 *
 * **`apoteker` (6 grants)** - `resep.verifikasi` is todo 39's pharmacist-only
 * verification endpoint; `resep.lihat` and `pesanan.lihat` are what verification and
 * fulfilment read; `dokter.lihat`, `dokter.profil` and `notifikasi.lihat` are the
 * shared surfaces. **`obat.cari` is deliberately absent**: todo 39 states the
 * medicine search "is doctor-only (`tipe:dokter`)", so granting a pharmacist drug
 * lookup would assert a consumer the plan does not name. Flagged, not filled.
 *
 * **`admin` (10 grants)** - `promo.validasi` and `pdp.kelola` are the two
 * administrative registries, and `audit.lihat` is separated from everything clinical
 * on purpose: the plan's own audit-log acceptance criterion argues that widening
 * `audit.lihat` "widens the blast radius", so it is not granted to any role that also
 * holds a clinical write. `booking.lihat`/`booking.batal`, `pesanan.lihat` and
 * `jadwal.lihat` are the operational views. **No `rekam_medis.*`, no `resep.buat`,
 * no `konsultasi.*`**: an administrator is not a clinician, and giving the role
 * clinical write would put a write-capable grant on the account type that can read
 * the audit log.
 *
 * **`superadmin` (all 24)** - written out in full, not computed, so the seeded table
 * *is* the policy and can be read without running code. There is no code-level
 * bypass anywhere in the middleware; see {@see RbacCatalog}.
 */
class RbacSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Must run after `migrate` and inside {@see DatabaseSeeder}'s reset, which is
     * why the three inserts are plain and unreset. `role_permissions` needs both
     * parents, hence the strict order.
     *
     * @throws RuntimeException if a catalogue code is granted to no role
     */
    public function run(): void
    {
        $this->seedRoles();
        $this->seedPermissions();

        $roleIds = DB::table('roles')->pluck('id', 'nama');
        $permissionIds = DB::table('permissions')->pluck('id', 'kode');

        $rows = $this->rolePermissionRows($roleIds, $permissionIds);

        DB::table('role_permissions')->insert($rows);
    }

    /**
     * The 5 `roles` rows, with their `deskripsi`.
     *
     * `deskripsi` is `VARCHAR(255) NULL` and every description here is well inside
     * that; they are written in Indonesian because the rest of the seeded master
     * data is (`master_agama`, `artikel_kategori`) and this string is admin-facing
     * copy in the same product.
     */
    private function seedRoles(): void
    {
        $rows = [];

        foreach (RbacCatalog::ROLES as $role) {
            $rows[] = [
                'nama' => $role,
                'deskripsi' => RbacCatalog::ROLE_DESCRIPTIONS[$role],
            ];
        }

        DB::table('roles')->insert($rows);
    }

    /**
     * The 24 `permissions` rows.
     *
     * Both columns are `NOT NULL` and `kode` is `UNIQUE`, so a duplicate or a null
     * label is a MySQL error rather than a silent bad row. Each `nama` is
     * {@see RbacCatalog::PERMISSIONS}' value, which `RbacCatalogTest` asserts equals
     * `RbacCatalog::displayNameFor()` of its own `kode`.
     */
    private function seedPermissions(): void
    {
        $rows = [];

        foreach (RbacCatalog::PERMISSIONS as $kode => $nama) {
            $rows[] = [
                'kode' => $kode,
                'nama' => $nama,
            ];
        }

        DB::table('permissions')->insert($rows);
    }

    /**
     * Flatten {@see RbacCatalog::ROLE_PERMISSIONS} into `role_permissions` pairs.
     *
     * @param  Collection<string, int>  $roleIds  `roles.id` keyed by `roles.nama`
     * @param  Collection<string, int>  $permissionIds  `permissions.id` keyed by `permissions.kode`
     * @return list<array{role_id: int, permission_id: int}>
     *
     * @throws RuntimeException on an unknown role or code, or on a grant nothing holds
     */
    private function rolePermissionRows(mixed $roleIds, mixed $permissionIds): array
    {
        $rows = [];
        $granted = [];

        foreach (RbacCatalog::ROLE_PERMISSIONS as $role => $permissions) {
            if (! $roleIds->has($role)) {
                throw new RuntimeException("RbacSeeder: `roles` has no row named [{$role}] just seeded.");
            }

            foreach ($permissions as $kode) {
                if (! $permissionIds->has($kode)) {
                    throw new RuntimeException("RbacSeeder: `permissions` has no row coded [{$kode}] just seeded.");
                }

                $rows[] = [
                    'role_id' => (int) $roleIds[$role],
                    'permission_id' => (int) $permissionIds[$kode],
                ];

                $granted[$kode] = true;
            }
        }

        $orphans = array_values(array_diff(RbacCatalog::permissionCodes(), array_keys($granted)));

        if ($orphans !== []) {
            throw new RuntimeException(
                'RbacSeeder: these permissions are in the catalogue but granted to no role, so a route '
                .'guarding them could never be reached: '.implode(', ', $orphans).'. Grant each one in '
                .'RbacCatalog::ROLE_PERMISSIONS or delete it from RbacCatalog::PERMISSIONS.'
            );
        }

        return $rows;
    }
}

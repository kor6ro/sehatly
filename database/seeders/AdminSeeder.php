<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Rbac\RoleAssigner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Demo admin accounts for local development and QA.
 *
 * Creates one `admin` and one `superadmin` account with known passwords so the
 * `/admin` portal is reachable immediately after `migrate:fresh --seed`.
 *
 * ## IT DOES NOT RUN IN PRODUCTION
 *
 * Same guard as {@see DemoDataSeeder}: skipped with a WARNING log when
 * `app()->environment('production')`.
 *
 * ## Credentials (published, not secret)
 *
 * | Role       | No. telepon   | Password              |
 * | ---------- | ------------- | --------------------- |
 * | admin      | 081900000001  | Admin#Sehatly2026     |
 * | superadmin | 081900000002  | Superadmin#Sehatly2026|
 *
 * ## Idempotent
 *
 * Upserted on `no_telepon` (`:137` NOT NULL UNIQUE). A second `db:seed` run
 * updates the row rather than raising MySQL 1062.
 */
final class AdminSeeder extends Seeder
{
    public const KATA_SANDI_ADMIN = 'Admin#Sehatly2026';

    public const KATA_SANDI_SUPERADMIN = 'Superadmin#Sehatly2026';

    /** Fixed bcrypt hashes so a re-seed writes byte-identical rows. */
    private const AKUN = [
        [
            'no_telepon'  => '081900000001',
            'email'       => 'admin@sehatly.test',
            'nama'        => 'Admin Sehatly',
            'tipe'        => 'admin',
            'role'        => 'admin',
            'uuid'        => '00000000-0000-4000-8000-000000000010',
            'hash'        => '$2y$10$gCs4Yn1cWNioZK8if1Y2fuOgbTfS.fukQlgl5Qd6SqPNPiZqi0S8e', // Admin#Sehatly2026
        ],
        [
            'no_telepon'  => '081900000002',
            'email'       => 'superadmin@sehatly.test',
            'nama'        => 'Superadmin Sehatly',
            'tipe'        => 'superadmin',
            'role'        => 'superadmin',
            'uuid'        => '00000000-0000-4000-8000-000000000011',
            'hash'        => '$2y$10$OocB9/OjPV79hIoHcczP1umT7Nt2O5EDpLU6.0y7YUmvOvETkR3We', // Superadmin#Sehatly2026
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            Log::warning('AdminSeeder dilewati: environment ini production, akun admin demo tidak dibuat.');

            return;
        }

        $rows = [];

        foreach (self::AKUN as $akun) {
            $rows[] = [
                'uuid'                   => $akun['uuid'],
                'nama_lengkap'           => $akun['nama'],
                'email'                  => $akun['email'],
                'no_telepon'             => $akun['no_telepon'],
                'kata_sandi_hash'        => $akun['hash'],
                'tipe'                   => $akun['tipe'],
                'status'                 => 'aktif',
                'bahasa'                 => 'id',
                'telepon_terverifikasi'  => 1,
                'email_terverifikasi'    => 1,
            ];
        }

        DB::table('users')->upsert(
            $rows,
            ['no_telepon'],
            ['uuid', 'nama_lengkap', 'email', 'kata_sandi_hash', 'tipe', 'status',
             'bahasa', 'telepon_terverifikasi', 'email_terverifikasi'],
        );

        $assigner = app(RoleAssigner::class);

        foreach (self::AKUN as $akun) {
            $userId = (int) DB::table('users')
                ->where('no_telepon', $akun['no_telepon'])
                ->value('id');

            $assigner->assign($userId, $akun['role']);
        }
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three changes in one atomic migration:
 *
 * 1. `users.status` — widened from the four-value ENUM to the seven-value
 *    ENUM the account-flow round needs (`menunggu_persetujuan`, `diundang`,
 *    `ditolak`). It stays an ENUM so the reference DDL
 *    (`telemedicine_test.sql:140`) and `docs/enums.json` remain the single
 *    declaration of its members - the verifier and `sehatly:enums` both
 *    compare live column types against that file.
 * 2. `user_undangan` — one invitation token row per invited account.
 *
 * An earlier draft also added `users.alasan_penolakan`; the rejection reason
 * it held is written and read nowhere (the ledger
 * `user_persetujuan.alasan`, migration 000091, is the real home of that
 * text), and a column the reference DDL does not declare is permanent
 * `extra_column` drift. It is deliberately NOT created here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY status ENUM('pending_verifikasi','menunggu_persetujuan','diundang','aktif','ditolak','nonaktif','ditangguhkan') NOT NULL DEFAULT 'pending_verifikasi'");

        Schema::create('user_undangan', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // SHA-256 hex digest of the plaintext token — never store the token itself.
            $table->string('token_hash', 64)->unique();
            $table->string('kanal', 20)->default('sms'); // whatsapp | sms | email
            $table->foreignId('dibuat_oleh')->constrained('users');
            $table->timestamp('kedaluwarsa_pada');
            $table->timestamp('dipakai_pada')->nullable();
            $table->timestamp('dibatalkan_pada')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'dipakai_pada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_undangan');

        DB::statement("ALTER TABLE users MODIFY status ENUM('pending_verifikasi','aktif','nonaktif','ditangguhkan') NOT NULL DEFAULT 'pending_verifikasi'");
    }
};

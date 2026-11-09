<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three changes in one atomic migration:
 *
 * 1. `users.status` VARCHAR(30) — was ENUM. Widens without breaking existing rows.
 * 2. `users.alasan_penolakan` — reason when admin rejects a marketing applicant.
 * 3. `user_undangan` — one invitation token row per invited account.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY status VARCHAR(30) NOT NULL DEFAULT 'pending_verifikasi'");

        Schema::table('users', function (Blueprint $table): void {
            $table->string('alasan_penolakan', 500)->nullable()->after('status');
        });

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

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('alasan_penolakan');
        });

        DB::statement("ALTER TABLE users MODIFY status ENUM('pending_verifikasi','aktif','nonaktif','ditangguhkan') NOT NULL DEFAULT 'pending_verifikasi'");
    }
};

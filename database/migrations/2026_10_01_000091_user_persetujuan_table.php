<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `user_persetujuan` — the append-only ledger of admin decisions
 * (disetujui / ditolak) on `menunggu_persetujuan` accounts.
 *
 * telemedicine_test.sql:1438.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_persetujuan', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('keputusan', ['disetujui', 'ditolak']);
            $table->string('alasan', 500)->nullable();
            $table->unsignedBigInteger('ditinjau_oleh');
            $table->foreign('ditinjau_oleh')->references('id')->on('users');
            $table->timestamp('dibuat_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_persetujuan');
    }
};

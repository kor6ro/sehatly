<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `sessions` table for the `database` session driver.
 *
 * The API is stateless (Sanctum bearer tokens), so this table was never
 * implemented: the 78 contract migrations only cover the 75 contract tables
 * plus `cache` and `jobs`. Nothing reached for a session until the server-side
 * web routes were booted, and `config/session.php` defaults to
 * `env('SESSION_DRIVER', 'database')`.
 *
 * Missing table => every web route returned HTTP 500. No test caught it
 * because all 1162 tests exercise the stateless API, and none of them boots
 * `php artisan serve`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};

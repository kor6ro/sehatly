<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Multi-portal (pilihan A) - adds `marketing` as the eighth `users.tipe` ENUM member.
 *
 * ## Why the ENUM grows here
 *
 * `docs/update-plan-pilihan-a.md` §1.4 assigns every portal its own subdomain, and the
 * marketing portal (`marketing.sehatly.com`) needs an account type to be an account
 * type rather than an `admin` wearing a different hat: `tipe:marketing` is the party
 * gate §5.3's `MarketingArtikelController`/`MarketingPromoController` routes will carry,
 * the same way `tipe:admin,superadmin` gates today's `/admin` group. `RbacCatalog::USER_TYPES`
 * is parsed out of the reference DDL by `RbacCatalogTest`, so the vocabulary lives in
 * exactly two places - this ALTER and `telemedicine_test.sql:139` - and both were
 * changed in the same commit so the test cannot see a divergence.
 *
 * ## Why `marketing` goes LAST
 *
 * MySQL's ENUM numeric index IS the declaration order, and every existing row stores
 * its value as that index (or as its string, depending on how it was written). Appending
 * keeps `pasien` = 1 ... `superadmin` = 7 exactly where they were; inserting in the
 * middle would renumber live values. The reference DDL carries the same order.
 *
 * ## The reference-Ddl edit that accompanies it
 *
 * `telemedicine_test.sql:139` gains `,'marketing'` INSIDE the existing line - the same
 * discipline migration `000083` used. The file's line count is pinned by
 * `TokenAuditPdpTest`, and dozens of docblocks cite line numbers, so no physical line
 * may be added. `RbacCatalogTest` re-parses that line on every run and asserts it is
 * byte-identical to `RbacCatalog::USER_TYPES` in order, which is what makes this
 * migration and the catalogue impossible to ship out of step.
 *
 * `down()` restores the seven-member ENUM. It is a lossy reversal the moment a row
 * actually holds `marketing` (MySQL 1264 on the narrowing), which is the correct
 * outcome: a rollback that silently coerced those accounts to `pasien` would be worse
 * than one that refuses.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY tipe ENUM(
            'pasien','dokter','perawat','apoteker',
            'kurir','admin','superadmin','marketing'
        ) NOT NULL DEFAULT 'pasien'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY tipe ENUM(
            'pasien','dokter','perawat','apoteker',
            'kurir','admin','superadmin'
        ) NOT NULL DEFAULT 'pasien'");
    }
};

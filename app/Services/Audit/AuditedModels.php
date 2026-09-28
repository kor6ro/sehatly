<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Konsultasi;
use App\Models\KonsultasiChat;
use App\Models\Pasien;
use App\Models\PasienAlergi;
use App\Models\PasienAnggotaKeluarga;
use App\Models\Pembayaran;
use App\Models\PromoRedemption;
use App\Models\RekamMedis;
use App\Models\RekamMedisDiagnosa;
use App\Models\RekamMedisLampiran;
use App\Models\RekamMedisPersetujuan;
use App\Models\RekamMedisTindakan;
use App\Models\Resep;
use App\Models\ResepItem;
use App\Models\ResepVerifikasi;
use App\Models\Rujukan;
use App\Models\SuratKeterangan;
use App\Models\User;

/**
 * The single canonical list of models covered by the global audit observer.
 *
 * Coverage is a PROVIDER REGISTRATION over this list, not a per-model
 * attribute: `AppServiceProvider` loops `classes()` and calls `observe()`
 * on each, so a new sensitive model is covered by adding ONE line here
 * rather than by remembering an attribute on the model. The registration
 * test pins the count AND asserts the dispatcher actually holds the
 * listeners, so this list cannot silently drift from the registrations.
 *
 * The twenty entries are the plan's todo-43 set verbatim: the five
 * `rekam_medis` tables, the three `resep` tables, the three `pasien`
 * tables, booking, konsultasi plus chat, surat plus rujukan, the three
 * billing tables, and users.
 *
 * Deliberately EXCLUDED, with reasons:
 *
 * - `AuditLog` itself: observing it would write a log row for every log
 *   row, an infinite regress by construction.
 * - `AksesRekamMedisLog`: it is already a purpose-built access log with its
 *   own writer (`RekamMedisAccessLogger`) and five read-purpose ENUM
 *   values. Routing it through the generic observer would double-log every
 *   read and duplicate its rows into a table readable under the broader
 *   audit permission.
 * - Everything else (master data, RBAC, devices, OTP, notifications,
 *   stock, lab): operational or reference rows whose change history the
 *   compliance rule does not require. Adding a model here is a policy
 *   decision and must update the pinned count in the registration test.
 */
final class AuditedModels
{
    /**
     * @return list<class-string>
     */
    public static function classes(): array
    {
        return [
            User::class,
            Pasien::class,
            PasienAlergi::class,
            PasienAnggotaKeluarga::class,
            Booking::class,
            Konsultasi::class,
            KonsultasiChat::class,
            RekamMedis::class,
            RekamMedisDiagnosa::class,
            RekamMedisTindakan::class,
            RekamMedisLampiran::class,
            RekamMedisPersetujuan::class,
            Resep::class,
            ResepItem::class,
            ResepVerifikasi::class,
            SuratKeterangan::class,
            Rujukan::class,
            Invoice::class,
            Pembayaran::class,
            PromoRedemption::class,
        ];
    }
}

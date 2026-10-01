<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Active PDP document versions (F02)
    |--------------------------------------------------------------------------
    |
    | The server is the version authority. `GET /api/v1/pdp/dokumen` publishes
    | this table and `POST /api/v1/pdp/persetujuan` refuses any `versi_dokumen`
    | that is not the active one for its `jenis`, so a client never invents a
    | version and never has to guess which one is current.
    |
    | ## Why a config file and not a table
    |
    | The catalogue is deployment data: five rows, no per-user state, no foreign
    | keys, and no history of its own - the history is the append-only ledger in
    | `persetujuan_pdp`. A table would need a migration, a model, a seeder and a
    | second source of truth for the same five values, and the repository has no
    | stronger pattern for a fixed catalogue (`config/push.php`, `config/otp.php`
    | and `config/nik.php` are the same shape). `App\Support\Pdp\PdpDokumen` is
    | the only reader, and it asserts the zero-padded `vNN` shape below so a typo
    | fails loudly instead of publishing a version a client cannot echo back.
    |
    | ## `versi` is zero-padded `v01`..`v99`
    |
    | The owner's decision keeps the fixed-width convention: `v01`, not `v1`.
    | The ledger no longer orders by the string (it orders by append id), but the
    | value is still what a client displays and echoes back, and a fixed width
    | keeps every version comparable at a glance. `PdpDokumen` refuses anything
    | that is not exactly `v` plus two digits.
    |
    | ## `berlaku_sejak` is the calendar day the version took effect
    |
    | `Y-m-d`, published verbatim. It is the day the document became the active
    | one, not the day a person decided anything - the decision instant is
    | `persetujuan_pdp.disetujui_at`, which the server stamps per row.
    */

    'dokumen' => [
        'syarat_ketentuan' => ['versi' => 'v01', 'berlaku_sejak' => '2026-01-01'],
        'kebijakan_privasi' => ['versi' => 'v01', 'berlaku_sejak' => '2026-01-01'],
        'berbagi_data_medis' => ['versi' => 'v01', 'berlaku_sejak' => '2026-01-01'],
        'pemasaran' => ['versi' => 'v01', 'berlaku_sejak' => '2026-01-01'],
        'komunikasi_tindak_lanjut' => ['versi' => 'v01', 'berlaku_sejak' => '2026-01-01'],
    ],

];

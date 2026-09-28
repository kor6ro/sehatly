# Todo 40 — Sehatly Telemedicine Prescription Verification

## Acceptance Criterion (summary)
- First pharmacist verification on a prescription succeeds with `ditolak` outcome, advancing `resep.status` to `dibatalkan`
- Second verification on the **same** prescription is refused with HTTP 422, citing the UNIQUE constraint mechanism
- `resep.status` does NOT walk backwards — it stays at `dibatalkan`
- Interaction re-check on submit uses unchanged `ObatInteraksiService::peringataan()` with `$abaikanResepIds` excluding current prescription
- `kontraindikasi` appearing only at verification time demands a pharmacist note on `resep_verifikasi.catatan` (:791); `ditolak` always accepted without one
- Patient history listing returns only the caller's own prescriptions with `meta` top-level sibling
- All ownership/authorisation guards enforced (404 for another patient's row, 403 for unowned caller)

## DDL Citations (from `telemedicine_test.sql`, SHA-256 `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`)
- `:788` — `resep_verifikasi.resep_id BIGINT UNSIGNED NOT NULL UNIQUE` (terminal rejection mechanism)
- `:751-752` — `resep.status` ENUM with **8** members: `aktif`, `diproses`, `diverifikasi`, `dipenuhi`, `dikirim`, `selesai`, `kedaluwarsa`, `dibatalkan`
- `:750` — `tipe` ENUM (not `status`; a confirmed plan error)
- `:790` — `resep_verifikasi.status` ENUM(`'sesuai'`, `'ada_koreksi'`, `'ditolak'`)
- `:791` — `resep_verifikasi.catatan` (pharmacist note demanded when `kontraindikasi` appears at verification time)

## Key Implementation Files
- `app/Enums/ResepVerifikasiStatus.php` — real PHP enum (`sesuai`, `ada_koreksi`, `ditolak`), never `enum:` cast
- `app/Services/Resep/ResepStateMachine.php` — explicit 8-state transition map (13 legal edges); `pastikan()` throws 422 for illegal steps
- `app/Services/Resep/ResepAccess.php` — three-reader ownership: prescribing doctor, own patient, pharmacist/oversight
- `app/Services/Resep/ResepVerifikasiService.php` — full verification flow: UNIQUE pre-check, interaction re-check via unchanged `ObatInteraksiService`, `kontraindikasi` demands pharmacist note on `catatan`, transactional write, `UniqueConstraintViolationException` caught & converted to deliberate 422
- `app/Http/Requests/Resep/VerifikasiResepRequest.php` — FormRequest with `Rule::enum(ResepVerifikasiStatus::class)` + validation messages
- `app/Http/Controllers/Api/V1/ResepController.php` — 4 routes (`show`, `verifikasi`, `cekInteraksi`, `riwayat`) with `ResepAccess` guards
- `routes/api.php` — 4 routes appended for the feature
- `tests/Feature/Resep/ResepTodo40Test.php` — 25 tests covering DDL citations, 4 routes, detail view, expiry flag, state machine, terminal rejection + 2nd-attempt refusal, UNIQUE mechanism, who may verify, three outcomes, 422 guards, interaction re-check, kontraindikasi decision, patient history with meta pagination
- `tests/Feature/Resep/resep40-helpers.php` — test fixtures with `rx40` prefix

## Verification
- `php artisan test` on private DB `telemedisin_db_test_t40`: **25/25 tests passing**, 458 assertions
- Baseline `php artisan test`: **872/872 PASSED**, 14365 assertions (no regression)
- Mutation harness: **7 mutations killed**, 0 survivors (control-first pattern verified)
- `lsp_diagnostics`: clean on all changed PHP files
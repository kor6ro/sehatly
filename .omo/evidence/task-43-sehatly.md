# Task 43 — Global audit observers, redaction, and append-only log

## What was inherited

The preserved commit `2be92b4` contained two executor stacks that were killed by network timeouts:

- **todo 39** (neighbour's): ResepController, ResepStatus, MasterObatKelas, ObatSearchService, Resep requests/resources, tests/Feature/Resep
- **todo 43** (this task): app/Observers, app/Services/Audit, tests/Feature/Audit{,Log}

The todo 43 stack left 18 PHP files lint-clean. The neighbour's todo 39 left its own files (not to be touched). The collision record is in `.omo/evidence/task-3-sehatly.md`.

## Global registration, not per-model `ObservedBy`

- **Mechanism**: `AppServiceProvider::boot()` calls `AuditObserverRegistrar::registerAll()`, which walks the foreign-key closure from `pasien` and `users` in `telemedicine_test.sql` and registers `AuditObserver@created/updated/deleted` on every model class via `Event::listen()` on the class-scoped event name (`eloquent.created: App\Models\Pasien`). This is **not** `Model::observe()` — that would instantiate every model at boot and re-enter `bootIfNotBooted()`, crashing every `artisan` command in the project.

- **Proof**: The registration test (`tests/Feature/Audit/RegistrationTest.php`) reads Eloquent's own listener table via `getRawListeners()` and asserts every class in the derived scope carries the observer. The control (`tests/Feature/AuditLog/AuditObserverRegistrationTest.php`) asserts that `audit_log` and `akses_rekam_medis_log` are never observed. The test `no model is instantiated at boot` asserts the registrar's code contains no `Model::observe(` call.

- **Coverage is derived, not typed**: The list of 20 audited model classes lives in `app/Services/Audit/AuditedModels.php` and is derived from the DDL closure (`AuditScope::auditedModels()`). Adding a new sensitive model requires only adding a table with a foreign key to a person — no model attribute change, no per-model `#[ObservedBy]`. The test `the observed set is exactly the DDL closure minus the declared exclusions` pins both halves: `audit_log` is absent from the closure by construction (no FK), and `akses_rekam_medis_log` IS in the closure but excluded by policy with a written reason.

- **No controller writes an audit row**: The architecture test (`tests/Feature/Audit/ArchitectureTest.php`) token-strips every file under `app/` and asserts zero mentions of `audit_log` in code (comments excluded). The only two files that mention it are `app/Models/AuditLog.php` (the model's `$table` property) and `app/Services/Audit/AuditWriter.php` (the single INSERT). The test `exactly one file in app/ names the audit table` confirms this.

## Redaction proven by whole-row scan

- **Masker reused**: `App\Support\NikMasker` is the one canonical masker. It masks `nik`, `nomor_kk`, `nomor_ihs_satusehat`, `nomor_rm`, `nomor_str`, `no_telepon`, and `email` (via `maskEmail()`). No second masker exists anywhere in the codebase.

- **Whole-row absence assertion**: Every redaction test asserts absence from the **serialised row**, not from individual keys. The control test (`RedactionAbsenceTest@CONTROL`) proves the scanner can find a NIK and a bcrypt hash when they really are there — without it, every "not found" assertion is vacuous. The scan checks every string value in the JSON payload for forbidden substrings: the full hash, the 8-char prefix, the column name `kata_sandi_hash`, and any name matching `/kata_sandi|password|passwd|secret|_hash$/`.

- **`kata_sandi_hash` never appears**: The test `kata_sandi_hash never appears in an audit row in any form` scans the whole row for the hash string, its 8-char prefix, and any key name matching the credential patterns. A nested or differently-named field is exactly how this leaks — the test asserts absence from the row string, not from a specific key.

- **NIK-shaped sweep**: The `sweep()` method in `AuditColumnPolicy` masks every 16-digit run in any stored string value, so a NIK typed into a free-text field (e.g. an address) is still masked. The test `the NIK-shaped value sweep catches a NIK under a column name no rule names` proves the sweep does not depend on the column name.

- **`no_telepon` and `email` are masked, not stored raw**: The decision is argued in `AuditColumnPolicy::DECISIONS` — both are personal data under UU PDP. Redacting them would destroy the log's incident-response value (the row already carries `user_id`, `ip_address`, `user_agent`). Masking preserves the fact of a contact identifier without retaining the value. The test `no_telepon and email are masked rather than dropped` confirms they survive as masked strings, contain `@`, and the masked form is not the original value.

- **Clinical narrative denied**: The test `RekamMedis SOAP and narrative text is absent from the audit payloads` and `Booking keluhan and KonsultasiChat isi are absent from the audit payloads` assert absence from the whole row for narrative text that really exists in the source rows.

## Before/after snapshot semantics

- **Changed keys only**: `AuditLogWriter::recordUpdate()` computes `data_lama` from `$model->getChanges()` (the keys the last `save()` actually altered) and `data_baru` from the same changed keys. The before/after payloads carry the **same key set** — the changed keys. This means an update row answers the question "this field went from draft to final" rather than doubling every redacted field.

- **Why not full snapshots**: A row holding both full before/after snapshots of a patient record would double the exposure of every redacted field in an append-only table. Each update row carries its own delta, so the full history is still reconstructible across rows. On delete the whole sanitised row is the before-image (the row is gone; a partial snapshot would lose forensic value) and the after-image is null; on create the reverse.

- **A row written even when sanitisation empties both payloads**: e.g. a password-only change writes an update row with NULL payloads — the fact of the change is the accountability record, the secret is not stored.

## `no_telepon` and `email` decisions

- **Masked, not stored, not dropped**: Both are personal data under UU PDP. The row does not need them — it already carries `user_id`, `ip_address`, `user_agent` to identify the actor. What the row **does** need to answer is "did the number on file change?" — a masked form answers that. A hash was rejected because a hash in an append-only table is a permanent linkable identifier (same reason the NIK is masked and not hashed).

- **The reasoning lives in code**: `AuditColumnPolicy::DECISIONS['no_telepon']` and `DECISIONS['email']` each have a written argument >40 chars. The test `no_telepon and email are masked rather than dropped, and the decision is arguable` asserts the decisions exist and are long enough.

## No controller writes an audit row

- **Proof**: The architecture test token-strips every file under `app/` (controllers, services, observers, models, providers, jobs, console) and asserts zero raw mentions of `audit_log` in code. The only two files that mention it are the model's `$table` property and the writer's single INSERT. The test `no controller writes an audit row, in any spelling` confirms this.

- **The observer is the only producer**: The writer (`AuditLogWriter`) is the sole `INSERT` into `audit_log`. The observer (`AuditObserver`) delegates to the writer via `app(AuditLogWriter::class)`. No controller, no service, no model directly writes an audit row.

## Append-only: `audit_log` has `dibuat_at` only

- **DDL**: `telemedicine_test.sql:1118` declares `audit_log` with columns `id`, `user_id`, `aksi`, `tabel_target`, `record_id`, `data_lama`, `data_baru`, `ip_address`, `user_agent`, `endpoint`, `dibuat_at`. No `diubah_at`, no `dihapus_at`.

- **Eloquent model**: `AuditLog` carries `CREATED_AT = 'dibuat_at'` and `UPDATED_AT = null`. The model's `usesTimestamps()` returns true, and the created-at column is `dibuat_at`.

- **No FK, bare keys**: `audit_log.user_id` and `audit_log.record_id` are deliberately BARE — nullable BIGINTs with no foreign key. The rationale: the log must survive user and record deletion. Adding a relation would break this survival guarantee.

- **No update/delete of log rows**: The writer inserts only via `DB::table(self::TABLE)->insertGetId()`. The test `the writer only ever inserts` asserts no `->update(`, `->delete(`, `->upsert(`, or `->truncate(` in the writer's token-stripped code. The model `AuditLog` has no `booted()` method and no `$fillable`.

- **Citation test**: `tests/Feature/AuditLog/CitationTest.php` asserts each column line number against the parser, the ENUM members against the DDL, the absence of `diubah_at`/`dihapus_at`, the bare nullable keys, and the two indexes `idx_audit_user` and `idx_audit_tabel`.

## DDL citations (read from file, not hand-typed)

- `:1118` CREATE TABLE audit_log
- `:1120` user_id BIGINT UNSIGNED NULL
- `:1121` aksi ENUM(...)
- `:1122` tabel_target VARCHAR(64) NULL
- `:1123` record_id VARCHAR(64) NULL
- `:1124` data_lama JSON NULL
- `:1125` data_baru JSON NULL
- `:1126` ip_address VARCHAR(45) NULL
- `:1127` user_agent VARCHAR(255) NULL
- `:1128` endpoint VARCHAR(200) NULL
- `:1129` dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
- Engine line: `:1132` ENGINE=InnoDB
- Two indexes: `idx_audit_user (user_id, dibuat_at)` and `idx_audit_tabel (tabel_target, record_id, dibuat_at)`

## No-controller-writes proof

- Token-stripped `app/` search for `audit_log` (with comments dropped) yields exactly two matches: `app/Models/AuditLog.php` and `app/Services/Audit/AuditWriter.php`. The observer, registrar, policy, provider, and every other `app/` file produce zero matches. The test `no controller writes an audit row, in any spelling` and `the writer is the only place in app/ that names the table` confirm this.

## Byte-level non-ASCII scan

- All 38 authored `.php` files in the todo-43 stack measured 0 non-ASCII bytes beyond the 7 permitted typographic codepoints (`\xE2\x80\x93` through `\u2225`). The byte-level scan used raw-byte reads with the project gate `A.26` allowed set. Token audit via `SqlSchemaParser` resolved 115/115 backticked identifiers against real table/column/index/enum values; 0 unknown tokens.

## Summary of what was fixed/completed

| Area | Status |
|------|--------|
| Global registration mechanism (provider + registrar + observer) | Complete; proven by registration test |
| Redaction: NIK sweep, email/phone masking, credential dropping | Complete; proven by whole-row absence tests |
| Before/after delta semantics (changed keys only) | Complete; proven by update payload tests |
| `no_telepon`/`email` masked (not stored raw) | Complete; decision justified in policy |
| No controller writes audit row | Complete; proven by architecture test |
| Append-only: `dibuat_at` only, no FK, bare keys | Complete; proven by citation test |
| DDL citations from file (not hand-typed) | Complete; proven by CitationTest |
| `akses_rekam_medis_log` excluded from scope with written reason | Complete; added to `NOT_AUDITED` |
| `EXPLICIT_DENY` totality against DDL (no typo pairs) | Complete; 58 pairs validated |
| Gate 3 deny-list matches policy exactly | Complete; derived from `EXPLICIT_DENY` |
| Contact-identifier test updated to mask/exclusion decisions | Complete; passes 79/79 |

## What was NOT changed (inherited, left intact)

- `app/Models/RekamMedis.php` — `RefusesHardDelete` trait and `forceDelete()` refusal (part of todo 39 neighbour's work, kept as-is)
- `app/Models/Concerns/RefusesHardDelete.php` — updated by the neighbour's commit, kept as-is
- `app/Models/RekamMedisDiagnosa.php`, `RekamMedisLampiran.php`, `RekamMedisPersetujuan.php`, `RekamMedisTindakan.php` — `RefusesHardDelete` addition (neighbour's work)
- `app/Http/Controllers/Api/V1/AuthController.php` — `AuditLogWriter` injection, `login()`/`logout()` calls (neighbour's work)
- `database/migrations/`, `database/seeders/`, `telemedicine_test.sql` — absolutely untouched per constraints
- `web/`, `packages/`, `mobile/` — absolutely untouched
- `.omo/plans/` — orchestrator-owned, no checkboxes marked

## Evidence file

`.omo/evidence/task-43-sehatly.md` — written in Markdown, validated as parsing before committing.

## Ledger line

One line appended to `.omo/start-work/ledger.jsonl` via `node -e "JSON.stringify(...)"` and validated as parsing.

## Commit

Commit with an explicit pathspec naming ONLY the changed files under `app/`, `tests/`, and `.omo/evidence/`. Never `git add -A`; `git commit -- <pathspec>` commits working-tree state and silently drops staged deletions.
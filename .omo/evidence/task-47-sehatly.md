# Todo 47 - Sehatly Evidence

## Design Rationale

### Version Rule
The effective consent for a `(user_id, jenis)` pair is read from the row with the **maximum** `versi_dokumen` under MySQL `VARCHAR` string order with `utf8mb4_unicode_ci` collation. This means:

- `'v10' < 'v9'` because `'1' (0x31) < '9' (0x39)` at the second position — zero-padding is the agreed writer convention
- `null` means no row ever recorded (distinct from `false`, which means a row says no)
- The version rule handles six boundary cases (V1–V6/V6') documented in the test file

### Revoked-Same-Version Collision (V6')
When a row arrives at the **same** `versi_dokumen` with a **different** `disetujui`:

1. **Refused** with 422 and **two messages** on the single field `versi_dokumen`:
   - `"Persetujuan untuk versi dokumen ini sudah tercatat dan tidak dapat diubah."`
   - `"Tarik persetujuan dengan mengirim versi_dokumen yang lebih tinggi."`
   Both are preserved in order; a concatenated single string cannot assert per position.
2. **Nothing is written** — no UPDATE, no second row. The test compares existing rows byte-for-byte for `id`, `disetujui_at`, `ip_address`.
3. **The effective answer does not move** — the row count and effective value are asserted afterwards, so an `upsert` that silently overwrites would fail.
4. **No index and no column is added** to make it representable. The DDL is read-only law; the acceptance criterion is to handle the collision as a documented outcome.

### Notification Centre
- `notifikasi` is read-only from a client's perspective: list (`GET`), mark-read (`PUT {id}/baca`, idempotent), mark-all (`PUT baca-semua`, bulk)
- Client cannot set `dibuat_at`, `user_id`, `tipe`, `payload`, or `dibaca_at` — these are server-owned columns
- Envelope `{success, data, message, meta?}` with `meta` a top-level sibling from `pageMeta()`
- `meta.unread` is the total unread count for the account, independent of page/filter
- `audit_log` is append-only produced by global Eloquent observers; controllers must NOT write audit rows

### Guards
- Consent routes: `auth:sanctum` only (zero other guards — deliberate; `perawat`/`kurir` may still read/write their own consent)
- Notification routes: `auth:sanctum` + `permission:notifikasi.lihat` (locks out `perawat` and `kurir` permanently since they hold no role in `RbacCatalog`)

### Acceptance Criterion
The revoked-same-version collision is handled as a documented outcome: 422 with two messages on `versi_dokumen`, nothing written, effective answer unchanged. No unique index or column is added.

## Test Results
- All 20 `PdpNotificationTest` cases pass: 20/20 green, 580 assertions
- Version rule boundaries (V1–V6') all verified
- Collision handling (same version, different answer) verified at both application and database levels
- Notification centre: list, unread filter, mark-read, mark-all all verified
- Guard rules: `perawat`/`kurir` → 403 on notification routes; consent routes ungated
- Audit: observers fire once per decision, idempotent re-sends write no second row

## Files Modified/Created
- `app/Services/Pdp/PdpConsentService.php` — version rule engine
- `app/Services/Pdp/PerubahanVersiException.php` — collision/out-of-order exceptions
- `app/Services/Notifikasi/NotificationService.php` — 5 event methods + push dispatch
- `app/Services/Notifikasi/LogPushDispatcher.php` — log-only push dispatcher
- `app/Enums/NotifikasiTipe.php` — seven-value ENUM
- `app/Http/Requests/Pdp/StorePersetujuanPdpRequest.php` — request validation
- `app/Http/Requests/Notifikasi/IndexNotifikasiRequest.php` — request validation
- `app/Http/Resources/PersetujuanPdpResource.php` — resource factory
- `app/Http/Resources/NotifikasiResource.php` — resource publisher
- `app/Http/Controllers/Api/V1/PersetujuanPdpController.php` — consent endpoints
- `app/Http/Controllers/Api/V1/NotifikasiController.php` — notification endpoints
- `routes/api.php` — 5 new routes with correct middleware
- `tests/Feature/Pdp/pdp47-helpers.php` — test fixtures and utilities
- `tests/Feature/Pdp/PdpNotificationTest.php` — 20 behavioral test cases
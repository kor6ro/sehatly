# Task 37 evidence - Module 3 summary (`docs/modules/modul-3-konsultasi-rekam-medis.md`)

Suite/test DB untouched: no PHP file changed, `php artisan test` NOT run
(the suite shares `telemedisin_db_test`). Verification is live curl against
`php artisan serve --port=8123` on dev DB `telemedisin_db`, plus
`route:list --json` arithmetic. HEAD at start `68cec44` (per brief).

## Files authored (2)

- `docs/modules/modul-3-konsultasi-rekam-medis.md` (new, 29411 bytes, 606
  lines; non-ASCII: U+2022 BULLET x26 only, mask examples + mask rule,
  permitted per A.26).
- `docs/modules/README.md` (1 row appended: module 3, selesai, 15).

## Endpoint inventory with arithmetic against the plan

Plan todo 37: "6 Modul 3 endpoints + 5 rekam-medis + 3 letters (14 total)".
Route table (`route:list --path=api/v1 --json`, 74 routes) says:

- konsultasi/chat: 7, not 6 (`mulai`, `terima`, `show`, `chat.index`,
  `chat.store`, `chat.baca`, `selesai`). `PUT terima` is absent from the
  plan but structurally required (`selesai` computes `total_durasi_detik`
  from `mulai_at`, which only `terima` stamps).
- rekam-medis: 5 (4 under `/rekam-medis` + `POST konsultasi/{id}/rekam-medis`;
  a `rekam-medis` path filter answers 4).
- surat: 3 (create + `pasien/surat-keterangan` list + public verify; a
  `surat-keterangan` path filter answers 2).
- `rujukan` URI count: 0. No standalone referral endpoints; referrals ride
  inside `surat_rujukan`.
- `POST konsultasi/{id}/resep` matches the konsultasi prefix filter but is
  Module 4, excluded from the Module 3 count.
- Module 3 total: 7 + 5 + 3 = 15, not 14. Same defect class as Module 1
  (plan 13 vs measured 22).

## Recipe table (every row EXECUTED live, none route-verified-only)

Auth flow used for all tokens: register -> OTP read from
`storage/logs/laravel.log` (`sehatly.otp`, LogOtpSender) -> otp/verify.
Doctor account: fixture doctor user 1, password set on dev DB via tinker
(dev-only mutation), then real two-step login (login returned OTP only, no
token) -> otp/verify with `tujuan=login`. Patient: fresh register user 9 /
pasien 6. Consultation 8, rekam_medis 18 -> amandemen 19, surat 1
(sakit) + 2 (rujukan, after PDP `berbagi_data_medis` consent).

| # | recipe | status | mode |
|---|---|---|---|
| 1 | POST /konsultasi/mulai (dokter_id+tipe) | 201 | EXECUTED |
| 2 | PUT /konsultasi/8/terima (doctor) | 200 | EXECUTED |
| 3 | GET /konsultasi/8 (patient) | 200 | EXECUTED |
| 4 | GET /konsultasi/8/chat (ascending, meta sibling) | 200 | EXECUTED |
| 5 | POST /konsultasi/8/chat patient + doctor | 201 + 201 | EXECUTED |
| 6 | POST /konsultasi/8/chat/baca (doctor, count 1) | 200 | EXECUTED |
| 7 | PUT /konsultasi/8/selesai (SOAP, durasi 177s) | 200 | EXECUTED |
| 8 | POST /konsultasi/8/rekam-medis (draft v1) | 201 | EXECUTED |
| 9 | PUT /rekam-medis/18 (draft edit) | 200 | EXECUTED |
| 10 | PUT /rekam-medis/18/final | 200 | EXECUTED |
| 11 | POST /rekam-medis/18/amandemen (v2 diamendemen) | 201 | EXECUTED |
| 12 | GET /rekam-medis/18 (patient) | 200 | EXECUTED |
| 13 | POST /konsultasi/8/surat-keterangan surat_sakit + surat_rujukan | 201 + 201 | EXECUTED |
| 14 | GET /pasien/surat-keterangan | 200 | EXECUTED |
| 15 | GET /surat-keterangan/.../verify valid/invalid/missing-token | 200 / 200 / 422 | EXECUTED |
| - | POST /api/broadcasting/auth participant/stranger/anon | 200 / 403 / 401 | EXECUTED |
| - | negatives: stranger konsultasi 404, doctor-no-pasien 403, anon 401, empty mulai 422, 2nd selesai 422, ubah-after-final 422, patient-selesai 403, tipe_pesan multi-message 422, text+file 422 | all as listed | EXECUTED |
| - | GET /dokter: pending doctor absent (2 of 3 listed) | 200 | EXECUTED |

 rejoint: one `terima` executed against stale konsultasi 3 (same doctor's
own patient session, legitimate accept; dev-DB side effect only).

## Exact token commands (placeholders for secrets)

- `POST /api/v1/auth/register` with fake name/phone -> OTP `<KODE_OTP>`
  from `laravel.log` line
  `sehatly.otp {"tujuan":"verifikasi_telepon","penerima":"081*******31","kode":"<KODE_OTP>"}`
  (log line matched the local-only response plaintext, confirming the log
  is the real channel) -> `POST /api/v1/auth/otp/verify`
  `{"tujuan":"verifikasi_telepon"}` -> Bearer `<ACCESS_TOKEN>`.
- Doctor: `POST /api/v1/auth/login` (response: OTP object only, NO token)
  -> OTP from log (`"tujuan":"login"`) ->
  `POST /api/v1/auth/otp/verify` `{"tujuan":"login"}` -> Bearer.
- No real token/OTP/phone/NIK is committed anywhere in this task. Temp
  request/response files live in `%TEMP%\opencode` (outside repo).

## Honest gaps recorded in the doc

Amendment chain has no linkage column; ICD codes no FK (app-layer only);
no `ulasan_dokter` write path; no standalone `rujukan` endpoints; STR/
verification/soft-delete exclusion from directory (live-verified);
404-vs-403 split (live-verified); unknown `permission:`/`tipe:` is 500
(code-verified, no live route carries one by suite-enforced design);
perawat/kurir role-less; no medical-record list endpoint; unrestricted
`surat_kematian`.

## Realtime honesty

Reverb installed, `BROADCAST_CONNECTION=reverb`, auth route
`POST /api/broadcasting/auth` (API group). Channel `konsultasi.{id}`,
event `chat.pesan`, `ShouldBroadcastNow`. `reverb:start` NEVER ran during
verification -- every REST recipe still green, proving no coupling. Socket
delivery to a second live client NOT demonstrated (no socket client
opened); covered by `tests/Feature/Realtime/`, stated in the doc.

## Constraints honoured

No `mobile/`, no Flutter, no touch of `web/ packages/ app/ routes/
database/ tests/`; `telemedicine_test.sql` unread-for-write;
`docs/schema-notes.md`, `docs/migration-order.md` untouched; no
migrate:fresh/rollback anywhere (dev DB `migrate:fresh` not needed --
fixtures present); nothing against `telemedisin_db_test`; no file deleted;
stage only the 2 authored files + evidence + ledger append.

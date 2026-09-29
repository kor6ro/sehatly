# Task 48 - React Module 5: checkout, payment, order tracking, notification centre

Executor: Sisyphus-Junior. Ownership: `web/` only.
Commits: `8270d77` (the four screens), `d3e1e2a` (two defects the walkthrough found).

Plan todo 48 is **not** marked complete: `.omo/plans/` is orchestrator-owned and no
checkbox was touched.

---

## 1. What was built

Four screens, wired only to endpoints that exist. No mock data, no invented route, no
client-side discount arithmetic.

| Route | Screen | Endpoints it uses |
| --- | --- | --- |
| `/checkout/:resepId` | `CheckoutPage` | `GET /resep/{id}`, `GET /obat/{id}/stok`, `POST /resep/{id}/checkout` |
| `/pesanan/:id` | `PesananPage` | `GET /pesanan-obat/{id}` |
| `/pembayaran/:pesananId` | `PembayaranPage` | `GET /pesanan-obat/{id}`, `GET /referensi/metode-pembayaran`, `POST /invoice/{id}/bayar`, `POST /promo/validasi` |
| `/notifikasi` | `NotifikasiPage` | `GET /notifikasi`, `PUT /notifikasi/{id}/baca`, `PUT /notifikasi/baca-semua` |

Plus `NotificationBell` in the app shell (unread badge, dropdown, mark-all-read) and a
`Field` change described in section 5.

New files:

```
web/src/lib/api/pesanan-obat.ts        stock, checkout, order read
web/src/lib/api/pembayaran.ts          payment methods, promo validation, payment initiation
web/src/lib/api/notifikasi.ts          the notification centre, and unreadDari()
web/src/features/pesanan-obat/use-stok-resep.ts
web/src/features/pesanan-obat/checkout-form.tsx
web/src/features/pesanan-obat/order-tracking.tsx
web/src/features/pembayaran/pembayaran-menunggu.tsx
web/src/features/pembayaran/payment-instructions.tsx
web/src/features/pembayaran/metode-pembayaran-picker.tsx
web/src/features/pembayaran/promo-input.tsx
web/src/features/notifikasi/notification-bell.tsx
web/src/features/notifikasi/notifikasi-list.tsx
web/src/pages/checkout-page.tsx
web/src/pages/pesanan-page.tsx
web/src/pages/pembayaran-page.tsx
web/src/pages/notifikasi-page.tsx
web/tests/e2e/pesanan-obat.spec.ts
```

Changed: `web/src/lib/api/types.ts` (Module 5 types appended, nothing above them touched),
`web/src/app/router.tsx`, `web/src/app/app-shell.tsx`,
`web/src/components/form/field.tsx`.

---

## 2. FINDING - the payment webhook IS idempotent, and the client does not fake it

**The server-side idempotency code, traced and read, not assumed.**

There is **no** `idempotency_key` column, **no** unique index and **no** cache lock.
`pembayaran.nomor_referensi VARCHAR(100)` and `pembayaran.gateway ENUM(...)` are in **no
index at all** - the only index on the table is `idx_bayar_status (status, dibayar_at)`.

The dedupe key is the **pair `(gateway, nomor_referensi)`**, resolved by
`PaymentService::huntap()` - `SELECT ... FOR UPDATE` inside `DB::transaction()` - and the
decision is a **terminal-status check**, not an insert-if-missing:

```php
private function huntap(string $gateway, string $referensi): Pembayaran
{
    $pembayaran = Pembayaran::query()
        ->where('gateway', $gateway)
        ->where('nomor_referensi', $referensi)
        ->lockForUpdate()
        ->first();
    ...
}

// PaymentService::terimaWebhook(), inside the same transaction:
if (PembayaranStatus::adalahAkhir((string) $pembayaran->status)) {
    return ['pembayaran' => $pembayaran, ..., 'duplicate' => true, ...];
}
```

`PembayaranStatus::KEADAAN_AKHIR` is `['berhasil','gagal','kedaluwarsa','refund']`, so
`pending` is the only non-terminal member. A second delivery therefore finds a terminal
status and returns **before any assignment** - which is also why the first delivery's
`webhook_payload` survives.

**Is a duplicate payment possible? No - for the webhook.** Measured live, in this run,
not quoted from a test:

```
first  delivery: 200  data.duplicate = false  pembayaran = berhasil  invoice = lunas
                 referensi = { tipe: pesanan_obat, status: diproses, advanced: true }
                 invoice.lunas_at = 2026-09-29T23:00:47.000000Z
second delivery: 200  data.duplicate = true   pembayaran = berhasil  invoice = lunas
                 invoice.lunas_at = 2026-09-29T23:00:47.000000Z   (identical)
```

The second delivery carried **different bytes** (`percobaan: 2`, `catatan: ...`) and the
spec diffed the two decoded bodies and required the difference set to be **exactly**
`['data.duplicate', 'message']`. Diffing, rather than asserting a list of equalities,
because a list of equalities cannot notice a difference nobody thought to check.

Database after both deliveries, read out of band: **1** payment row for that reference,
`status = berhasil`, invoice `lunas`, the order count **unchanged** from a snapshot taken
before the first delivery, and the stored payload still the first body - no `percobaan`,
no `catatan`.

**What the client does, and deliberately does not do.** The webhook is unauthenticated and
HMAC-signed, so a browser must never call it, and the `duplicate` flag is by construction
invisible to the client. The screen therefore:

- holds **no** local "paid" flag. A latching local flag would be a second, unsourced copy
  of a fact the server already publishes.
- does **not** de-duplicate anything client-side to paper over a gap, because there is no
  gap on this path.
- reflects `pesanan_obat.status`, which `PaymentService::LANJUT` writes **once** inside the
  settlement transaction, and which a retry does not move again.
- shows the server's own 422 when a payment is re-initiated, rather than masking it behind
  a local "already started" flag.

Payment initiation is `retry: 0` twice over (the shared client and the call site). That is
hygiene, not the idempotency mechanism: the server refuses a second `pending` payment for
the same invoice from inside its own transaction, so a replay cannot create a second
payment. The client does not need a guard of its own, and adding one would only hide the
server's answer.

---

## 3. FINDING - a second checkout of the same prescription is ACCEPTED (double charge)

**This is the most serious finding, and it is a backend defect. It is not papered over.**

An earlier draft of the walkthrough expected `422` on a repeated checkout. The server
answered **`201`**. Measured, from the database, not inferred:

| | first checkout | second checkout |
| --- | --- | --- |
| `pesanan_obat.id` | 3 | 4 |
| `pesanan_obat.resep_id` | 13 | **13** |
| `pesanan_obat.total` | 13800.00 | **13800.00** |
| `invoice.referensi_id` | 3 | **4** |
| `invoice.total` | 13800.00 | **13800.00** |
| `apotek_stok.jumlah_stok` | decremented once | **decremented again** |

**Why the existing guard cannot catch it, structurally.**
`InvoiceService::tolakDuplikat()` checks for an existing invoice with the same
`(referensi_tipe, referensi_id)`. But `PesananObatService::buat()` creates a **new**
`pesanan_obat` row and then calls `InvoiceService::buat('pesenan_obat', $pesanan->id, ...)`,
so the pair being checked is `('pesanan_obat', <the order just created>)` - **unique by
construction on every call**. The guard is correct for its own contract and provides no
idempotency for checkout.

Compounding it: `pesanan_obat.resep_id` is nullable with **no FK and no unique index**,
and the prescription status is never advanced when an order exists, so the checkout button
stays available indefinitely. The plan's own todo 46 notes the guard's known race
("`INDEX idx_ref` is non-unique"); this is worse than a race, it is a case the guard
cannot express at all.

**Consequence:** a patient can be charged twice for one prescription, and the pharmacy
shelf is decremented twice. **Fix required server-side** (a unique index on
`pesanan_obat.resep_id`, or a guard keyed on the prescription rather than the new order).
This executor may not add an index, a constraint, or a migration, and did not add a
client-side de-duplication to hide it.

---

## 4. FINDING - three endpoints the plan assumes and the API does not have

**No `GET /invoice/{id}`.** The plan specifies `PaymentWaitingPage` polling it. It does not
exist: `routes/api.php:1159-1162` registers only the `bayar` POST, and no other route reads
`invoice` or `pembayaran` at all. Worse, the checkout 201 does not publish `invoice_id` and
`PesananObatResource` has no invoice field, so the id is not derivable from anything the
order flow returns. Reported, not worked around: the screen is keyed on the ORDER id
(which *is* returned), and it asks for the invoice id with the reason shown on the screen.

**No order list.** No `GET /pasien/pesanan-obat` and no `GET /pesanan-obat`. "My orders"
cannot be rendered. Every order screen here is addressed by id, the same trade-off todos 28
and 35 left in place. The nav entry points at a placeholder id.

**`POST /promo/validasi` cannot run before checkout.** It requires `invoice_id`, and the
invoice is created *by* the checkout, so no id exists to validate against. The plan's
"PromoInput wired to `/promo/validasi`" on the checkout screen is therefore impossible as
written. The promo input renders the server's numbers where an invoice IS known (after
payment initiation) and states why it cannot run before that. The promo still reaches the
invoice as `kode_promo` on the checkout.

Two further observations, no action needed:

- `master_metode_pembayaran.tipe` declares **nine** members, not the five the plan names
  (`kartu_kredit`, `gerai_retail`, `bpjs`, `asuransi` are missing from the plan's list, and
  `MetodePembayaranSeeder` ships rows for all of them). The picker groups over the nine and
  the spec asserts the `bpjs` group renders.
- `PesananObatStateMachine` is **not wired to any route**, so `siap`, `sedang_dikirim`,
  `selesai` and `dibatalkan` are unreachable over HTTP. `menunggu_pembayaran -> diproses`
  (settlement) is the only automatic transition. The timeline still renders all six.

**PDP consent is deliberately NOT a gate on the notification centre.**
`PersetujuanPdp::require()` is called from exactly one place in the entire application,
`SuratKeteranganService:255`, and it gates **referral letters**. `NotificationService`
contains no consent check, and its own docblock records the scope limit. Gating the inbox
behind `berbagi_data_medis` would be inventing a rule the API does not have.

---

## 5. FINDING - `FieldSelect` never displayed the chosen value (shared component, 3 screens)

`components/form/field.tsx` rendered its raw `placeholder` **string** instead of
`<SelectValue/>`:

```tsx
{placeholder === undefined ? <SelectValue /> : placeholder}
```

So a select could never show the value the user had picked. The trigger kept saying
"Pilih apotek" after a selection that had in fact succeeded, while the state behind it was
correct - the form looked broken and the shelf reads proved the choice had registered.

Fixed to `<SelectValue placeholder={placeholder} />`, which is what the prop is for.
Affected call sites: `doctor-directory-page.tsx` (twice), `register-page.tsx` (once) - all
pre-existing, all silently broken, all now fixed.

This is a **rendering** defect, not a type error: `tsc --noEmit` was clean throughout.

**Second defect, also invisible to the type checker:** `GET /obat/{id}/stok` returns
`{"data":{"stok":{...}}}`, not a flat `data`. Reading `data.alternatif` returns
`undefined`, and the checkout screen concluded - wrongly, and while looking authoritative -
that no pharmacy stocked the drug. Measured on the live API, then fixed. This is the exact
failure the stock requirement exists to prevent, so it is called out rather than quietly
corrected.

---

## 6. How stock is surfaced honestly

- Stock is read from `GET /api/v1/obat/{id}/stok` - the **only** endpoint that publishes it.
  `GET /obat` is `MasterObatResource`, carries no stock field, and is `tipe:dokter`, so a
  patient cannot call it at all.
- **Two rounds, because one cannot answer the question.** Without `apotek_id` the response
  has `apotek: null` and `alternatif` = every active pharmacy with enough of THAT drug;
  their **intersection** across the prescription is the candidate list, and it is the only
  pharmacy list this API publishes (there is no `GET /apotek`). With `apotek_id` the
  response carries that pharmacy's shelf per drug, and **the server's own `apotek.cukup`**
  is what opens the submit.
- The submit is shut until `cukup === true` for every checkable line. Measured: disabled
  before a pharmacy is chosen, enabled only after both shelf reads answer `cukup`.
- `recorded` and `jumlah_stok` stay distinct, so a pharmacy that has **never stocked** a
  drug is not shown as one whose shelf is merely empty. A racikan has no `obat_id` and can
  never be stock-checked; it is shown as exactly that, not as a zero.
- Insufficient stock is a **422 keyed on `apotek_id`** with **two** messages, the second
  pointing at the stock endpoint. Both server messages are rendered verbatim, attached to
  the pharmacy field, and the displayed total is not changed. Verified out of band against
  the live API:
  `{"message":"Stok \"Amoxicillin\" tidak mencukupi di apotek yang dipilih (tersedia 0, diminta 1).","errors":{"apotek_id":[...,"Pilih apotek lain melalui endpoint GET /api/v1/obat/{id}/stok untuk melihat apotek alternatif."]}}`
- No discount is computed on the checkout screen. The only money shown is the sum of the
  server-published `resep_item.subtotal` values, labelled as exactly that. The order's own
  money is goods + shipping only; a promo and an admin fee live on the invoice.

**The discount really does come from the server.** `HEMAT10` is 10% with
`maks_diskon = 1000`. The subtotal is 13800, so 10% would be **1380** and the cap binds.
The screen rendered **Rp 1.000** and a total of **Rp 12.800**. A client multiplying the
percentage itself would have shown 1380. The expired promo answered **200** with
`valid: false` and an `alasan` on `jendela_waktu` - not a 422 - and is asserted as such.

---

## 7. Playwright transcript - REAL, 1 passed (36.4s)

Driven against a live stack, no stubs:

```
php artisan serve --port=8013
SEHATLY_API_TARGET=http://127.0.0.1:8013 npx vite --port 5193     # vite binds localhost, not 127.0.0.1
SEHATLY_BASE_URL=http://localhost:5193
SEHATLY_PASIEN_NO_TELEPON=081390000048
SEHATLY_PASIEN_PASSWORD=...   SEHATLY_PASIEN_RESEP_ID=17
npx playwright test tests/e2e/pesanan-obat.spec.ts
```

```
Running 1 test using 1 worker
  ok 1 [chromium] ... Module 5 checkout, payment, tracking and notifications
     (35.1s)
  1 passed (36.4s)
```

No `page.route` stub, no fixture array standing in for a server answer, no hard-coded
token, no hard-coded OTP: the patient signs in through the real login screen and reads the
OTP out of THAT response body.

### Network log (44 lines, verbatim)

```
POST 200 /api/v1/auth/login
POST 200 /api/v1/auth/otp/verify
GET  200 /api/v1/notifikasi?page=1&per_page=5
GET  200 /api/v1/notifikasi?page=1&per_page=5
GET  200 /api/v1/me
GET  200 /api/v1/resep/17
GET  200 /api/v1/obat/2/stok?jumlah=1
GET  200 /api/v1/obat/5/stok?jumlah=1
GET  200 /api/v1/obat/2/stok?apotek_id=3&jumlah=1
GET  200 /api/v1/obat/5/stok?apotek_id=3&jumlah=1
POST 201 /api/v1/resep/17/checkout
GET  200 /api/v1/obat/2/stok?jumlah=1
GET  200 /api/v1/obat/5/stok?jumlah=1
GET  200 /api/v1/obat/2/stok?apotek_id=3&jumlah=1
GET  200 /api/v1/obat/5/stok?apotek_id=3&jumlah=1
GET  200 /api/v1/pesanan-obat/11
GET  200 /api/v1/notifikasi?page=1&per_page=5
GET  200 /api/v1/me
GET  200 /api/v1/resep/17
GET  200 /api/v1/obat/2/stok?jumlah=1
GET  200 /api/v1/obat/5/stok?jumlah=1
GET  200 /api/v1/obat/2/stok?apotek_id=3&jumlah=1
GET  200 /api/v1/obat/5/stok?apotek_id=3&jumlah=1
POST 201 /api/v1/resep/17/checkout
GET  200 /api/v1/obat/2/stok?jumlah=1
GET  200 /api/v1/obat/5/stok?jumlah=1
GET  200 /api/v1/notifikasi?page=1&per_page=5
GET  200 /api/v1/me
GET  200 /api/v1/pesanan-obat/11
GET  200 /api/v1/referensi/metode-pembayaran
POST 201 /api/v1/invoice/11/bayar
POST 200 /api/v1/promo/validasi
POST 200 /api/v1/promo/validasi
GET  200 /api/v1/pesanan-obat/11
GET  200 /api/v1/notifikasi?page=1&per_page=5
GET  200 /api/v1/me
GET  200 /api/v1/notifikasi?page=1&per_page=10
GET  200 /api/v1/notifikasi?page=1&per_page=10&unread=true
PUT  200 /api/v1/notifikasi/5/baca
GET  200 /api/v1/notifikasi?page=1&per_page=5
GET  200 /api/v1/notifikasi?page=1&per_page=10
PUT  200 /api/v1/notifikasi/baca-semua
GET  200 /api/v1/notifikasi?page=1&per_page=5
GET  200 /api/v1/notifikasi?page=1&per_page=10
```

Read off this log, not asserted from the UI:

- The **two** `POST 201 /api/v1/resep/17/checkout` lines are the checkout and the
  deliberate duplicate probe of section 3. The `unread=true` line proves the filter is sent
  as the **string** `"true"`, as `IndexNotifikasiRequest` requires - it is not Laravel's
  `boolean` rule, and `?unread=false` would mean read-only, not "everything".
- Exactly four shelf reads per checkout screen visit: two without `apotek_id` (the
  candidate list) and two with it (the truthful per-pharmacy state).
- The webhook POSTs are absent **by design**: they are sent from Node with a real HMAC, not
  from the page, because the endpoint is unauthenticated and must never be called by a
  browser. Their transcript is the `WEBHOOK_T48` block quoted in section 2.

### Screenshots (11, all real, committed as `.omo/evidence/t48-*.png`)

`web/playwright-report/` is gitignored, so the spec writes there and the evidence copies
live here, matching todo 41's convention.

| File | Bytes | What it shows |
| --- | --- | --- |
| `t48-01-pilih-apotek.png` | 86822 | the open pharmacy list, with only the stocked pharmacy offered |
| `t48-02-checkout-siap.png` | 88901 | honest per-line stock, `Rp 13.800` subtotal, submit enabled |
| `t48-03-tracking.png` | 64687 | the tracking timeline, status `Menunggu pembayaran` |
| `t48-04-checkout-duplikat-diterima.png` | 41285 | the duplicate checkout the server accepted |
| `t48-05-metode-pembayaran.png` | 105350 | the method list, all nine `tipe` groups |
| `t48-06-instruksi-pembayaran.png` | 142429 | the VA number and the gateway's own steps |
| `t48-07-promo-valid.png` | 153560 | **server-computed `Rp 1.000`**, total `Rp 12.800` |
| `t48-08-promo-kadaluwarsa.png` | 155078 | the 200-with-`valid:false` refusal |
| `t48-09-sudah-terbayar.png` | 163156 | the order after settlement, status `Diproses apotek` |
| `t48-10-notifikasi.png` | 62295 | the centre, badge `2`, 3 rows |
| `t48-11-notifikasi-dibaca.png` | 60805 | after mark-all-read, badge `0` |

The two load-bearing screenshots were read back with a vision tool: `t48-07` shows
`Diskon Rp 1.000` / `Total Rp 12.800`, and `t48-02` shows
`Apotek Utama (e2e-48): stok 40 untuk 1 diminta` on both lines - visibly decremented from
the seeded 50 by the earlier runs' checkouts, which is the stock guard working.

---

## 8. Verification actually run

| Command | Result |
| --- | --- |
| `cd web && npm run types:check` | **exit 0** |
| `cd web && npm run build` | **exit 0**, `built in 1.05s` |
| `cd web && npm run test:unit` | **37 pass, 0 fail, 0 skipped** |
| `npx playwright test tests/e2e/pesanan-obat.spec.ts` | **1 passed (36.4s)** |

Byte-level scan over **all 21 touched files**, read as RAW BYTES: every one is **pure
ASCII, no BOM, no CR**. (`PowerShell 5.1` has no `-Encoding utf8NoBOM`, so every write used
`[System.IO.File]::WriteAllText` with an explicit `UTF8Encoding($false)`.)

`git status --porcelain` over `app routes database docs packages telemedicine_test.sql
phpunit.xml .omo/plans mobile` is **EMPTY**. `telemedicine_test.sql` SHA-256 is
`AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`, matching the recorded
value. `mobile/` does not exist; no `pubspec.yaml` was added or edited. No migration, no
index, no constraint, no route. `git add -A` was never used - every commit staged explicit
`web/` paths, and `.playwright-mcp/` is untracked and uncommitted.

---

## 9. Dev-database writes, stated plainly

The dev fixture set has **no** `faskes.tipe = 'apotek'` row, **zero** `apotek_stok` rows and
**zero** `master_promo` rows - no seeder creates any of them - so the stock guard and the
checkout path were **unreachable** before this run. A throwaway provisioning script, living
OUTSIDE the repository in the temp directory and never committed, created:

- 3 `faskes` rows with `tipe = 'apotek'` (ids 3, 4, 5);
- 4 `apotek_stok` rows, covering the three states the UI must tell apart: plenty (50),
  recorded-at-zero, and **no row at all**;
- 2 `master_promo` rows, `HEMAT10` (valid, capped) and `KADALUARSA` (window closed),
  because there is no promo seeder at all;
- 1 `users` row (id 19) + 1 `pasien` row (id 14) + the `pasien` role grant, with a known
  password - `DevFixtureSeeder` hashes `bin2hex(random_bytes(32))`, so no seeded account can
  be signed into, and `POST /auth/register` hard-codes `tipe = 'pasien'`, so a role cannot be
  provisioned over HTTP at all;
- 3 `notifikasi` rows, re-armed each run, because `NotificationService` has **no** producer
  wired, so checkout and settlement create zero notifications;
- a fresh `diverifikasi` prescription per run, with two stockable lines totalling 13800.

These are **additive fixture rows in the dev database `telemedisin_db` only**.
`telemedisin_db_test` was never written to, no migration was run, and no schema was
altered. This follows the precedent todo 41 recorded in the ledger, and it is disclosed
here rather than left implicit. **The dev database is now seeded with these fixtures**;
`web/playwright-report/*.png` and the Playwright spec are committed.

The walkthrough is **not** idempotent by design: the duplicate-invoice finding of section 3
means a settled prescription cannot be checked out again, so each run needs its own. The
provisioning script mints a fresh one.

---

## 10. Unfinished

- **The duplicate-checkout defect (section 3) is unfixed.** It needs a server-side change -
  a unique index on `pesanan_obat.resep_id`, or a guard keyed on the prescription. Both are
  outside this executor's authority, and no client-side de-duplication was added to hide it.
- **`GET /invoice/{id}` and an order-list route are still missing** (section 4), so the
  payment screen asks for an invoice id and the order screens are addressed by id.
- **`GET /pesanan-obat/{id}` is polled every 15 s** while an order is not terminal, and
  **`GET /notifikasi` every 30 s** for the badge. Neither route carries `throttle:`, so
  nothing is being pushed against, and `refetchIntervalInBackground` is off so a hidden tab
  stops asking. The cadences are a product choice, not a measured limit.
- **No PDP consent screen was built.** Todo 48 does not ask for one, and the consent gates
  referral letters, not notifications. Recorded so the absence is a decision, not an
  oversight.
- **The sidebar links `/checkout/1`, `/pesanan/1` and `/pembayaran/1` are placeholders**,
  matching the `/konsultasi/1` and `/rekam-medis/1` entries todos 28 and 35 left in place.
  The real link to a specific order is the one checkout supplies.
- **The e2e spec reads two values out of band** - the invoice id and the webhook HMAC secret
  - because the API publishes neither. That is a real gap, not a shortcut; the reads are
  commented as such in the spec.
- **The realtime layer was not exercised.** `BROADCAST_CONNECTION` is `reverb` and Reverb was
  not started; this feature has no realtime surface, and Module 3's specs cover it.

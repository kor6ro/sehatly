# OTP and push delivery — prototype integrations (F-005)

**This document describes a PROTOTYPE. Nothing here is production guidance, and
nothing in this repository should be read as claiming that these channels are
ready for real patient data.**

Two external delivery channels were added behind interfaces the project already
had. Both default to a `log` driver, which is the only driver permitted outside
`local`/`testing`; a production boot with it is refused (see *Boot guard* below).

| Channel | Interface | Drivers | Production-ready? |
| --- | --- | --- | --- |
| OTP (login / registration codes) | `App\Services\Auth\OtpSender` | `log`, `fonnte` | **No** — Fonnte is unofficial |
| Push notifications | `App\Services\Notifikasi\PushDispatcher` | `log`, `fcm` | Partly — FCM v1 is a real API, but this integration is untested against a live project |

---

## Fonnte is an UNOFFICIAL channel, and only for the prototype

Fonnte (`https://api.fonnte.com/send`) drives **WhatsApp Web** on a number the
operator controls. It is not WhatsApp Business, it is not the WhatsApp Cloud API,
and it is not a Business Solution Provider. Consequences that matter:

- **No SLA, no delivery guarantee, no delivery receipt contract.**
- **The number can be blocked by WhatsApp**, at any time, with no appeal — which
  for a health platform means every login stops working at once.
- WhatsApp's terms do not sanction this kind of automated sending from a personal
  or shared number.

Use a **spare number, never a personal one**, and treat the whole channel as
disposable.

**Before real patient data, the OTP channel must move to** the WhatsApp Cloud API
(a Meta-approved sender), an official BSP, or a contracted SMS gateway. That is a
**container-binding change** — implement `OtpSender`, point `OTP_DRIVER` at it —
and not a rewrite of the call site, because `OtpService` never learns which channel
is in use.

### OTP behaviour on failure

`OtpSender`'s own contract requires it: an implementation **must not throw** for a
delivery failure the caller can recover from. So a timeout, a 4xx or a 5xx from
Fonnte produces **one warning log line and a return**:

- `otp.fonnte.gagal` — with the purpose, the **masked** destination and the failure
  shape (status or exception class).
- `otp.fonnte.tanpa_token` — a missing token (reachable only in `local`/`testing`;
  the guard refuses it elsewhere).

**The OTP code is never written to the log**, and neither is Fonnte's response
body, which can echo the submitted message. `FonnteOtpSenderTest` asserts the
absence by searching the whole log context for the code.

An OTP that was minted but not delivered stays a live `user_otp` row; the account
holder can request a fresh code, which supersedes it.

---

## Setup — OTP via Fonnte

1. Create a Fonnte account and connect a **spare** WhatsApp number (not a personal
   number).
2. Copy the device token into the environment:
   ```
   OTP_DRIVER=fonnte
   FONNTE_TOKEN=<token>
   FONNTE_URL=https://api.fonnte.com/send
   FONNTE_TIMEOUT=10
   ```
3. The token is a secret. It is read only from the environment, is never
   hardcoded, and `config/otp.php` ships it empty.

The sender posts `target` and `message` as a form body with the token **raw** in
`Authorization` (not `Bearer <token>`, which is what `Http::withToken()` sends).

---

## Setup — push via Firebase Cloud Messaging (HTTP v1)

FCM HTTP v1 authenticates with a short-lived OAuth2 access token minted from a
Google **service-account** key. This repository implements that exchange directly
(a signed RS256 JWT, exchanged at Google's token endpoint) rather than adding a
vendor SDK — see `App\Support\Push\KredensialFirebase`. The seam is that class, so
swapping in an SDK later is a binding.

1. Create a Firebase project and enable **Cloud Messaging**.
2. Generate a **service-account JSON** (Project settings → Service accounts).
3. Save it **outside this repository**, for example
   `C:\secrets\sehatly-firebase.json`. The path is an environment value; the file
   is never committed, and `.gitignore` refuses the names Google's console
   downloads under (`*firebase-adminsdk*.json`, `*service-account*.json`,
   `firebase-credentials.json`).
4. Set:
   ```
   PUSH_DRIVER=fcm
   FIREBASE_CREDENTIALS=C:\secrets\sehatly-firebase.json
   FIREBASE_PROJECT_ID=<project id, or leave empty to read it from the JSON>
   ```
5. The mobile app must register its FCM token through
   `POST /api/v1/auth/devices` (`fcm_token` on `user_devices`).

### Push behaviour

`FcmPushDispatcher` **never throws**: `NotificationService::dorong()` calls it
from inside a booking, a payment settlement or a prescription verification, so a
push that threw would turn a delivered write into a 500. A missing credential, a
refused token exchange, a network fault or a provider 5xx is logged
(`notifikasi.push.fcm_gagal`) and returns.

A device token that FCM reports as dead — `404 NOT_FOUND` or `400
INVALID_ARGUMENT` with `UNREGISTERED` — is **retired**, not retried: the
`user_devices` row is flipped to `aktif = 0`, the same flag a logout sets. The line
is `notifikasi.push.fcm_token_mati`.

The log line carries the notification id and the status, **never the notification
body** (`notifikasi.isi` is clinical-adjacent text and a real transport must not
copy it into a second place).

---

## Boot guard

`App\Support\Security\PenjagaPengirimanProduksi` runs in
`AppServiceProvider::boot()` and **refuses to boot** when `APP_ENV` is anything
other than `local`/`testing` and:

- `OTP_DRIVER=log` — no account holder can receive a code; or
- `PUSH_DRIVER=log` — no device receives a push; or
- `OTP_DRIVER=fonnte` with an empty `FONNTE_TOKEN`; or
- `PUSH_DRIVER=fcm` with an empty `FIREBASE_CREDENTIALS`.

The pairing with the senders' "log, do not throw" rule is deliberate: boot is the
only place a misconfiguration can be loud, so it is where it is enforced.

---

## Tests

No test reaches the network. Both channels are exercised with `Http::fake()`:

- `tests/Unit/Auth/FonnteOtpSenderTest.php` — request shape, and the three failure
  shapes (5xx, transport fault, missing token) with the code absent from every log.
- `tests/Feature/Notifikasi/FcmPushDispatcherTest.php` — the FCM v1 request shape,
  the retirement of an `UNREGISTERED` token, and the non-throwing path when the
  credential is unusable. The JWT is signed with a **throwaway test RSA key**
  embedded in the test file; it has no privileges and is not a credential.
- `tests/Unit/Security/PenjagaPengirimanProduksiTest.php` — the boot guard.

---

## What this deliberately does NOT do

- **No delivery-state persistence.** `notifikasi` has no `dikirim_at`,
  `status_kirim`, `channel` or attempt counter, and that is a schema decision, not
  an omission. Delivery state lives in the log, exactly as it did before F-005.
- **No queue or retry.** Both sends are synchronous in the request path. Moving
  them behind a queued job is the next step for any real load, and the
  `PushDispatcher`/`OtpSender` seams are where it attaches.
- **No access-token refresh beyond the cached OAuth token.** The FCM access token
  is cached for 55 minutes; nothing rotates the service-account key.
- **No live verification.** There is no Fonnte account and no Firebase project
  behind this repository, so the integration is proven against fakes, not against
  the providers.

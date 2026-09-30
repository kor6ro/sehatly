<?php

namespace App\Providers;

use App\Services\Audit\AuditObserverRegistrar;
use App\Services\Audit\AuditScope;
use App\Services\Auth\LogOtpSender;
use App\Services\Auth\OtpSender;
use App\Services\Auth\OtpService;
use App\Services\Notifikasi\LogPushDispatcher;
use App\Services\Notifikasi\PushDispatcher;
use App\Services\Payment\MockPaymentGatewayService;
use App\Services\Payment\PaymentGatewayService;
use App\Services\SuratKeterangan\QrTokenGenerator;
use App\Services\SuratKeterangan\StrQrTokenGenerator;
use App\Support\ApiResponse;
use App\Support\Security\PenjagaRahasiaWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use LogicException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AppServiceProvider extends ServiceProvider
{
    /** Failed logins allowed against one account in a minute. */
    private const LOGIN_PER_MENIT = 5;

    /**
     * Failed logins allowed from one client address in a minute.
     *
     * Sixty is a deployment decision and the reasoning is in
     * {@see configureRateLimiting()}: Indonesian mobile traffic is behind
     * carrier-grade NAT and a hospital is behind its own, so this number has to
     * survive thousands of unrelated subscribers sharing one address.
     */
    private const LOGIN_IP_PER_MENIT = 60;

    /** `POST /auth/register` attempts allowed per identifier and address. */
    private const OTP_SEND_PER_MENIT = 10;

    /** OTP codes allowed to one phone number in a minute. */
    private const OTP_KIRIM_PER_MENIT = 3;

    /** OTP codes allowed to one phone number in an hour. */
    private const OTP_KIRIM_PER_JAM = 10;

    /** Guesses allowed against one issued code before it is burned. */
    private const OTP_VERIFY_PER_KODE = 5;

    /** Registrations allowed from one client address in an hour. */
    private const REGISTER_PER_JAM = 3;

    /** Token rotations allowed per presented refresh token in a minute. */
    private const REFRESH_PER_MENIT = 30;

    /** Bookings allowed per authenticated user in a minute. */
    private const BOOKING_PER_MENIT = 10;

    /** Checkouts allowed per authenticated user in a minute. */
    private const CHECKOUT_PER_MENIT = 5;

    /** Promo codes a user may test in a minute. */
    private const PROMO_VALIDASI_PER_MENIT = 20;

    /** Chat messages allowed in one consultation in a minute. */
    private const CHAT_PER_MENIT = 60;

    /** Webhook calls allowed per gateway and address in a minute. */
    private const WEBHOOK_PER_MENIT = 60;

    /**
     * The `user_otp.tujuan` values whose code may be irreversibly burned.
     *
     * Only `login` qualifies, and the reason is a denial of service rather than a
     * guess. A login code is re-mintable by signing in again, so burning one costs
     * the patient a code and nothing else. A registration code has no re-request
     * path in this API -- `POST /auth/register` is closed to a number that already
     * exists -- so burning one would let six anonymous requests deny a real patient
     * access to their own account, permanently, for the cost of nothing. The limiter
     * still refuses the sixth attempt; only the irreversible write is withheld, and
     * the `300`-second window already bounds that code to five guesses in total.
     *
     * @var list<string>
     */
    private const TUJUAN_YANG_DAPAT_DIBURN = [
        OtpService::TUJUAN_LOGIN,
    ];

    /**
     * The headers sent on every response, whatever its status.
     *
     * `X-Content-Type-Options: nosniff` stops a browser from reinterpreting a JSON
     * error body as HTML, which is the whole of a reflected-XSS chain when a
     * response is ever rendered. `X-Frame-Options: DENY` says the API is not a
     * framing target. `Referrer-Policy: no-referrer` keeps a bearer token out of a
     * `Referer` header if a client is ever tricked into loading an API URL from a
     * page it does not control. The two cross-origin headers close the two windows
     * a JSON surface inherits from the browser's default policy.
     *
     * @var array<string, string>
     */
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];

    /**
     * The policy for the JSON surface only.
     *
     * A browser ignores `Content-Security-Policy` on a response it never renders as
     * a document, so on `/api/*` this is defence in depth rather than an active
     * control: it is what stops a JSON body becoming a document if one is ever
     * served from an `api/` path, or opened directly. It is deliberately NOT applied
     * to the SPA shell, which IS a document, and where `default-src 'none'` would
     * break the application this repository serves.
     */
    private const API_CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    /**
     * HSTS, sent only over TLS.
     *
     * A browser ignores `Strict-Transport-Security` on a plain-HTTP response, so
     * sending it unconditionally would not weaken anything -- but it would make the
     * local `php artisan serve` run and any TLS-terminating reverse proxy in front
     * of it look as though a policy were in force when none was. Two years, with
     * subdomains, and no `preload`: adding a domain to the preload list is
     * irreversible and is not this application's decision to make.
     */
    private const HSTS = 'max-age=63072000; includeSubDomains';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->configureQrTokenSource();
        $this->configurePaymentGateway();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->pastikanRahasiaWebhook();
        $this->configureDefaults();
        $this->configureOtpDelivery();
        $this->configureRateLimiting();
        $this->configureSecurityHeaders();
        $this->configureAuditObservers();
        $this->configureNotificationPush();
    }

    /**
     * Refuse to boot outside `local`/`testing` while a payment webhook secret is the
     * shipped default.
     *
     * The guard is a separate class so the refusal is directly testable and so this
     * provider keeps the one job it has here - calling it first, before anything else
     * in `boot()`, which is what makes the failure land on the first console command
     * rather than on the first forged delivery. The full argument is in
     * {@see PenjagaRahasiaWebhook}.
     */
    private function pastikanRahasiaWebhook(): void
    {
        PenjagaRahasiaWebhook::pastikan($this->app);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Bind the QR verification-token source.
     *
     * `SuratKeteranganService` depends on the {@see QrTokenGenerator} INTERFACE so a
     * test can substitute a stub that returns a value already stored - the only way to
     * force a genuine `qr_token` collision, since the column has no UNIQUE index
     * (`telemedicine_test.sql:592`) and the DDL is read-only law. The production
     * implementation is {@see StrQrTokenGenerator}, a v4 UUID. Binding the interface
     * here rather than injecting the concrete class is what makes the substitution a
     * one-line change, exactly as `configureOtpDelivery()` does for `OtpSender`.
     */
    private function configureQrTokenSource(): void
    {
        $this->app->bind(QrTokenGenerator::class, StrQrTokenGenerator::class);
    }

    /**
     * Bind the OTP delivery channel.
     *
     * The only shipped implementation is {@see LogOtpSender}, because the plan forbids
     * adding a real SMS provider. Binding the interface rather than injecting
     * `LogOtpSender` directly into `OtpService` is what makes the substitution a
     * one-line change: a deployment that adds a gateway replaces this binding and
     * nothing else. A test that needs to read the generated code replaces the same
     * binding, which is why `AuthFlowTest` never has to parse a log file.
     */
    private function configureOtpDelivery(): void
    {
        $this->app->bind(OtpSender::class, LogOtpSender::class);
    }

    /**
     * Bind the payment gateway, selected by `config('payment.gateway')`.
     *
     * ## The one place a real Midtrans or Xendit would be named
     *
     * The plan puts real providers out of scope and the schema has no gateway
     * table, no API-key column and no callback table, so the only shipped
     * implementation is {@see MockPaymentGatewayService}. Everything else in
     * the application depends on {@see PaymentGatewayService} - the controller
     * and the service both take the INTERFACE - so adding a real gateway is:
     * add the class, add one entry to `config/payment.php`'s `implementasi`
     * map, and change the `PAYMENT_GATEWAY` environment value. No call site
     * changes, and no test that drives the endpoint has to change either,
     * because the signature scheme is part of the contract each adapter
     * implements rather than something the controller knows about.
     *
     * ## Why the binding is a `bind()` and not a `singleton()`
     *
     * `bind()` hands out a fresh instance per resolution, which is what
     * `configureOtpDelivery()` and `configureQrTokenSource()` already do in
     * this file. None of the three implementations holds mutable state, so a
     * singleton would buy nothing and would introduce a shared object whose
     * lifetime spans requests for no reason.
     *
     * ## An unknown `PAYMENT_GATEWAY` FAILS LOUDLY, at boot
     *
     * The lookup falls back to the one registered implementation and then
     * throws a `LogicException` naming the value and the registered set. That
     * is deliberate and it is the opposite of what a `??` chain usually does:
     * a typo in an environment variable that silently resolved to the mock
     * would leave a production deployment minting `MOCK-` references and
     * accepting webhooks signed with a development secret, with nothing in the
     * logs. A 500 on the first request is a far better failure than a quiet
     * one.
     *
     * @throws LogicException when `config('payment.gateway')` names no
     *                        registered implementation
     */
    private function configurePaymentGateway(): void
    {
        $this->app->bind(PaymentGatewayService::class, function (): PaymentGatewayService {
            $terpilih = (string) config('payment.gateway');

            $implementasi = (array) config('payment.implementasi', []);

            $kelas = $implementasi[$terpilih] ?? null;

            if (! is_string($kelas) || ! class_exists($kelas)) {
                throw new LogicException(sprintf(
                    'config("payment.gateway") is [%s], which is not a registered implementation. Registered: [%s].',
                    $terpilih,
                    implode(', ', array_keys($implementasi))
                ));
            }

            return $this->app->make($kelas);
        });
    }

    /**
     * Configure rate limiting.
     *
     * ## This method is the single authority for every limit in the application
     *
     * `OpenApiDocumentBuilder::limitFor()` reads each named limiter's closure at
     * generation time and prints `maxAttempts` and `decaySeconds` into
     * `docs/openapi.yaml` as `x-ratelimit`. A second `RateLimiter::for()` anywhere
     * else is therefore a second place for a number to be wrong, which is why
     * `tests/Feature/Security/RateLimitingTest.php` asserts that no file in `app/`
     * other than this one registers a limiter.
     *
     * ## The inventory, and where each one is mounted
     *
     * F-002 mounted seven of the ten entries that had no route: a registered limiter
     * protects nobody until a route names it, and before F-002 ten of the thirteen
     * were unowned. The `mounted on` column is the route the
     * `->middleware('throttle:...')` line sits on, and
     * `tests/Feature/Security/RouteThrottlingTest.php` reads the mounted set back
     * out of the route table so the table and `routes/api.php` cannot drift.
     *
     * Three remain deliberately unmounted, and the reason is a CEILING rather than a
     * missing line:
     *
     * - `otp-kirim` (3/min) and `otp-kirim-jam` (10/hour) are keyed on the
     *   identifier and were written for `/auth/login` - the second endpoint that
     *   mints an OTP, after the password matched. Mounting them there would make
     *   login's EFFECTIVE ceiling 3/min instead of the plan's 5, and would lock a
     *   legitimate patient out of login after ten attempts in an hour. Moving a
     *   documented ceiling is a decision, not wiring, so it is recorded here and
     *   asserted UNMOUNTED by `RouteThrottlingTest`.
     * - `auth-register` (3/hour, client IP) would lower `/auth/register` from the
     *   documented 10/min (`auth-otp-send`) to 3/hour per address. The
     *   carrier-grade-NAT paragraph below is exactly why that number must not be
     *   adopted silently: one clinic's egress is not one abuser.
     *
     * | limiter | key | ceiling | window | mounted on |
     * | --- | --- | --- | --- | --- |
     * | `auth-login` | identifier | 5 | 60 s | `POST /auth/login` |
     * | `auth-login-ip` | client IP | 60 | 60 s | `POST /auth/login` |
     * | `auth-otp-send` | identifier + IP | 10 | 60 s | `POST /auth/register` |
     * | `otp-kirim` | identifier | 3 | 60 s | **not mounted - ceiling decision (F-002)** |
     * | `otp-kirim-jam` | identifier | 10 | 3600 s | **not mounted - ceiling decision (F-002)** |
     * | `auth-otp-verify` | `user_otp.id` | 5 | 300 s | `POST /auth/otp/verify` |
     * | `auth-register` | client IP | 3 | 3600 s | **not mounted - ceiling decision (F-002)** |
     * | `auth-refresh` | SHA-256 of the presented refresh token | 30 | 60 s | `POST /auth/refresh` |
     * | `booking` | user id | 10 | 60 s | `POST /booking` |
     * | `checkout` | user id | 5 | 60 s | `POST /resep/{id}/checkout` |
     * | `webhook-payment` | gateway + client IP | 60 | 60 s | `POST /webhook/payment/{gateway}` |
     * | `promo-validasi` | user id | 20 | 60 s | `POST /promo/validasi` |
     * | `chat` | consultation id | 60 | 60 s | `POST /konsultasi/{id}/chat` |
     *
     * ## `auth-login` keys on the IDENTIFIER, not on identifier + IP
     *
     * This is a change from the key todo 20 shipped, and it is deliberate.
     *
     * Folding the caller's address into the key gives every source address its own
     * five attempts against one account, so a distributed guessing run -- a botnet,
     * a mobile farm -- gets `5 x addresses` attempts on a single phone number
     * instead of five, and no per-account ceiling exists at all in practice. The
     * per-IP half of the old key bounded exactly one threat: a single host
     * spraying many accounts. That threat is real, and it is now bounded by a
     * **separate** limiter (`auth-login-ip`) whose number is chosen for the
     * deployment rather than for the attack, which is the whole point of splitting
     * the two.
     *
     * The cost of the change is a deliberate one: five failed attempts against a
     * known phone number now costs the legitimate owner a one-minute wait, and an
     * attacker can impose that wait on purpose from anywhere. Mitigating it needs a
     * per-account lockout with exponential backoff, which is a schema-shaped change
     * this round does not make. The alternative key (`identifier + IP`) was not
     * chosen: it is a weaker bound on the thing this file is here to bound.
     *
     * ## Why the per-IP ceiling is 60 and not 5
     *
     * Indonesian mobile deployments are behind carrier-grade NAT. A single public
     * IPv4 address is shared by an entire cell's worth of subscribers -- on a busy
     * site that is thousands of unrelated people -- and a hospital's own NAT puts
     * every doctor, every patient and every kiosk behind one address as well. A
     * per-IP login limit of 5 would therefore lock out *every patient behind a
     * shared hospital NAT* and *every subscriber in a CGNAT pool* on the first
     * morning peak, which is a self-inflicted outage, not a security control.
     *
     * 60/min is chosen as the highest number that is still worthless to an attacker:
     * it is one guess per second against a six-figure phone-number space, which is
     * slower than any online cracking rate that matters, and it is twelve times the
     * worst plausible honest burst (a shift change, where a whole clinic signs in
     * within a couple of minutes). The per-identifier half does the real work on
     * any single account; this half exists to stop one host from spraying, and
     * 60/min stops that without punishing a shared egress.
     *
     * ## The OTP limiter is keyed on the ISSUED CODE, and burns it
     *
     * `user_otp` has no attempt-counter column, so this limiter is the whole
     * brute-force bound on a six-digit code. Its key is the `user_otp.id` of the
     * live code rather than the caller's identifier, which gives the property the
     * plan asks for: **five attempts per issued code**, and no more, no matter which
     * address, device, token or session presents it. The window is
     * `OtpService::TTL_MENIT` rather than a minute, so the bucket outlives the code
     * it counts: a shorter window would let a patient whose code is still valid
     * start a second batch of five after it expired, and five-per-code would be a
     * claim rather than a fact.
     *
     * On the sixth attempt the code is BURNED -- `sudah_dipakai = 1`, a database
     * write, not a cache flag -- so clearing the client's state buys nothing. The
     * burn is withheld for one purpose only, {@see TUJUAN_YANG_DAPAT_DIBURN}, and
     * the reason is a denial of service rather than a guess: a registration code has
     * no re-request path in this API, so burning it would let six anonymous
     * requests lock a real patient out of their own account permanently. The
     * limiter still refuses; only the irreversible write is withheld.
     *
     * ## Every limiter answers with the project envelope and a real `Retry-After`
     *
     * A named limiter's closure may return a `Response` instead of a `Limit`
     * (`ThrottleRequests::handleRequestUsingNamedLimiter()` hands it straight to the
     * client), and that is the form every refusal here takes. Two reasons, and the
     * first was found by writing it the obvious way first and measuring:
     *
     * 1. **`Limit::response()` is a 500 in THIS application.** It makes
     *    `ThrottleRequests` throw an `HttpResponseException`; `bootstrap/app.php`
     *    registers its renderer against a bare `Throwable`, which matches it, and
     *    `HttpResponseException` is not an `HttpExceptionInterface`, so it falls
     *    through to the sanitised-500 branch. Measured against a running server:
     *    `HTTP 500 {"success":false,"message":"Internal server error.","errors":{}}`
     *    on a route that owed the client a 429.
     * 2. **The framework's own `ThrottleRequestsException` drops the headers.** It
     *    *does* carry `Retry-After`, and the same renderer does keep its 429 status
     *    -- it rebuilds the body with `ApiResponse::error()` and a fresh
     *    `JsonResponse`, which has no headers at all. So the `Retry-After` the
     *    framework computed is thrown away, and a 429 that does not say when to come
     *    back is the one failure a client cannot handle gracefully.
     *
     * Returning the response is the only shape that gives this application both the
     * envelope it publishes and the headers the client needs, without editing the
     * kernel that every other executor shares.
     *
     * ## Every name is `auth-*`, and the reason is now historical
     *
     * These were originally named to avoid colliding with the named `login` limiter
     * that the now-deleted `FortifyServiceProvider` registered, which read
     * `$request->session()` -- a call that does not exist on the stateless `api`
     * group, so registering the same name would have silently clobbered one or the
     * other depending on provider order. Todo 30 removed Fortify, so that collision
     * can no longer happen and `login` is free again. The names added by this todo
     * follow the same convention for the same reason: a limiter name is part of the
     * published contract (`x-ratelimit.limiter`), so one vocabulary, spelled once
     * here, is better than a mixture.
     */
    private function configureRateLimiting(): void
    {
        // ---------------------------------------------------------- anonymous --
        //
        // `POST /auth/login` returns NO token: it checks the password and sends an
        // OTP. The token pair is issued by `POST /auth/otp/verify`. The login
        // limiter therefore belongs here and nowhere else -- a test that expected a
        // token from this endpoint, or a limiter applied to `otp/verify` on the
        // theory that "login" happens there, would be wrong about both.

        RateLimiter::for('auth-login', fn (Request $request): Limit|SymfonyResponse => $this->guard('auth-login', $this->identifierKey('auth-login', $request),
            self::LOGIN_PER_MENIT,
            1,
        ));

        RateLimiter::for('auth-login-ip', fn (Request $request): Limit|SymfonyResponse => $this->guard('auth-login-ip', 'auth-login-ip|'.$request->ip(),
            self::LOGIN_IP_PER_MENIT,
            1,
        ));

        // `auth-otp-send` is on `POST /auth/register` only, and is the endpoint that
        // mints an OTP with no prior credential. `POST /auth/login` also mints one,
        // but only after the password matched, so its OTP volume is already bounded
        // by `auth-login`; `otp-kirim` below is the limiter to mount there when
        // `routes/api.php` is next edited.
        RateLimiter::for('auth-otp-send', fn (Request $request): Limit|SymfonyResponse => $this->guard('auth-otp-send', $this->throttleKey($request, 'auth-otp-send'),
            self::OTP_SEND_PER_MENIT,
            1,
        ));

        // The plan's `otp-kirim`, 3/min and 10/hour per phone number. Two limiters
        // rather than one closure returning an array, because
        // `OpenApiDocumentBuilder::limitFor()` reads `$limit->maxAttempts` off
        // whatever a named limiter returns: an array is a silent null in the
        // published contract, not an error.
        RateLimiter::for('otp-kirim', fn (Request $request): Limit|SymfonyResponse => $this->guard('otp-kirim', $this->identifierKey('otp-kirim', $request),
            self::OTP_KIRIM_PER_MENIT,
            1,
        ));

        RateLimiter::for('otp-kirim-jam', fn (Request $request): Limit|SymfonyResponse => $this->guard('otp-kirim-jam', $this->identifierKey('otp-kirim-jam', $request),
            self::OTP_KIRIM_PER_JAM,
            60,
        ));

        // The one limiter that writes, and therefore the one that cannot be a
        // one-liner. The refusal is built here rather than by `guard()` so the burn
        // can happen first: the code is invalidated and the client is told why in the
        // same breath, and there is no window in which a client has been told 429
        // while the code is still good.
        RateLimiter::for('auth-otp-verify', function (Request $request): Limit|SymfonyResponse {
            $tujuan = (string) $request->input('tujuan', '');

            $otpId = $this->liveOtpId($request);

            $key = 'auth-otp-verify|'.($otpId === null
                ? 'tanpa-kode|'.$this->throttleKey($request, 'auth-otp-verify')
                : 'otp-'.$otpId);

            $cacheKey = $this->throttleCacheKey('auth-otp-verify', $key);

            if (! RateLimiter::tooManyAttempts($cacheKey, self::OTP_VERIFY_PER_KODE)) {
                return Limit::perMinutes(OtpService::TTL_MENIT, self::OTP_VERIFY_PER_KODE)->by($key);
            }

            if ($otpId !== null && in_array($tujuan, self::TUJUAN_YANG_DAPAT_DIBURN, true)) {
                $this->burnOtp($otpId);
            }

            return $this->throttleResponse($cacheKey, self::OTP_VERIFY_PER_KODE);
        });

        // -------------------------------------------------------- authenticated --

        RateLimiter::for('auth-register', fn (Request $request): Limit|SymfonyResponse => $this->guard('auth-register', 'auth-register|'.$request->ip(),
            self::REGISTER_PER_JAM,
            60,
        ));

        // `POST /auth/refresh` is UNauthenticated -- the caller presents a refresh
        // token, not a bearer token, so `$request->user()` is null and a "per user"
        // key is not expressible. The presented token is the session, so it is what
        // the budget follows, hashed: the raw token never becomes part of a cache
        // key that a cache dump or a log line would expose.
        RateLimiter::for('auth-refresh', function (Request $request): Limit|SymfonyResponse {
            $token = (string) $request->input('refresh_token', '');

            return $this->guard(
                'auth-refresh',
                'auth-refresh|'.($token === ''
                    ? 'tanpa-token|'.$request->ip()
                    : hash('sha256', $token)),
                self::REFRESH_PER_MENIT,
                1,
            );
        });

        RateLimiter::for('booking', fn (Request $request): Limit|SymfonyResponse => $this->guard('booking', $this->userKey('booking', $request),
            self::BOOKING_PER_MENIT,
            1,
        ));

        RateLimiter::for('checkout', fn (Request $request): Limit|SymfonyResponse => $this->guard('checkout', $this->userKey('checkout', $request),
            self::CHECKOUT_PER_MENIT,
            1,
        ));

        // A pure calculation with no state of its own, which is exactly what makes
        // it an oracle: 21 calls a minute is enough to enumerate a promo table.
        RateLimiter::for('promo-validasi', fn (Request $request): Limit|SymfonyResponse => $this->guard('promo-validasi', $this->userKey('promo-validasi', $request),
            self::PROMO_VALIDASI_PER_MENIT,
            1,
        ));

        // Per consultation rather than per user, because the resource being
        // protected is the thread: one participant flooding a consultation must not
        // be able to spend the other participant's budget.
        RateLimiter::for('chat', function (Request $request): Limit|SymfonyResponse {
            $konsultasi = $request->route('id');

            return $this->guard(
                'chat',
                'chat|'.(is_scalar($konsultasi) && (string) $konsultasi !== ''
                    ? 'konsultasi-'.$konsultasi
                    : $this->userKey('chat', $request)),
                self::CHAT_PER_MENIT,
                1,
            );
        });

        // Unauthenticated, because a payment gateway holds no Sanctum token. The
        // gateway name is part of the key so one provider's noisy retry storm
        // cannot exhaust another's budget from the same address.
        RateLimiter::for('webhook-payment', function (Request $request): Limit|SymfonyResponse {
            $gateway = $request->route('gateway');

            return $this->guard(
                'webhook-payment',
                'webhook-payment|'.(is_scalar($gateway) ? (string) $gateway : 'unknown').'|'.$request->ip(),
                self::WEBHOOK_PER_MENIT,
                1,
            );
        });
    }

    /**
     * The whole limiter: the `Limit` when the request may proceed, the finished 429
     * when it may not.
     *
     * Returning the `Limit` leaves the counting and the `X-RateLimit-*` headers to
     * `ThrottleRequests`, which is what we want on the allowed path: the framework's
     * own bookkeeping stays the framework's own bookkeeping. Only the refusal is
     * built here, and only because the framework's refusal cannot carry this
     * project's envelope and its headers at the same time -- see the class docblock
     * for the measurement.
     *
     * `Limit::perMinutes()` takes the DECAY FIRST, which reads the wrong way round at
     * a call site and silently produces a one-attempt limit with a five-minute window
     * instead of an error. The swap happens in exactly this one place, and
     * `tests/Feature/Security/RateLimitingTest.php` reads every ceiling back out of
     * the running limiter to prove it happened.
     */
    private function guard(string $limiter, string $key, int $maxAttempts, int $decayMinutes): Limit|SymfonyResponse
    {
        $cacheKey = $this->throttleCacheKey($limiter, $key);

        if (RateLimiter::tooManyAttempts($cacheKey, $maxAttempts)) {
            return $this->throttleResponse($cacheKey, $maxAttempts);
        }

        return Limit::perMinutes($decayMinutes, $maxAttempts)->by($key);
    }

    /**
     * The cache key `ThrottleRequests` will count this limiter's attempts under.
     *
     * A limiter that wants to answer with its OWN response has to ask
     * `RateLimiter::tooManyAttempts()` about the same entry the middleware will
     * later ask about, and the middleware does not use the string given to
     * `Limit::by()` -- it derives its own:
     *
     * ```php
     * // Illuminate\Routing\Middleware\ThrottleRequests::handleRequestUsingNamedLimiter()
     * 'key' => self::$shouldHashKeys ? md5($limiterName.$limit->key) : $limiterName.':'.$limit->key,
     * ```
     *
     * `$shouldHashKeys` is a `protected static` and is `true` in this framework
     * version, so the derivation is reproduced here rather than read: reflecting
     * into the framework's privates to save one `md5()` would be a worse trade than
     * the line of arithmetic.
     *
     * **If a future framework release changes that derivation, this stops matching**
     * and the limiter degrades to the framework's own 429 -- still rate limited,
     * without `Retry-After`. It does not fail open and it does not crash. The test
     * `the sixth login attempt in a minute is refused with 429, the envelope and a
     * real Retry-After` is what turns that silent degradation into a red build, so
     * the coupling is asserted rather than assumed.
     */
    private function throttleCacheKey(string $limiter, string $key): string
    {
        return md5($limiter.$key);
    }

    /**
     * The 429 every limiter in this application answers with.
     *
     * The body is the project envelope, so a client parses one shape for every
     * failure. The three headers are the ones a client needs in order to behave, and
     * the ones the kernel's envelope-rebuilding renderer drops:
     *
     * - `Retry-After` is the seconds until the bucket frees, from
     *   `RateLimiter::availableIn()` -- the same call `ThrottleRequests` makes. It is
     *   in the CORS-safelisted response-header set, so a browser client can read it
     *   with nothing exposed.
     * - `X-RateLimit-Limit` and `X-RateLimit-Remaining` mirror what the framework
     *   puts on an ALLOWED response, so a client sees the same pair on both sides of
     *   the boundary. They are NOT safelisted, so `config/cors.php` should expose
     *   them; that is a finding rather than a change here.
     */
    private function throttleResponse(string $key, int $maxAttempts): SymfonyResponse
    {
        $response = ApiResponse::error(
            'Terlalu banyak permintaan. Silakan coba lagi nanti.',
            [],
            SymfonyResponse::HTTP_TOO_MANY_REQUESTS,
        );

        $response->headers->set('Retry-After', (string) RateLimiter::availableIn($key));
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($key, $maxAttempts));

        return $response;
    }

    /**
     * The `user_otp.id` of the code this request is trying to spend, or null.
     *
     * Null for anything that is not a shaped `POST /auth/otp/verify` body -- a
     * synthetic request from the OpenAPI builder, a call that failed validation, an
     * identifier that matches no account. The caller falls back to an
     * identifier-plus-IP bucket in that case, which is the correct bound: there is
     * no code to count attempts against.
     *
     * The row is resolved rather than derived from the request, because the only
     * thing a client can be trusted not to change is which code it *believes* it is
     * spending. Keying the bucket on a hash of the presented `kode` would hand an
     * attacker five fresh attempts per guess and bound nothing at all.
     *
     * Deliberately NOT filtered on `sudah_dipakai`: the key must not move when the
     * code is consumed, or a code the attacker already holds would get a new budget
     * the moment it stopped being useful. `dihapus_at` is honoured because
     * `AuthController::resolveUser()` honours it through `SoftDeletes`, and the two
     * must agree about which accounts exist.
     */
    private function liveOtpId(Request $request): ?int
    {
        $column = match (true) {
            $request->filled('no_telepon') => 'no_telepon',
            $request->filled('email') => 'email',
            default => null,
        };

        if ($column === null || ! $request->isMethod('POST')) {
            return null;
        }

        $tujuan = (string) $request->input('tujuan', '');

        if ($tujuan === '' || ! in_array($tujuan, OtpService::TUJUAN, true)) {
            return null;
        }

        $id = DB::table('user_otp')
            ->join('users', 'users.id', '=', 'user_otp.user_id')
            ->where('users.'.$column, $request->input($column))
            ->whereNull('users.dihapus_at')
            ->where('user_otp.tujuan', $tujuan)
            ->orderByDesc('user_otp.id')
            ->value('user_otp.id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Flip a code to used, irreversibly.
     *
     * Through the query builder rather than the model for the reason
     * `OtpService::issue()` gives: `UserOtp::UPDATED_AT` is null because
     * `user_otp` declares no `diubah_at`, and a bulk model update would have to be
     * proven not to add one. The builder cannot.
     */
    private function burnOtp(int $otpId): void
    {
        DB::table('user_otp')
            ->where('id', $otpId)
            ->where('sudah_dipakai', false)
            ->update(['sudah_dipakai' => true]);
    }

    /**
     * The rate-limiter key for a request that names an account.
     *
     * The identifier alone, with the client IP appended ONLY when there is no
     * identifier -- a request that failed validation. That fallback is not a hole:
     * such a request was refused by the `FormRequest` before any OTP was minted, so
     * there is nothing to spray, and the fallback stops every anonymous caller from
     * sharing one global bucket a single client could exhaust for everyone.
     */
    private function identifierKey(string $limiter, Request $request): string
    {
        $identifier = $this->identifier($request);

        return $limiter.'|'.($identifier === '' ? 'anon|'.$request->ip() : $identifier);
    }

    /**
     * The rate-limiter key for a request behind `auth:sanctum`.
     *
     * Falls back to the client IP when there is no authenticated user, which is
     * either a route that forgot the guard or a request the guard refused before the
     * limiter ran. Neither should be silent, so the fallback shares one bucket per
     * address rather than one bucket for the whole internet.
     */
    private function userKey(string $limiter, Request $request): string
    {
        $user = $request->user();

        return $limiter.'|'.($user === null ? 'anon|'.$request->ip() : 'user-'.$user->getAuthIdentifier());
    }

    /**
     * The account identifier a request names, normalised.
     *
     * `no_telepon` wins over `email` because it is the DDL's `NOT NULL UNIQUE`
     * column (`:137`) and therefore the one that identifies an account for sure.
     * `Str::transliterate()` is applied so an address that arrives in a different
     * Unicode normalisation form lands in the same bucket as its ASCII twin --
     * otherwise a case-and-normalisation variant is a free extra set of attempts.
     */
    private function identifier(Request $request): string
    {
        $identifier = $request->input('no_telepon') ?? $request->input('email') ?? '';

        return Str::transliterate(Str::lower((string) $identifier));
    }

    /**
     * Attach the security headers to every response the HTTP kernel produces.
     *
     * ## An event listener, and not a middleware, for three checkable reasons
     *
     * 1. **The kernel is untouched.** `bootstrap/app.php` is the file every
     *    executor in this project shares, and a global `appendMiddleware()` there
     *    would put this todo's blast radius on top of every other todo's.
     * 2. **The body is untouched.** This listener adds headers to a `Response` and
     *    never reads or rewrites `getContent()`. `ApiResponse` remains the only
     *    thing in the application that builds an envelope.
     * 3. **It sees the failures too.** `Illuminate\Foundation\Http\Kernel::handle()`
     *    dispatches `RequestHandled` after the response has been built *or* rendered
     *    from an exception, so a 401 from the guard, a 422 from a `FormRequest`, a
     *    404 and a 429 all arrive here -- and those are the responses a client is
     *    handling untrusted input on.
     *
     * ## Why this cannot break the Sanctum bearer flow
     *
     * It cannot, structurally: the listener runs after authentication has already
     * produced the response, and it mutates only the header bag. The empirical proof
     * is in `tests/Feature/Security/SecurityHeadersTest.php`, which drives a real
     * bearer token through `GET /api/v1/me` and asserts both the 200 and its
     * byte-exact body, because "the header did not break auth" is a measurement and
     * not a consequence one can reason about.
     */
    private function configureSecurityHeaders(): void
    {
        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            $this->applySecurityHeaders($event->request, $event->response);
        });
    }

    /**
     * Write the header set onto one response.
     *
     * `@param  SymfonyResponse  $response`  any `Response`; `BinaryFileResponse` for
     *                                      the SPA shell and its assets, which is why
     *                                      the type is the Symfony base class and not
     *                                      `JsonResponse`.
     */
    private function applySecurityHeaders(Request $request, SymfonyResponse $response): void
    {
        foreach (self::SECURITY_HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', self::HSTS);
        }

        if (! $request->is('api/*')) {
            return;
        }

        $response->headers->set('Content-Security-Policy', self::API_CSP);

        // `POST /api/v1/auth/otp/verify` answers with a token pair and
        // `POST /api/v1/auth/login` with a code. A shared cache that stored either
        // would hand one patient's credentials to the next caller through the
        // infrastructure rather than through the code, so the API surface is
        // explicitly uncacheable. `Pragma` is here for HTTP/1.0 intermediaries,
        // which predate `Cache-Control` and honour only this one.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
    }

    /**
     * Register the global audit observer over every sensitive model.
     *
     * Central registration is the policy, and the SCOPE is derived rather than
     * typed: {@see AuditObserverRegistrar} walks the foreign-key closure
     * outward from `pasien` and `users` in the reference SQL, so a new
     * sensitive model is audited the day it is written, with no line to add
     * here and no `#[ObservedBy]` attribute to remember on the model.
     *
     * The listener is registered on the class-scoped event name the framework
     * itself uses, which is why this does NOT go through `Model::observe()`:
     * that method is `(new static)->registerObserver(...)` and would boot every
     * audited model from inside the provider that is booting.
     *
     * The registration test reads Eloquent's own listener table and asserts
     * every class in the closure carries the observer, and that the wildcard
     * listener set is empty - which is what stops the registration and the
     * closure from drifting apart in either direction.
     *
     * @see AuditObserverRegistrar
     * @see AuditScope
     */
    private function configureAuditObservers(): void
    {
        AuditObserverRegistrar::registerAll();
    }

    /**
     * The push transport for `NotificationService`.
     *
     * Bound here rather than injected by concrete type because
     * `PushDispatcher` is a contract with one implementation today and a real
     * FCM client tomorrow, and the choice of transport is a deployment decision
     * like the OTP sender's - which is why {@see configureOtpDelivery()} exists
     * in the same provider for the same reason.
     *
     * The log is the delivery record because `notifikasi` cannot hold one: no
     * `dikirim_at`, no `status_kirim`, no `channel`.
     *
     * @see LogPushDispatcher for the limitation
     * @see PushDispatcher for the contract
     */
    private function configureNotificationPush(): void
    {
        $this->app->bind(PushDispatcher::class, LogPushDispatcher::class);
    }

    /**
     * The rate-limiter key for one auth request: the account it names, plus the caller.
     *
     * Falls back to the bare client IP when neither identifier is present, which is the
     * shape of a request that failed validation. That is correct rather than a hole: a
     * request that named no account was refused by the FormRequest before any OTP was
     * minted, so there is nothing to spray, and the fallback stops every anonymous
     * caller from sharing one global bucket a single client could exhaust for everyone.
     */
    private function throttleKey(Request $request, string $limiter): string
    {
        $identifier = $request->input('no_telepon') ?? $request->input('email') ?? '';

        $identifier = Str::transliterate(Str::lower((string) $identifier));

        return $limiter.'|'.$identifier.'|'.$request->ip();
    }
}

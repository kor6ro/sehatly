<?php

namespace App\Providers;

use App\Observers\AuditObserver;
use App\Services\Audit\AuditedModels;
use App\Services\Auth\LogOtpSender;
use App\Services\Auth\OtpSender;
use App\Services\SuratKeterangan\QrTokenGenerator;
use App\Services\SuratKeterangan\StrQrTokenGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->configureQrTokenSource();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureOtpDelivery();
        $this->configureRateLimiting();
        $this->configureAuditObservers();
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
     * Configure rate limiting.
     *
     * ## The three limits are the plan's, and the keys are per-identifier
     *
     * `user_otp` has no attempt-counter column, so `auth-otp-verify` at 5/min **is** the
     * brute-force bound on a six-digit code; there is no second mechanism. The key is
     * the caller's own `no_telepon` or `email` (lowercased, so case variants of an
     * address share one budget) together with the client IP, which gives the usual
     * property: an attacker is limited per account *and* per source, so spraying one
     * account from many hosts is bounded by the per-IP half and spraying many accounts
     * from one host shares a per-IP budget rather than a per-account one.
     *
     * `auth-otp-send` is on `POST /auth/register` only. That is the sole endpoint that
     * mints an OTP without any prior credential -- `POST /auth/login` checks the
     * password first -- so a limiter on "OTP send" is a limiter on registration, and an
     * unauthenticated caller cannot use it to have codes sent to somebody else's phone.
     *
     * ## Every name is `auth-*`, and the reason is now historical
     *
     * These were originally named to avoid colliding with the named `login` limiter
     * that the now-deleted `FortifyServiceProvider` registered, which read
     * `$request->session()` - a call that does not exist on the stateless `api` group,
     * so registering the same name would have silently clobbered one or the other
     * depending on provider order. Todo 30 removed Fortify, so that collision can no
     * longer happen and `login` is free again. The `auth-*` names are kept rather than
     * renamed: `routes/api.php` already spells them, and renaming them would buy
     * nothing but a second chance to mistype a limiter name.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth-login', function (Request $request): Limit {
            return Limit::perMinute(5)->by($this->throttleKey($request, 'auth-login'));
        });

        RateLimiter::for('auth-otp-verify', function (Request $request): Limit {
            return Limit::perMinute(5)->by($this->throttleKey($request, 'auth-otp-verify'));
        });

        RateLimiter::for('auth-otp-send', function (Request $request): Limit {
            return Limit::perMinute(10)->by($this->throttleKey($request, 'auth-otp-send'));
        });
    }

    /**
     * Register the global audit observer over every sensitive model.
     *
     * Central registration is the policy: the loop covers exactly
     * {@see AuditedModels::classes()}, so a new sensitive model is audited
     * by adding one line to the registry rather than by remembering an
     * attribute on the model. The registration test asserts the dispatcher
     * holds created/updated/deleted listeners for each entry, which is what
     * stops the registry and the registrations drifting apart.
     */
    private function configureAuditObservers(): void
    {
        foreach (AuditedModels::classes() as $model) {
            $model::observe(AuditObserver::class);
        }
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

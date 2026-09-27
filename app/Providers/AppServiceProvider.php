<?php

namespace App\Providers;

use App\Services\Auth\LogOtpSender;
use App\Services\Auth\OtpSender;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureOtpDelivery();
        $this->configureRateLimiting();
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
     * ## `login` is deliberately not the name
     *
     * `FortifyServiceProvider` already registers a named `login` limiter that reads
     * `$request->session()`, which does not exist on the stateless `api` group.
     * Registering the same name here would silently clobber one or the other depending
     * on provider order. Every name below is `auth-*` for that reason.
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

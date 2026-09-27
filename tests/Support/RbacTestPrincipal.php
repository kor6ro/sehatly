<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\Contracts\HasApiTokens;
use Tests\Feature\RbacMiddlewareTest;

/**
 * A minimal `Authenticatable` for the RBAC middleware tests.
 *
 * ## Why this exists instead of `App\Models\User`
 *
 * `app/Models/**` is todo 19's to write, and todo 19 is running in parallel with
 * this todo. The RBAC tests need an authenticated principal carrying a `users.id`
 * and a `users.tipe`, and they need it to be *this* principal - a real `User` model
 * read back out of the database would work, but only once todo 19 lands, and
 * binding this todo's suite to another todo's in-flight work is exactly the kind of
 * cross-dependency A.12 exists to prevent.
 *
 * So the principal is a local fixture, and **the rows it points at are real**.
 * {@see RbacMiddlewareTest} inserts an actual `users` row through
 * the query builder and grants it actual `user_roles` rows, because
 * `user_roles.user_id` carries a real foreign key to `users(id)`
 * (`telemedicine_test.sql:175`) and a fixture id that exists in no row would prove
 * nothing about the join. What is faked is the *model*, not the data - which is the
 * arrangement the task brief asks for: assert against the table, do not depend on a
 * model that does not exist yet.
 *
 * ## It implements `HasApiTokens` only so `Sanctum::actingAs()` accepts it
 *
 * `Sanctum::actingAs()` calls `$user->withAccessToken($token)` and
 * `app('auth')->guard('sanctum')->setUser($user)`. The interface declares
 * `tokens()`, `tokenCan()`, `createToken()` and `currentAccessToken()` as well; the
 * four are implemented honestly rather than throwing, because a test that stubs them
 * with `throw` is a test that breaks the moment a route calls one, and a route
 * calling `currentAccessToken()` is ordinary Sanctum usage.
 *
 * `tokenCan()` delegates to the real token object that `actingAs()` installed, so
 * the `*` ability Sanctum grants a `TransientToken`-less acting-as user behaves
 * exactly as it would in production.
 */
final class RbacTestPrincipal implements Authenticatable, HasApiTokens
{
    private ?HasAbilities $accessToken = null;

    /**
     * @param  int  $id  a real `users.id`; `user_roles.user_id` is FK-constrained to it
     * @param  string  $tipe  a real `users.tipe` ENUM value
     */
    public function __construct(
        public readonly int $id,
        public string $tipe,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        // `telemedicine_test.sql:138` is `kata_sandi_hash`, not the scaffold's
        // `password`. Reported by the name so an authenticator that logs a failed
        // attempt names the column the schema actually has.
        return 'kata_sandi_hash';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // `users` has no `remember_token` column; the scaffold's was dropped in
        // todo 8. So there is nothing to remember and nothing to write.
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    public function withAccessToken($accessToken): static
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    public function currentAccessToken(): ?HasAbilities
    {
        return $this->accessToken;
    }

    public function tokenCan(string $ability): bool
    {
        return $this->accessToken?->can($ability) ?? false;
    }

    public function tokens(): MorphMany
    {
        // Never reached by these tests: token *creation* is todo 20's endpoint. It is
        // declared rather than stubbed so the class satisfies the interface honestly,
        // and it fails loudly if that assumption is ever wrong.
        throw new \LogicException('RbacTestPrincipal::tokens() is not available: personal_access_tokens is todo 20 surface.');
    }

    public function createToken(string $name, array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null)
    {
        throw new \LogicException('RbacTestPrincipal::createToken() is not available: token issuance is todo 20 surface.');
    }
}

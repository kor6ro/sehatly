<?php

declare(strict_types=1);

namespace App\Support\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * F-005: the Google service-account credential and the OAuth2 access token FCM
 * HTTP v1 requires.
 *
 * ## Why there is no vendor package
 *
 * FCM HTTP v1 authenticates with a short-lived OAuth2 access token minted from a
 * service-account key: a signed RS256 JWT is exchanged at Google's token endpoint
 * for a bearer token. That is three OpenSSL and HTTP calls, both of which this
 * application already has, so the prototype carries no new dependency - a package
 * would add a tree, a version to track and a second HTTP client for the same two
 * requests. The seam is this class: swapping it for a vendor SDK is a binding, not
 * a rewrite.
 *
 * ## The key file is never in the repository
 *
 * `FIREBASE_CREDENTIALS` is a PATH. The file lives outside the repository, the path
 * is an env value, and `.gitignore` refuses the names Google's console downloads
 * under. `muat()` raises when the path is unreadable or the JSON is not a service
 * account, and `FcmPushDispatcher` catches that per send rather than letting it
 * break the write that raised the notification.
 *
 * ## The access token is cached, briefly
 *
 * Google's tokens live an hour; caching for 55 minutes keeps one token exchange per
 * process-hour instead of one per push. The cache key is fixed and the value is a
 * bearer token, so the cache store must be private - which `database` and Redis
 * are, and which is why `CACHE_STORE=database` is the shipped default rather than a
 * shared file.
 */
final class KredensialFirebase
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const CACHE_KEY = 'fcm.access_token';

    private const CACHE_SECONDS = 3300;

    /**
     * @param  array{client_email: string, private_key: string}  $akun
     */
    private function __construct(
        private readonly array $akun,
        private readonly string $tokenUri,
        private readonly string $endpoint,
        private readonly string $projectId,
    ) {}

    /**
     * @throws RuntimeException when the path is unreadable or the JSON is not a service account
     */
    public static function muat(): self
    {
        $path = (string) config('push.firebase.credentials');

        if ($path === '' || ! is_readable($path)) {
            throw new RuntimeException(
                'FIREBASE_CREDENTIALS is not a readable file, so the FCM push transport cannot authenticate.',
            );
        }

        $isi = file_get_contents($path);
        $akun = $isi === false ? null : json_decode($isi, true);

        if (! is_array($akun) || ! isset($akun['client_email'], $akun['private_key'])) {
            throw new RuntimeException(
                'FIREBASE_CREDENTIALS is not a Google service-account JSON (client_email/private_key missing).',
            );
        }

        $project = (string) config('push.firebase.project_id');

        if ($project === '') {
            $project = (string) ($akun['project_id'] ?? '');
        }

        if ($project === '') {
            throw new RuntimeException(
                'No Firebase project id: set FIREBASE_PROJECT_ID or ship one in the service-account JSON.',
            );
        }

        return new self(
            [
                'client_email' => (string) $akun['client_email'],
                'private_key' => (string) $akun['private_key'],
            ],
            (string) config('push.firebase.token_uri'),
            (string) config('push.firebase.endpoint'),
            $project,
        );
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * A bearer token for the messaging scope, cached for just under its lifetime.
     *
     * @throws RuntimeException when Google refuses the exchange
     */
    public function tokenAkses(): string
    {
        return (string) Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): string {
            $response = Http::asForm()
                ->timeout((int) config('push.firebase.timeout'))
                ->post($this->tokenUri, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $this->jwt(),
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('FCM OAuth token exchange failed with status '.$response->status().'.');
            }

            return (string) $response->json('access_token');
        });
    }

    private function jwt(): string
    {
        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $this->akun['client_email'],
            'scope' => self::SCOPE,
            'aud' => $this->tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $unsigned = $this->base64url((string) json_encode($header))
            .'.'.$this->base64url((string) json_encode($claims));

        $signature = '';

        if (openssl_sign($unsigned, $signature, $this->akun['private_key'], OPENSSL_ALGO_SHA256) !== true) {
            throw new RuntimeException('Could not sign the FCM OAuth assertion with the service-account key.');
        }

        return $unsigned.'.'.$this->base64url($signature);
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

/// The base URLs this client is expected to be pointed at.
///
/// ## Why the table is here and not only in the README
///
/// A wrong base URL is the one mistake that produces a 404 with a Laravel HTML
/// body instead of the JSON envelope, and it is a mistake a client makes once per
/// environment. Putting the URLs in code makes the set greppable, makes a
/// reviewer able to see that no environment points at a production host by
/// accident, and lets [SehatlyEnvironment.forName] fail loudly for a typo rather
/// than building a client that 404s.
///
/// ## Every environment is a plain HTTP loopback except [staging]
///
/// [local] and [test] use `127.0.0.1` rather than `localhost` on purpose: on
/// Windows `localhost` resolves to `::1` first, and a PHP dev server bound only
/// to the IPv4 socket answers a connection refusal from the IPv6 attempt. The
/// loopback address is not ambiguous.
enum SehatlyEnvironment {
  /// `php artisan serve` on a developer machine.
  ///
  /// Base URL `http://127.0.0.1:8000/api/v1`. The OTP `kode` is populated in the
  /// response here and only here, because `OtpService::plainTextForClient()`
  /// publishes it outside production.
  local('local', 'http://127.0.0.1:8000/api/v1', Duration(seconds: 10)),

  /// A staging deployment over TLS.
  staging(
    'staging',
    'https://staging.sehatly.id/api/v1',
    Duration(seconds: 20),
  ),

  /// Production.
  production(
    'production',
    'https://api.sehatly.id/api/v1',
    Duration(seconds: 30),
  ),

  /// A test double, or a `MockAdapter`-backed unit test.
  test('test', 'http://127.0.0.1:8000/api/v1', Duration(seconds: 5));

  const SehatlyEnvironment(this.wireName, this.baseUrl, this.connectTimeout);

  /// The short name, as used in `--dart-define` and in a crash report.
  final String wireName;

  /// The full base URL, **including** the `/api/v1` prefix.
  ///
  /// The prefix is here because `bootstrap/app.php` mounts the API group with
  /// `apiPrefix: 'api/v1'`; a client that appended it a second time would build
  /// `/api/v1/api/v1/...` and 404.
  final String baseUrl;

  /// The connect timeout for this environment.
  ///
  /// Short on loopback and long on the public internet. A ten-second connect
  /// timeout to a host that is not there is ten seconds of a spinner; a
  /// three-second one on a mobile network is three seconds of a spinner on a
  /// request that was probably going to succeed.
  final Duration connectTimeout;

  /// Looks an environment up by [wireName], or returns `null`.
  static SehatlyEnvironment? forName(String name) {
    for (final SehatlyEnvironment value in values) {
      if (value.wireName == name) {
        return value;
      }
    }

    return null;
  }

  /// The receive timeout, which is [connectTimeout] scaled up.
  ///
  /// Separate from the connect timeout because a large list endpoint legitimately
  /// takes longer to *receive* than to connect, and collapsing the two forces a
  /// choice between a spinner that appears on a slow list and a list that
  /// abandons a working connection.
  Duration get receiveTimeout => connectTimeout * 3;

  /// The send timeout, which equals the connect timeout.
  ///
  /// A request body in this API is a profile form or a short JSON object, so
  /// sending takes as long as connecting and no longer.
  Duration get sendTimeout => connectTimeout;
}

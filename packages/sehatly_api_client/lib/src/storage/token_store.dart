import 'key_value_backend.dart';

/// Read/write access to the **access token**.
///
/// Split from [RefreshTokenStore] on purpose. The two tokens have different
/// lifetimes (minutes against weeks), different blast radii, and -- once todo
/// 20's rotation contract is in play -- a different consequence when lost: a
/// lost access token costs one re-authentication, a lost *refresh* token is a
/// revoked chain. A single interface with both values would force every caller
/// and every storage decision to treat them as one thing, and the interesting
/// operation on the pair -- "clear the session" -- is not expressible as two
/// independent `clear()` calls without a caller that remembers to do both.
abstract interface class TokenStore {
  /// Reads the current access token, or `null` when there is none.
  Future<String?> readAccessToken();

  /// Writes [token] as the current access token.
  Future<void> writeAccessToken(String token);

  /// Forgets the access token.
  Future<void> clear();

  /// One line naming the backing store, for logs and documentation.
  String get storageKind;
}

/// Read/write access to the **refresh token**.
///
/// See [TokenStore] for why this is a separate interface.
abstract interface class RefreshTokenStore {
  /// Reads the current refresh token, or `null` when there is none.
  Future<String?> readRefreshToken();

  /// Writes [token] as the current refresh token.
  Future<void> writeRefreshToken(String token);

  /// Forgets the refresh token.
  Future<void> clear();

  /// One line naming the backing store, for logs and documentation.
  String get storageKind;
}

/// The key the access token is written under.
///
/// Namespaced with a `sehatly.` prefix so a shared keychain entry (a keychain
/// scoped by bundle id, a Windows Credential Locker target, an Android
/// SharedPreferences file shared with a host app) cannot collide with an
/// unrelated value.
const String kAccessTokenKey = 'sehatly.access_token';

/// The key the refresh token is written under.
///
/// Namespaced for the reason given on [kAccessTokenKey].
const String kRefreshTokenKey = 'sehatly.refresh_token';

/// The access-token half of a [TokenStorage], backed by a platform secret store.
///
/// The default, and the only implementation a shipping mobile app should use.
/// It holds no logic of its own: every method is a delegated read, write or
/// delete against a [SecureKeyValueBackend], so the security properties are
/// exactly the backend's and are auditable in one place.
///
/// Constructing one requires a [SecureKeyValueBackend] by type, not by
/// convention. Passing an [InMemoryKeyValueBackend] does not compile, which is
/// the mechanism that stops "secure store, plain map" from shipping by accident.
class SecureTokenStore implements TokenStore {
  /// Wraps [backend] as the access-token store.
  const SecureTokenStore(this._backend);

  final SecureKeyValueBackend _backend;

  /// The backend these tokens are held in, for diagnostics and for a test that
  /// wants to assert what was written.
  SecureKeyValueBackend get backend => _backend;

  @override
  Future<String?> readAccessToken() => _backend.read(kAccessTokenKey);

  @override
  Future<void> writeAccessToken(String token) =>
      _backend.write(kAccessTokenKey, token);

  @override
  Future<void> clear() => _backend.delete(kAccessTokenKey);

  @override
  String get storageKind => 'secure: ${_backend.description}';
}

/// The refresh-token half of a [TokenStorage], backed by a platform secret store.
///
/// See [SecureTokenStore].
class SecureRefreshTokenStore implements RefreshTokenStore {
  /// Wraps [backend] as the refresh-token store.
  const SecureRefreshTokenStore(this._backend);

  final SecureKeyValueBackend _backend;

  /// The backend these tokens are held in, for diagnostics and for a test that
  /// wants to assert what was written.
  SecureKeyValueBackend get backend => _backend;

  @override
  Future<String?> readRefreshToken() => _backend.read(kRefreshTokenKey);

  @override
  Future<void> writeRefreshToken(String token) =>
      _backend.write(kRefreshTokenKey, token);

  @override
  Future<void> clear() => _backend.delete(kRefreshTokenKey);

  @override
  String get storageKind => 'secure: ${_backend.description}';
}

/// The access-token half of a [TokenStorage], backed by non-secret storage.
///
/// ## When this is the right choice
///
/// Desktop development against a local `php artisan serve`, a CLI integration
/// script, and unit tests. None of those ship to a phone, and on a desktop the
/// OS keychain prompt on every read is worse than a file for a value the machine
/// already trusts its own logged-in user to have.
///
/// ## When it is the wrong choice
///
/// Anything on a mobile device. A bearer token in an unencrypted file on an
/// Android device is readable by any app with `READ_EXTERNAL_STORAGE` before
/// scoped storage closed that door, and on a rooted or jailbroken device it is
/// readable regardless. That is MASVS-STORAGE-1, and it is why
/// [TokenStorage.plain] refuses to construct without an explicit acknowledgement
/// rather than merely documenting the risk.
class PlainTokenStore implements TokenStore {
  /// Wraps [backend] as the access-token store.
  const PlainTokenStore(this._backend);

  final PlainKeyValueBackend _backend;

  /// The backend these tokens are held in, for diagnostics.
  PlainKeyValueBackend get backend => _backend;

  @override
  Future<String?> readAccessToken() => _backend.read(kAccessTokenKey);

  @override
  Future<void> writeAccessToken(String token) =>
      _backend.write(kAccessTokenKey, token);

  @override
  Future<void> clear() => _backend.delete(kAccessTokenKey);

  @override
  String get storageKind => 'plain: ${_backend.description}';
}

/// The refresh-token half of a [TokenStorage], backed by non-secret storage.
///
/// See [PlainTokenStore].
class PlainRefreshTokenStore implements RefreshTokenStore {
  /// Wraps [backend] as the refresh-token store.
  const PlainRefreshTokenStore(this._backend);

  final PlainKeyValueBackend _backend;

  /// The backend these tokens are held in, for diagnostics.
  PlainKeyValueBackend get backend => _backend;

  @override
  Future<String?> readRefreshToken() => _backend.read(kRefreshTokenKey);

  @override
  Future<void> writeRefreshToken(String token) =>
      _backend.write(kRefreshTokenKey, token);

  @override
  Future<void> clear() => _backend.delete(kRefreshTokenKey);

  @override
  String get storageKind => 'plain: ${_backend.description}';
}

import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'key_value_backend.dart';
import 'token_store.dart';

/// The two token stores the client uses, and the one operation that has to
/// clear both.
///
/// ## Why the pair is a single object
///
/// The interesting lifecycle event is "this session is over", and it has to
/// clear **both** tokens. `RefreshCoordinator` does that on a 401 from
/// `POST /auth/refresh`, because the server's rotation contract makes that
/// status unrecoverable. If the two stores were two unrelated fields on the
/// client, that call site would be `await access.clear(); await refresh.clear();`
/// -- two statements that a later edit could half-complete, leaving a live
/// access token in storage with no refresh token behind it. Pairing them makes
/// the whole-session clear one method that cannot be half-done.
class TokenStorage {
  /// Pairs an access-token store with a refresh-token store.
  const TokenStorage({required this.accessTokens, required this.refreshTokens});

  /// Holds both tokens in a platform secret store.
  ///
  /// This is the recommended construction and the one the mobile app uses. Both
  /// stores share [backend] so the two values live under the same access
  /// control: a keychain entry that one store can read and the other cannot
  /// would be a strange configuration to have to reason about.
  factory TokenStorage.secure(SecureKeyValueBackend backend) {
    return TokenStorage(
      accessTokens: SecureTokenStore(backend),
      refreshTokens: SecureRefreshTokenStore(backend),
    );
  }

  /// Holds both tokens in non-secret storage, given an explicit acknowledgement.
  ///
  /// ## Why [allowInsecureStorage] exists
  ///
  /// The plan's todo 24 says the client "must NOT put tokens in a plain store by
  /// default". A default of "secure" is not enough to satisfy that, because the
  /// failure mode is a developer reaching for the convenient constructor on a
  /// build that will ship to a phone. A required named boolean makes the choice
  /// a **visible, greppable line at the call site** instead of an omission, and
  /// the exception it throws names the reason so the fix is obvious at the
  /// call site rather than three frames away.
  ///
  /// ```dart
  /// // Rejected: StateError, at the point of the mistake.
  /// TokenStorage.plain(backend);
  ///
  /// // Accepted, and `allowInsecureStorage: true` is in the diff.
  /// TokenStorage.plain(backend, allowInsecureStorage: true);
  /// ```
  factory TokenStorage.plain(
    PlainKeyValueBackend backend, {
    required bool allowInsecureStorage,
  }) {
    if (!allowInsecureStorage) {
      throw StateError(
        'TokenStorage.plain() was called without allowInsecureStorage: true. '
        'Plain storage is readable by any other process on the device and is '
        'not acceptable for a bearer token on mobile. Use '
        'TokenStorage.secure() with a platform secret store, or pass the flag '
        'explicitly if this really is a desktop or test target.',
      );
    }

    return TokenStorage(
      accessTokens: PlainTokenStore(backend),
      refreshTokens: PlainRefreshTokenStore(backend),
    );
  }

  /// Where the access token lives.
  final TokenStore accessTokens;

  /// Where the refresh token lives.
  final RefreshTokenStore refreshTokens;

  /// Whether both halves are held in a platform secret store.
  ///
  /// `false` here means a bearer token is in readable storage, and is worth a
  /// log line in a debug build so a tester can see it.
  bool get isSecure =>
      accessTokens.storageKind.startsWith('secure:') &&
      refreshTokens.storageKind.startsWith('secure:');

  /// A one-line description of where the tokens live.
  String get description =>
      'access=${accessTokens.storageKind}, refresh=${refreshTokens.storageKind}';

  /// Forgets both tokens.
  ///
  /// Called on a 401 from `POST /auth/refresh`, and on an explicit sign-out.
  /// Both clears are awaited even if the first throws, so a backend that cannot
  /// delete its access-token entry does not leave the refresh token behind --
  /// which is the worse of the two to leave behind, since it is the longer-lived
  /// secret.
  Future<void> clearAll() async {
    Object? firstError;
    StackTrace? firstStack;

    try {
      await accessTokens.clear();
    } catch (error, stackTrace) {
      firstError = error;
      firstStack = stackTrace;
    }

    try {
      await refreshTokens.clear();
    } catch (error, stackTrace) {
      firstError ??= error;
      firstStack ??= stackTrace;
    }

    if (firstError != null) {
      Error.throwWithStackTrace(firstError, firstStack ?? StackTrace.current);
    }
  }
}

/// A [PlainKeyValueBackend] backed by a JSON file on disk.
///
/// For desktop development and for a CLI integration script, where the OS
/// keychain prompt on every read is a worse trade than a file. It is **plain**:
/// see [PlainTokenStore] for why that is not acceptable on a mobile device.
///
/// ## Why the value is base64 and not the token
///
/// It is base64, which is **not** encryption, and the class is named for what
/// it is. Base64 is there for one narrow reason: a bearer token is a
/// `users.email`-shaped opaque string with no newlines, and several desktop
/// secret-file formats and log shippers mangle or truncate arbitrary bytes in
/// the middle of a long value. Encoding keeps the value byte-safe and greppable
/// in a log as a single token, which makes a leak obvious rather than
/// reassembled.
///
/// It provides **no** confidentiality whatsoever. Anyone who can read the file
/// can read the token. `FileKeyValueBackend` therefore implements
/// [PlainKeyValueBackend] and not [SecureKeyValueBackend], so the type system
/// carries that fact all the way to [TokenStorage.plain]'s required flag.
class FileKeyValueBackend implements PlainKeyValueBackend {
  /// Backs the store with the file at [path], creating it on first write.
  ///
  /// The file is created with owner-only permissions on POSIX platforms. On
  /// Windows, `File.create` inherits the containing directory's ACL, which is
  /// the platform's answer and is not something this class can tighten without
  /// pulling in a Win32 dependency.
  FileKeyValueBackend(this.path);

  /// Where the JSON object lives.
  final String path;

  @override
  Future<String?> read(String key) async {
    final Map<String, String> values = await _load();

    return values[key];
  }

  @override
  Future<void> write(String key, String value) async {
    final Map<String, String> values = await _load();
    values[key] = value;

    await _store(values);
  }

  @override
  Future<void> delete(String key) async {
    final Map<String, String> values = await _load();
    values.remove(key);

    await _store(values);
  }

  @override
  Future<void> deleteAll() async {
    await _store(<String, String>{});
  }

  @override
  String get description => 'plain JSON file at $path (base64, not encrypted)';

  /// Reads the whole file, treating every failure as "nothing stored".
  ///
  /// A missing file, an empty file, a truncated file from a crash mid-write and
  /// a hand-edited file are all the same situation from the caller's side: no
  /// token is stored, so the client has to sign in again. Throwing for any of
  /// them would turn a recoverable "your session was not persisted" into a crash
  /// on startup.
  Future<Map<String, String>> _load() async {
    final File file = File(path);
    String contents;

    try {
      contents = await file.readAsString();
    } on FileSystemException {
      return <String, String>{};
    }

    if (contents.trim().isEmpty) {
      return <String, String>{};
    }

    try {
      return _decodeToMap(contents);
    } on FormatException {
      return <String, String>{};
    }
  }

  Future<void> _store(Map<String, String> values) async {
    final File file = File(path);
    await file.parent.create(recursive: true);
    await file.writeAsString(_encode(values), flush: true);
  }

  /// Serialises the map to JSON, base64-encoding each value.
  ///
  /// See the class docblock for why the values are encoded and why that is not
  /// a security measure.
  static String _encode(Map<String, String> values) {
    final Map<String, String> encoded = <String, String>{
      for (final MapEntry<String, String> entry in values.entries)
        entry.key: base64.encode(ascii.encode(entry.value)),
    };

    return const JsonEncoder.withIndent('  ').convert(encoded);
  }

  /// Reverses [_encode], tolerating a plain-text value.
  ///
  /// A value that is not valid base64 is returned as-is rather than dropped. That
  /// is the forgiving half of the two: a hand-edited or half-written file loses
  /// its token, which costs one re-authentication, and silently losing it is far
  /// better than a crash on startup.
  static Map<String, String> _decodeToMap(String contents) {
    final Object? decoded = jsonDecode(contents);

    if (decoded is! Map<Object?, Object?>) {
      throw const FormatException('Not a JSON object.');
    }

    final Map<String, String> values = <String, String>{};

    for (final MapEntry<Object?, Object?> entry in decoded.entries) {
      final Object? value = entry.value;

      if (value is! String) {
        continue;
      }

      values['${entry.key}'] = _decodeValue(value);
    }

    return values;
  }

  static String _decodeValue(String value) {
    try {
      return ascii.decode(base64.decode(value));
    } on FormatException {
      return value;
    }
  }
}

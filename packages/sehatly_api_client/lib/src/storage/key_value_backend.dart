/// The storage primitive both token stores delegate to.
///
/// ## Why this is an interface and not a `Map`
///
/// A token store has exactly four operations and no query surface, so a
/// `Map<String, String>` would be enough *if* the values lived in the process.
/// They do not, on any platform worth shipping on: an access token outlives the
/// process, and reading it back has to go through whatever the operating system
/// offers for "a secret that must not be readable by another app on this
/// device". Naming those four operations is what lets a
/// [SecureKeyValueBackend] and a [PlainKeyValueBackend] be different types, and
/// therefore lets [TokenStorage.plain] be a compile-time decision rather than a
/// runtime setting somebody forgets to pass.
///
/// ## `description` is not decoration
///
/// [description] is what the README's storage table, a crash report, and any
/// security review read to answer "where did this token live?". A backend that
/// cannot name itself in one line is a backend nobody can audit.
library;

/// A minimal asynchronous string key/value store.
abstract interface class KeyValueBackend {
  /// Reads the value for [key], or `null` when the key is not present.
  Future<String?> read(String key);

  /// Writes [value] under [key], replacing any existing value.
  Future<void> write(String key, String value);

  /// Removes [key]. A missing key is not an error.
  Future<void> delete(String key);

  /// Removes every key this backend owns.
  Future<void> deleteAll();

  /// One line naming the backing store, for logs and documentation.
  String get description;
}

/// Marks a [KeyValueBackend] as backed by a platform secret store.
///
/// ## The contract an implementation must honour
///
/// A backend claiming this interface is asserting all four of the following.
/// They are the OWASP MASVS-STORAGE-1 properties, restated as things a Dart type
/// system can carry:
///
/// 1. The bytes are held in a store the operating system protects -- the iOS
///    keychain, the Android `EncryptedSharedPreferences`/Keystore, the Windows
///    Credential Locker, the macOS Keychain -- and not in a file this process
///    wrote and another process can read.
/// 2. The store is **not** included in an application backup, so a token does not
///    ride a device restore onto different hardware.
/// 3. Reads and writes are authenticated by the platform, so another app on the
///    device cannot supply or observe the value.
/// 4. The value is excluded from the platform's crash and analytics pipelines.
///
/// `flutter_secure_storage` 11.2.0 is configured to satisfy all four and is what
/// the mobile app supplies; see the package README for the copy-pasteable
/// adapter.
///
/// ## Why this package ships no implementation
///
/// There is no pure-Dart way to reach a platform keychain: every mechanism is a
/// **Flutter plugin**, and a plugin is resolved through the Flutter SDK's own
/// package resolution, which a `dart`-only package cannot participate in
/// without taking a `flutter:` SDK constraint. Taking that constraint would make
/// `dart pub get` fail for any CI runner without Flutter, which is the opposite
/// of what this package is for.
///
/// So the interface is here, the `SecureTokenStore` that depends on it is here,
/// and the one thing that has to live in the consuming Flutter app is the twenty
/// lines of adapter behind this interface. A `SecureTokenStore` constructed
/// without a real [SecureKeyValueBackend] does not compile, which is the point:
/// there is no way to accidentally ship the secure store backed by a plain map.
abstract interface class SecureKeyValueBackend implements KeyValueBackend {}

/// Marks a [KeyValueBackend] as **not** secret-store protected.
///
/// Implementing this is an explicit statement that the values are readable by
/// anything with access to the process, its memory, or its files. See
/// [TokenStorage.plain] for the flag that has to accompany it.
abstract interface class PlainKeyValueBackend implements KeyValueBackend {}

/// An in-memory [PlainKeyValueBackend].
///
/// For unit tests, for a CLI tool with a disposable session, and as the reference
/// implementation of the four methods. It is **plain**, not secure, and it says
/// so: a token written here is readable by anything in the same isolate and
/// disappears when the isolate does.
class InMemoryKeyValueBackend implements PlainKeyValueBackend {
  /// Creates a backend, optionally pre-seeded with [initial] contents.
  InMemoryKeyValueBackend([Map<String, String>? initial])
    : _values = <String, String>{...?initial};

  final Map<String, String> _values;

  /// A read-only view of the current contents, for assertions.
  Map<String, String> get snapshot => Map<String, String>.unmodifiable(_values);

  @override
  Future<String?> read(String key) async => _values[key];

  @override
  Future<void> write(String key, String value) async {
    _values[key] = value;
  }

  @override
  Future<void> delete(String key) async {
    _values.remove(key);
  }

  @override
  Future<void> deleteAll() async {
    _values.clear();
  }

  @override
  String get description => 'in-memory map (not a secret store)';
}

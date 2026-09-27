/// The Sehatly telemedicine API client -- pure Dart, no Flutter.
///
/// One import gives a mobile app every Module 1 endpoint:
///
/// ```dart
/// import 'package:sehatly_api_client/sehatly_api_client.dart';
/// ```
///
/// ## What is here
///
/// - [SehatlyApiClient] -- the entry point. `SehatlyApiClient.secure(...)` for a
///   shipping app, `.plain(...)` for a desktop or test target.
/// - [AuthApi], [MeApi], [PasienApi], [DokterApi] -- the typed endpoint groups,
///   reachable as `client.auth`, `client.me`, `client.pasien`, `client.dokter`.
/// - [ApiException] (aliased [ApiError]) -- the typed failure, with
///   `isValidationError`, `isUnauthorized`, `isForbidden`, `isNotFound`,
///   `isTooManyRequests`, `isServerError`, `isNetworkError`, `isSlotTaken` and
///   `isConsentRequired`.
/// - [ApiEnvelope] and [ApiMeta] and [Paginated] -- the response envelope and the
///   pagination block, which is absent on non-list responses by design.
/// - [jsonMap], [jsonList], [jsonInt] and the rest of the readers -- the total
///   JSON accessors every DTO is built on, exported so a caller working with
///   [ApiEnvelope.raw] reads a `data` member the same way this package does.
/// - [TokenStore], [RefreshTokenStore], [SecureTokenStore], [PlainTokenStore],
///   [TokenStorage] -- the storage split, and the flag that stops a plain store
///   being selected by accident.
/// - [RefreshCoordinator] and [AuthInterceptor] -- single-flight rotation and the
///   bearer header.
/// - The DTOs and the DDL `ENUM` vocabularies, transcribed from
///   `telemedicine_test.sql` with the source line named on every constant.
///
/// ## No Flutter, and that is load-bearing
///
/// There is no `flutter:` SDK constraint in `pubspec.yaml` and no Flutter import
/// anywhere in `lib/`. The package resolves, analyzes and tests with a bare
/// `dart` binary, so it can be verified on a CI runner that has no Flutter, and
/// the concrete `SecureKeyValueBackend` -- the twenty lines that wrap
/// `flutter_secure_storage` -- lives in the consuming app behind the interface.
/// See `README.md` for the OWASP MASVS-STORAGE-1 reasoning and the
/// copy-pasteable adapter.
library;

export 'src/api/auth_api.dart';
export 'src/api/dokter_api.dart';
export 'src/api/me_api.dart';
export 'src/api/pasien_api.dart';
export 'src/api/paths.dart';
export 'src/api/transport.dart';
export 'src/auth/auth_interceptor.dart';
export 'src/auth/refresh_coordinator.dart';
export 'src/client.dart';
export 'src/config.dart';
export 'src/core/api_envelope.dart';
export 'src/core/api_exception.dart';
export 'src/core/json.dart';
export 'src/core/pagination.dart';
export 'src/fake/fake_api.dart';
export 'src/model/dto.dart';
export 'src/model/enums.dart';
export 'src/storage/key_value_backend.dart';
export 'src/storage/token_storage.dart';
export 'src/storage/token_store.dart';

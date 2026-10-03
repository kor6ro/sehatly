/// Every Module 1 route, as a constant.
///
/// ## Why the constants and not inline strings
///
/// `php artisan route:list --path=api/v1` reports exactly 22 routes, and this
/// file names the 22 of them. A path typed into a call site is a path nothing
/// checks, and a `data` key typed into a parser is a key nothing checks -- and
/// the two together are the whole wire contract.
///
/// The base URL already ends in `/api/v1`: `bootstrap/app.php` mounts the API
/// group with `apiPrefix: 'api/v1'`, so a path here starts at the group root and
/// carries no prefix. A path that repeated `/api/v1` would 404 against a server
/// configured with a different prefix, which is the whole point of the group
/// declaration existing.
library;

/// `POST /api/v1/auth/sign-up`
const String pathAuthSignUp = '/auth/sign-up';

/// `POST /api/v1/auth/login`
const String pathAuthLogin = '/auth/login';

/// `POST /api/v1/auth/otp/verify`
const String pathAuthOtpVerify = '/auth/otp/verify';

/// `POST /api/v1/auth/refresh`
const String pathAuthRefresh = '/auth/refresh';

/// `POST /api/v1/auth/logout`
const String pathAuthLogout = '/auth/logout';

/// `GET|POST /api/v1/auth/devices`
const String pathAuthDevices = '/auth/devices';

/// `DELETE /api/v1/auth/devices/{deviceId}`
///
/// The segment is a **string**, the client-supplied installation identifier from
/// `user_devices.device_id`, and never the surrogate `id`. The route is declared
/// without `whereNumber()` for exactly that reason. A non-numeric-looking device
/// id is normal: an Android installation id and an iOS `identifierForVendor` are
/// both alphanumeric.
String pathAuthDevice(String deviceId) => '/auth/devices/$deviceId';

/// `GET /api/v1/me`
const String pathMe = '/me';

/// `GET|PUT /api/v1/pasien/profil`
const String pathPasienProfil = '/pasien/profil';

/// `GET|POST /api/v1/pasien/anggota-keluarga`
const String pathPasienAnggotaKeluarga = '/pasien/anggota-keluarga';

/// `PUT|DELETE /api/v1/pasien/anggota-keluarga/{id}`
///
/// Constrained to a number by `whereNumber('id')` on the route, so a
/// non-numeric segment is a 404 from the router. A 64-bit `BIGINT UNSIGNED`
/// surrogate does not fit a Dart `int` on the web, so this takes a [String] and
/// the caller passes the id it was given verbatim rather than round-tripping it
/// through a double.
String pathPasienAnggotaKeluargaById(String id) =>
    '/pasien/anggota-keluarga/$id';

/// `GET|POST /api/v1/pasien/alergi`
const String pathPasienAlergi = '/pasien/alergi';

/// `PUT|DELETE /api/v1/pasien/alergi/{id}`
///
/// Number-constrained by the route, and a `String` parameter for the reason on
/// [pathPasienAnggotaKeluargaById].
String pathPasienAlergiById(String id) => '/pasien/alergi/$id';

/// `GET /api/v1/dokter`
const String pathDokter = '/dokter';

/// `GET /api/v1/dokter/{dokter}`
///
/// The segment is named `dokter`, not `id`, and is deliberately **not** bound to
/// a model: implicit route-model binding would resolve the row and bypass the
/// eligibility rules the directory service exists to enforce, answering 200 with
/// an unverified doctor's profile. A `String` parameter is what keeps that
/// impossible from the client side too.
String pathDokterDetail(String dokterId) => '/dokter/$dokterId';

/// `GET /api/v1/master-spesialisasi`
const String pathMasterSpesialisasi = '/master-spesialisasi';

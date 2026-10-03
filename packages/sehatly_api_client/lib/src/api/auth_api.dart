import '../core/api_envelope.dart';
import '../core/json.dart';
import '../core/pagination.dart';
import '../model/dto.dart';
import '../model/enums.dart';
import 'paths.dart';
import 'transport.dart';

/// `POST /api/v1/auth/sign-up` -- the two-step flow this class is built around.
///
/// ## Registration is TWO requests, and the first one returns no token
///
/// ```text
/// POST /auth/sign-up     -> 201, {user, otp}          (NO token)
///     ... the user reads the SMS ...
/// POST /auth/otp/verify   -> 200, {user, token}        (the only place a token is minted)
/// ```
///
/// The same shape applies to login. **No Module 1 endpoint other than
/// `otp/verify` returns a token**, and a client written to expect one from
/// [register] or [login] has no fallback path: it will read a null and either
/// crash or, worse, present an empty bearer token and get an opaque 401 on
/// everything afterwards.
///
/// [LoginResult] has no `token` member at all, which makes the mistake a
/// compile error rather than a null. That is the entire reason it is a distinct
/// type from [VerifyOtpResult].
class AuthApi {
  /// Creates the endpoint group over [transport].
  const AuthApi(this._transport);

  final ApiTransport _transport;

  /// `POST /api/v1/auth/sign-up`
  ///
  /// Required: `nama_lengkap` (3-150), `no_telepon` (optional `+`, 8-20 digits,
  /// unique), `password` (8-255), `jenis_kelamin` (`L` or `P`), `tanggal_lahir`
  /// (`Y-m-d`, 1900-01-01 to today), `alamat_lengkap` (5+). Optional: `email`
  /// (unique, `NULL` permitted), `tempat_lahir` (100), `bahasa` (`id` or `en`).
  ///
  /// There is **no `nik` field**: registration does not take one, and a client
  /// that sends one gets a 422. `password` is never echoed back and is not
  /// `confirmed` -- the server deliberately omits Laravel's `confirmed` rule
  /// because it is a server-rendered-form convention and a JSON client sends the
  /// same value twice for no benefit.
  ///
  /// Throttled at 10/min. Answers 201.
  Future<RegisterResult> register({
    required String namaLengkap,
    required String noTelepon,
    required String password,
    required JenisKelamin jenisKelamin,
    required String tanggalLahir,
    required String alamatLengkap,
    String? email,
    String? tempatLahir,
    Bahasa? bahasa,
  }) async {
    final ApiEnvelope<Object?> envelope = await _transport.post<Object?>(
      pathAuthSignUp,
      anonymous: true,
      allowUnsafeRetry: true,
      body: <String, Object?>{
        'nama_lengkap': namaLengkap,
        'no_telepon': noTelepon,
        'password': password,
        'jenis_kelamin': jenisKelamin.wire,
        'tanggal_lahir': tanggalLahir,
        'alamat_lengkap': alamatLengkap,
        'email': ?email,
        'tempat_lahir': ?tempatLahir,
        if (bahasa != null) 'bahasa': bahasa.wire,
      },
      parseData: parseDataObject,
    );

    return RegisterResult.fromJson(envelope.dataMap);
  }

  /// `POST /api/v1/auth/login`
  ///
  /// Required: exactly one of `no_telepon` or `email`, plus `password`.
  ///
  /// **Returns no token.** The password step mints a `login` OTP and stops,
  /// because a phone-proved second factor is only a control while the second step
  /// still requires the phone; issuing a token here would make the OTP a
  /// confirmation screen rather than a control.
  ///
  /// An unknown identifier and a wrong password produce the **same** 401 body and,
  /// by way of a decoy hash, take the same time -- so this endpoint is not a
  /// phone-number oracle. A 403 means the account is `nonaktif` or
  /// `ditangguhkan`, which is a different answer from a wrong password and is
  /// worth surfacing differently in a UI.
  ///
  /// Throttled at 5/min.
  Future<LoginResult> login({
    required String password,
    String? noTelepon,
    String? email,
  }) async {
    final (String? phone, String? mail) = _identifiers(noTelepon, email);

    if (phone == null && mail == null) {
      throw ArgumentError(
        'login() needs noTelepon or email: the server requires at least one of '
        'the two and answers 422 naming both keys when it gets neither.',
      );
    }

    final ApiEnvelope<Object?> envelope = await _transport.post<Object?>(
      pathAuthLogin,
      anonymous: true,
      allowUnsafeRetry: true,
      body: <String, Object?>{
        'password': password,
        'no_telepon': ?phone,
        'email': ?mail,
      },
      parseData: parseDataObject,
    );

    return LoginResult.fromJson(envelope.dataMap);
  }

  /// `POST /api/v1/auth/otp/verify`
  ///
  /// The only endpoint in the whole Module 1 surface that mints a token, and the
  /// only one that flips `user_otp.sudah_dipakai`. It serves both flows, told
  /// apart by [tujuan].
  ///
  /// Required: exactly one of `no_telepon` or `email`, plus `kode` (a
  /// **string** of exactly six digits -- it may begin with `0`, so parsing it as
  /// an integer produces a code that can never match) and [tujuan], which the
  /// server narrows to [OtpTujuanDiterbitkan]: in practice
  /// [OtpTujuanDiterbitkan.verifikasiTelepon] after [register] and
  /// [OtpTujuanDiterbitkan.login] after [login].
  ///
  /// `reset_kata_sandi` and `verifikasi_email` are in the column and are
  /// **refused** here on purpose: this endpoint's effect is "issue a session", and
  /// neither of those needs a session. Accepting them would let a caller turn a
  /// reset code into a logged-in one.
  ///
  /// Optional: `device_id`, which is recorded as the Sanctum token's name so an
  /// administrator can tell a phone session from a web one. It does **not** write
  /// a `user_devices` row -- that is [registerDevice]'s job -- and it does not
  /// survive a refresh.
  ///
  /// Every rejection is a **422** with the reason under `errors.kode`, so a client
  /// can tell "resend" from "start over". An unknown identifier is reported with
  /// the *unknown code* text rather than a 404, because the row not existing is
  /// not the caller's business.
  ///
  /// Throttled at 5/min, keyed on the caller's identifier so one attacker cannot
  /// lock every account out by spending a shared budget.
  ///
  /// The returned pair is **not** written to storage by this method. Call
  /// `SehatlyApiClient.persistSession(pair)` so the choice of storage is the
  /// caller's, and a caller that wants to inspect a pair before trusting it can.
  Future<VerifyOtpResult> verifyOtp({
    required String kode,
    required OtpTujuanDiterbitkan tujuan,
    String? noTelepon,
    String? email,
    String? deviceId,
  }) async {
    final (String? phone, String? mail) = _identifiers(noTelepon, email);

    if (phone == null && mail == null) {
      throw ArgumentError(
        'verifyOtp() needs noTelepon or email: the server requires at least one '
        'of the two and answers 422 naming both keys when it gets neither.',
      );
    }

    final ApiEnvelope<Object?> envelope = await _transport.post<Object?>(
      pathAuthOtpVerify,
      anonymous: true,
      allowUnsafeRetry: true,
      body: <String, Object?>{
        'kode': kode,
        'tujuan': tujuan.wire,
        'no_telepon': ?phone,
        'email': ?mail,
        'device_id': ?deviceId,
      },
      parseData: parseDataObject,
    );

    return VerifyOtpResult.fromJson(envelope.dataMap);
  }

  /// Normalises the two identifier fields into `(phone, email)`.
  ///
  /// `AuthRequest` requires **exactly one of** `no_telepon` or `email` and
  /// attaches the same "Isi no_telepon atau email." message to both keys when it
  /// gets neither, so a caller that sends neither learns which two fields it was
  /// expected to send. Both are `nullable` individually, and both are `''`-safe
  /// here: an empty string would be sent as an empty string by a naive
  /// null-check, and `email` is validated as an address even when it is the field
  /// the caller did *not* mean to send, so `""` is a 422 rather than a no-op.
  static (String?, String?) _identifiers(String? noTelepon, String? email) {
    final String? phone = (noTelepon != null && noTelepon.isNotEmpty)
        ? noTelepon
        : null;
    final String? mail = (email != null && email.isNotEmpty) ? email : null;

    return (phone, mail);
  }

  /// `POST /api/v1/auth/refresh`
  ///
  /// Rotates the pair. **The presented token is revoked on every use**, so this is
  /// a one-shot call per token and a client must persist the new pair before it
  /// can ever need another.
  ///
  /// A 401 here is **terminal**. The token was already spent -- a client bug or a
  /// stolen one -- and in the second case the server has revoked every live
  /// refresh token for the account. This client treats a 401 from *this endpoint*
  /// as "session over": both stores are cleared and `sessionExpired` fires once.
  ///
  /// Prefer letting the interceptor do this. It calls the refresh on your behalf
  /// when a request 401s, coalesces N simultaneous 401s into one rotation, and
  /// applies the terminal-failure rule. Reach for this method only to rotate
  /// proactively, and read [SehatlyApiClient.rotateSession] for the guard that
  /// makes a proactive rotation safe.
  Future<TokenPair> refresh(String refreshToken) async {
    final ApiEnvelope<Object?> envelope = await _transport.post<Object?>(
      pathAuthRefresh,
      anonymous: true,
      allowUnsafeRetry: true,
      body: <String, Object?>{'refresh_token': refreshToken},
      parseData: parseDataObject,
    );

    return TokenPair.fromJson(jsonMap(envelope.dataMap['token']));
  }

  /// `POST /api/v1/auth/logout`
  ///
  /// Revokes the presented refresh token, deletes the Sanctum access token this
  /// request arrived on, and deactivates **every** `user_devices` row for the
  /// account.
  ///
  /// `refresh_token` is **required**, and this method therefore has no default
  /// for it. The server refuses an optional one deliberately: "log out" must have
  /// exactly one meaning, or "revoke every live refresh token for this account"
  /// becomes a silent consequence of forgetting a field. A caller who has
  /// genuinely lost the secret still has a working access token and can call
  /// [removeDevice] instead.
  ///
  /// The broad device deactivation is unavoidable server-side:
  /// `user_refresh_tokens` has no `device_id` column, so the server cannot tell
  /// which device a session belongs to. It is recoverable -- [registerDevice]
  /// re-activates a row -- and [removeDevice] is the scoped alternative for ending
  /// one session only.
  Future<LogoutResult> logout(String refreshToken) async {
    final ApiEnvelope<Object?> envelope = await _transport.post<Object?>(
      pathAuthLogout,
      body: <String, Object?>{'refresh_token': refreshToken},
      parseData: parseDataObject,
      allowUnsafeRetry: true,
    );

    return LogoutResult.fromJson(envelope.dataMap);
  }

  /// `GET /api/v1/auth/devices`
  ///
  /// The caller's own rows, most recently active first. **Not paginated** -- an
  /// account has a handful -- but it still answers the project-wide `meta` shape
  /// with the degenerate values `current_page = 1` and `last_page = 1`, so a
  /// client parses one list envelope for every list endpoint rather than two.
  Future<Paginated<UserDevice>> devices() async {
    final ApiEnvelope<Object?> envelope = await _transport.get<Object?>(
      pathAuthDevices,
      parseData: parseDataObject,
    );

    return Paginated<UserDevice>.fromEnvelope(
      data: envelope.data,
      meta: envelope.meta,
      key: 'devices',
      itemParser: UserDevice.fromJson,
    );
  }

  /// `POST /api/v1/auth/devices`
  ///
  /// Upserts on `uq_device (user_id, device_id)` and sets `aktif = 1`, which is
  /// what makes a device deactivated by a logout usable again.
  ///
  /// Required: `device_id` (3-255, the client-supplied installation id),
  /// `platform` (`android`, `ios` or `web`). Optional: `fcm_token` (255),
  /// `app_versi` (20, restricted to `[0-9A-Za-z.+_-]`).
  ///
  /// Answers 201 for an insert and 200 for the upsert it collided with, so the
  /// status distinguishes "first time" from "already known" without a second
  /// request. `fcm_token` is returned because the caller just supplied it; it is
  /// not a credential for this API.
  Future<UserDevice> registerDevice({
    required String deviceId,
    required DevicePlatform platform,
    String? fcmToken,
    String? appVersi,
  }) async {
    final ApiEnvelope<Object?> envelope = await _transport.post<Object?>(
      pathAuthDevices,
      body: <String, Object?>{
        'device_id': deviceId,
        'platform': platform.wire,
        'fcm_token': ?fcmToken,
        'app_versi': ?appVersi,
      },
      parseData: parseDataObject,
      allowUnsafeRetry: true,
    );

    return UserDevice.fromJson(jsonMap(envelope.dataMap['device']));
  }

  /// `DELETE /api/v1/auth/devices/{deviceId}`
  ///
  /// [deviceId] is the **string** installation identifier, never a numeric id:
  /// the surrogate `id` is deliberately not published, and
  /// `DELETE /api/v1/auth/devices/{deviceId}` takes `user_devices.device_id`.
  ///
  /// The row is deactivated, never deleted -- `user_devices` is the only record
  /// of which installations hold a push registration. A `device_id` belonging to
  /// another account is a **404, not a 403**, because a 403 would confirm it
  /// exists and a client may legitimately hold one for a device it no longer owns.
  Future<UserDevice> removeDevice(String deviceId) async {
    final ApiEnvelope<Object?> envelope = await _transport.delete<Object?>(
      pathAuthDevice(deviceId),
      parseData: parseDataObject,
      allowUnsafeRetry: true,
    );

    return UserDevice.fromJson(jsonMap(envelope.dataMap['device']));
  }
}

/// `data` of a `POST /auth/sign-up` response: the account and its pending OTP.
///
/// [otp] is present and its [OtpChallenge.kode] is populated **only** when the
/// server environment is local; in production the code arrives over SMS and
/// [OtpChallenge.kode] is `null`.
class RegisterResult {
  /// Creates a result directly, without parsing.
  const RegisterResult({required this.user, required this.otp});

  /// Parses the `data` object.
  factory RegisterResult.fromJson(Map<String, Object?> json) {
    return RegisterResult(
      user: User.fromJson(jsonMap(json['user'])),
      otp: OtpChallenge.fromJson(jsonMap(json['otp'])),
    );
  }

  /// The caller's own `users` row.
  ///
  /// `User.pasien` and `User.dokter` are **null** here, not because the rows are
  /// missing but because the resource uses `whenLoaded()` and registration loads
  /// neither. A `pasien` row is created in the same transaction; the response
  /// simply does not publish it. Call `MeApi.show()` for the populated shape.
  final User user;

  /// The OTP that was just minted, for [OtpTujuan.verifikasiTelepon].
  final OtpChallenge otp;

  @override
  String toString() => 'RegisterResult(user: ${user.namaLengkap}, otp: $otp)';
}

/// `data` of a `POST /auth/login` response.
///
/// ## There is deliberately no `token` member
///
/// Login mints an OTP and stops. A `token` field here -- even a nullable one --
/// would invite a client to read it, find `null`, and treat that as a transient
/// state rather than as the design. Its absence is a compile error at the call
/// site, which is the only place this mistake is cheap to fix.
///
/// Continue with [AuthApi.verifyOtp] and [OtpTujuanDiterbitkan.login].
class LoginResult {
  /// Creates a result directly, without parsing.
  const LoginResult({required this.otp});

  /// Parses the `data` object.
  factory LoginResult.fromJson(Map<String, Object?> json) {
    return LoginResult(otp: OtpChallenge.fromJson(jsonMap(json['otp'])));
  }

  /// The OTP that was just minted, for [OtpTujuan.login].
  final OtpChallenge otp;

  @override
  String toString() => 'LoginResult(otp: $otp)';
}

/// `data` of a `POST /auth/otp/verify` response: the account and the new session.
///
/// This is the **only** Module 1 response that carries a [TokenPair]. Call
/// `SehatlyApiClient.persistSession(result.token)` before making any
/// authenticated call.
class VerifyOtpResult {
  /// Creates a result directly, without parsing.
  const VerifyOtpResult({required this.user, required this.token});

  /// Parses the `data` object.
  factory VerifyOtpResult.fromJson(Map<String, Object?> json) {
    return VerifyOtpResult(
      user: User.fromJson(jsonMap(json['user'])),
      token: TokenPair.fromJson(jsonMap(json['token'])),
    );
  }

  /// The caller's own `users` row, with `telepon_terverifikasi` now `true` and
  /// `status` promoted from `pending_verifikasi` to `aktif` on a first
  /// verification. The relations are **null**; see [RegisterResult.user].
  final User user;

  /// The freshly minted session.
  final TokenPair token;

  @override
  String toString() => 'VerifyOtpResult(user: ${user.namaLengkap}, $token)';
}

/// `data` of a `POST /auth/logout` response: what the server actually did.
///
/// Three booleans and a count, all of them **reports rather than commands**. A
/// client that clears its own storage on the strength of a 200 is trusting the
/// status code; this type lets it check that the refresh token really was
/// revoked. A `false` here is not an error -- it means the token was already gone,
/// which is exactly the state an idempotent sign-out should tolerate.
class LogoutResult {
  /// Creates a result directly, without parsing.
  const LogoutResult({
    required this.refreshDicabut,
    required this.accessDihapus,
    required this.perangkatDimatikan,
  });

  /// Parses the `data` object.
  factory LogoutResult.fromJson(Map<String, Object?> json) {
    return LogoutResult(
      refreshDicabut:
          jsonBool(jsonMap(json['refresh_token'])['dicabut']) ?? false,
      accessDihapus:
          jsonBool(jsonMap(json['access_token'])['dihapus']) ?? false,
      perangkatDimatikan: jsonInt(jsonMap(json['perangkat'])['dimatikan']) ?? 0,
    );
  }

  /// `data.refresh_token.dicabut` -- whether the presented refresh token was
  /// revoked by this call.
  ///
  /// The wire key is `dicabut`, the Indonesian participle for "revoked". It is not
  /// `dicabat`, and the sibling keys are `dihapus` and `dimatikan` -- three
  /// different Indonesian roots for the three things the endpoint reports.
  final bool refreshDicabut;

  /// `data.access_token.dihapus` -- whether the Sanctum access token this
  /// request arrived on was deleted.
  final bool accessDihapus;

  /// `data.perangkat.dimatikan` -- how many `user_devices` rows were
  /// deactivated. Broad by necessity; see [AuthApi.logout].
  final int perangkatDimatikan;

  @override
  String toString() =>
      'LogoutResult(refreshDicabut: $refreshDicabut, '
      'accessDihapus: $accessDihapus, perangkatDimatikan: $perangkatDimatikan)';
}

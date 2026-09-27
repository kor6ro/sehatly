import '../api/auth_api.dart';
import '../api/dokter_api.dart';
import '../api/me_api.dart';
import '../api/pasien_api.dart';
import '../api/transport.dart';
import '../core/api_exception.dart';
import '../core/pagination.dart';
import '../model/dto.dart';
import '../model/enums.dart';

/// A [SehatlyApiClient] stand-in for widget tests, with no network at all.
///
/// ## Why the interface is the same one
///
/// The point is that a test's fake and the real client are **interchangeable** at
/// the type level, so a widget that takes a `PasienApi` or a `DokterApi` is
/// handed this in a test and the real one in production with no change to the
/// widget. A fake behind a different interface would need an adapter in every
/// test, and the adapter is where the interesting mistakes happen.
///
/// This is not a mock framework. Every method returns a value the test
/// registered, or throws an [ApiException] the test registered, and both are
/// visible in a test failure message rather than hidden in a recorded interaction
/// log.
///
/// ## What it deliberately does not model
///
/// The refresh and storage machinery. There is no token to expire, no rotation
/// to coalesce and no `sessionExpired` to fire, so a fake that modelled them
/// would be testing the fake. Test those against the real client with a
/// [HttpClientAdapter] that counts, which is what `test/` does.
class FakeAuthApi implements AuthApi {
  /// Creates a fake that answers every method with an [ApiException].
  ///
  /// The default is "nothing is registered", which fails loudly. A fake that
  /// answered with a zero-valued object would let an unregistered call pass a
  /// test and produce a blank screen in the app, which is the failure this
  /// design is meant to prevent.
  FakeAuthApi({this.onUnregistered = _unregistered});

  /// Called when a method has no registered answer.
  ///
  /// Defaults to throwing an [ApiException] naming the method.
  final Never Function(String method) onUnregistered;

  RegisterResult? registerResult;
  LoginResult? loginResult;
  VerifyOtpResult? verifyOtpResult;
  TokenPair? refreshResult;
  LogoutResult? logoutResult;
  Paginated<UserDevice>? devicesResult;
  UserDevice? registerDeviceResult;
  UserDevice? removeDeviceResult;

  /// Registers the answer for [AuthApi.register].
  void onRegister(RegisterResult value) => registerResult = value;

  /// Registers the answer for [AuthApi.login].
  void onLogin(LoginResult value) => loginResult = value;

  /// Registers the answer for [AuthApi.verifyOtp].
  void onVerifyOtp(VerifyOtpResult value) => verifyOtpResult = value;

  /// Registers the answer for [AuthApi.refresh].
  void onRefresh(TokenPair value) => refreshResult = value;

  /// Registers the answer for [AuthApi.logout].
  void onLogout(LogoutResult value) => logoutResult = value;

  /// Registers the answer for [AuthApi.devices].
  void onDevices(Paginated<UserDevice> value) => devicesResult = value;

  /// Registers the answer for [AuthApi.registerDevice].
  void onRegisterDevice(UserDevice value) => registerDeviceResult = value;

  /// Registers the answer for [AuthApi.removeDevice].
  void onRemoveDevice(UserDevice value) => removeDeviceResult = value;

  @override
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
    return registerResult ?? onUnregistered('auth.register');
  }

  @override
  Future<LoginResult> login({
    required String password,
    String? noTelepon,
    String? email,
  }) async {
    return loginResult ?? onUnregistered('auth.login');
  }

  @override
  Future<VerifyOtpResult> verifyOtp({
    required String kode,
    required OtpTujuanDiterbitkan tujuan,
    String? noTelepon,
    String? email,
    String? deviceId,
  }) async {
    return verifyOtpResult ?? onUnregistered('auth.otp.verify');
  }

  @override
  Future<TokenPair> refresh(String refreshToken) async {
    return refreshResult ?? onUnregistered('auth.refresh');
  }

  @override
  Future<LogoutResult> logout(String refreshToken) async {
    return logoutResult ?? onUnregistered('auth.logout');
  }

  @override
  Future<Paginated<UserDevice>> devices() async {
    return devicesResult ?? onUnregistered('auth.devices');
  }

  @override
  Future<UserDevice> registerDevice({
    required String deviceId,
    required DevicePlatform platform,
    String? fcmToken,
    String? appVersi,
  }) async {
    return registerDeviceResult ?? onUnregistered('auth.devices.store');
  }

  @override
  Future<UserDevice> removeDevice(String deviceId) async {
    return removeDeviceResult ?? onUnregistered('auth.devices.destroy');
  }

  static Never _unregistered(String method) {
    throw ApiException(
      statusCode: 0,
      message:
          'FakeAuthApi.$method was called but no answer was registered. '
          'Register one with on${method.split('.').last}() before the widget '
          'under test can reach it.',
    );
  }
}

/// A [MeApi] stand-in for widget tests.
class FakeMeApi implements MeApi {
  /// Creates a fake, optionally with a pre-registered answer for [show].
  FakeMeApi({this.user});

  /// The value [show] returns.
  User? user;

  /// Registers the answer for [MeApi.show].
  void onShow(User value) => user = value;

  @override
  Future<User> show() async {
    final User? value = user;

    if (value == null) {
      throw const ApiException(
        statusCode: 0,
        message: 'FakeMeApi.show() was called but no answer was registered.',
      );
    }

    return value;
  }
}

/// A [PasienApi] stand-in for widget tests.
class FakePasienApi implements PasienApi {
  /// Creates a fake with every answer unset, so an unregistered call fails
  /// loudly rather than answering with a zero-valued object.
  FakePasienApi();

  PasienProfile? profilResult;
  PasienProfile? updateProfilResult;
  Paginated<AnggotaKeluarga>? anggotaKeluargaResult;
  AnggotaKeluarga? createAnggotaKeluargaResult;
  AnggotaKeluarga? updateAnggotaKeluargaResult;
  DeletedRow? deleteAnggotaKeluargaResult;
  Paginated<PasienAlergi>? alergiResult;
  PasienAlergi? createAlergiResult;
  PasienAlergi? updateAlergiResult;
  DeletedRow? deleteAlergiResult;

  /// Registers the answer for `profil`.
  void onProfil(PasienProfile value) => profilResult = value;

  /// Registers the answer for `updateProfil`.
  void onUpdateProfil(PasienProfile value) => updateProfilResult = value;

  /// Registers the answer for `anggotaKeluarga`.
  void onAnggotaKeluarga(Paginated<AnggotaKeluarga> value) =>
      anggotaKeluargaResult = value;

  /// Registers the answer for `createAnggotaKeluarga`.
  void onCreateAnggotaKeluarga(AnggotaKeluarga value) =>
      createAnggotaKeluargaResult = value;

  /// Registers the answer for `updateAnggotaKeluarga`.
  void onUpdateAnggotaKeluarga(AnggotaKeluarga value) =>
      updateAnggotaKeluargaResult = value;

  /// Registers the answer for `deleteAnggotaKeluarga`.
  void onDeleteAnggotaKeluarga(DeletedRow value) =>
      deleteAnggotaKeluargaResult = value;

  /// Registers the answer for `alergi`.
  void onAlergi(Paginated<PasienAlergi> value) => alergiResult = value;

  /// Registers the answer for `createAlergi`.
  void onCreateAlergi(PasienAlergi value) => createAlergiResult = value;

  /// Registers the answer for `updateAlergi`.
  void onUpdateAlergi(PasienAlergi value) => updateAlergiResult = value;

  /// Registers the answer for `deleteAlergi`.
  void onDeleteAlergi(DeletedRow value) => deleteAlergiResult = value;

  @override
  Future<PasienProfile> profil() async => _require(profilResult, 'profil');

  @override
  Future<PasienProfile> updateProfil(Map<String, Object?> body) async =>
      _require(updateProfilResult, 'updateProfil');

  @override
  Future<Paginated<AnggotaKeluarga>> anggotaKeluarga({
    PageQuery page = const PageQuery(),
  }) async {
    return _require(anggotaKeluargaResult, 'anggotaKeluarga');
  }

  @override
  Future<AnggotaKeluarga> createAnggotaKeluarga(
    Map<String, Object?> body,
  ) async => _require(createAnggotaKeluargaResult, 'createAnggotaKeluarga');

  @override
  Future<AnggotaKeluarga> updateAnggotaKeluarga(
    String id,
    Map<String, Object?> body,
  ) async {
    return _require(updateAnggotaKeluargaResult, 'updateAnggotaKeluarga');
  }

  @override
  Future<DeletedRow> deleteAnggotaKeluarga(String id) async =>
      _require(deleteAnggotaKeluargaResult, 'deleteAnggotaKeluarga');

  @override
  Future<Paginated<PasienAlergi>> alergi({
    PageQuery page = const PageQuery(),
  }) async => _require(alergiResult, 'alergi');

  @override
  Future<PasienAlergi> createAlergi(Map<String, Object?> body) async =>
      _require(createAlergiResult, 'createAlergi');

  @override
  Future<PasienAlergi> updateAlergi(
    String id,
    Map<String, Object?> body,
  ) async => _require(updateAlergiResult, 'updateAlergi');

  @override
  Future<DeletedRow> deleteAlergi(String id) async =>
      _require(deleteAlergiResult, 'deleteAlergi');

  T _require<T>(T? value, String method) {
    if (value == null) {
      throw ApiException(
        statusCode: 0,
        message:
            'FakePasienApi.$method() was called but no answer was '
            'registered.',
      );
    }

    return value;
  }
}

/// A [DokterApi] stand-in for widget tests.
class FakeDokterApi implements DokterApi {
  /// Creates a fake with every answer unset.
  FakeDokterApi();

  Paginated<DokterListing>? indexResult;
  DokterDetail? showResult;
  Paginated<MasterSpesialisasi>? spesialisasiResult;

  /// Registers the answer for `index`.
  void onIndex(Paginated<DokterListing> value) => indexResult = value;

  /// Registers the answer for `show`.
  void onShow(DokterDetail value) => showResult = value;

  /// Registers the answer for `spesialisasi`.
  void onSpesialisasi(Paginated<MasterSpesialisasi> value) =>
      spesialisasiResult = value;

  @override
  Future<Paginated<DokterListing>> index({
    String? spesialisasi,
    DokterTipe? tipe,
    String? search,
    bool? tersediaTelemedisin,
    PageQuery page = const PageQuery(),
  }) async {
    return _require(indexResult, 'index');
  }

  @override
  Future<DokterDetail> show(String dokterId) async =>
      _require(showResult, 'show');

  @override
  Future<Paginated<MasterSpesialisasi>> spesialisasi() async =>
      _require(spesialisasiResult, 'spesialisasi');

  T _require<T>(T? value, String method) {
    if (value == null) {
      throw ApiException(
        statusCode: 0,
        message:
            'FakeDokterApi.$method() was called but no answer was '
            'registered.',
      );
    }

    return value;
  }
}

import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:sehatly_api_client/sehatly_api_client.dart';

/// One request as it reached the transport.
///
/// The recorded [path] is the path **relative to the base URL**, which is what
/// the client actually sent, and [data] is the decoded request body when one was
/// sent. Recording the post-interceptor state is the point: a test that asserts
/// on the `Authorization` header or on a request body is asserting on what went
/// on the wire, not on what an endpoint class intended.
class RecordedRequest {
  /// Records one dispatched request.
  RecordedRequest({
    required this.method,
    required this.path,
    required this.headers,
    required this.data,
    required this.extra,
  });

  /// The HTTP method, upper-cased.
  final String method;

  /// The path relative to the base URL, e.g. `/me` or `/auth/refresh`.
  final String path;

  /// The headers, with keys lower-cased.
  final Map<String, Object?> headers;

  /// The decoded request body, or `null` when the request had none.
  final Object? data;

  /// The dio `extra` map as it was at dispatch time.
  final Map<String, Object?> extra;

  /// The `Authorization` header, or `null` when the request carried none.
  String? get authorization {
    for (final MapEntry<String, Object?> entry in headers.entries) {
      if (entry.key == 'authorization') {
        final Object? value = entry.value;

        return value is String ? value : null;
      }
    }

    return null;
  }

  /// The request body as a JSON object, or an empty map when there was none.
  Map<String, Object?> get body => jsonMap(data);

  @override
  String toString() => '$method $path';
}

/// A scripted [HttpClientAdapter] that records every dispatch.
///
/// ## Why the count lives here and not in the client
///
/// The single-flight invariant is about **requests that reached the network**, so
/// the thing that has to be counted is the transport. A counter inside
/// `RefreshCoordinator` would count the coordinator's own decisions, which is one
/// step earlier and would still read `1` if the coordinator decided to refresh
/// once and then somehow dispatched it twice. Counting at [fetch] is the only
/// place the claim is actually about the wire.
///
/// ## The `gate` is what makes the test a concurrency test
///
/// [gate] is a future every request waits on before it is answered. A test that
/// opens it only after all N requests have been *dispatched* guarantees that all
/// N 401s are outstanding at the same moment, rather than hoping a loop iteration
/// order produced overlap.
class ScriptedAdapter implements HttpClientAdapter {
  /// Creates an adapter that answers with [handler], or `{}` with 200 when none
  /// is given.
  ScriptedAdapter({this.handler});

  /// Answers a recorded request. `null` means "200 with an empty object".
  Future<ResponseBody> Function(RecordedRequest request)? handler;

  /// Every dispatch, in order.
  final List<RecordedRequest> requests = <RecordedRequest>[];

  /// The total number of dispatches.
  int get callCount => requests.length;

  /// Dispatches to [path].
  Iterable<RecordedRequest> to(String path) =>
      requests.where((RecordedRequest r) => r.path == path);

  /// How many dispatches reached [path].
  int countOf(String path) => to(path).length;

  /// Releases every request currently waiting on [gate].
  Completer<void>? _gate;

  /// Holds every response until the returned future completes.
  ///
  /// Set it to make N requests genuinely concurrent: open all N, await until
  /// [pendingCount] reaches N, then complete.
  Completer<void> get gate {
    final Completer<void>? existing = _gate;

    if (existing != null && !existing.isCompleted) {
      return existing;
    }

    final Completer<void> created = Completer<void>();
    _gate = created;

    return created;
  }

  /// How many dispatches are currently parked on [gate].
  int pendingCount = 0;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final RecordedRequest recorded = RecordedRequest(
      method: options.method.toUpperCase(),
      path: options.path,
      headers: <String, Object?>{
        for (final MapEntry<String, dynamic> entry in options.headers.entries)
          entry.key.toLowerCase(): entry.value,
      },
      data: options.data is String
          ? _tryDecode(options.data as String)
          : options.data,
      extra: Map<String, Object?>.from(options.extra),
    );

    requests.add(recorded);

    final Completer<void>? wait = _gate;

    if (wait != null && !wait.isCompleted) {
      pendingCount += 1;
      await wait.future;
    }

    final Future<ResponseBody> Function(RecordedRequest request)? answer =
        handler;

    if (answer == null) {
      return jsonResponse(<String, Object?>{}, 200);
    }

    return answer(recorded);
  }

  @override
  void close({bool force = false}) {}

  /// A 200 response carrying [body] as the project's success envelope.
  static ResponseBody jsonResponse(Map<String, Object?> body, int status) {
    return ResponseBody.fromString(
      jsonEncode(body),
      status,
      headers: <String, List<String>>{
        'content-type': <String>['application/json'],
      },
    );
  }

  /// A success envelope with [data] and no `meta` key at all.
  ///
  /// The absence is the point: `ApiResponse::success()` omits the key rather than
  /// sending `"meta": null`, and a fixture that sent `null` would not exercise the
  /// case this API actually produces.
  static ResponseBody successResponse(Object? data, {String message = 'OK'}) {
    return jsonResponse(<String, Object?>{
      'success': true,
      'data': data,
      'message': message,
    }, 200);
  }

  /// A success envelope with a pagination block, as every list endpoint sends.
  static ResponseBody paginatedResponse(
    Object? data, {
    required int currentPage,
    required int lastPage,
    required int perPage,
    required int total,
    int? from,
    int? to,
    String message = 'OK',
  }) {
    return jsonResponse(<String, Object?>{
      'success': true,
      'data': data,
      'message': message,
      'meta': <String, Object?>{
        'current_page': currentPage,
        'last_page': lastPage,
        'per_page': perPage,
        'total': total,
        'from': from,
        'to': to,
      },
    }, 200);
  }

  /// A failure envelope, exactly as `ApiResponse::error()` renders it.
  ///
  /// `errors` is passed through as a JSON object so an empty map encodes as `{}`,
  /// which is what the server's `(object)` cast is for.
  static ResponseBody errorResponse(
    String message,
    int status, {
    Map<String, Object?> errors = const <String, Object?>{},
  }) {
    return jsonResponse(<String, Object?>{
      'success': false,
      'message': message,
      'errors': errors,
    }, status);
  }

  static Object? _tryDecode(String raw) {
    try {
      return jsonDecode(raw);
    } on FormatException {
      return raw;
    }
  }
}

/// An in-package stand-in for a `flutter_secure_storage` adapter.
///
/// It exists so the tests can construct [SehatlyApiClient.secure] -- whose
/// parameter is typed [SecureKeyValueBackend], so a [PlainKeyValueBackend] will
/// not compile -- and assert exactly which keys were written. It is a test double,
/// not a shipping backend: see the class docblock on [SecureKeyValueBackend] for
/// the four properties a real implementation must uphold, none of which this one
/// can.
class FakeSecureBackend implements SecureKeyValueBackend {
  /// Creates a backend, optionally pre-seeded with [initial] contents.
  FakeSecureBackend([Map<String, String>? initial])
    : values = <String, String>{...?initial};

  /// The current contents.
  final Map<String, String> values;

  /// Every key ever written, in order, for an ordering assertion.
  final List<String> writeOrder = <String>[];

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async {
    values[key] = value;
    writeOrder.add(key);
  }

  @override
  Future<void> delete(String key) async {
    values.remove(key);
  }

  @override
  Future<void> deleteAll() async {
    values.clear();
  }

  @override
  String get description => 'FakeSecureBackend (test double)';
}

/// A valid [TokenPair] fixture, with overridable fields.
TokenPair tokenPair({
  String accessToken = 'access-1',
  String refreshToken = 'refresh-1',
  int expiresIn = 1439,
  String accessExpiresAt = '2099-01-01T00:00:00.000000Z',
  String refreshExpiresAt = '2099-02-01T00:00:00.000000Z',
}) {
  return TokenPair.fromJson(<String, Object?>{
    'token_type': 'Bearer',
    'access_token': accessToken,
    'expires_in': expiresIn,
    'access_token_expires_at': accessExpiresAt,
    'refresh_token': refreshToken,
    'refresh_token_expires_at': refreshExpiresAt,
  });
}

/// A valid `UserResource` fixture.
Map<String, Object?> userJson({
  int id = 7,
  String namaLengkap = 'Siti Aminah',
  String tipe = 'pasien',
  String status = 'aktif',
  bool withPasien = true,
  bool withDokter = false,
}) {
  // Masked by NikMasker: first four, U+2022 bullets, last four. Never raw.
  // Bound to a local because `nik` and `nomor_kk` are the same mask of the same
  // value -- deriving it twice in place is how one of them drifts.
  final String maskedNik = '3273${'\u2022' * 8}0021';

  return <String, Object?>{
    'id': id,
    'uuid': '0f6c1a5e-0000-4000-8000-000000000001',
    'nama_lengkap': namaLengkap,
    'no_telepon': '08123456789',
    'email': 'siti@example.test',
    'tipe': tipe,
    'status': status,
    'bahasa': 'id',
    'foto_profil': null,
    'telepon_terverifikasi': true,
    'email_terverifikasi': false,
    'last_login_at': '2026-01-02T03:04:05.000000Z',
    'dibuat_at': '2026-01-01T00:00:00.000000Z',
    if (withPasien)
      'pasien': <String, Object?>{
        'id': 11,
        'nomor_rm': 'RM-202601-000011',
        'nik': maskedNik,
        'nomor_kk': maskedNik,
        'nama_lengkap': namaLengkap,
        'jenis_kelamin': 'P',
        'tanggal_lahir': '1990-05-17',
        'tempat_lahir': 'Bandung',
        'golongan_darah_id': 2,
        'rhesus': 'positif',
        'agama_id': 1,
        'pendidikan_id': 3,
        'pekerjaan': 'Guru',
        'status_pernikahan_id': 2,
        'alamat_lengkap': 'Jl. Merdeka No. 1',
        'provinsi_id': 9,
        'kabupaten_kota_id': 22,
        'kecamatan_id': 101,
        'kelurahan_id': 1001,
        'rt': '01',
        'rw': '02',
        'kode_pos': '40115',
        'tinggi_badan_cm': 158.0,
        'berat_badan_kg': 54.5,
        'is_meninggal': false,
        'tanggal_meninggal': null,
        'dibuat_at': '2026-01-01T00:00:00.000000Z',
        'diubah_at': '2026-01-01T00:00:00.000000Z',
      },
    if (withDokter)
      'dokter': <String, Object?>{
        'id': 3,
        'tipe': 'dokter_umum',
        'nomor_ihs_satusehat': null,
        'pengalaman_tahun': 8,
        'bio': null,
        'biaya_konsultasi_online': 50000.0,
        'biaya_luar_jam': 75000.0,
        'durasi_default_menit': 15,
        'rating_rata_rata': 4.5,
        'jumlah_ulasan': 12,
        'jumlah_konsultasi': 140,
        'tersedia_telemedisin': true,
        'status_verifikasi': 'terverifikasi',
        'status_aktif': true,
        'spesialisasi': <Object?>[
          <String, Object?>{
            'spesialisasi_id': 1,
            'kode': 'UMUM',
            'nama': 'Dokter Umum',
            'tipe': 'dokter_umum',
            'is_utama': true,
          },
        ],
        'pendidikan': <Object?>[
          <String, Object?>{
            'jenjang': 'profesi',
            'institusi': 'Universitas Indonesia',
            'tahun_lulus': 2015,
          },
        ],
        'dibuat_at': '2025-01-01T00:00:00.000000Z',
        'diubah_at': '2025-06-01T00:00:00.000000Z',
      },
  };
}

/// A `v_dokter_katalog` list-row fixture, exactly as `DokterResource` publishes it.
Map<String, Object?> dokterListingJson({
  int id = 3,
  String namaLengkap = 'Budi Santoso',
  String tipe = 'dokter_umum',
  String? spesialisasi = 'Dokter Umum',
}) {
  return <String, Object?>{
    'id': id,
    'nama_lengkap': namaLengkap,
    'tipe': tipe,
    // GROUP_CONCAT string on the list projection, not an array.
    'spesialisasi': spesialisasi,
    'biaya_konsultasi_online': 50000.0,
    'rating_rata_rata': 4.5,
    'jumlah_konsultasi': 140,
    'status_verifikasi': 'terverifikasi',
  };
}

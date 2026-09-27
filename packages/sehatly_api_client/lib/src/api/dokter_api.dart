import '../core/api_envelope.dart';
import '../core/json.dart';
import '../core/pagination.dart';
import '../model/dto.dart';
import '../model/enums.dart';
import 'paths.dart';
import 'transport.dart';

/// The public doctor directory and the reference lists that filter it.
///
/// ## Both routes are unauthenticated on purpose
///
/// The plan says so, and `RbacCatalog` is what makes it the only workable
/// answer: `dokter.lihat` **is** a real permission code, but `permission:`
/// resolves through a guard that answers 401 for an anonymous caller, so the
/// gate would 401 every visitor who has not registered yet -- the opposite of a
/// directory. It would also 403 `perawat` and `kurir`, who are real `users.tipe`
/// ENUM members that hold no role and therefore no grant at all.
///
/// So `dokter.lihat` is the wrong vocabulary *here*. It belongs on an
/// administrative directory that lists unverified, inactive and STR-expired
/// doctors for an operator to renew or suspend, and that endpoint must be
/// authenticated and must not reuse this query, because its whole purpose is to
/// bypass the eligibility rules.
class DokterApi {
  /// Creates the endpoint group over [transport].
  const DokterApi(this._transport);

  final ApiTransport _transport;

  /// `GET /api/v1/dokter`
  ///
  /// Filters: `spesialisasi` (a `master_spesialisasi.kode` or an id), `tipe` (a
  /// [DokterTipe] member), `search`, `tersedia_telemedisin`. Ordering is
  /// `rating_rata_rata DESC`, then `jumlah_konsultasi DESC`, then `dokter_id ASC`
  /// -- the two plan keys plus a unique tiebreaker, so the order is total.
  /// `per_page` is capped at 100 server-side and **clamped rather than
  /// rejected**, so the truth is [ApiMeta.perPage] in the response.
  ///
  /// ## The pagination block is in the envelope's top-level `meta`
  ///
  /// This is verified against the current server, not assumed:
  /// `DokterController::index()` passes `ApiResponse::pageMeta($paginator)` as
  /// the fourth argument to `ApiResponse::success()`, and
  /// `tests/Feature/Dokter/DokterDirectoryTest.php` asserts `meta.total`,
  /// `meta.per_page`, `meta.last_page`, `meta.current_page`, `meta.from` and
  /// `meta.to` on this route.
  ///
  /// The plan's todo 24 prose states the opposite -- that `/dokter` is the one
  /// exception carrying its pagination inside `data` because it predates the
  /// `meta` key. That described an earlier revision and is not true now; the
  /// same migration is recorded for `GET /auth/devices` in `AuthController`'s own
  /// `devicesIndex()` docblock, which used to answer
  /// `data: {devices: [...], total: N}`. `Paginated.fromEnvelope` still reads the
  /// `data`-embedded shape as a fallback; it is a documented fallback, not the
  /// expected path. See `Paginated`'s class docblock.
  Future<Paginated<DokterListing>> index({
    String? spesialisasi,
    DokterTipe? tipe,
    String? search,
    bool? tersediaTelemedisin,
    PageQuery page = const PageQuery(),
  }) async {
    final Map<String, Object?> query = <String, Object?>{
      ...page.toQueryParameters(),
      'spesialisasi': ?spesialisasi,
      if (tipe != null) 'tipe': tipe.wire,
      'search': ?search,
      'tersedia_telemedisin': ?tersediaTelemedisin,
    };

    final ApiEnvelope<Object?> envelope = await _transport.get<Object?>(
      pathDokter,
      queryParameters: query,
      parseData: parseDataObject,
      anonymous: true,
    );

    return Paginated<DokterListing>.fromEnvelope(
      data: envelope.data,
      meta: envelope.meta,
      key: 'dokter',
      itemParser: DokterListing.fromJson,
    );
  }

  /// `GET /api/v1/dokter/{dokter}`
  ///
  /// A 404 for a doctor who does not exist **and** for one who is not eligible
  /// -- unverified, inactive, or STR-expired -- because the two are deliberately
  /// indistinguishable to a public caller. Distinguishing them would let an
  /// anonymous caller enumerate which doctors exist but have not been vetted.
  ///
  /// The segment is a [String] and is never bound to a model; the server's own
  /// docblock records that a `Dokter` type-hint would make Laravel resolve the
  /// model and bypass both eligibility rules.
  ///
  /// This is the endpoint that carries `jumlah_ulasan` and
  /// `durasi_default_menit`; the list projection omits both because the view
  /// behind it does not select them, and a `null` there would have meant "no
  /// reviews" rather than "not reported".
  Future<DokterDetail> show(String dokterId) async {
    final ApiEnvelope<Object?> envelope = await _transport.get<Object?>(
      pathDokterDetail(dokterId),
      parseData: parseDataObject,
      anonymous: true,
    );

    return DokterDetail.fromJson(jsonMap(envelope.dataMap['dokter']));
  }

  /// `GET /api/v1/master-spesialisasi`
  ///
  /// The reference list for the `spesialisasi` filter: a pure read of a 16-row
  /// master table, added by the server's todo 22 beyond the plan's endpoint
  /// table.
  ///
  /// It answers the **degenerate** single-page block -- `current_page = 1`,
  /// `last_page = 1` -- so [Paginated.isPaginated] is `true` and
  /// [Paginated.hasNextPage] is `false`. That is the truth about a 16-row table
  /// rather than a special case, and it is what lets a client parse one list
  /// envelope for every list endpoint.
  ///
  /// [MasterSpesialisasi.tipe] is `master_spesialisasi.tipe`, which shares
  /// **exactly one** member with `dokter.tipe`. Populating a `dokter.tipe`
  /// dropdown from this response is a vocabulary error; the two are separate Dart
  /// enums so it does not compile. See [MasterSpesialisasiTipe].
  Future<Paginated<MasterSpesialisasi>> spesialisasi() async {
    final ApiEnvelope<Object?> envelope = await _transport.get<Object?>(
      pathMasterSpesialisasi,
      parseData: parseDataObject,
      anonymous: true,
    );

    return Paginated<MasterSpesialisasi>.fromEnvelope(
      data: envelope.data,
      meta: envelope.meta,
      key: 'spesialisasi',
      itemParser: MasterSpesialisasi.fromJson,
    );
  }
}

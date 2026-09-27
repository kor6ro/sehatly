import 'json.dart';

/// The project-wide pagination block, derived by `ApiResponse::pageMeta()`.
///
/// ```json
/// {"current_page": 2, "last_page": 5, "per_page": 15, "total": 68, "from": 16, "to": 30}
/// ```
///
/// Six keys, and the three the plan names are the first three. The other three
/// are the reason this is a class and not a `Map<String, dynamic>`: a client
/// rendering "16-30 of 68" needs `from`, `to` and `per_page`, and the server
/// derives them in exactly one place so that no two controllers can spell them
/// differently.
///
/// ## `per_page` is the page size that was APPLIED, not the one requested
///
/// Every list endpoint caps `per_page` at 100. A request for `per_page=500`
/// answers `per_page: 100`, so this value is the truth about the current
/// response and is what a paging control has to render. Deriving the size from
/// the request instead is how a pager ends up claiming 500 rows per page over a
/// body that holds 100.
///
/// ## `from` and `to` are `null` on an empty page, never `0`
///
/// "No rows" has no first row and no last row. The server publishes `null`
/// there, and this class preserves it rather than defaulting to `0`, because
/// `from: 0` renders as "0-0 of 0" and reads as a bug to a user.
class ApiMeta {
  /// Creates a pagination block directly, without parsing.
  const ApiMeta({
    required this.currentPage,
    required this.lastPage,
    required this.perPage,
    required this.total,
    this.from,
    this.to,
  });

  /// Parses a `meta` object.
  ///
  /// The four required keys are read leniently, because a paginator that
  /// answers with fewer than six keys must still yield a usable block: a missing
  /// `current_page` reads as `1` and a missing `total` as `0`, which is the
  /// honest reading of "the server did not say how many there are" for a client
  /// that is about to render a list either way. `from` and `to` stay `null` when
  /// absent, matching the server's own treatment of an empty page.
  factory ApiMeta.fromMap(Map<String, Object?> json) {
    return ApiMeta(
      currentPage: jsonInt(json['current_page']) ?? 1,
      lastPage: jsonInt(json['last_page']) ?? 1,
      perPage: jsonInt(json['per_page']) ?? 0,
      total: jsonInt(json['total']) ?? 0,
      from: jsonInt(json['from']),
      to: jsonInt(json['to']),
    );
  }

  /// Parses a `meta` object, or returns `null` when [json] holds no block.
  ///
  /// The presence test is "does this object carry pagination keys at all", not
  /// "is this object non-empty". A `meta` that exists but is `{}` is a
  /// contract violation rather than an absent block, and reporting it as absent
  /// is the softer failure; both readings render the items, which is the part
  /// that matters.
  static ApiMeta? tryParse(Map<String, Object?>? json) {
    if (json == null) {
      return null;
    }

    return ApiMeta.fromMap(json);
  }

  /// The 1-based page this response holds.
  final int currentPage;

  /// The 1-based number of the last page.
  final int lastPage;

  /// The page size the server actually applied, after its 100-row cap.
  final int perPage;

  /// The total number of rows across every page.
  final int total;

  /// The 1-based index of the first row on this page, or `null` when empty.
  final int? from;

  /// The 1-based index of the last row on this page, or `null` when empty.
  final int? to;

  /// Whether another page exists after this one.
  bool get hasNextPage => currentPage < lastPage;

  /// Whether a page exists before this one.
  bool get hasPreviousPage => currentPage > 1;

  /// The page number to request next, or `null` when this is the last page.
  int? get nextPage => hasNextPage ? currentPage + 1 : null;

  /// The page number to request previous, or `null` when this is the first page.
  int? get previousPage => hasPreviousPage ? currentPage - 1 : null;

  /// The number of rows on this page, computed from the block itself.
  ///
  /// Preferred over [items].length for a *degenerate* single-page list, where
  /// the two agree anyway, and equal to it for a real page. Kept so a caller
  /// rendering "1-15 of 47" does not have to re-derive the arithmetic.
  int get pageRowCount => (to ?? 0) - (from ?? 0) + 1;

  @override
  String toString() {
    return 'ApiMeta(page $currentPage/$lastPage, perPage: $perPage, total: $total)';
  }
}

/// A page of items plus the block that describes it, when there is one.
///
/// ## `meta` is nullable on purpose, and that is the tolerance the plan asks for
///
/// A non-list response carries **no** `meta` key at all (see `ApiEnvelope`), and
/// a list response that is deliberately not paged -- `GET /auth/devices` and
/// `GET /master-spesialisasi` -- carries the degenerate block
/// `current_page = 1, last_page = 1`. So there are three real states and this
/// type holds all of them:
///
/// | response | [isPaginated] | what a caller does |
/// | --- | --- | --- |
/// | single resource, no `meta` | `false` | render [items] alone |
/// | a real page | `true` | render [items] plus a pager from [meta] |
/// | an unpaged list, degenerate `meta` | `true`, [hasNextPage] false | render [items]; a pager would be one disabled button |
///
/// Collapsing the first two into "empty meta" would make a client render
/// "1-1 of 0" on a profile screen, and collapsing the third into the first
/// would make `GET /auth/devices` look like it lost its rows.
///
/// ## Where the block is read from, and the `data`-embedded fallback
///
/// The block is read from the envelope's top-level `meta`. That is where the
/// server puts it for **every** Module 1 list endpoint, `GET /api/v1/dokter`
/// included: `DokterController::index()` calls
/// `ApiResponse::pageMeta($paginator)` and passes it as the fourth argument to
/// `ApiResponse::success()`, and `tests/Feature/Dokter/DokterDirectoryTest.php`
/// asserts `meta.total`, `meta.per_page`, `meta.last_page`, `meta.current_page`,
/// `meta.from` and `meta.to` on that route.
///
/// The plan's todo 24 prose asserts the opposite -- that `/api/v1/dokter` is an
/// exception that carries its pagination inside `data` because the endpoint
/// predates the `meta` key. That was true of an earlier revision of this
/// codebase and is **not** true of the current one: `AuthController`'s
/// `devicesIndex()` docblock records the same migration for `/auth/devices`,
/// which used to answer `data: {devices: [...], total: N}`.
///
/// [fromDataFallback] still implements the `data`-embedded shape, because a
/// client that cannot read the older shape is worse than one that reads both,
/// and because the migration is not frozen. It is a documented fallback, not
/// the expected path.
class Paginated<T> {
  /// Creates a page directly, without parsing.
  const Paginated({required this.items, this.meta}) : _single = null;

  /// Reads a list out of [data] and a block out of the envelope.
  ///
  /// [key] is the `data` member holding the rows, which is *not* uniform across
  /// this API: `devices`, `dokter`, `spesialisasi`, `alergi` and
  /// `anggota_keluarga`. [meta] is the envelope's `meta`, which may be `null`.
  /// [itemParser] converts one row.
  ///
  /// A missing [key] yields an empty [items] rather than a throw, so a caller
  /// that asked for a page of doctors and got a doctor-shaped object by mistake
  /// sees an empty list rather than an unhandled cast.
  factory Paginated.fromEnvelope({
    required Object? data,
    required Map<String, Object?>? meta,
    required String key,
    required T Function(Map<String, Object?> json) itemParser,
  }) {
    final Map<String, Object?> dataMap = jsonMap(data);
    final ApiMeta? parsed = ApiMeta.tryParse(meta) ?? fromDataFallback(dataMap);

    return Paginated<T>(
      items: jsonList(dataMap[key])
          .map((Object? row) => itemParser(jsonMap(row)))
          .toList(growable: false),
      meta: parsed,
    );
  }

  /// Creates a page with an explicit list and no block.
  ///
  /// For the endpoints whose `data` holds a single object rather than a list, so
  /// a call site can return a `Paginated` uniformly and let [isPaginated] carry
  /// the "this was not a list endpoint" information.
  const Paginated.single(T item)
    : items = const <Never>[],
      meta = null,
      _single = item;

  /// The rows on this page.
  final List<T> items;

  /// The block describing the page, or `null` when the response carried none.
  final ApiMeta? meta;

  /// A single-item payload, present only for [Paginated.single].
  ///
  /// Kept out of the public surface deliberately; read it through
  /// [singleOrNull], which is the honest accessor for a value that is only ever
  /// set by one of the two constructors.
  final T? _single;

  /// Whether this response carried a pagination block.
  bool get isPaginated => meta != null;

  /// The number of rows on this page.
  int get length => items.length;

  /// Whether this page holds no rows.
  bool get isEmpty => items.isEmpty;

  /// Whether this page holds at least one row.
  bool get isNotEmpty => items.isNotEmpty;

  /// The first row.
  ///
  /// Throws a [StateError] on an empty page, which is the SDK's own
  /// [List.first] behaviour and is better here than a nullable row that a
  /// caller would have to null-check on every access.
  T get first => items.first;

  /// The single payload, or `null` when this is a real page.
  ///
  /// The reason `items` is empty for a [Paginated.single] rather than holding
  /// the one element: a single-resource endpoint is not a list, and letting it
  /// report `length == 1` would make `isPaginated` and `items` disagree about
  /// what kind of thing came back.
  T? get singleOrNull => _single;

  /// [meta], or a synthetic single-page block for an unpaginated response.
  ///
  /// Lets a caller render a uniform "showing N" footer without branching on
  /// [isPaginated], on a response that genuinely has every row it will ever
  /// have. The synthetic block is truthful: page 1 of 1, [length] rows total.
  ApiMeta get effectiveMeta => meta ?? _syntheticSinglePage();

  @override
  String toString() {
    return 'Paginated<$T>(${items.length} rows, paginated: $isPaginated)';
  }

  ApiMeta _syntheticSinglePage() {
    return ApiMeta(
      currentPage: 1,
      lastPage: 1,
      perPage: items.length,
      total: items.length,
      from: items.isEmpty ? null : 1,
      to: items.isEmpty ? null : items.length,
    );
  }

  /// Reads a pagination block out of the `data` object itself.
  ///
  /// This is the **fallback** path, for a response shaped like the pre-`meta`
  /// revision of this API: `data: {dokter: [...], current_page: 1, ...}`. It
  /// activates only when the envelope carried no `meta` **and** the `data`
  /// object actually contains the pagination keys, so it cannot shadow a real
  /// `meta` block.
  ///
  /// Returning `null` for a `data` object that simply has no pagination keys is
  /// what keeps this from inventing a block for every single-resource response:
  /// [effectiveMeta] exists for that case, and fabricating one here would make
  /// [isPaginated] true for `GET /api/v1/me`.
  static ApiMeta? fromDataFallback(Map<String, Object?> data) {
    const List<String> keys = <String>[
      'current_page',
      'last_page',
      'per_page',
      'total',
      'from',
      'to',
    ];

    final bool carriesAny = keys.any(data.containsKey);

    if (!carriesAny) {
      return null;
    }

    return ApiMeta.fromMap(data);
  }
}

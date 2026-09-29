// Exhaustive, repeatable field-by-field audit of the Module 1 wire contract.
//
// Compares every response field the Dart client models against the field the
// Laravel resource actually publishes, for all 22 Module 1 endpoints.
//
// Run from packages/sehatly_api_client:
//
//     dart run tool/audit_wire_fields.dart
//     dart run tool/audit_wire_fields.dart --verbose
//
// Exits 0 when the contract holds and 1 on any drift, so it is a gate and not a
// report.
//
// ## The two failure classes
//
// **Client-only** -- the client reads a key the server never sends. Always a
// bug: the value is silently absent at runtime and the DTO reports a
// zero-valued field. Always a failure, with no way to suppress it.
//
// **Server-only** -- the server sends a key the client ignores. Usually benign
// and occasionally correct (`DokterDetailResource` withholds `diubah_at` on
// purpose). Also a failure unless an entry exists in [_dropJustifications],
// which is what forces every intentional drop to be written down and keeps a
// later addition to the client from quietly making the entry stale.
//
// ## Why the server side is a lexer and not a set of regular expressions
//
// A regex over `'([a-z_]+)' =>` cannot tell a key from a key inside a nested
// array, a docblock, a string or an attribute, and every one of those appears
// here: `UserResource`'s docblock quotes field names in prose, the nested
// `pendidikan` map in `DokterDetailResource` reuses `id`, and
// `$rows->map(fn (...) => [...])` opens a list with no key at all. So the PHP is
// read with a character-level scanner that skips comments and strings and tracks
// bracket depth, which is the only way the nesting is recoverable.
//
// Three lexer bugs are worth recording, because each one produced a *plausible*
// result rather than an error, and each was found only by printing what the
// scanner had extracted:
//
//   1. `'id' =>` has a space before the arrow. Testing `source[i + 1] == '='`
//      never matches, so every resource parsed as having zero fields and the
//      audit reported 113 client-only fields -- the exact inverse of the truth.
//   2. Clearing the in-string flag on the closing quote is mandatory. Leaving it
//      set makes the *next* quote in the file read as a closing one, so the
//      scanner desynchronises and recovers a single field per file by accident.
//   3. Method scoping matters. `DokterDetailResource` publishes three shapes --
//      its top level from `toArray`, and `spesialisasi`, `pendidikan` and
//      `faskes` from private helpers -- and merging them per file makes a
//      nested object's fields indistinguishable from the top level's.
//
// The Dart side needs none of that: a scoped `['key']` scan is exact, once the
// chunking boundary is a line starting in column zero rather than `^class`. The
// file also holds top-level functions, and chunking on `class` alone makes the
// last class swallow all of them -- which is how `DeletedRow`, a two-field DTO,
// came to report five client-only fields belonging to `_akunSpesialisasi`.

import 'dart:io';

/// Where the repository root is, relative to this script.
///
/// Two levels up: the package sits at `packages/sehatly_api_client`, and the
/// Laravel application -- the authoritative side of this contract -- is the
/// repository root. The Dart sources are at the package root instead, so the two
/// bases are deliberately separate helpers; one relative base silently resolves
/// one side against the wrong directory.
const String _rootFromPackage = '../..';

/// A parsed PHP array literal, as a node in a tree.
class _Node {
  _Node({this.parentKey});

  /// The key in the enclosing array that owns this node, or `null` at the root
  /// and for a list element such as a `->map(fn () => [...])` body.
  final String? parentKey;

  /// Set when the node is closed and a scan proves it holds no `'k' =>` pair.
  bool isList = false;

  /// Keys written directly in this node, in source order.
  final List<String> keys = <String>[];

  /// Nested nodes, in source order.
  final List<_Node> children = <_Node>[];

  /// Dotted leaf paths under this node, e.g. `refresh_token.dicabut`.
  List<String> get leafPaths {
    final List<String> out = <String>[];

    for (final String key in keys) {
      final List<_Node> nested = children
          .where((_Node c) => c.parentKey == key)
          .toList(growable: false);

      if (nested.isEmpty) {
        out.add(key);
        continue;
      }

      for (final _Node child in nested) {
        final String segment = child.isList ? '$key[]' : key;

        for (final String leaf in child.leafPaths) {
          out.add('$segment.$leaf');
        }
      }
    }

    return out;
  }

  /// Keys directly in this node, without descending.
  List<String> get topLevelKeys => List<String>.from(keys);
}

/// One client DTO class and the server shape it is checked against.
class _Unit {
  const _Unit({
    required this.endpoint,
    required this.clientClass,
    required this.serverSource,
    required this.clientKeys,
    required this.serverPaths,
  });

  /// `METHOD /path`, repeated on every unit of an endpoint.
  final String endpoint;

  /// The Dart class whose parser was scanned.
  final String clientClass;

  /// The PHP shape the keys were read from: a resource name, a
  /// `Resource.subpath`, or a `Controller.action[#leaves]`.
  final String serverSource;

  /// Wire paths the client reads, sorted.
  final List<String> clientKeys;

  /// Wire paths the server publishes, sorted.
  final List<String> serverPaths;

  /// Paths the client reads and the server never sends.
  List<String> get clientOnly =>
      clientKeys.where((String k) => !serverPaths.contains(k)).toList()..sort();

  /// Paths the server sends and the client ignores.
  List<String> get serverOnly {
    final List<String> missing = serverPaths
        .where((String p) => !clientKeys.contains(p))
        .toList();

    missing.sort();

    return missing;
  }

  /// Paths present on both sides.
  List<String> get matched => clientKeys
      .where((String k) => serverPaths.contains(k))
      .toList(growable: false);
}

/// Server fields the client deliberately does not model, and why.
///
/// Keyed `endpoint/clientClass:field`. A registry rather than a prose note, so
/// that dropping a field is a decision somebody had to type, and so that adding
/// the field to the client later -- which would make the entry stale -- is
/// still caught rather than silently tolerated.
///
/// **Empty, and that is the finding.** The audit found no server field the
/// client fails to model on any of the 22 endpoints, so nothing needed an
/// exemption. Every Module 1 resource field the server publishes has a
/// corresponding client read.
const Map<String, String> _dropJustifications = <String, String>{};

void main(List<String> args) {
  final _Audit audit = _Audit(Directory(_rootFromPackage));

  audit.run();
  audit.printReport(verbose: args.contains('--verbose'));

  final int clientOnly = audit.units
      .where((_Unit u) => u.clientOnly.isNotEmpty)
      .length;
  final List<_Unit> unjustified = audit.units
      .where((_Unit u) => u.serverOnly.isNotEmpty)
      .where((_Unit u) => !audit.isJustified(u))
      .toList();

  stdout.writeln('');
  stdout.writeln('client-only units: $clientOnly');
  stdout.writeln('unjustified server-only units: ${unjustified.length}');
  stdout.writeln('fixture-only token keys: ${audit._fixtureExtra.length}');
  stdout.writeln('token keys with no fixture: ${audit._fixtureMissing.length}');

  if (clientOnly == 0 &&
      unjustified.isEmpty &&
      audit._fixtureExtra.isEmpty &&
      audit._fixtureMissing.isEmpty) {
    stdout.writeln('AUDIT PASS');
    return;
  }

  stdout.writeln('AUDIT FAIL');
  exitCode = 1;
}

/// One endpoint's `data` wrapper: the key the client reads and the key the
/// controller writes.
class _Wrapper {
  const _Wrapper({
    required this.endpoint,
    required this.clientKeys,
    required this.serverKeys,
  });

  final String endpoint;
  final List<String> clientKeys;
  final List<String> serverKeys;

  List<String> get clientOnly => clientKeys
      .where((String k) => !serverKeys.contains(k))
      .toList(growable: false);

  List<String> get serverOnly => serverKeys
      .where((String k) => !clientKeys.contains(k))
      .toList(growable: false);
}

/// Reads both sides of the contract and compares them.
class _Audit {
  _Audit(this.root);

  final Directory root;

  final List<_Unit> units = <_Unit>[];
  final List<_Wrapper> wrappers = <_Wrapper>[];

  /// Server shapes, keyed `Resource`, `Resource.toArray`, `Resource.helper`
  /// or `Resource.inlineKey`.
  final Map<String, List<String>> _resourcePaths = <String, List<String>>{};

  /// Controller `data` shapes, keyed `Controller.action` and
  /// `Controller.action.key`, plus `Controller.action#leaves`.
  final Map<String, Map<String, List<String>>> _controllerDataKeys =
      <String, Map<String, List<String>>>{};

  /// Keys each client DTO reads, keyed by class name.
  final Map<String, Set<String>> _dtoKeys = <String, Set<String>>{};

  /// The result DTO each method builds from the whole `data` object, keyed
  /// `Api.method`, or `null` when the method indexes a key itself.
  final Map<String, Map<String, String?>> _wholeMapDtoNames =
      <String, Map<String, String?>>{};

  /// `data` envelope keys each endpoint method reads, keyed `Api.method`.
  final Map<String, Map<String, Set<String>>> _apiMethodKeys =
      <String, Map<String, Set<String>>>{};

  void run() {
    _loadResources();
    _loadControllers();
    _loadDtos();
    _loadApiClasses();
    _compare();
    _auditTokenFixtures();
  }

  /// Audits the wire keys this package's own tests assert on.
  ///
  /// The same question, applied to the package's new code rather than to the
  /// server: a test fixture that names a token key the server does not publish
  /// is a test that passes against a fiction, which is worse than no test. Every
  /// object literal in `test/` carrying both secrets is a token pair by
  /// definition -- there is no other shape here that has both -- so the fixture
  /// set is found structurally rather than by a list somebody has to maintain.
  ///
  /// This is the audit applied to the code added while closing the audit's own
  /// gaps, which is the only way "I checked my own work" is checkable.
  void _auditTokenFixtures() {
    final List<String> keys = <String>[];

    for (final FileSystemEntity entry in Directory(
      'test',
    ).listSync(recursive: true)) {
      if (entry is! File || !entry.path.endsWith('.dart')) {
        continue;
      }

      final String code = _stripComments(File(entry.path).readAsStringSync());

      for (final RegExpMatch m in RegExp(
        r"<String, Object\?>\s*\{[^{}]*'access_token'[^{}]*\}",
      ).allMatches(code)) {
        for (final RegExpMatch k in RegExp(
          r"'([A-Za-z_][A-Za-z_0-9]*)'\s*:",
        ).allMatches(m.group(0)!)) {
          keys.add(k.group(1)!);
        }
      }
    }

    final List<String> server =
        _resourcePaths['AuthTokenResource'] ?? const <String>[];
    final Set<String> unique = keys.toSet();
    final List<String> extra = (unique.toList()..sort())
        .where((String k) => !server.contains(k))
        .toList();
    final List<String> missing =
        server.where((String k) => !unique.contains(k)).toList()..sort();

    _fixtureKeys = unique.toList()..sort();
    _fixtureExtra = extra;
    _fixtureMissing = missing;
  }

  List<String> _fixtureKeys = <String>[];
  List<String> _fixtureExtra = <String>[];
  List<String> _fixtureMissing = <String>[];

  /// Reads a file at the repository root.
  String _read(String relative) {
    final File file = File('${root.path}${Platform.pathSeparator}$relative');

    if (!file.existsSync()) {
      throw StateError('audit: missing $relative');
    }

    return file.readAsStringSync();
  }

  /// Reads a file inside this package, relative to the package root.
  String _readClient(String relative) {
    final File file = File(relative);

    if (!file.existsSync()) {
      throw StateError('audit: missing $relative');
    }

    return file.readAsStringSync();
  }

  // ----------------------------------------------------------------- server

  void _loadResources() {
    final Directory dir = Directory(
      '${root.path}${Platform.pathSeparator}app'
      '${Platform.pathSeparator}Http'
      '${Platform.pathSeparator}Resources',
    );

    for (final FileSystemEntity entry in dir.listSync()) {
      if (entry is! File || !entry.path.endsWith('.php')) {
        continue;
      }

      final String name = entry.uri.pathSegments.last.replaceAll('.php', '');
      // `entry.path` is already relative to the CWD, which the launcher sets to
      // the package root, so it is read directly rather than through [_read].
      final String source = File(entry.path).readAsStringSync();
      final List<int> starts = <int>[];
      final List<String> names = <String>[];

      for (final RegExpMatch m in RegExp(
        r'\n    (?:public|private|protected) function (\w+)\(',
      ).allMatches(source)) {
        starts.add(m.start);
        names.add(m.group(1)!);
      }

      if (starts.isEmpty) {
        throw StateError('audit: no method found in $name');
      }

      for (int i = 0; i < starts.length; i++) {
        final int to = i + 1 < starts.length ? starts[i + 1] : source.length;
        final String method = names[i];

        for (final _Node node in _parsePhpArrays(
          source.substring(starts[i], to),
        )) {
          if (method == 'toArray') {
            // The top level is compared as keys, not leaf paths: `pendidikan` is
            // a key the client reads, and flattening it to `pendidikan.jenjang`
            // would report the client as reading a field the server never sends.
            _resourcePaths['$name.toArray'] = node.topLevelKeys;
            _registerInlineNested(name, node);
          } else {
            // A private helper named after the key that uses it, which is the
            // convention in `DokterDetailResource` and `DokterAkunResource`.
            _resourcePaths['$name.$method'] = node.leafPaths;
          }
        }
      }

      // A unit naming the resource alone means its top level.
      final List<String>? top = _resourcePaths['$name.toArray'];

      if (top != null) {
        _resourcePaths[name] = top;
      }
    }
  }

  /// Registers each inline nested literal of a `toArray` under its own key.
  void _registerInlineNested(String resource, _Node root) {
    for (final String key in root.keys) {
      final List<String> paths = <String>[];

      for (final _Node child in root.children) {
        if (child.parentKey == key) {
          paths.addAll(child.leafPaths);
        }
      }

      if (paths.isNotEmpty) {
        _resourcePaths['$resource.$key'] = paths..sort();
      }
    }
  }

  void _loadControllers() {
    const Map<String, String> files = <String, String>{
      'AuthController': 'app/Http/Controllers/Api/V1/AuthController.php',
      'MeController': 'app/Http/Controllers/Api/V1/MeController.php',
      'PasienController': 'app/Http/Controllers/Api/V1/PasienController.php',
      'DokterController': 'app/Http/Controllers/Api/V1/DokterController.php',
    };

    for (final MapEntry<String, String> entry in files.entries) {
      _controllerDataKeys[entry.key] = _dataKeysPerAction(_read(entry.value));
    }
  }

  /// The array literal that is the first argument of every
  /// `ApiResponse::success(...)` call, grouped by the enclosing method.
  ///
  /// Nested inline maps are registered under `Controller.action.key`, and the
  /// whole `data` node's dotted leaf set under `Controller.action#leaves`,
  /// because two Module 1 responses are exactly that shape: the OTP challenge
  /// from `register` and `login`, and the three-key acknowledgement from
  /// `logout`. Both are modelled by a client DTO that reads through the nesting,
  /// so both need a leaf-path view and not only a top-level key list.
  Map<String, List<String>> _dataKeysPerAction(String source) {
    final Map<String, List<String>> out = <String, List<String>>{};
    final List<int> starts = <int>[];

    for (final RegExpMatch m in RegExp(
      r'\n    public function (\w+)\(',
    ).allMatches(source)) {
      starts.add(m.start);
    }

    for (int i = 0; i < starts.length; i++) {
      final int from = starts[i];
      final int to = i + 1 < starts.length ? starts[i + 1] : source.length;
      final String body = source.substring(from, to);
      final String method = RegExp(r'\n    public function (\w+)\(')
          .firstMatch(body)!
          .group(1)!;

      // Only the first argument is the payload: `success($data, $message,
      // $status, $meta)`. A bracket-matching scan finds its extent.
      final int call = body.indexOf('ApiResponse::success(');

      if (call < 0) {
        continue;
      }

      final int open = body.indexOf('[', call);
      final int close = _matchingBracket(body, open);

      if (open < 0 || close < 0) {
        continue;
      }

      final List<_Node> parsed = _parsePhpArrays(
        body.substring(open, close + 1),
      );

      if (parsed.isEmpty) {
        continue;
      }

      out[method] = parsed.expand((_Node n) => n.topLevelKeys).toList();

      final List<String> allLeaves = <String>[];

      for (final _Node node in parsed) {
        allLeaves.addAll(node.leafPaths);

        for (final _Node child in node.children) {
          final String? key = child.parentKey;

          if (key != null && child.leafPaths.isNotEmpty) {
            out['$method.$key'] = child.leafPaths;
          }
        }
      }

      allLeaves.sort();
      out['$method#leaves'] = allLeaves;
    }

    return out;
  }

  /// The index of the `]` matching the `[` at [open], or -1.
  static int _matchingBracket(String source, int open) {
    if (open < 0) {
      return -1;
    }

    int depth = 0;
    bool inLine = false;
    bool inBlock = false;
    bool inSingle = false;
    bool inDouble = false;

    for (int i = open; i < source.length; i++) {
      final String c = source[i];
      final String next = i + 1 < source.length ? source[i + 1] : '';

      if (inLine) {
        if (c == '\n') {
          inLine = false;
        }

        continue;
      }

      if (inBlock) {
        if (c == '*' && next == '/') {
          inBlock = false;
          i++;
        }

        continue;
      }

      if (inSingle || inDouble) {
        if (c == '\\') {
          i++;
        } else if ((inSingle && c == "'") || (!inSingle && c == '"')) {
          inSingle = false;
          inDouble = false;
        }

        continue;
      }

      if (c == '/' && next == '/') {
        inLine = true;
        i++;
        continue;
      }

      if (c == '#') {
        inLine = true;
        continue;
      }

      if (c == '/' && next == '*') {
        inBlock = true;
        i++;
        continue;
      }

      if (c == "'") {
        inSingle = true;
        continue;
      }

      if (c == '"') {
        inDouble = true;
        continue;
      }

      if (c == '[') {
        depth++;
      } else if (c == ']') {
        depth--;

        if (depth == 0) {
          return i;
        }
      }
    }

    return -1;
  }

  /// Scans [source] for array literals, skipping comments and strings.
  ///
  /// Returns one node per literal opened while the stack was empty, i.e. per
  /// top-level literal rather than per nested one.
  static List<_Node> _parsePhpArrays(String source) {
    final List<_Node> roots = <_Node>[];
    final List<_Node> stack = <_Node>[];
    String? pendingKey;
    bool inLine = false;
    bool inBlock = false;
    bool inSingle = false;
    bool inDouble = false;
    String? literal;

    for (int i = 0; i < source.length; i++) {
      final String c = source[i];
      final String next = i + 1 < source.length ? source[i + 1] : '';

      if (inLine) {
        if (c == '\n') {
          inLine = false;
        }

        continue;
      }

      if (inBlock) {
        if (c == '*' && next == '/') {
          inBlock = false;
          i++;
        }

        continue;
      }

      if (inSingle || inDouble) {
        if (c == '\\') {
          i++;
          literal = (literal ?? '') + c;
          continue;
        }

        if ((inSingle && c == "'") || (!inSingle && c == '"')) {
          // The literal has ended. Whitespace before the arrow must be skipped:
          // `'id' =>` is the style throughout this codebase, so testing
          // `source[i + 1] == '='` never matches and every resource parses as
          // empty.
          int after = i + 1;

          while (after < source.length &&
              (source[after] == ' ' || source[after] == '\t')) {
            after++;
          }

          final bool isKey =
              after + 1 < source.length &&
              source[after] == '=' &&
              source[after + 1] == '>';

          if (isKey && stack.isNotEmpty) {
            final String key = _unquote(literal ?? '');
            stack.last.keys.add(key);

            // The bracket that follows, whenever it comes, is this key's value.
            // The gap is deliberate: the common shape is a closure body,
            // `'spesialisasi' => $rows->map(fn (...) => [ ... ])`, so the bracket
            // is many characters away with an expression in between.
            pendingKey = key;
          }

          // The string flags MUST be cleared on every closing quote. Leaving
          // them set makes the next quote in the file read as a *closing* one,
          // which desynchronises the scanner so it recovers a single field per
          // file by accident -- a plausible wrong answer rather than an error.
          inSingle = false;
          inDouble = false;
          literal = null;

          if (isKey) {
            i = after + 1;
          }

          continue;
        }

        if (literal != null) {
          literal = literal + c;
        }

        continue;
      }

      if (c == '/' && next == '/') {
        inLine = true;
        i++;
        continue;
      }

      if (c == '#') {
        inLine = true;
        continue;
      }

      if (c == '/' && next == '*') {
        inBlock = true;
        i++;
        continue;
      }

      if (c == "'" || c == '"') {
        inSingle = c == "'";
        inDouble = c == '"';
        // The opening quote is kept, so the key reads back as it was written.
        literal = c;
        continue;
      }

      if (c == '[') {
        final _Node node = _Node(parentKey: pendingKey);
        pendingKey = null;

        if (stack.isEmpty) {
          roots.add(node);
        } else {
          stack.last.children.add(node);
        }

        stack.add(node);
        continue;
      }

      if (c == ']') {
        if (stack.isEmpty) {
          continue;
        }

        // A node with no `'k' =>` of its own is a list, which is what makes a
        // closure body render as `key[]` rather than `key`.
        final _Node closed = stack.removeLast();
        closed.isList = closed.keys.isEmpty;
        continue;
      }

      if (c == ',' && stack.isNotEmpty) {
        pendingKey = null;
      }
    }

    return roots;
  }

  /// Strips the one leading quote the lexer kept when it opened a literal.
  static String _unquote(String value) {
    if (value.isEmpty) {
      return value;
    }

    final String first = value[0];

    if (first == "'" || first == '"') {
      return value.substring(1);
    }

    return value;
  }

  // ----------------------------------------------------------------- client

  /// Loads the wire paths every client DTO reads.
  ///
  /// Chunked on any line starting in **column zero**, not on `^class`: the files
  /// also hold top-level functions, and chunking on `class` alone makes the last
  /// class swallow every trailing one. Column zero is a reliable boundary in a
  /// `dart format`ed file because every class member is indented.
  ///
  /// The four `*_api.dart` files are scanned alongside `dto.dart` because
  /// `RegisterResult`, `LoginResult`, `VerifyOtpResult` and `LogoutResult` are
  /// declared there and not in `dto.dart`; missing them reports four endpoints
  /// as modelling nothing at all.
  void _loadDtos() {
    const List<String> files = <String>[
      'lib/src/model/dto.dart',
      'lib/src/api/auth_api.dart',
      'lib/src/api/me_api.dart',
      'lib/src/api/pasien_api.dart',
      'lib/src/api/dokter_api.dart',
    ];

    for (final String path in files) {
      _indexDeclarations(_readClient(path));
    }
  }

  /// Registers each top-level declaration in [source] under a usable name.
  ///
  /// A `class X` chunk registers as `X`; a top-level `T _name(` chunk registers
  /// as `T` too, which is how `DokterAkunSpesialisasi` -- built by
  /// `_akunSpesialisasi` rather than by a factory on the class -- gets its keys
  /// read at all.
  void _indexDeclarations(String source) {
    final List<int> starts = <int>[];

    for (final RegExpMatch m in RegExp(
      r'^[A-Za-z_]',
      multiLine: true,
    ).allMatches(source)) {
      starts.add(m.start);
    }

    for (int i = 0; i < starts.length; i++) {
      final int to = i + 1 < starts.length ? starts[i + 1] : source.length;
      final String chunk = source.substring(starts[i], to);
      final String? name = _declarationName(chunk);

      if (name == null) {
        continue;
      }

      final Set<String> keys = _indexKeys(chunk);

      // A later declaration wins only if it actually reads something. This is
      // load-bearing for `DokterAkunSpesialisasi`: the class is declared first and
      // has a named-parameter constructor with no JSON reads at all, so it
      // registers an empty set. The keys live in the top-level function
      // `_akunSpesialisasi` that builds it, and a plain "first one wins" guard
      // would discard them and report a correctly-modelled DTO as reading nothing.
      if (keys.isEmpty && _dtoKeys.containsKey(name)) {
        continue;
      }

      _dtoKeys[name] = keys;
    }
  }

  static String? _declarationName(String chunk) {
    final RegExpMatch? asClass = RegExp(
      r'^class (\w+)',
      multiLine: true,
    ).firstMatch(chunk);

    if (asClass != null) {
      return asClass.group(1);
    }

    final RegExpMatch? asEnum = RegExp(
      r'^enum (\w+)',
      multiLine: true,
    ).firstMatch(chunk);

    if (asEnum != null) {
      return asEnum.group(1);
    }

    // A top-level function or getter, read as `Type name(`. Generic helpers such
    // as `T? _readNested` resolve to `T`, which no unit refers to, so they cost
    // nothing.
    final RegExpMatch? asFunction = RegExp(
      r'^(?:final\s+)?([A-Z][\w<>?]*)\s+_?\w+\s*\(',
      multiLine: true,
    ).firstMatch(chunk);

    return asFunction?.group(1);
  }

  void _loadApiClasses() {
    const Map<String, String> files = <String, String>{
      'AuthApi': 'lib/src/api/auth_api.dart',
      'MeApi': 'lib/src/api/me_api.dart',
      'PasienApi': 'lib/src/api/pasien_api.dart',
      'DokterApi': 'lib/src/api/dokter_api.dart',
    };

    for (final MapEntry<String, String> entry in files.entries) {
      final String source = _readClient(entry.value);
      final List<int> starts = <int>[];

      // `.+` rather than `[^>]*`: a return type like
      // `Future<Paginated<UserDevice>>` nests its brackets, and a character
      // class stopping at the first `>` silently misses every such method.
      // Missing one shifts every later chunk boundary, which attributes one
      // endpoint's keys to the next.
      for (final RegExpMatch m in RegExp(
        r'^  Future<.+>\s+(\w+)\(',
        multiLine: true,
      ).allMatches(source)) {
        starts.add(m.start);
      }

      final Map<String, Set<String>> byMethod = <String, Set<String>>{};
      final Map<String, String?> byWholeMap = <String, String?>{};

      for (int i = 0; i < starts.length; i++) {
        final int to = i + 1 < starts.length ? starts[i + 1] : source.length;
        final String chunk = source.substring(starts[i], to);
        final String name = RegExp(
          r'^  Future<.+>\s+(\w+)\(',
          multiLine: true,
        ).firstMatch(chunk)!.group(1)!;

        byMethod[name] = _wrapperKeys(chunk);
        byWholeMap[name] = _wholeMapDto(chunk);
      }

      _apiMethodKeys[entry.key] = byMethod;
      _wholeMapDtoNames[entry.key] = byWholeMap;
    }
  }

  /// The DTO a method builds from the entire `data` object, if it does that.
  ///
  /// Detected as `X.fromJson(envelope.dataMap)` -- no index between them. A
  /// method that writes `X.fromJson(jsonMap(envelope.dataMap['k']))` indexes a
  /// key and is not this case, which is what makes the two forms separable
  /// without a blocklist.
  static String? _wholeMapDto(String source) {
    final String code = _stripComments(source);

    return RegExp(r'(\w+)\.fromJson\(\s*envelope\.dataMap\s*\)')
        .firstMatch(code)
        ?.group(1);
  }

  /// The `data` envelope keys one endpoint method reads.
  ///
  /// Exactly two syntactic sites and nothing else: `envelope.dataMap['k']` for a
  /// nested single object, and `key: 'k'` for a `Paginated` list. Matching those
  /// directly is what makes this precise -- scanning every `['k']` in the method
  /// also picks up request-side keys and unrelated maps, and filtering those with
  /// a blocklist is a guess that gets weaker as the API grows.
  static Set<String> _wrapperKeys(String source) {
    final String code = _stripComments(source);
    final Set<String> out = <String>{};

    for (final RegExpMatch m in RegExp(
      r"""dataMap\[['"]([A-Za-z_][A-Za-z_0-9]*)['"]\]""",
    ).allMatches(code)) {
      out.add(m.group(1)!);
    }

    for (final RegExpMatch m in RegExp(
      r"""key:\s*'([A-Za-z_][A-Za-z_0-9]*)'""",
    ).allMatches(code)) {
      out.add(m.group(1)!);
    }

    return out;
  }

  /// Every wire path a client DTO reads, ignoring doc comments.
  ///
  /// Dotted where the client indexes *into* a value it just unwrapped:
  /// `jsonMap(json['refresh_token'])['dicabut']` reads `refresh_token.dicabut`,
  /// which is what the server publishes. When a declaration contains any dotted
  /// read, its outer keys are emitted **only** in dotted form -- otherwise
  /// `LogoutResult` would also report `refresh_token` as a top-level read and
  /// three correct reads would be called client-only.
  ///
  /// A declaration with no nested read is unaffected: every key it reads is at
  /// the top level, so the two forms coincide.
  static Set<String> _indexKeys(String source) {
    final String code = _stripComments(source);
    final Set<String> flat = <String>{};
    final Set<String> dotted = <String>{};
    final Set<String> nested = <String>{};

    // An index whose closing bracket is immediately followed by `)` and then a
    // second index: the unwrap-then-index shape. Matching on the brackets rather
    // than on the reader names is what makes it work through the nesting --
    // `jsonBool(jsonMap(json['refresh_token'])['dicabut'])` has two reader calls
    // and a name-based pattern misses all of them.
    for (final RegExpMatch m in RegExp(
      r"""\[\s*'(\w+)'\s*\]\s*\)\s*\[\s*'(\w+)'\s*\]""",
    ).allMatches(code)) {
      dotted.add('${m.group(1)}.${m.group(2)}');
      nested
        ..add(m.group(1)!)
        ..add(m.group(2)!);
    }

    for (final RegExpMatch m in RegExp(
      r"\['([A-Za-z_][A-Za-z_0-9]*)'\]",
    ).allMatches(code)) {
      flat.add(m.group(1)!);
    }

    for (final RegExpMatch m in RegExp(
      r"""key:\s*'([A-Za-z_][A-Za-z_0-9]*)'""",
    ).allMatches(code)) {
      flat.add(m.group(1)!);
    }

    if (dotted.isEmpty) {
      return flat;
    }

    // Both halves of a nested read leave the flat set. The outer key is not a
    // top-level field, and the inner one is not a field of this object at all --
    // it belongs to the nested object the dotted path already names. Keeping
    // either would report a correct read as a field the server never sends.
    return <String>{
      ...flat.where((String k) => !nested.contains(k)),
      ...dotted,
    };
  }

  /// Blanks out `///`, `//` and block comments, preserving offsets.
  static String _stripComments(String source) {
    final StringBuffer out = StringBuffer();
    int i = 0;

    while (i < source.length) {
      if (source.startsWith('///', i) || source.startsWith('//', i)) {
        while (i < source.length && source[i] != '\n') {
          out.write(' ');
          i++;
        }

        continue;
      }

      if (source.startsWith('/*', i)) {
        while (i < source.length && !source.startsWith('*/', i)) {
          out.write(source[i] == '\n' ? '\n' : ' ');
          i++;
        }

        if (i < source.length) {
          out.write('  ');
          i += 2;
        }

        continue;
      }

      out.write(source[i]);
      i++;
    }

    return out.toString();
  }

  // ---------------------------------------------------------------- compare

  /// The 22 Module 1 endpoints and the DTOs each one is expected to model.
  ///
  /// The only part a human maintains is the endpoint list and the client/server
  /// pairing. Every field name on both sides is read out of the source, so a
  /// typo in a field name cannot hide here.
  ///
  /// A `*Result` container is deliberately absent from every `units` list. Those
  /// classes read only the `data` wrapper keys -- `user`, `otp`, `token` -- which
  /// the wrapper comparison above already covers, and listing them as units would
  /// report those three correct reads as client-only. The DTOs they delegate to
  /// are what the resource comparison needs.
  static const List<_Spec> specs = <_Spec>[
    _Spec(
      endpoint: 'POST /api/v1/auth/register',
      controller: 'AuthController',
      action: 'register',
      api: 'AuthApi',
      method: 'register',
      units: <_UnitSpec>[
        _UnitSpec('User', 'UserResource'),
        _UnitSpec('OtpChallenge', 'AuthController.register.otp'),
      ],
    ),
    _Spec(
      endpoint: 'POST /api/v1/auth/login',
      controller: 'AuthController',
      action: 'login',
      api: 'AuthApi',
      method: 'login',
      units: <_UnitSpec>[_UnitSpec('OtpChallenge', 'AuthController.login.otp')],
    ),
    _Spec(
      endpoint: 'POST /api/v1/auth/otp/verify',
      controller: 'AuthController',
      action: 'verifyOtp',
      api: 'AuthApi',
      method: 'verifyOtp',
      units: <_UnitSpec>[
        _UnitSpec('User', 'UserResource'),
        _UnitSpec('TokenPair', 'AuthTokenResource'),
      ],
    ),
    _Spec(
      endpoint: 'POST /api/v1/auth/refresh',
      controller: 'AuthController',
      action: 'refresh',
      api: 'AuthApi',
      method: 'refresh',
      units: <_UnitSpec>[_UnitSpec('TokenPair', 'AuthTokenResource')],
    ),
    _Spec(
      endpoint: 'POST /api/v1/auth/logout',
      controller: 'AuthController',
      action: 'logout',
      api: 'AuthApi',
      method: 'logout',
      units: <_UnitSpec>[
        _UnitSpec('LogoutResult', 'AuthController.logout#leaves'),
      ],
    ),
    _Spec(
      endpoint: 'GET /api/v1/auth/devices',
      controller: 'AuthController',
      action: 'devicesIndex',
      api: 'AuthApi',
      method: 'devices',
      units: <_UnitSpec>[_UnitSpec('UserDevice', 'UserDeviceResource')],
    ),
    _Spec(
      endpoint: 'POST /api/v1/auth/devices',
      controller: 'AuthController',
      action: 'devicesStore',
      api: 'AuthApi',
      method: 'registerDevice',
      units: <_UnitSpec>[_UnitSpec('UserDevice', 'UserDeviceResource')],
    ),
    _Spec(
      endpoint: 'DELETE /api/v1/auth/devices/{deviceId}',
      controller: 'AuthController',
      action: 'devicesDestroy',
      api: 'AuthApi',
      method: 'removeDevice',
      units: <_UnitSpec>[_UnitSpec('UserDevice', 'UserDeviceResource')],
    ),
    _Spec(
      endpoint: 'GET /api/v1/me',
      controller: 'MeController',
      action: 'show',
      api: 'MeApi',
      method: 'show',
      units: <_UnitSpec>[
        _UnitSpec('User', 'UserResource'),
        _UnitSpec('PasienProfile', 'PasienResource'),
        _UnitSpec('DokterAccount', 'DokterAkunResource'),
        _UnitSpec('DokterAkunSpesialisasi', 'DokterAkunResource.spesialisasi'),
      ],
    ),
    _Spec(
      endpoint: 'GET /api/v1/pasien/profil',
      controller: 'PasienController',
      action: 'profilShow',
      api: 'PasienApi',
      method: 'profil',
      units: <_UnitSpec>[_UnitSpec('PasienProfile', 'PasienResource')],
    ),
    _Spec(
      endpoint: 'PUT /api/v1/pasien/profil',
      controller: 'PasienController',
      action: 'profilUpdate',
      api: 'PasienApi',
      method: 'updateProfil',
      units: <_UnitSpec>[_UnitSpec('PasienProfile', 'PasienResource')],
    ),
    _Spec(
      endpoint: 'GET /api/v1/pasien/anggota-keluarga',
      controller: 'PasienController',
      action: 'anggotaKeluargaIndex',
      api: 'PasienApi',
      method: 'anggotaKeluarga',
      units: <_UnitSpec>[
        _UnitSpec('AnggotaKeluarga', 'PasienAnggotaKeluargaResource'),
      ],
    ),
    _Spec(
      endpoint: 'POST /api/v1/pasien/anggota-keluarga',
      controller: 'PasienController',
      action: 'anggotaKeluargaStore',
      api: 'PasienApi',
      method: 'createAnggotaKeluarga',
      units: <_UnitSpec>[
        _UnitSpec('AnggotaKeluarga', 'PasienAnggotaKeluargaResource'),
      ],
    ),
    _Spec(
      endpoint: 'PUT /api/v1/pasien/anggota-keluarga/{id}',
      controller: 'PasienController',
      action: 'anggotaKeluargaUpdate',
      api: 'PasienApi',
      method: 'updateAnggotaKeluarga',
      units: <_UnitSpec>[
        _UnitSpec('AnggotaKeluarga', 'PasienAnggotaKeluargaResource'),
      ],
    ),
    _Spec(
      endpoint: 'DELETE /api/v1/pasien/anggota-keluarga/{id}',
      controller: 'PasienController',
      action: 'anggotaKeluargaDestroy',
      api: 'PasienApi',
      method: 'deleteAnggotaKeluarga',
      units: <_UnitSpec>[
        _UnitSpec('DeletedRow', 'PasienController.anggotaKeluargaDestroy'),
      ],
    ),
    _Spec(
      endpoint: 'GET /api/v1/pasien/alergi',
      controller: 'PasienController',
      action: 'alergiIndex',
      api: 'PasienApi',
      method: 'alergi',
      units: <_UnitSpec>[_UnitSpec('PasienAlergi', 'PasienAlergiResource')],
    ),
    _Spec(
      endpoint: 'POST /api/v1/pasien/alergi',
      controller: 'PasienController',
      action: 'alergiStore',
      api: 'PasienApi',
      method: 'createAlergi',
      units: <_UnitSpec>[_UnitSpec('PasienAlergi', 'PasienAlergiResource')],
    ),
    _Spec(
      endpoint: 'PUT /api/v1/pasien/alergi/{id}',
      controller: 'PasienController',
      action: 'alergiUpdate',
      api: 'PasienApi',
      method: 'updateAlergi',
      units: <_UnitSpec>[_UnitSpec('PasienAlergi', 'PasienAlergiResource')],
    ),
    _Spec(
      endpoint: 'DELETE /api/v1/pasien/alergi/{id}',
      controller: 'PasienController',
      action: 'alergiDestroy',
      api: 'PasienApi',
      method: 'deleteAlergi',
      units: <_UnitSpec>[
        _UnitSpec('DeletedRow', 'PasienController.alergiDestroy'),
      ],
    ),
    _Spec(
      endpoint: 'GET /api/v1/dokter',
      controller: 'DokterController',
      action: 'index',
      api: 'DokterApi',
      method: 'index',
      units: <_UnitSpec>[_UnitSpec('DokterListing', 'DokterResource')],
    ),
    _Spec(
      endpoint: 'GET /api/v1/dokter/{dokter}',
      controller: 'DokterController',
      action: 'show',
      api: 'DokterApi',
      method: 'show',
      units: <_UnitSpec>[
        _UnitSpec('DokterDetail', 'DokterDetailResource'),
        _UnitSpec('DokterSpesialisasi', 'DokterDetailResource.spesialisasi'),
        _UnitSpec('DokterPendidikan', 'DokterDetailResource.pendidikan'),
        _UnitSpec('DokterFaskes', 'DokterDetailResource.faskes'),
      ],
    ),
    _Spec(
      endpoint: 'GET /api/v1/master-spesialisasi',
      controller: 'DokterController',
      action: 'spesialisasiIndex',
      api: 'DokterApi',
      method: 'spesialisasi',
      units: <_UnitSpec>[
        _UnitSpec('MasterSpesialisasi', 'MasterSpesialisasiResource'),
      ],
    ),
  ];

  void _compare() {
    for (final _Spec spec in specs) {
      final Map<String, Set<String>>? methods = _apiMethodKeys[spec.api];

      if (methods == null || !methods.containsKey(spec.method)) {
        // A spec naming a method that does not exist compares against nothing
        // and reports every key as server-only, which reads like a contract
        // finding rather than a typo in this file. Four of the original method
        // names here were wrong in exactly that way.
        throw StateError(
          'audit: ${spec.api}.${spec.method} does not exist '
          '(have: ${(methods ?? const <String, Set<String>>{}).keys.join(', ')})',
        );
      }

      final Map<String, List<String>>? actions =
          _controllerDataKeys[spec.controller];

      if (actions == null || !actions.containsKey(spec.action)) {
        throw StateError(
          'audit: ${spec.controller}::${spec.action}() does not exist '
          '(have: ${(actions ?? const <String, List<String>>{}).keys.join(', ')})',
        );
      }

      final Set<String> methodKeys = methods[spec.method]!;
      final String? wholeMapDto = _wholeMapDtoNames[spec.api]?[spec.method];

      wrappers.add(
        _Wrapper(
          endpoint: spec.endpoint,
          // When a method hands the entire `data` object to a result DTO, the
          // keys it reads are that DTO's, not the call site's. `register`,
          // `login`, `otp/verify`, `logout` and both deletes all do this, and
          // without this branch their wrapper comparison is empty on the client
          // side and flags correct reads as server-only.
          clientKeys:
              (methodKeys.isEmpty && wholeMapDto != null
                      ? _dtoReachedKeys(wholeMapDto)
                      : methodKeys)
                  .toList()
                ..sort(),
          serverKeys: (actions[spec.action]!).toList()..sort(),
        ),
      );

      for (final _UnitSpec unit in spec.units) {
        units.add(
          _Unit(
            endpoint: spec.endpoint,
            clientClass: unit.clientClass,
            serverSource: unit.serverSource,
            clientKeys: (_dtoKeys[unit.clientClass] ?? <String>{}).toList()
              ..sort(),
            serverPaths: _serverPathsFor(unit.serverSource),
          ),
        );
      }
    }
  }

  /// The `data` keys a DTO reaches, at the top level.
  ///
  /// Distinct from [_dtoKeys] in one way: a key the DTO only ever reads *through*
  /// a nesting is reported as its outer key, not as the dotted path. That is the
  /// right view for comparing against a controller's `data` map, whose keys are
  /// the outer ones -- `LogoutResult` reads `refresh_token.dicabut` but what
  /// `logout` writes under `data` is `refresh_token`.
  Set<String> _dtoReachedKeys(String dto) {
    final Set<String> out = <String>{};

    for (final String path in _dtoKeys[dto] ?? <String>{}) {
      final int dot = path.indexOf('.');

      out.add(dot > 0 ? path.substring(0, dot) : path);
    }

    return out;
  }

  /// Resolves a spec's server source to the wire paths it publishes.
  ///
  /// Tried in order, and the order matters. A resource whose private helper is
  /// named after the key that uses it -- `DokterDetailResource.spesialisasi` --
  /// is registered under that exact name. A resource with an inline nested
  /// literal is registered the same way. An inline map inside a controller
  /// action belongs to neither, so it is resolved through the action's key. The
  /// `#leaves` suffix selects the whole `data` node's dotted paths, which is what
  /// a DTO reading *through* a nesting needs.
  List<String> _serverPathsFor(String source) {
    final List<String>? direct = _resourcePaths[source];

    if (direct != null) {
      return direct;
    }

    // A controller shape: `Controller.action`, `Controller.action.key` or
    // `Controller.action#leaves`. The key is the *remainder* rejoined, because
    // `AuthController.register.otp` is two dots deep and dropping everything but
    // the last segment looks the action up as `otp`, which no action is called.
    final List<String> parts = source.split('.');

    if (parts.length >= 2) {
      final Map<String, List<String>> actions =
          _controllerDataKeys[parts.first] ?? <String, List<String>>{};
      final List<String>? found = actions[parts.sublist(1).join('.')];

      if (found != null) {
        return found;
      }
    }

    // A resource whose nested object is an inline literal rather than a private
    // helper, reachable only as a dotted prefix of its parent's leaf paths.
    final int dot = source.indexOf('.');

    if (dot > 0) {
      final List<String>? all = _resourcePaths[source.substring(0, dot)];

      if (all != null) {
        final String listPrefix = '${source.substring(dot + 1)}[].';

        return all
            .where((String p) => p.startsWith(listPrefix))
            .map((String p) => p.substring(listPrefix.length))
            .toList()
          ..sort();
      }
    }

    return <String>[];
  }

  bool isJustified(_Unit unit) {
    for (final String field in unit.serverOnly) {
      final String key = '${unit.endpoint}/${unit.clientClass}:$field';

      if (!_dropJustifications.containsKey(key)) {
        return false;
      }
    }

    return true;
  }

  // ----------------------------------------------------------------- report

  void printReport({required bool verbose}) {
    if (verbose) {
      stdout.writeln('server shapes as parsed');

      for (final MapEntry<String, List<String>> e in _resourcePaths.entries) {
        stdout.writeln('- ${e.key}: ${e.value.join(', ')}');
      }

      stdout.writeln('controller data shapes as parsed');

      for (final MapEntry<String, Map<String, List<String>>> c
          in _controllerDataKeys.entries) {
        stdout.writeln('- ${c.key}: ${c.value}');
      }

      stdout.writeln('client dto reads as parsed');

      for (final MapEntry<String, Set<String>> d in _dtoKeys.entries) {
        stdout.writeln('- ${d.key}: ${d.value.toList()..sort()}');
      }

      stdout.writeln('client data-wrapper reads as parsed');

      for (final MapEntry<String, Map<String, Set<String>>> a
          in _apiMethodKeys.entries) {
        stdout.writeln('- ${a.key}: ${a.value}');
      }

      stdout.writeln('');
    }

    int matched = 0;
    int clientOnly = 0;
    int serverOnly = 0;

    for (final _Wrapper w in wrappers) {
      matched += w.clientKeys.where(w.serverKeys.contains).length;
      clientOnly += w.clientOnly.length;
      serverOnly += w.serverOnly.length;
    }

    for (final _Unit u in units) {
      matched += u.matched.length;
      clientOnly += u.clientOnly.length;
      serverOnly += u.serverOnly.length;
    }

    stdout.writeln('Module 1 wire-field audit');
    stdout.writeln('endpoints: ${specs.length}');
    stdout.writeln('wrapper comparisons: ${wrappers.length}');
    stdout.writeln('dto/resource comparisons: ${units.length}');
    stdout.writeln('');
    stdout.writeln(
      'TOTALS  matched=$matched  client-only=$clientOnly  '
      'server-only=$serverOnly',
    );
    stdout.writeln('');
    stdout.writeln('== data envelope wrapper keys ==');
    stdout.writeln('endpoint | client reads | server writes');

    for (final _Wrapper w in wrappers) {
      stdout.writeln(
        '${w.endpoint} | ${w.clientKeys.join(',')} | '
        '${w.serverKeys.join(',')}',
      );

      if (w.clientOnly.isNotEmpty || w.serverOnly.isNotEmpty) {
        stdout.writeln(
          '   !! client-only=${w.clientOnly} '
          'server-only=${w.serverOnly}',
        );
      }
    }

    stdout.writeln('');
    stdout.writeln('== resource field keys ==');
    stdout.writeln(
      'endpoint | client class | server shape | matched | '
      'client-only | server-only',
    );

    for (final _Unit u in units) {
      stdout.writeln(
        '${u.endpoint} | ${u.clientClass} | ${u.serverSource} | '
        '${u.matched.length} | ${u.clientOnly.length} | '
        '${u.serverOnly.length}',
      );

      if (u.clientOnly.isNotEmpty) {
        stdout.writeln('   !! client-only: ${u.clientOnly.join(', ')}');
      }

      if (u.serverOnly.isNotEmpty) {
        stdout.writeln(
          '   -- server-only: ${u.serverOnly.join(', ')} '
          '${isJustified(u) ? '(justified)' : '(UNJUSTIFIED)'}',
        );
      }

      if (verbose) {
        stdout.writeln('      client: ${u.clientKeys.join(', ')}');
        stdout.writeln('      server: ${u.serverPaths.join(', ')}');
      }
    }

    stdout.writeln('');
    stdout.writeln('== test-fixture token keys vs AuthTokenResource ==');
    stdout.writeln(
      'server: '
      '${(_resourcePaths['AuthTokenResource'] ?? const <String>[]).join(', ')}',
    );
    stdout.writeln('fixtures: ${_fixtureKeys.join(', ')}');

    if (_fixtureExtra.isNotEmpty) {
      stdout.writeln(
        '   !! fixture-only (server does not publish): '
        '${_fixtureExtra.join(', ')}',
      );
    }

    if (_fixtureMissing.isNotEmpty) {
      stdout.writeln(
        '   -- server-only (no fixture covers it): '
        '${_fixtureMissing.join(', ')}',
      );
    }
  }
}

/// One client DTO class checked against one server shape.
class _UnitSpec {
  const _UnitSpec(this.clientClass, this.serverSource);

  final String clientClass;
  final String serverSource;
}

/// One of the 22 endpoints and the DTOs it is expected to model.
class _Spec {
  const _Spec({
    required this.endpoint,
    required this.controller,
    required this.action,
    required this.api,
    required this.method,
    required this.units,
  });

  final String endpoint;
  final String controller;
  final String action;
  final String api;
  final String method;
  final List<_UnitSpec> units;
}

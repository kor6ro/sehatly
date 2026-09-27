/// Total, dependency-free readers for values that arrived as decoded JSON.
///
/// ## Why this file exists instead of inline casts
///
/// Every DTO in this package is hand-written, so every one of them would
/// otherwise open with a line of `(json['x'] as String)` and a cast that
/// throws `TypeError` the first time a column is null. Laravel publishes
/// nullable columns as JSON `null` and `whenLoaded()` relations as *absent*
/// keys, so "absent" and "null" are both ordinary, expected states on the wire.
///
/// These readers therefore never throw. A key that is absent, `null`, or of an
/// unexpected type reads as `null` (or an empty collection), which turns a
/// schema surprise into one field being empty instead of an unhandled
/// `TypeError` inside a `fromJson` factory that the caller cannot guard.
///
/// The trade is deliberate and it is the usual one: this client prefers a
/// `null` it can see over a crash it cannot. A *wrong type* for a value that
/// must be present is still worth failing loudly, and the DTOs handle the
/// required fields (`ApiMeta.total` and the rest of the pagination block) by
/// reading through these helpers and defaulting explicitly, so the default is
/// visible in the DTO rather than hidden here.
library;

/// Reads [source] as a JSON object, or returns an empty map.
///
/// A `Map<String, dynamic>` decoded by `dart:convert` satisfies
/// `Map<Object?, Object?>`, so this accepts it. Keys are stringified so a
/// numeric key cannot leak a non-`String` into a typed accessor.
Map<String, Object?> jsonMap(Object? source) {
  if (source is Map<Object?, Object?>) {
    return source.map(
      (Object? key, Object? value) => MapEntry<String, Object?>('$key', value),
    );
  }

  return const <String, Object?>{};
}

/// Reads [source] as a JSON array, or returns an empty list.
List<Object?> jsonList(Object? source) {
  if (source is List<Object?>) {
    return source;
  }

  return const <Object?>[];
}

/// Reads [value] as an `int`, or returns `null`.
///
/// Laravel's `DECIMAL(12,2)` money columns are returned by the MySQL driver as
/// strings, so the string branch is load-bearing for
/// `biaya_konsultasi_online` and `rating_rata_rata` rather than defensive.
int? jsonInt(Object? value) {
  if (value is int) {
    return value;
  }

  if (value is double) {
    return value.toInt();
  }

  if (value is String) {
    return int.tryParse(value);
  }

  return null;
}

/// Reads [value] as a `double`, or returns `null`.
double? jsonDouble(Object? value) {
  if (value is num) {
    return value.toDouble();
  }

  if (value is String) {
    return double.tryParse(value);
  }

  return null;
}

/// Reads [value] as a `String`, or returns `null`.
///
/// Numbers and booleans are stringified. `json_encode` never emits a bare
/// number where the server wrote a string, so this only fires on a value the
/// server changed type on, and reading it as text is the more useful answer
/// than `null`.
String? jsonString(Object? value) {
  if (value is String) {
    return value;
  }

  if (value is num || value is bool) {
    return '$value';
  }

  return null;
}

/// Reads [value] as a `bool`, or returns `null`.
///
/// Accepts `0`/`1` because `TINYINT(1)` columns (`aktif`,
/// `tersedia_telemedisin`, `is_meninggal`) arrive as integers on some driver
/// configurations and as booleans on others.
bool? jsonBool(Object? value) {
  if (value is bool) {
    return value;
  }

  if (value is int) {
    return value != 0;
  }

  if (value is String) {
    switch (value.toLowerCase()) {
      case 'true':
      case '1':
        return true;
      case 'false':
      case '0':
        return false;
    }
  }

  return null;
}

/// Reads [value] as an ISO-8601 instant, or returns `null`.
///
/// Every timestamp in this API is produced by `toISOString()` on a `Carbon`
/// value under `config/app.php` `timezone => 'UTC'`, so the string ends in `Z`
/// and the parsed `DateTime` is unambiguously UTC.
DateTime? jsonDateTime(Object? value) {
  final String? raw = jsonString(value);

  if (raw == null) {
    return null;
  }

  return DateTime.tryParse(raw);
}

/// Reads a per-field error map, i.e. the `errors` key of the failure envelope.
///
/// `ApiResponse::error()` casts the map to a JSON object so an empty error set
/// encodes as `{}` rather than `[]`, and Laravel's `ValidationException::errors()`
/// always produces `Map<string, list<string>>`. Both are handled, and a value
/// that is neither a list nor a string is dropped rather than stringified into
/// a message the user would read.
Map<String, List<String>> jsonErrorMap(Object? source) {
  if (source is! Map<Object?, Object?>) {
    return const <String, List<String>>{};
  }

  final Map<String, List<String>> result = <String, List<String>>{};

  for (final MapEntry<Object?, Object?> entry in source.entries) {
    final Object? value = entry.value;
    final List<String> messages = <String>[];

    if (value is List<Object?>) {
      for (final Object? item in value) {
        final String? text = jsonString(item);

        if (text != null) {
          messages.add(text);
        }
      }
    } else {
      final String? text = jsonString(value);

      if (text != null) {
        messages.add(text);
      }
    }

    if (messages.isNotEmpty) {
      result['${entry.key}'] = messages;
    }
  }

  return result;
}

/// Parses `a, b, c` into a list, or returns `null` when [value] is absent.
///
/// `v_dokter_katalog.spesialisasi` is a `GROUP_CONCAT(... SEPARATOR ', ')`
/// string rather than an array (`DokterResource`'s docblock), so a client
/// rendering a list of tags has to split it. `null` is returned rather than an
/// empty list for an absent value so "the server sent nothing" stays
/// distinguishable from "the server sent an empty list".
List<String>? jsonDelimitedList(Object? value) {
  final String? raw = jsonString(value);

  if (raw == null) {
    return null;
  }

  if (raw.isEmpty) {
    return const <String>[];
  }

  return raw
      .split(',')
      .map((String part) => part.trim())
      .where((String part) => part.isNotEmpty)
      .toList(growable: false);
}

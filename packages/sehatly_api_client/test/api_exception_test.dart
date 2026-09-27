import 'package:dio/dio.dart';
import 'package:sehatly_api_client/sehatly_api_client.dart';
import 'package:test/test.dart';

/// Parses [body] as a [DioException] carrying [status] and [body].
DioException dioFailure(int status, Map<String, Object?> body) {
  return DioException(
    requestOptions: RequestOptions(path: '/me', method: 'GET'),
    response: Response<Object?>(
      requestOptions: RequestOptions(path: '/me', method: 'GET'),
      statusCode: status,
      data: body,
    ),
  );
}

void main() {
  group('the failure envelope', () {
    test('parses success/message/errors for a 422', () {
      final ApiException error = ApiException.fromDio(
        dioFailure(422, <String, Object?>{
          'success': false,
          'message': 'The given data was invalid.',
          'errors': <String, Object?>{
            'no_telepon': <String>['Nomor telepon sudah digunakan.'],
            'email': <String>['Format email tidak valid.', 'Sudah digunakan.'],
          },
        }),
      );

      expect(error.statusCode, 422);
      expect(error.message, 'The given data was invalid.');
      expect(error.isValidationError, isTrue);
      expect(error.errors, hasLength(2));
      expect(error.errors['no_telepon'], <String>[
        'Nomor telepon sudah digunakan.',
      ]);
      expect(error.errors['email'], hasLength(2));
      expect(error.errorFields, containsAll(<String>['no_telepon', 'email']));
      expect(error.firstErrorFor('email'), 'Format email tidak valid.');
      expect(error.firstErrorFor('nope'), isNull);
      expect(error.method, 'GET');
      expect(error.path, '/me');
    });

    test('an empty errors map decodes as {} and stays empty', () {
      // `ApiResponse::error()` casts to (object) precisely so an empty map encodes
      // as {} rather than [], and 401/403/404/500 all pass an empty map.
      final ApiException error = ApiException.fromDio(
        dioFailure(401, <String, Object?>{
          'success': false,
          'message': 'Unauthenticated.',
          'errors': <String, Object?>{},
        }),
      );

      expect(error.isUnauthorized, isTrue);
      expect(error.isValidationError, isFalse);
      expect(error.errors, isEmpty);
    });

    test('a 422 with errors.slot is classified as isSlotTaken', () {
      final ApiException error = ApiException.fromDio(
        dioFailure(422, <String, Object?>{
          'success': false,
          'message': 'The given data was invalid.',
          'errors': <String, Object?>{
            'slot': <String>['Slotbooking sudah terisi.'],
          },
        }),
      );

      expect(error.isSlotTaken, isTrue);
      expect(error.isValidationError, isTrue);
      expect(
        error.firstErrorFor(ApiException.slotErrorKey),
        'Slotbooking sudah terisi.',
      );
    });

    test('a 422 without errors.slot is NOT isSlotTaken', () {
      final ApiException error = ApiException.fromDio(
        dioFailure(422, <String, Object?>{
          'success': false,
          'message': 'The given data was invalid.',
          'errors': <String, Object?>{
            'kode': <String>['Kode OTP tidak valid.'],
          },
        }),
      );

      expect(error.isSlotTaken, isFalse);
      expect(
        error.firstErrorFor('kode'),
        'Kode OTP tidak valid.',
        reason: 'a rejected OTP is a 422 with a reason under errors.kode',
      );
    });

    test('a 401 is never isSlotTaken, even if it somehow carried the key', () {
      final ApiException error = ApiException.fromDio(
        dioFailure(401, <String, Object?>{
          'success': false,
          'message': 'Unauthenticated.',
          'errors': <String, Object?>{
            'slot': <String>['x'],
          },
        }),
      );

      expect(
        error.isSlotTaken,
        isFalse,
        reason: 'isSlotTaken is a 422 classification, not a key lookup alone',
      );
    });

    test('a 403 carrying a consent key is isConsentRequired', () {
      final ApiException error = ApiException.fromDio(
        dioFailure(403, <String, Object?>{
          'success': false,
          'message': 'This action is unauthorized.',
          'errors': <String, Object?>{
            'consent': <String>['Persetujuan PDP diperlukan.'],
          },
        }),
      );

      expect(error.isConsentRequired, isTrue);
      expect(error.isForbidden, isTrue);
    });

    test('a 403 whose message names persetujuan is isConsentRequired', () {
      final ApiException error = ApiException.fromDio(
        dioFailure(403, <String, Object?>{
          'success': false,
          'message': 'Persetujuan PDP belum diberikan.',
          'errors': <String, Object?>{},
        }),
      );

      expect(error.isConsentRequired, isTrue);
    });

    test('an ordinary 403 is NOT isConsentRequired', () {
      // A missing RBAC grant is the common case and must not be reported as a
      // consent prompt, or every forbidden screen would send the user to a
      // consent flow that cannot help them.
      final ApiException error = ApiException.fromDio(
        dioFailure(403, <String, Object?>{
          'success': false,
          'message': 'This action is unauthorized.',
          'errors': <String, Object?>{},
        }),
      );

      expect(error.isForbidden, isTrue);
      expect(error.isConsentRequired, isFalse);
    });
  });

  group('status classifiers', () {
    test('map each status the bootstrap handler produces', () {
      ApiException at(int status) => ApiException.fromDio(
        dioFailure(status, <String, Object?>{
          'success': false,
          'message': 'x',
          'errors': <String, Object?>{},
        }),
      );

      expect(at(401).isUnauthorized, isTrue);
      expect(at(403).isForbidden, isTrue);
      expect(at(404).isNotFound, isTrue);
      expect(at(422).isValidationError, isTrue);
      expect(at(429).isTooManyRequests, isTrue);
      expect(at(500).isServerError, isTrue);
      expect(at(503).isServerError, isTrue);
      expect(at(404).isServerError, isFalse);
      expect(at(429).isNotFound, isFalse);
    });

    test('a transport failure is isNetworkError with status 0', () {
      final ApiException error = ApiException.fromDio(
        DioException(
          requestOptions: RequestOptions(path: '/me'),
          type: DioExceptionType.connectionError,
        ),
      );

      expect(error.statusCode, 0);
      expect(error.isNetworkError, isTrue);
      expect(error.isUnauthorized, isFalse);
      expect(error.isServerError, isFalse);
      expect(error.message, 'Could not reach the server.');
    });

    test('a timeout is distinguished from an unreachable server', () {
      final ApiException receive = ApiException.fromDio(
        DioException(
          requestOptions: RequestOptions(path: '/me'),
          type: DioExceptionType.receiveTimeout,
        ),
      );

      expect(receive.isNetworkError, isTrue);
      expect(receive.message, contains('waiting for the response'));
    });

    test('a non-JSON body falls back to the status reason phrase', () {
      // An HTML error page from a proxy in front of the app, or a truncated body.
      final ApiException error = ApiException.fromDio(
        DioException(
          requestOptions: RequestOptions(path: '/me'),
          response: Response<Object?>(
            requestOptions: RequestOptions(path: '/me'),
            statusCode: 502,
            data: '<html><body>502 Bad Gateway</body></html>',
          ),
        ),
      );

      expect(error.statusCode, 502);
      expect(error.message, 'Internal server error.');
      expect(error.errors, isEmpty);
    });
  });

  group('exception identity', () {
    test('ApiError is an alias for ApiException, so both names compile', () {
      const ApiException asAlias = ApiException(
        statusCode: 404,
        message: 'Resource not found.',
      );

      expect(asAlias, isA<ApiError>());
      expect(asAlias.isNotFound, isTrue);
    });

    test(
      'toString names the status, the verb, the path and the error keys',
      () {
        final ApiException error = ApiException.fromDio(
          dioFailure(422, <String, Object?>{
            'success': false,
            'message': 'The given data was invalid.',
            'errors': <String, Object?>{
              'slot': <String>['taken'],
              'kode': <String>['bad'],
            },
          }),
        );

        final String rendered = error.toString();
        expect(rendered, contains('ApiException(422'));
        expect(rendered, contains('GET /me'));
        expect(rendered, contains('errors='));
        expect(rendered, contains('slot'));
        expect(rendered, contains('kode'));
      },
    );
  });
}

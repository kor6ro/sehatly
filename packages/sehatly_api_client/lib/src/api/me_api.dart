import '../core/api_envelope.dart';
import '../core/json.dart';
import '../model/dto.dart';
import 'paths.dart';
import 'transport.dart';

/// `GET /api/v1/me` -- the caller's own account, with whichever record it owns.
///
/// ## This is the only Module 1 endpoint that publishes both relations
///
/// `MeController::show()` eager-loads `pasien` and `dokter` and sets them on the
/// model, so [User.pasien] and [User.dokter] are both populated here. The two
/// Module 1 auth endpoints publish the same `users` row through the same
/// `UserResource` with both keys **absent** -- the server uses `whenLoaded()`, so
/// `POST /auth/sign-up` and `POST /auth/otp/verify` do not pay for a query whose
/// result the response would not use, and do not publish a `null` for a row that
/// exists.
///
/// A client that renders a profile should call this once and keep the result,
/// rather than treating the register or verify response as a substitute and
/// finding a null patient record afterwards.
class MeApi {
  /// Creates the endpoint over [transport].
  const MeApi(this._transport);

  final ApiTransport _transport;

  /// `GET /api/v1/me`
  ///
  /// Requires a valid access token; a missing or expired one is a 401, which the
  /// client's interceptor repairs by refreshing and replaying.
  Future<User> show() async {
    final ApiEnvelope<Object?> envelope = await _transport.get<Object?>(
      pathMe,
      parseData: parseDataObject,
    );

    return User.fromJson(jsonMap(envelope.dataMap['user']));
  }
}

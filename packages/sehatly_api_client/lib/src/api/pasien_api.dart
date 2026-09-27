import '../core/api_envelope.dart';
import '../core/json.dart';
import '../core/pagination.dart';
import '../model/dto.dart';
import 'paths.dart';
import 'transport.dart';

/// The caller's own patient records: profile, family members, allergies.
///
/// ## Nothing here addresses anybody else's row
///
/// Every method is behind `auth:sanctum` and resolves the caller's own `pasien`
/// row server-side, through the same service that answers 403 for "not a patient
/// account" and 404 for "not your row". There is no `pasien_id` parameter in this
/// class and no way to reach another account's record through it, which is
/// deliberate: the scoping is a server-side property and a client that could
/// name a patient id would be one refactor away from an IDOR.
class PasienApi {
  /// Creates the endpoint group over [transport].
  const PasienApi(this._transport);

  final ApiTransport _transport;

  /// `GET /api/v1/pasien/profil`
  ///
  /// The caller's own `pasien` row plus `users.nama_lengkap`, which the server
  /// reads from the eager-loaded `user` relation so a profile screen does not
  /// need a second call to render a name.
  ///
  /// **The NIK is masked.** See [PasienProfile.nik]; there is no accessor
  /// anywhere in this package that returns an unmasked one.
  Future<PasienProfile> profil() async {
    final ApiEnvelope<Object?> envelope = await _transport.get<Object?>(
      pathPasienProfil,
      parseData: parseDataObject,
    );

    return PasienProfile.fromJson(jsonMap(envelope.dataMap['profile']));
  }

  /// `PUT /api/v1/pasien/profil`
  ///
  /// A **partial** update: only the keys present in [body] are written, across
  /// `users` and `pasien`, in one transaction. The server's own docblock records
  /// that full replacement was rejected so that a client editing one field does
  /// not have to re-send a birth date and NIK it might get wrong.
  ///
  /// The writable keys are exactly:
  ///
  /// | on `users` | on `pasien` |
  /// | --- | --- |
  /// | `nama_lengkap` | `tempat_lahir`, `pekerjaan`, `alamat_lengkap`, `rt`, `rw`, `kode_pos`, `golongan_darah_id`, `agama_id`, `pendidikan_id`, `status_pernikahan_id`, `provinsi_id`, `kabupaten_kota_id`, `kecamatan_id`, `kelurahan_id`, `tinggi_badan_cm`, `berat_badan_kg` |
  ///
  /// `tipe`, `status`, `no_telepon`, `nik`, `jenis_kelamin`, `tanggal_lahir`,
  /// `nomor_rm`, `user_id`, `is_meninggal` and `tanggal_meninggal` are **not**
  /// writable here. Sending one is a 422, because it is not a validated key --
  /// which is how a client discovers the rule rather than by it being silently
  /// ignored.
  ///
  /// The four wilayah ids are validated as one chain: a `kelurahan_id` that
  /// cannot belong to the supplied `provinsi_id` is a 422. That check goes beyond
  /// the plan's literal text and the server records it as such.
  ///
  /// [allowUnsafeRetry] is not a parameter here: the server rejects an
  /// unauthenticated `PUT` at the guard, before the controller, so a replay
  /// cannot double-apply. The transport passes the interceptor's opt-in flag
  /// unconditionally for this verb.
  Future<PasienProfile> updateProfil(Map<String, Object?> body) async {
    final ApiEnvelope<Object?> envelope = await _transport.put<Object?>(
      pathPasienProfil,
      body: body,
      parseData: parseDataObject,
      allowUnsafeRetry: true,
    );

    return PasienProfile.fromJson(jsonMap(envelope.dataMap['profile']));
  }

  /// `GET /api/v1/pasien/anggota-keluarga`
  ///
  /// A real page, with the `meta` block on the envelope. The server
  /// eager-loads the `hubungan` relation here, so [AnggotaKeluarga.hubungan] is
  /// populated for every row and a list can render `Ibu` rather than a bare `3`.
  Future<Paginated<AnggotaKeluarga>> anggotaKeluarga({
    PageQuery page = const PageQuery(),
  }) async {
    final ApiEnvelope<Object?> envelope = await _transport.get<Object?>(
      pathPasienAnggotaKeluarga,
      queryParameters: page.toQueryParameters(),
      parseData: parseDataObject,
    );

    return Paginated<AnggotaKeluarga>.fromEnvelope(
      data: envelope.data,
      meta: envelope.meta,
      key: 'anggota_keluarga',
      itemParser: AnggotaKeluarga.fromJson,
    );
  }

  /// `POST /api/v1/pasien/anggota-keluarga`
  ///
  /// Required: `hubungan_id`, `nama_lengkap`, `jenis_kelamin`, `tanggal_lahir`
  /// (`Y-m-d`). Optional: `nik` (exactly 16 digits), `no_telepon`,
  /// `catatan_alergi`.
  ///
  /// **`nik` here is the only place a raw NIK is submitted anywhere in this API.**
  /// It is masked on the way back out by [AnggotaKeluarga.nik], so an edit form
  /// has to keep what it sent rather than read it from the response. That is the
  /// cost the server accepts for never disclosing it.
  Future<AnggotaKeluarga> createAnggotaKeluarga(
    Map<String, Object?> body,
  ) async {
    final ApiEnvelope<Object?> envelope = await _transport.post<Object?>(
      pathPasienAnggotaKeluarga,
      body: body,
      parseData: parseDataObject,
      allowUnsafeRetry: true,
    );

    return AnggotaKeluarga.fromJson(
      jsonMap(envelope.dataMap['anggota_keluarga']),
    );
  }

  /// `PUT /api/v1/pasien/anggota-keluarga/{id}`
  ///
  /// A partial update, for the reason on [updateProfil]. Only the keys present in
  /// [body] are written, and the response is the **whole row** after the write,
  /// so a client never has to reason about which fields stuck.
  Future<AnggotaKeluarga> updateAnggotaKeluarga(
    String id,
    Map<String, Object?> body,
  ) async {
    final ApiEnvelope<Object?> envelope = await _transport.put<Object?>(
      pathPasienAnggotaKeluargaById(id),
      body: body,
      parseData: parseDataObject,
      allowUnsafeRetry: true,
    );

    return AnggotaKeluarga.fromJson(
      jsonMap(envelope.dataMap['anggota_keluarga']),
    );
  }

  /// `DELETE /api/v1/pasien/anggota-keluarga/{id}`
  ///
  /// A **hard** delete: `pasien_anggota_keluarga` has no `dihapus_at` (:269), so
  /// the schema allows nothing else. Answers 200 with
  /// `{"deleted": true, "id": <id>}` rather than 204, so a client parsing one
  /// envelope does not special-case a delete, and the echoed id lets a client
  /// that fired several deletes tell which one answered.
  ///
  /// Another patient's row is a 404, not a 403: a 403 would confirm it exists.
  Future<DeletedRow> deleteAnggotaKeluarga(String id) async {
    final ApiEnvelope<Object?> envelope = await _transport.delete<Object?>(
      pathPasienAnggotaKeluargaById(id),
      parseData: parseDataObject,
      allowUnsafeRetry: true,
    );

    return DeletedRow.fromJson(envelope.dataMap);
  }

  /// `GET /api/v1/pasien/alergi`
  ///
  /// This is the API's **only** allergy surface. `pasien.catatan_alergi` is a
  /// second, unsynchronised source and is deliberately not published by
  /// [PasienProfile], so a client that rendered both would show two lists that
  /// disagree. Note the contrast with [AnggotaKeluarga.catatanAlergi], which is
  /// about a *different person* and is not a competing source.
  Future<Paginated<PasienAlergi>> alergi({
    PageQuery page = const PageQuery(),
  }) async {
    final ApiEnvelope<Object?> envelope = await _transport.get<Object?>(
      pathPasienAlergi,
      queryParameters: page.toQueryParameters(),
      parseData: parseDataObject,
    );

    return Paginated<PasienAlergi>.fromEnvelope(
      data: envelope.data,
      meta: envelope.meta,
      key: 'alergi',
      itemParser: PasienAlergi.fromJson,
    );
  }

  /// `POST /api/v1/pasien/alergi`
  ///
  /// Required: `tipe_alergen`, `nama_alergen`. Optional: `reaksi`, `keparahan`.
  ///
  /// `pasien_id` and `dicatat_oleh_user_id` are written **server-side** from the
  /// authenticated account and are not request fields; sending either is a 422.
  ///
  /// An omitted `keparahan` comes back as `ringan`, not `null`: the column is
  /// `NOT NULL DEFAULT 'ringan'`, and the server re-reads the row after insert so
  /// the response reflects the database default rather than an unset model
  /// attribute.
  Future<PasienAlergi> createAlergi(Map<String, Object?> body) async {
    final ApiEnvelope<Object?> envelope = await _transport.post<Object?>(
      pathPasienAlergi,
      body: body,
      parseData: parseDataObject,
      allowUnsafeRetry: true,
    );

    return PasienAlergi.fromJson(jsonMap(envelope.dataMap['alergi']));
  }

  /// `PUT /api/v1/pasien/alergi/{id}`
  ///
  /// A partial update. `dicatat_oleh_user_id` is **not** rewritten: it records
  /// who first recorded the row, and a later edit by the same patient is not a
  /// second observation.
  Future<PasienAlergi> updateAlergi(
    String id,
    Map<String, Object?> body,
  ) async {
    final ApiEnvelope<Object?> envelope = await _transport.put<Object?>(
      pathPasienAlergiById(id),
      body: body,
      parseData: parseDataObject,
      allowUnsafeRetry: true,
    );

    return PasienAlergi.fromJson(jsonMap(envelope.dataMap['alergi']));
  }

  /// `DELETE /api/v1/pasien/alergi/{id}`
  ///
  /// A **hard** delete: `pasien_alergi` has no `dihapus_at` (:282). Answers 200
  /// with `{"deleted": true, "id": <id>}`. Another patient's row is a 404.
  Future<DeletedRow> deleteAlergi(String id) async {
    final ApiEnvelope<Object?> envelope = await _transport.delete<Object?>(
      pathPasienAlergiById(id),
      parseData: parseDataObject,
      allowUnsafeRetry: true,
    );

    return DeletedRow.fromJson(envelope.dataMap);
  }
}

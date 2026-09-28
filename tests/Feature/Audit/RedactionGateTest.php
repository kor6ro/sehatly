<?php

declare(strict_types=1);

use App\Services\Audit\AuditColumnPolicy;
use App\Services\Audit\AuditScope;
use App\Support\NikMasker;

require_once __DIR__.'/audit-helpers.php';

/*
|--------------------------------------------------------------------------
| The three redaction gates
|--------------------------------------------------------------------------
|
| APPENDED by todo 43.
|
| An audit row is a COPY. Everything written into one is a second copy of
| something that already exists in a table somebody chose to protect, and a
| second copy is a second thing to breach, subpoena, or leak. The allow-list is
| therefore computed, not declared, and it is computed by subtracting three
| sets from the real column list of each table:
|
|   Gate 1  the DDL TYPE. `text`, `json`, and the blob family are narrative or
|           structured payloads, which is where SOAP notes, complaint text, chat
|           bodies and notification payloads live. The database has already
|           decided these are unbounded free text, and an unbounded value has
|           no business in a bounded log.
|
|   Gate 2  the column NAME, against a credential vocabulary. `user_otp` and
|           `user_refresh_tokens` are out of the scope entirely, but a hash can
|           also appear on an in-scope table, and a name rule catches a column
|           nobody thought to add to a hand-written list.
|
|   Gate 3  the columns the DDL types as safe and that are still not safe:
|           a person's name, a diagnosis, a signature, a room id, an
|           attachment filename. These are VARCHAR, so gates 1 and 2 pass them
|           straight through, and each one has a demonstrated habit of carrying
|           the thing the whole feature exists to keep out.
|
| The gates SUBTRACT. A column must survive all three to be written at all,
| and of the survivors a short list is masked rather than dropped.
|
| @see \App\Services\Audit\AuditColumnPolicy
*/

test('gate 1 denies every textual column in the scope, so no narrative can be written', function () {
    $textual = ['text', 'longtext', 'mediumtext', 'tinytext', 'json', 'blob', 'mediumblob', 'longblob', 'tinyblob', 'binary', 'varbinary'];
    $checked = 0;

    foreach (AuditScope::auditedTables() as $table) {
        $allowed = AuditColumnPolicy::allowList($table);

        foreach (audSpec()->table($table)->columns as $column) {
            $base = strtolower((string) preg_replace('/\(.*$/', '', $column->type));

            if (in_array($base, $textual, true)) {
                expect(in_array($column->name, $allowed, true))
                    ->toBeFalse($table.'.'.$column->name.' is '.$column->type.' and gate 1 must deny it');

                $checked++;
            }
        }
    }

    // The gate has to be carrying a real workload, or this file proves nothing.
    expect($checked)->toBeGreaterThan(20, 'gate 1 should be denying a substantial number of columns');
});

test('gate 2 denies every credential column in the scope, by name', function () {
    $credentials = ['kata_sandi_hash', 'token_hash', 'kode_hash', 'qr_token', 'fcm_token'];

    // The expected set is COUNTED FROM THE DDL, not asserted as a magic number.
    // The inherited version demanded `>= 5` and the real answer is 4, which is
    // a fact about the schema rather than a defect: `token_hash` and
    // `kode_hash` live only on the two tables AuditScope excludes, so they
    // never reach gate 2. A hand-typed threshold hides exactly that.
    $expected = [];

    foreach (AuditScope::auditedTables() as $table) {
        foreach (audSpec()->table($table)->columns as $column) {
            if (in_array($column->name, $credentials, true)) {
                $expected[] = $table.'.'.$column->name;
            }
        }
    }

    $actual = [];

    foreach (AuditScope::auditedTables() as $table) {
        $allowed = AuditColumnPolicy::allowList($table);

        foreach (audSpec()->table($table)->columns as $column) {
            if (in_array($column->name, $credentials, true)) {
                expect(in_array($column->name, $allowed, true))
                    ->toBeFalse($table.'.'.$column->name.' is a credential and gate 2 must deny it');

                $actual[] = $table.'.'.$column->name;
            }

            // And the rule is a RULE, so a name nobody has seen is judged too.
            if (AuditColumnPolicy::isSecretName($column->name)) {
                expect(in_array($column->name, $allowed, true))
                    ->toBeFalse($table.'.'.$column->name.' matches the credential vocabulary');
            }
        }
    }

    expect($actual)->toBe($expected);
    // Pest's toContain is VARIADIC, so a second argument is read as another
    // needle rather than as a message. Assert on the needle alone.
    expect($expected)->toContain('users.kata_sandi_hash');

    // The vocabulary discriminates: a credential is denied, a name is not.
    foreach ($credentials as $credential) {
        expect(AuditColumnPolicy::isSecretName($credential))->toBeTrue($credential);
    }

    foreach (['nama_lengkap', 'status', 'pasien_id', 'tipe_layanan', 'tanggal_lahir'] as $ordinary) {
        expect(AuditColumnPolicy::isSecretName($ordinary))->toBeFalse($ordinary);
    }
});

test('gate 3 denies the VARCHAR columns the DDL types as safe and that are still not safe', function () {
    // Each of these is a VARCHAR, so gates 1 and 2 pass it through untouched.
    $named = [
        ['users', 'nama_lengkap'],
        ['users', 'foto_profil'],
        ['pasien', 'tempat_lahir'],
        ['pasien', 'pekerjaan'],
        ['pasien', 'alamat_lengkap'],
        ['pasien', 'rt'],
        ['pasien', 'rw'],
        ['pasien', 'kode_pos'],
        ['pasien_anggota_keluarga', 'nama_lengkap'],
        ['pasien_alergi', 'nama_alergen'],
        ['pasien_alergi', 'reaksi'],
        ['pasien_riwayat_penyakit', 'nama_penyakit'],
        ['pasien_imunisasi', 'nama_vaksin'],
        ['pasien_imunisasi', 'pemberi'],
        ['pasien_imunisasi', 'no_batch'],
        ['pasien_penjamin', 'nomor_peserta'],
        ['pasien_penjamin', 'file_kartu'],
        ['dokter', 'file_str_url'],
        ['dokter', 'file_sip_url'],
        ['dokter_libur', 'alasan'],
        ['dokter_pendidikan', 'institusi'],
        ['rekam_medis', 'diagnosis_kerja'],
        ['rekam_medis', 'satusehat_encounter_id'],
        ['rekam_medis_diagnosa', 'deskripsi'],
        ['rekam_medis_tindakan', 'nama_tindakan'],
        ['rekam_medis_lampiran', 'nama_file'],
        ['rekam_medis_lampiran', 'file_url'],
        ['rekam_medis_persetujuan', 'ditandatangani_oleh'],
        ['rekam_medis_persetujuan', 'tanda_tangan_url'],
        ['booking', 'alasan_pembatalan'],
        ['konsultasi', 'diagnosis_kerja'],
        ['konsultasi', 'room_id'],
        ['konsultasi_chat', 'file_url'],
        ['konsultasi_chat', 'file_nama'],
        ['surat_keterangan', 'file_url'],
        ['ulasan_dokter', 'isi'],
        ['notifikasi', 'isi'],
        ['notifikasi', 'judul'],
        ['notifikasi', 'tautan'],
        ['artikel', 'judul'],
        ['artikel', 'slug'],
        ['artikel', 'ringkasan'],
        ['artikel', 'cover_url'],
        ['resep_item', 'nama_obat'],
        ['resep_item', 'kekuatan'],
        ['resep_item', 'aturan_pakai'],
        ['resep_item', 'racikan_nama'],
        ['lab_hasil', 'nilai'],
        ['lab_hasil', 'nilai_rujukan'],
        ['lab_hasil', 'file_pdf_url'],
        ['lab_permintaan', 'nomor_permintaan'],
        ['pembayaran', 'nomor_referensi'],
        ['pembayaran', 'va_number'],
        ['pesanan_obat', 'no_resi'],
        ['pesanan_obat_tracking', 'keterangan'],
        ['pesanan_obat_tracking', 'lokasi'],
        ['refund', 'alasan'],
        ['rujukan', 'diagnosis_kerja'],
    ];

    foreach ($named as [$table, $column]) {
        expect(in_array($column, AuditColumnPolicy::allowList($table), true))
            ->toBeFalse($table.'.'.$column.' must be denied');
    }

    // Cross-checked against the POLICY rather than against a hand-typed count.
    // A literal `60` here would keep passing if somebody added a deny pair to
    // the policy and forgot to add it here, which is precisely the drift the
    // list is supposed to catch. The count is still pinned, just derived.
    expect($named)->toBe(AuditColumnPolicy::EXPLICIT_DENY);
    expect($named)->not->toBeEmpty();
});

test('every explicit-deny pair names a real column, so a typo cannot silently un-deny one', function () {
    // A pair that names nothing denies nothing, and the gate-3 test above
    // passes vacuously for it - `in_array` over an allow-list that never
    // contained the phantom is trivially false. This pins totality against
    // the DDL instead: the pair, the table and the column must all resolve.
    //
    // This once caught three live defects: `pasien.nama_lengkap` (the name
    // lives on `users`), `resep.catatan_apoteker` (the pharmacist note is
    // `resep_verifikasi.catatan`), and the misspelt `booking.alasan_pebatalan`
    // for the real `alasan_pembatalan` - the last of which left a genuine
    // free-text column allow-listed while looking denied.
    foreach (AuditColumnPolicy::EXPLICIT_DENY as [$table, $column]) {
        $spec = audSpec()->table($table);

        expect($spec)->not->toBeNull();
        expect($spec->columns)->toHaveKey($column);
    }
});

test('the allow-list is a sorted, duplicate-free subset of the real columns', function () {
    foreach (AuditScope::auditedTables() as $table) {
        $real = array_keys(audSpec()->table($table)->columns);
        $allowed = AuditColumnPolicy::allowList($table);

        foreach ($allowed as $column) {
            expect(in_array($column, $real, true))->toBeTrue($table.'.'.$column.' is not a real column');
        }

        // Sorted and unique, so two runs of the same write produce the same
        // JSON and a diff of two audit rows shows a real change rather than a
        // reshuffle.
        expect($allowed)->toBe(array_values(array_unique($allowed)), $table.' duplicates');

        $sorted = $allowed;
        sort($sorted);
        expect($allowed)->toBe($sorted, $table.' ordering');
    }
});

test('every MASKED rule names a column that really is allow-listed somewhere', function () {
    // A rule that points at nothing is a rule that stopped being checked the
    // day somebody renamed the column, so totality is asserted.
    $allowLists = [];

    foreach (AuditScope::auditedTables() as $table) {
        foreach (AuditColumnPolicy::allowList($table) as $column) {
            $allowLists[$column][] = $table;
        }
    }

    expect(AuditColumnPolicy::MASKED)->not->toBeEmpty();

    foreach (array_keys(AuditColumnPolicy::MASKED) as $column) {
        // `toHaveKey($key, $value)` takes a VALUE as its second argument, not a
        // message, and `toContain` is variadic - so both of the inherited
        // assertions here were really asserting that the collection contained
        // the explanation STRING. That is how the previous version failed with
        // "does not match expected type string" instead of naming the column.
        // The needle alone is asserted, and the collection is reported by the
        // expectation itself when it fails.
        expect(array_keys($allowLists))->toContain($column);
    }
});

test('every MASKED rule carries a written decision, and the phone and email rules are explicit', function () {
    // The brief asks for the phone and email decision to be argued rather than
    // assumed, so the reasoning is part of the code and this test is what stops
    // it being deleted as "unused documentation".
    foreach (['no_telepon', 'email'] as $column) {
        expect(AuditColumnPolicy::DECISIONS)->toHaveKey($column);
        expect(trim(AuditColumnPolicy::DECISIONS[$column]))->not->toBe('');
        expect(strlen(AuditColumnPolicy::DECISIONS[$column]))->toBeGreaterThan(40, $column);
    }

    // Both are MASKED rather than denied, which is the deliberate part: a
    // masked phone still answers "which of my two numbers was on file".
    expect(AuditColumnPolicy::MASKED)->toHaveKey('no_telepon');
    expect(AuditColumnPolicy::MASKED)->toHaveKey('email');

    // And every rule declares how it redacts, so the map is data, not a list of
    // column names that some later branch has to interpret.
    foreach (AuditColumnPolicy::MASKED as $column => $rule) {
        expect($rule)->toBeString($column);
        expect($rule)->not->toBe('', $column);
    }
});

test('the NIK-shaped value sweep catches a NIK under a column name no rule names', function () {
    // The MASKED map is a convenience keyed by column name. The guarantee is
    // the value sweep, so this tests the sweep directly on a key the map has
    // never heard of. This is the assertion that would have caught a NIK typed
    // into an allow-listed free-text field.
    $swept = AuditColumnPolicy::redactValue('1234567890123456', 'some_unlisted_column');
    $namedKey = AuditColumnPolicy::redactValue('1234567890123456', 'nik');

    expect($swept)->not->toBe('1234567890123456');
    expect($swept)->toBe($namedKey, 'the sweep must not depend on the column name');

    // The mask character is the project's canonical one, U+2022 BULLET, because
    // the audit log and the API responses publish the SAME identifier and a
    // second mask character would mean the same NIK looks different depending
    // on which endpoint you asked. Reuse, not a second masker.
    expect($swept)->toContain(NikMasker::PENGGANTI);
    expect($swept)->toBe(NikMasker::mask('1234567890123456'));

    // A value that is not NIK shaped is left alone by the sweep, so the sweep
    // is not simply destroying every long number.
    expect(AuditColumnPolicy::redactValue('2026', 'tahun_lahir'))->toBe('2026');
    expect(AuditColumnPolicy::redactValue('081234567890', 'tahun_lahir'))
        ->toBe('081234567890', 'a phone number is not a NIK run and the sweep leaves it');

    // The phone rule still applies BY NAME, which is the part the sweep cannot
    // do: 081234567890 is only 12 digits, so only the column rule masks it.
    expect(AuditColumnPolicy::redactValue('081234567890', 'no_telepon'))
        ->not->toBe('081234567890');
    expect(AuditColumnPolicy::redactValue('081234567890', 'no_telepon'))
        ->toBe(NikMasker::mask('081234567890'));
});

<?php

declare(strict_types=1);

use App\Services\Audit\AuditColumnPolicy;
use App\Support\NikMasker;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/audit-helpers.php';

/*
|--------------------------------------------------------------------------
| The absence claims, each paired with a scanner that is itself proven
|--------------------------------------------------------------------------
|
| APPENDED by todo 43.
|
| "The serialised row contains no NIK" is a claim about a string nobody is
| reading. On its own it is indistinguishable from a test that passes because
| the assertion never runs, or because the scanner matches nothing at all.
|
| So the first test in this file is a CONTROL: the same scanner, fed a row that
| really does contain a NIK and a bcrypt hash, must report it. Only once the
| scanner is shown to be capable of finding those two things does "not found"
| mean anything in the tests that follow.
|
| Every absence assertion in this project is written against the WHOLE row, not
| against a column the author expected to be interesting. A redaction bug that
| copies a column nobody thought of is invisible to a per-column assertion and
| visible to this one.
|
| @see \App\Services\Audit\AuditRedactor
| @see \App\Support\NikMasker
*/

test('CONTROL: the row scanner finds a NIK and a hash when they really are there', function () {
    // Without this, every absence assertion below would be satisfied by a
    // scanner that silently matches nothing.
    $nik = '3201234567890123';
    $hash = password_hash('known', PASSWORD_BCRYPT);

    $planted = (object) ['data_baru' => json_encode(['nik' => $nik, 'kata_sandi_hash' => $hash])];

    expect(audFindInRow($planted, $nik))->toBe(['value:data_baru']);
    expect(audFindInRow($planted, $hash))->toBe(['value:data_baru']);
    expect(audFindInRow($planted, substr($hash, 0, 8)))->toBe(['value:data_baru'], 'a hash prefix must be findable');
    expect(audFindInRow($planted, '320123'))->toBe(['value:data_baru'], 'any substring must be findable');
});

test('a NIK is never stored in an audit row, in any of the three identifier columns', function () {
    $nik = '3273014507910001';
    $kk = '3273014507910002';
    $ihs = 'IHS-770011223344';

    // Created through the MODEL, not the query builder: this test is about the
    // `pasien` create row, and a query-builder insert fires no model event, so
    // there would be no row to inspect. `rhesus` is set explicitly rather than
    // left to the column default, because Eloquent's attributes after an insert
    // are what was assigned, not what MySQL filled in.
    $pasien = audPasienModel([
        'nik' => $nik,
        'nomor_kk' => $kk,
        'nomor_ihs_satusehat' => $ihs,
        'rhesus' => 'O',
    ]);

    $row = audOne('pasien', (int) $pasien->getKey());
    $whole = audWholeRow($row);

    // Absence from the whole row, not from a key we expected.
    expect(audFindInRow($row, $nik))->toBe([], 'pasien.nik');
    expect(audFindInRow($row, $kk))->toBe([], 'pasien.nomor_kk');
    expect(audFindInRow($row, $ihs))->toBe([], 'pasien.nomor_ihs_satusehat');
    expect($whole)->not->toContain('nik_cipher');

    // Not merely dropped: the masked form is stored, so an auditor can still
    // correlate one NIK across rows without ever holding the NIK.
    $payload = audPayload($row, 'data_baru');

    expect($payload['nik'])->toBe(NikMasker::mask($nik));
    expect($payload['nik'])->not->toBe($nik);
    expect($payload['nomor_kk'])->toBe(NikMasker::mask($kk));
    expect($payload['nomor_ihs_satusehat'])->toBe(NikMasker::mask($ihs));

    // And the row still says enough to be useful: the demographics a breach
    // officer would ask about, none of which is an identifier.
    expect($payload)->toHaveKeys(['tanggal_lahir', 'jenis_kelamin', 'rhesus']);
});

test('a NIK is masked on a create and stays masked through an update and a delete', function () {
    $nik = '3201113001990003';
    $pasien = audPasienModel(['nik' => $nik, 'tempat_lahir' => 'Bandung']);
    $pasienId = (int) $pasien->getKey();

    $row = audOne('pasien', $pasienId);
    expect(audFindInRow($row, $nik))->toBe([], 'create');

    // An update is the moment a naive before/after copies the old value forward.
    $pasien->setAttribute('pekerjaan', 'Guru');
    $pasien->save();

    $update = audOne('pasien', $pasienId, 'update');
    expect(audFindInRow($update, $nik))->toBe([], 'update data_baru');

    $pasien->delete();

    $delete = audOne('pasien', $pasienId, 'delete');
    expect(audFindInRow($delete, $nik))->toBe([], 'delete data_lama');
    expect(audFindInRow($delete, 'Bandung'))->toBe([], 'tempat_lahir is denied');
});

test('the password hash is absent from an audit row in every form', function () {
    $hash = password_hash('known-password', PASSWORD_BCRYPT);
    $email = 'aud.hash@example.test';

    $user = audUserModel(['email' => $email, 'kata_sandi_hash' => $hash]);

    // A change of password is the only moment the hash is a changed value, so
    // it is the moment a before/after implementation would copy it.
    $user->setAttribute('kata_sandi_hash', password_hash('another-password', PASSWORD_BCRYPT));
    $user->setAttribute('nama_lengkap', 'Aud Nama Baru');
    $user->save();

    $rows = audRowsFor('users', (int) $user->getKey());

    expect($rows)->toHaveCount(2, 'create plus one update');

    foreach ($rows as $row) {
        $whole = audWholeRow($row);

        foreach (audHashFingerprints($hash) as $what => $fingerprint) {
            expect($whole)->not->toContain($fingerprint, "kata_sandi_hash leaked as {$what}");
        }

        // The column NAME must not appear either: a key named kata_sandi_hash
        // with a null value is a schema disclosure even when the value is gone.
        expect($whole)->not->toContain('kata_sandi_hash');
        expect($whole)->not->toContain('nama_lengkap', 'a name is denied, so the key must be absent too');

        // And the masked email is not the email.
        expect($whole)->not->toContain($email);
    }
});

test('a clinical narrative never reaches an audit row, on the three tables the brief names', function () {
    $secret = 'Batuk productive disertai sesak napas sejak lima hari.';

    $pasienId = audPasien();
    $dokterId = audDokter();
    $userId = audUserRow();

    $record = audRecord($pasienId, $dokterId, [
        'subjektif' => $secret,
        'objektif' => $secret,
        'asesmen' => $secret,
        'plan' => $secret,
        'diagnosis_kerja' => 'Pneumonia suspect',
    ]);

    $booking = audBooking($pasienId, $dokterId, $userId, ['keluhan' => $secret]);

    $konsultasi = audKonsultasi($pasienId, $dokterId, ['diagnosis_kerja' => $secret, 'catatan_plan' => $secret]);
    $chat = audChat((int) $konsultasi->getKey(), $userId, [
        'isi' => $secret,
        'file_nama' => 'resep-obat.pdf',
        'file_url' => 'https://cdn.example.test/a/b/resep-obat.pdf',
    ]);

    $cases = [
        ['rekam_medis', (int) $record->getKey()],
        ['booking', (int) $booking->getKey()],
        ['konsultasi', (int) $konsultasi->getKey()],
        ['konsultasi_chat', (int) $chat->getKey()],
    ];

    foreach ($cases as [$table, $id]) {
        $row = audOne($table, $id);

        expect(audFindInRow($row, $secret))->toBe([], $table.' leaked the narrative');
        expect(audFindInRow($row, 'Pneumonia suspect'))->toBe([], $table.' leaked the diagnosis');
        expect(audFindInRow($row, 'resep-obat.pdf'))->toBe([], $table.' leaked the attachment name');
    }

    // Not vacuous: the narrative really is in the source rows, so the assertions
    // above are about a live hazard rather than a fixture that never wrote it.
    expect(DB::table('rekam_medis')->where('id', $record->getKey())->value('subjektif'))->toBe($secret);
    expect(DB::table('booking')->where('id', $booking->getKey())->value('keluhan'))->toBe($secret);
    expect(DB::table('konsultasi_chat')->where('id', $chat->getKey())->value('isi'))->toBe($secret);
    expect(DB::table('konsultasi_chat')->where('id', $chat->getKey())->value('file_nama'))->toBe('resep-obat.pdf');
});

test('a narrative written in a LANGUAGE the code does not speak is denied just the same', function () {
    // A redaction rule that only recognises the alphabet its author reads is
    // not a redaction rule. This narrative is deliberately not ASCII.
    $secret = 'Pasien mengeluh kembung dan mual setelah makan';

    $pasienId = audPasien();
    $dokterId = audDokter();

    $record = audRecord($pasienId, $dokterId, ['subjektif' => $secret, 'keluhan_utama' => $secret]);
    $row = audOne('rekam_medis', (int) $record->getKey());

    expect(audFindInRow($row, $secret))->toBe([]);

    // The source row really does hold it.
    expect(DB::table('rekam_medis')->where('id', $record->getKey())->value('subjektif'))->toBe($secret);
});

test('no_telepon and email are masked rather than dropped, and the decision is arguable', function () {
    $phone = '081234567890';
    $email = 'dewi.santoso@example.test';

    $user = audUserModel([
        'nama_lengkap' => 'Dewi Santoso',
        'email' => $email,
        'no_telepon' => $phone,
    ]);

    $row = audOne('users', (int) $user->getKey());
    $whole = audWholeRow($row);
    $payload = audPayload($row, 'data_baru');

    // The whole address is gone, including the local part and the domain.
    expect(audFindInRow($row, $phone))->toBe([], 'phone');
    expect(audFindInRow($row, $email))->toBe([], 'email');
    expect(audFindInRow($row, 'dewi.santoso'))->toBe([], 'email local part');
    expect(audFindInRow($row, 'example.test'))->toBe([], 'email domain');
    expect($whole)->not->toContain('Dewi Santoso', 'a name is denied');

    // Kept, not dropped: a masked phone still answers "which of my two numbers
    // was on file", and a masked email still answers "was this address the
    // verified one", which is the question the row exists to answer.
    expect($payload['no_telepon'])->toBeString()->not->toBe('');
    expect($payload['email'])->toBeString()->not->toBe('');
    expect($payload['email'])->toContain('@', 'the @ survives so the field is still recognisable as an email');
    expect($payload['email'])->not->toContain('example');
    expect($payload['no_telepon'])->toContain(NikMasker::PENGGANTI, 'the phone rule uses the project\'s canonical mask character');

    // The reasoning is in the code, and this stops it being deleted as
    // "unused documentation" the first time somebody asks why email is not
    // simply nulled.
    expect(AuditColumnPolicy::DECISIONS)->toHaveKey('no_telepon');
    expect(AuditColumnPolicy::DECISIONS)->toHaveKey('email');
    expect(strlen(AuditColumnPolicy::DECISIONS['no_telepon']))->toBeGreaterThan(40);
    expect(strlen(AuditColumnPolicy::DECISIONS['email']))->toBeGreaterThan(40);
});

test('a foreign key survives, so the row can still say whose data this was', function () {
    $pasienId = audPasien();
    $dokterId = audDokter();

    $payload = audPayload(audOne('rekam_medis', (int) audRecord($pasienId, $dokterId)->getKey()), 'data_baru');

    // A surrogate key is not personal data on its own, and without it the row
    // cannot answer "which patient's record was touched", which is the first
    // question anybody asks of an audit log.
    expect((int) $payload['pasien_id'])->toBe($pasienId);
    expect((int) $payload['dokter_id'])->toBe($dokterId);

    // But the national-system identifier is a different thing: it is a
    // credential into another country's health record, so it is gone.
    expect($payload)->not->toHaveKey('satusehat_encounter_id');
});

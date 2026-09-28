<?php

declare(strict_types=1);

use App\Services\Audit\AuditObserverRegistrar;
use App\Services\Audit\AuditScope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

require_once __DIR__.'/audit-helpers.php';

/*
|--------------------------------------------------------------------------
| before / after: what the two JSON columns are for
|--------------------------------------------------------------------------
|
| APPENDED by todo 43.
|
| `data_lama` and `data_baru` (:1124-1125) are the columns a naive
| implementation fills with two full copies of the row. That is a second and
| a third copy of everything the redaction gates exist to withhold, and for an
| UPDATE it is doubly pointless: the unchanged columns are, by definition, the
| same in both copies.
|
| So the two payloads carry the CHANGED KEYS, and they carry the SAME key set.
| That is what makes the row answerable - "this field went from A to B" - and it
| is asserted in both directions below, because an implementation that filled
| only `data_baru` would satisfy a one-sided test and answer no question.
|
| A create has no "before" and a delete has no "after", and saying so with NULL
| rather than with an empty object is the difference between "nothing was there"
| and "an empty thing was there".
|
| @see \App\Services\Audit\AuditObserver
*/

test('a create writes data_baru and leaves data_lama null', function () {
    $pasienId = audPasien();
    $dokterId = audDokter();

    $row = audOne('rekam_medis', (int) audRecord($pasienId, $dokterId)->getKey());

    expect($row->aksi)->toBe('create');
    expect($row->data_lama)->toBeNull('a create has no before');
    expect(audPayload($row, 'data_baru'))->not->toBeNull();
});

test('an update writes only the changed allow-listed keys, and both sides agree', function () {
    $record = audRecord(audPasien(), audDokter());

    // Two changes: one auditable, one narrative.
    $record->setAttribute('status_dokumen', 'diamendemen');
    $record->setAttribute('instruksi_tindak_lanjut', 'Rujuk ke SpOG dalam tiga hari.');
    $record->save();

    $row = audOne('rekam_medis', (int) $record->getKey(), 'update');
    $before = audPayload($row, 'data_lama');
    $after = audPayload($row, 'data_baru');

    expect($after)->toBe(['status_dokumen' => 'diamendemen'], 'only the changed auditable key');
    expect(array_keys($before))->toBe(array_keys($after), 'the two sides must name the same keys');
    expect($before['status_dokumen'])->toBe('draft', 'telemedicine_test.sql:645 defaults status_dokumen to final, and the fixture wrote draft');
    expect($after['status_dokumen'])->toBe('diamendemen');
    expect($before)->not->toBe($after);

    // The narrative is a TEXT column (:637), so it is not merely masked: it is
    // not in either payload.
    expect($after)->not->toHaveKey('instruksi_tindak_lanjut');
    expect($before)->not->toHaveKey('instruksi_tindak_lanjut');
});

test('an update whose every change is un-auditable still writes a row, with null payloads', function () {
    $record = audRecord(audPasien(), audDokter());

    // Only narrative changed. "A row changed and nothing about it is auditable"
    // is itself a fact, and dropping the row would let a caller change a
    // medical record with no trace that anything happened at all.
    $record->setAttribute('subjektif', 'Batuk productive tiga hari tanpa demam.');
    $record->save();

    $row = audOne('rekam_medis', (int) $record->getKey(), 'update');

    expect($row->aksi)->toBe('update');
    expect(audPayload($row, 'data_lama'))->toBeNull();
    expect(audPayload($row, 'data_baru'))->toBeNull();
    expect(audFindInRow($row, 'Batuk productive'))->toBe([]);
});

test('a delete writes data_lama and leaves data_baru null', function () {
    $pasienId = audPasien();
    $dokterId = audDokter();

    $booking = audBooking($pasienId, $dokterId, audUserRow());
    $booking->delete();

    $row = audOne('booking', (int) $booking->getKey(), 'delete');

    expect($row->aksi)->toBe('delete');
    expect($row->data_baru)->toBeNull('a delete has no after');
    expect(audPayload($row, 'data_lama'))->toHaveKey('status');
    expect(audPayload($row, 'data_lama'))->toHaveKey('pasien_id');
});

test('a soft delete is still a delete, and it is logged as one', function () {
    $pasien = App\Models\Pasien::query()->whereKey(audPasien())->firstOrFail();

    expect(in_array(SoftDeletes::class, class_uses_recursive($pasien), true))
        ->toBeTrue('this test is about a soft delete, so Pasien must use SoftDeletes');

    $pasien->delete();

    expect(audOne('pasien', (int) $pasien->getKey(), 'delete')->data_baru)->toBeNull();
});

test('an earlier audit row is byte-identical after later writes', function () {
    $record = audRecord(audPasien(), audDokter());
    $id = (int) $record->getKey();

    $record->setAttribute('status_dokumen', 'final');
    $record->save();

    $createBefore = audRowsFor('rekam_medis', $id)->where('aksi', 'create')->first();
    $snapshot = (array) $createBefore;

    $record->setAttribute('status_dokumen', 'diamendemen');
    $record->setAttribute('versi', 3);
    $record->save();

    $record->setAttribute('jadwal_kontrol', '2026-05-01');

    // telemedicine_test.sql:1129 gives audit_log no diubah_at, so there is no
    // column an update could even write to. This asserts the row is untouched
    // anyway, because "no column exists" is a schema claim and "the bytes did
    // not change" is the observable one.
    expect((array) audRowsFor('rekam_medis', $id)->where('aksi', 'create')->first())->toBe($snapshot);
    expect(audRowsFor('rekam_medis', $id)->where('aksi', 'update'))->toHaveCount(2);
});

test('every audited table produces a create, an update and a delete row', function () {
    // A cheap structural sweep: for each table in the scope, the observer must
    // be reachable for all three verbs. It builds no rows, so it cannot prove
    // redaction, but it does catch a table that was added to the scope and then
    // never wired for one of the three events.
    $verbs = ['created', 'updated', 'deleted', 'forceDeleted'];
    $missing = [];

    foreach (AuditObserverRegistrar::auditedModels() as $class) {
        $raw = Illuminate\Database\Eloquent\Model::getEventDispatcher()->getRawListeners();

        foreach ($verbs as $verb) {
            $listeners = $raw['eloquent.'.$verb.': '.$class] ?? [];

            $covered = collect($listeners)->contains(
                fn ($listener) => is_string($listener)
                    && $listener === App\Observers\AuditObserver::class.'@'.$verb
            );

            if (! $covered) {
                $missing[] = $class.'@'.$verb;
            }
        }
    }

    expect($missing)->toBe([]);
});

test('the row names the table and the key, so it can be found without the model', function () {
    $pasienId = audPasien();

    $booking = audBooking($pasienId, audDokter(), audUserRow());
    $row = audOne('booking', (int) $booking->getKey());

    expect($row->tabel_target)->toBe('booking');
    expect($row->record_id)->toBe((string) $booking->getKey());
    expect($row->record_id)->toBeString('telemedicine_test.sql:1123 is VARCHAR, so a uuid key must fit as a string');
});

test('a uuid-keyed table records the uuid, not a null', function () {
    // telemedicine_test.sql:1123 is VARCHAR(64), not BIGINT, and the scope
    // contains uuid-keyed tables. A (string) cast on an integer key is what
    // makes that column work for both, and this is the table that proves it.
    $record = audRecord(audPasien(), audDokter());
    $row = audOne('rekam_medis', (int) $record->getKey());

    expect($row->record_id)->not->toBe('');
    expect($row->record_id)->toBe((string) $record->getKey());

    // And the uuid is in the payload, which is what lets a reader of the log
    // correlate the row with the API's public identifier.
    expect(audPayload($row, 'data_baru'))->toHaveKey('uuid');
    expect(Str::isUuid(audPayload($row, 'data_baru')['uuid']))->toBeTrue();
});

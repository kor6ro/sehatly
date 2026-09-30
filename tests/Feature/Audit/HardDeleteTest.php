<?php

declare(strict_types=1);

use App\Models\Concerns\RefusesHardDelete;
use App\Models\RekamMedis;
use App\Models\RekamMedisDiagnosa;
use App\Models\RekamMedisLampiran;
use App\Models\RekamMedisPersetujuan;
use App\Models\RekamMedisTindakan;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/audit-helpers.php';

/*
|--------------------------------------------------------------------------
| A medical record is never hard deleted
|--------------------------------------------------------------------------
|
| APPENDED by todo 43.
|
| An audit log that records a delete which then actually removes the record is
| not much of a control: the row it names is gone, and the log can only say
| that something used to exist. So the two tables that the reference SQL
| cascades FROM are protected.
|
| The guard has to be a THROW rather than a silent no-op. A silent `false` from
| a `deleting` listener is indistinguishable, at the call site, from a delete
| that worked, and a caller that believes it deleted a medical record will not
| go looking for the absence of an exception.
|
| ## What is and is not claimed
|
| Claimed, and tested: `forceDelete()` and `delete()` on `RekamMedis` and its
| four children throw, and the rows survive.
|
| NOT claimed: a raw `DB::table('rekam_medis')->delete()` issued by application
| code. A query-builder delete fires no model event, so no observer, no
| `deleting` listener, and no `Auditable` trait can see it. The codebase issues
| those in TEST teardown (`BookingConcurrencyTest`) and in the admin
| maintenance paths; closing that gap means denying the privilege at the
| database, which is a migration and a privilege grant, not an observer. It is
| recorded here rather than left as an unstated gap in the coverage.
|
| @see \App\Models\Concerns\RefusesHardDelete
*/

test('the guard is on the record root and all four children', function () {
    $guarded = [
        RekamMedis::class,
        RekamMedisDiagnosa::class,
        RekamMedisLampiran::class,
        RekamMedisPersetujuan::class,
        RekamMedisTindakan::class,
    ];

    foreach ($guarded as $class) {
        expect(in_array(RefusesHardDelete::class, class_uses_recursive($class), true))
            ->toBeTrue($class.' must use RefusesHardDelete');
    }

    // And it is not scattered further than the medical record: a patient may
    // be hard deleted by a privacy request, and pretending otherwise would
    // make the guard meaningless.
    foreach (glob(base_path('app/Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (in_array($class, $guarded, true)) {
            continue;
        }

        expect(in_array(RefusesHardDelete::class, class_uses_recursive($class), true))
            ->toBeFalse($class.' must not carry the medical-record guard');
    }
});

test('forceDelete throws on the record root and all four children', function () {
    $classes = [RekamMedis::class, RekamMedisDiagnosa::class, RekamMedisLampiran::class, RekamMedisPersetujuan::class, RekamMedisTindakan::class];

    foreach ($classes as $class) {
        $model = new $class;

        // `toThrow($class, $message)` compares $message EXACTLY, so it cannot
        // carry a per-class explanatory string. Assert the type here and the
        // text separately below, where a partial match is what is wanted.
        try {
            $model->forceDelete();

            expect(false)->toBeTrue($class.'::forceDelete() returned without throwing');
        } catch (LogicException $exception) {
            expect($exception->getMessage())->toContain($class);
            expect($exception->getMessage())->toContain('forceDelete()');
            expect($exception->getMessage())->toContain((new $class)->getTable());
            expect($exception->getMessage())->toContain('RefusesHardDelete');
        }
    }
});

test('delete throws too, because rekam_medis has no soft-delete column to fall back to', function () {
    // telemedicine_test.sql:621 declares rekam_medis with `dibuat_at` (:648) and
    // `diubah_at` (:649) and NO `dihapus_at`, so `delete()` on this model is a
    // HARD delete. Allowing `delete()` while blocking `forceDelete()` would be
    // blocking the labelled door and leaving the window open.
    //
    // The fixture is a PERSISTED row, not `new RekamMedis`. `Model::delete()`
    // returns early when `exists` is false, before any `deleting` event fires,
    // so a phantom instance proves nothing about the guard - it would pass
    // whether or not the listener were registered.
    $record = audRecord(audPasien(), audDokter());

    expect(audSpec()->table('rekam_medis')->columns)->not->toHaveKey('dihapus_at');
    expect(in_array(SoftDeletes::class, class_uses_recursive($record), true))
        ->toBeFalse('rekam_medis is not a soft-deleting model, so delete() is destructive');
    expect($record->exists)->toBeTrue();

    expect(fn () => $record->delete())
        ->toThrow(LogicException::class);
});

test('a refused hard delete leaves the row, its children, and no audit trail of a delete', function () {
    $pasienId = audPasien();
    $dokterId = audDokter();
    $record = audRecord($pasienId, $dokterId);
    $recordId = (int) $record->getKey();

    $childId = (int) DB::table('rekam_medis_diagnosa')->insertGetId([
        'rekam_medis_id' => $recordId,
        'icd10_kode' => 'R05.1',
        'jenis' => 'utama',
    ]);

    $before = (array) DB::table('rekam_medis')->where('id', $recordId)->first();

    $thrown = false;

    try {
        $record->forceDelete();
    } catch (LogicException) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();

    // The root survived, so the ON DELETE CASCADE never started and the child
    // is still there. Read with the query builder because hydrating a
    // RekamMedis outside a RekamMedisReadScope is itself refused.
    expect((array) DB::table('rekam_medis')->where('id', $recordId)->first())->toBe($before);
    expect(DB::table('rekam_medis_diagnosa')->where('id', $childId)->exists())->toBeTrue();

    // And no delete row was written, because nothing was deleted. A log that
    // claims a deletion that did not happen is worse than no log.
    expect(audRowsFor('rekam_medis', $recordId)->where('aksi', 'delete'))->toHaveCount(0);
    expect(audRowsFor('rekam_medis', $recordId)->where('aksi', 'create'))->toHaveCount(1);
});

test('the reference SQL really does cascade from rekam_medis, which is why the guard is here', function () {
    $children = ['rekam_medis_diagnosa', 'rekam_medis_lampiran', 'rekam_medis_persetujuan', 'rekam_medis_tindakan'];

    foreach ($children as $child) {
        $spec = audSpec()->table($child);
        $onDelete = null;

        foreach ($spec->foreignKeys as $foreignKey) {
            if ($foreignKey->referencedTable === 'rekam_medis') {
                $onDelete = $foreignKey->onDelete;
            }
        }

        expect($onDelete)->toBe('CASCADE', $child.' must really cascade from rekam_medis');
    }
});

test('the refusal is loud enough that a caller cannot mistake it for success', function () {
    $record = audRecord(audPasien(), audDokter());

    // A `false` return, or a silent no-op, is what a caller writes code
    // against by accident. The message names the class and the reason so the
    // failure reads as a design constraint rather than a bug report.
    try {
        $record->forceDelete();
        expect(false)->toBeTrue('forceDelete() returned without throwing');
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toContain('rekam_medis');
        expect($exception->getMessage())->toContain('RefusesHardDelete');
    }
});

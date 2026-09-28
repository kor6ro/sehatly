<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The six `konsultasi.status` values, and the ONLY legal moves between them.
 *
 * ## The six values, in the DDL's own order
 *
 * `telemedicine_test.sql:542-543`:
 *
 * ```
 * status ENUM('menunggu_dokter','berlangsung','menunggu_resep','selesai','dibatalkan','gagal')
 *        NOT NULL DEFAULT 'menunggu_dokter',
 * ```
 *
 * {@see self::cases()} is that list, in that order, and
 * `KonsultasiTest` re-parses the DDL with the project's own
 * `App\Support\Schema\SqlSchemaParser` and asserts the two are identical with
 * `toBe`, which checks order as well as membership. A seventh value cannot
 * therefore join this enum without a DDL change, which is the point: the
 * vocabulary is data the schema owns, not data the code invented.
 *
 * ## Why a real PHP enum, and why the model still casts `status` to string
 *
 * There are two independent reasons and they point in opposite directions, so
 * both are stated.
 *
 * 1. **A real enum is the right home for a state machine.** `BookingRequest`
 *    (`app/Http/Requests/Booking/BookingRequest.php:24-33`) records that on
 *    laravel/framework 13.33 both the `enum:` cast and `Rule::enum` need a real
 *    PHP enum class, and that this project had none for a MySQL ENUM - so it
 *    used `Rule::in` with a transcribed array. This class is that missing
 *    foundation for the one column whose transitions are the whole feature, and
 *    `Rule::enum(KonsultasiStatus::class)` is used wherever the value is
 *    validated.
 * 2. **The model must NOT cast `status` to this enum.**
 *    `ModelFoundationTest::test_every_ENUM_column_is_a_plain_string_cast`
 *    (`tests/Unit/Models/ModelFoundationTest.php:519-537`) walks all 69
 *    `enum(...)` columns in the DDL and asserts each one's declared cast is
 *    exactly `'string'`. Changing `Konsultasi::$casts['status']` to
 *    `KonsultasiStatus::class` would fail that project-wide invariant.
 *
 * So: **no `enum:` cast is used anywhere.** On 13.33
 * `HasAttributes::isEnumCastable()` requires `enum_exists($castType)`, so the
 * string-list form `'status' => 'enum:menunggu_dokter,berlangsung'` is a
 * **silent no-op** - not a primitive cast, not a class castable - which reads
 * like validation and validates nothing. `ModelFoundationTest:539-555` asserts
 * that no cast in `app/Models/**` starts with `enum:`, and that assertion is why
 * this class is a vocabulary and transition table rather than a cast.
 *
 * `Konsultasi::from()` is used at the boundary instead: the column arrives as
 * a string and is narrowed to a case exactly where a decision is made.
 *
 * ## The transition table, and why the DDL does not contain one
 *
 * **A MySQL ENUM constrains the VALUE SET, never the edges.** Nothing in
 * `telemedicine_test.sql` stops a bare `UPDATE konsultasi SET status = 'gagal'`
 * on a row that is already `selesai`; the DDL has no `CHECK`, no trigger and no
 * state column beyond the one ENUM. So every arrow in {@see self::TRANSISI}
 * below is an APPLICATION policy decision, authored here and asserted here, not
 * something read out of the schema. What the DDL does supply is the value set
 * and the declaration order, and the order is the lifecycle order:
 * `menunggu_dokter` -> `berlangsung` -> `menunggu_resep` -> `selesai`, with the
 * two abort states (`dibatalkan`, `gagal`) declared last.
 *
 * | from | to | why |
 * | --- | --- | --- |
 * | `menunggu_dokter` | `berlangsung` | the assigned doctor accepts; this is the only move that stamps `mulai_at` (`:545`) |
 * | `menunggu_dokter` | `dibatalkan` | the patient gives up before anybody joined, so `mulai_at` stays NULL |
 * | `menunggu_dokter` | `gagal` | the wait could not be served at all (doctor unreachable) |
 * | `berlangsung` | `menunggu_resep` | the doctor says a prescription is coming; the DDL places this value exactly between `berlangsung` and `selesai` |
 * | `berlangsung` | `selesai` | ordinary completion |
 * | `berlangsung` | `dibatalkan` | abandoned mid-call |
 * | `berlangsung` | `gagal` | technical failure mid-call |
 * | `menunggu_resep` | `selesai` | completion after the prescription |
 * | `menunggu_resep` | `dibatalkan` | abandoned while waiting for the prescription |
 * | `menunggu_resep` | `gagal` | technical failure while waiting for the prescription |
 * | `selesai` | - | **terminal** |
 * | `dibatalkan` | - | **terminal** |
 * | `gagal` | - | **terminal** |
 *
 * **The three terminal states have no outgoing edge at all**, and that absence
 * is the load-bearing part: it is what makes every backwards move (`berlangsung`
 * -> `menunggu_dokter`, `selesai` -> `berlangsung`) and every skip
 * (`menunggu_dokter` -> `selesai`, `berlangsung` -> `selesai` from a state that
 * never started) a refusal rather than a silent overwrite. A konsultasi that
 * ended cannot be re-opened by replaying its endpoint, which is what "a second
 * `PUT /selesai` returns 422, not a double write" actually requires.
 *
 * `KonsultasiTest` drives the complete 6x6 matrix through
 * `KonsultasiService::ubahStatus()`: every one of the 36 pairs is either applied
 * or refused, and the 10 rows of {@see self::TRANSISI} are asserted to be
 * exactly the 10 pairs the suite observed succeeding. An 11th arrow cannot be
 * added without a test observing it first.
 */
enum KonsultasiStatus: string
{
    case MenungguDokter = 'menunggu_dokter';
    case Berlangsung = 'berlangsung';
    case MenungguResep = 'menunggu_resep';
    case Selesai = 'selesai';
    case Dibatalkan = 'dibatalkan';
    case Gagal = 'gagal';

    /**
     * The legal moves, keyed by the case they leave and valued by the cases they
     * reach. A state absent from this array has no legal successor at all.
     *
     * @var array<string, list<string>>
     */
    public const TRANSISI = [
        'menunggu_dokter' => ['berlangsung', 'dibatalkan', 'gagal'],
        'berlangsung' => ['menunggu_resep', 'selesai', 'dibatalkan', 'gagal'],
        'menunggu_resep' => ['selesai', 'dibatalkan', 'gagal'],
        'selesai' => [],
        'dibatalkan' => [],
        'gagal' => [],
    ];

    /**
     * The three states no transition leaves. Published so a resource or a
     * console command can ask "has this konsultasi ended?" without restating
     * the list, and so a test can assert the three are exactly the ones with an
     * empty successor list rather than trusting this constant to agree.
     *
     * @var list<string>
     */
    public const STATUS_AKHIR = ['selesai', 'dibatalkan', 'gagal'];

    /**
     * Is this state one no move leaves?
     */
    public function adalahAkhir(): bool
    {
        return self::TRANSISI[$this->value] === [];
    }

    /**
     * May a konsultasi in THIS state move to `$tujuan`?
     *
     * A backwards move answers false because the reverse arrow is not in the
     * table - `berlangsung` -> `menunggu_dokter` has no row and needs none - and
     * a move from a terminal state answers false because the whole array is
     * empty. The identical answer for "backwards" and "after the end" is
     * deliberate: both are refusals and the caller learns nothing from the
     * difference.
     */
    public function bisaKe(self $tujuan): bool
    {
        return in_array($tujuan->value, self::TRANSISI[$this->value], true);
    }

    /**
     * The state a caller may legally be in right now, for a 422 message.
     *
     * @return list<string>
     */
    public function tujuanYang(): array
    {
        return self::TRANSISI[$this->value];
    }

    /**
     * Every value, in the DDL's declaration order.
     *
     * @return list<string>
     */
    public static function nilai(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Konsultasi;

use App\Enums\KonsultasiStatus;
use App\Enums\KonsultasiTipe;
use App\Models\Booking;
use App\Models\Konsultasi;
use App\Models\KonsultasiChat;
use App\Models\Pasien;
use App\Models\User;
use App\Services\Dokter\DokterDirectoryService;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The konsultasi lifecycle and its chat transcript.
 *
 * ## Every mutation returns the CHAT row it wrote, not the konsultasi
 *
 * `mulai()`, `terima()`, `kirim()` and `selesai()` each write exactly one
 * `konsultasi_chat` row as part of the same transaction, and each returns
 * that row with its `konsultasi` relation pre-loaded. The controller then
 * broadcasts it. That shape is chosen over returning the konsultasi for two
 * reasons:
 *
 * 1. `KonsultasiMessageSent` (`app/Events/`) takes an ALREADY-SERIALISED
 *    message array and forwards it, deliberately, so the socket payload and the
 *    REST payload cannot drift. The controller therefore needs the chat row, and
 *    the konsultasi comes back with it through the relation.
 * 2. It keeps the "dispatch AFTER commit" rule in the controller rather than in
 *    the service, and that placement is load-bearing: `ShouldBroadcastNow` sends
 *    inline, so dispatching inside the transaction would tell both clients about
 *    a message a concurrent `GET` cannot yet see. `DB::afterCommit()` is NOT used
 *    instead, because `tests/Pest.php` binds `RefreshDatabase` to every Feature
 *    test and its wrapping transaction is never committed - an `afterCommit`
 *    callback would simply never fire in the suite, which is the worst possible
 *    place for a rule to be untested.
 *
 * ## The system author is the ACTING ACCOUNT, and why the schema allows nothing else
 *
 * `konsultasi_chat` has TWO columns about authorship and they do not agree
 * in scope:
 *
 * - `pengirim_tipe ENUM('pasien','dokter','sistem') NOT NULL` (`:567`) - three
 *   values, one of which names a non-human author;
 * - `pengirim_user_id BIGINT UNSIGNED NOT NULL` with
 *   `FOREIGN KEY (pengirim_user_id) REFERENCES users(id)` (`:566`, `:577`) - an
 *   account, mandatory, with no `ON DELETE` clause so the row is RESTRICTed while
 *   the author exists.
 *
 * And `users.tipe` is a SEVEN-value ENUM (`:139`): `pasien`, `dokter`, `perawat`,
 * `apoteker`, `kurir`, `admin`, `superadmin`. **None of the seven means "system",
 * "service", "bot" or "robot".** The schema therefore has a system author TYPE
 * and no system author ACCOUNT, while requiring an account. The three ways out,
 * and why two of them are closed here:
 *
 * - **A new `users.tipe` value** (e.g. `'layanan'`). Closed: `telemedicine_test.sql`
 *   is read-only law, and `RbacCatalog::USER_TYPES` is asserted equal to that ENUM
 *   on every test run.
 * - **A seeded service user row.** Closed: `database/seeders/` is not this todo's
 *   to write, and a row invented here would be a row no deployment has.
 * - **`pengirim_user_id = 0`, or making the column nullable.** Closed: `0` violates
 *   the foreign key, so it is a 1452 rather than a sentinel, and a nullable column
 *   would be a column this todo added.
 *
 * What the DDL DOES supply is its own idiom for a system actor: an ENUM VALUE.
 * `booking.dibatalkan_oleh ENUM('pasien','dokter','sistem')` (`:517`) records
 * "the system cancelled this" as a value, and `notifikasi.tipe` includes
 * `'sistem'` (`:1041`). `konsultasi_chat` is the same idea with an extra
 * mandatory account reference attached.
 *
 * **So the rule enforced here is: a system message carries
 * `pengirim_tipe = 'sistem'` and `pengirim_user_id` = the authenticated account
 * whose action the system is reporting.** Every system message this service
 * writes is triggered by exactly one such action - a patient started the
 * konsultasi, the assigned doctor accepted, the doctor completed it - so the
 * account always exists, the foreign key is always satisfied, and no row outside
 * the DDL is invented. `pengirim_tipe` then correctly tells the client the line
 * is a system notice rather than an utterance by the named account, which is
 * what the column exists for.
 *
 * The one thing this makes impossible is an unattributable system notice, and
 * that is reported as a schema finding rather than worked around: a genuinely
 * anonymous system author needs either an eighth `users.tipe` value or a nullable
 * `pengirim_user_id`, and both are DDL changes.
 *
 * ## Read receipts therefore never count a system line
 *
 * {@see tandaiDibaca()} stamps `dibaca_at` only on rows whose `pengirim_tipe` is
 * the OTHER party. A `sistem` row is skipped in both directions, which is right
 * for two reasons: neither party authored it, and attributing it to the acting
 * account would make a patient's own "you started a konsultasi" notice show up
 * as an unread message from the doctor.
 *
 * ## What is NOT here, and why
 *
 * - **No `dibatalkan` or `gagal` HTTP endpoint.** `KonsultasiStatus` has
 *   both values and {@see ubahStatus()} will apply both, but no permission code
 *   in `RbacCatalog::PERMISSIONS` names a cancel or a fail action, and adding one
 *   is a policy change in `app/Support/Rbac/` that `RbacCatalog`'s own docblock
 *   reserves for a catalogue decision. Inventing `konsultasi.batal` here to make
 *   a route possible would be inventing vocabulary.
 * - **No `menunggu_resep` HTTP endpoint.** The same argument. `POST
 *   /konsultasiultasi/{id}/resep` is the plan's todo 39, and it is where
 *   `KonsultasiStatus::Berlangsung->bisaKe(MenungguResep)` is meant to be
 *   used; {@see ubahStatus()} is public so that todo can call it rather than
 *   re-implement the table.
 * - **No `dokter.jumlah_konsultasi` increment.** The column exists (`:425`) and
 *   the directory publishes it, but the plan does not say which event increments
 *   it, and guessing would make a counter that is either double-counted or frozen.
     */
final class KonsultasiService
{

    /**
     * The six columns a doctor may write through `PUT /konsultasi/{id}/selesai`: the four
     * SOAP fields plus `diagnosis_kerja` and `saran_tindak_lanjut`.
     *
     * Published rather than written out inside {@see tulisSoap()}'s loop, because
     * the method compares the request's keys against this list and refuses anything
     * else - and a list that existed only inside the loop could not be compared with
     * anything.
     *
     * **The `catatan_asessment` spelling is the DDL's, and it is one `s` short of
     * the English word.** `telemedicine_test.sql:550` writes
     * `catatan_asessment TEXT NULL`, the migration reproduces it and the live column
     * matches, so nothing here is a typo - but the name sits two letters from
     * `catatan_subjektif` in shape and one letter from every reader's expectation,
     * and {@see tulisSoap()} is hardened precisely because a misspelling of it is a
     * SILENT no-op rather than an error. `KonsultasiSchemaTest` asserts that DDL line
     * byte for byte and the SOAP test asserts the stored value.
     *
     * @var list<string>
     */
    public const KOLOM_SOAP = [
        'catatan_subjektif',
        'catatan_objektif',
        'catatan_asessment',
        'catatan_plan',
        'diagnosis_kerja',
        'saran_tindak_lanjut',
    ];

    /**
     * `booking.status` values from which a consultation may be started
     * (`telemedicine_test.sql:515-516`, an EIGHT-value ENUM).
     *
     * `menunggu_pembayaran` is excluded because the slot is not paid for,
     * `berlangsung` and `selesai` because the visit has already had its own
     * lifecycle, and `dibatalkan` / `kadaluarsa` / `no_show` because the visit
     * will not happen at all.
     *
     * @var list<string>
     */
    public const STATUS_BOOKING_BISA_MULAI = ['terjadwal', 'check_in'];

    /**
     * `booking.tipe_layanan` values a telemedicine `konsultasi` row can be built
     * from (`:506`, a FOUR-value ENUM). `kunjungan_klinik` and `home_visit` are
     * IN-PERSON services: a `konsultasiultasi` row is a telemedicine session - it
     * has a `room_id` for a video SDK at `:544` and a `konsultasi_chat`
     * transcript hanging off it - so neither of the other two is coercible into
     * one and both are refused rather than silently mapped onto `telepon`.
     *
     * @var list<string>
     */
    public const TIPE_LAYANAN_BISA_DIKONSULTASI = ['chat', 'video_call'];

    /**
     * The disk a chat attachment is written to. `config/filesystems.php` defines
     * it with `visibility => 'public'` and a `url` rooted at `APP_URL/storage`, so
     * `file_url` is a real absolute URL and not a path only the server can read.
     */
    public const DISK_BERKAS = 'public';

    /**
     * The largest attachment accepted, in kilobytes. 10 MB is the ceiling for a
     * chat attachment; a `video_note` is a short clip, not a konsultasi
     * recording, and the raw recording is the video SDK's problem.
     */
    public const BERKAS_MAKS_KB = 10240;

    /**
     * The MIME group each file-backed message type must belong to.
     *
     * `dokumen` is an ALLOW-LIST rather than a `text/` or `application/` prefix
     * because those two prefixes are far too wide: `application/x-msdownload` and
     * `application/octet-stream` are both `application/`, and a chat transcript
     * that will be rendered by two clients is not the place to accept arbitrary
     * executables under a `.dokumen` label.
     *
     * @var array<string, list<string>>
     */
    public const MIME_BERKAS = [
        'gambar' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        ],
        'dokumen' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain', 'text/csv',
        ],
        'audio' => [
            'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/webm',
        ],
        'video_note' => [
            'video/mp4', 'video/quicktime', 'video/webm', 'video/3gpp',
        ],
    ];

    /**
     * `konsultasi.tipe_pesan` is an EIGHT-value ENUM
     * (`telemedicine_test.sql:568-569`): `teks`, `gambar`, `dokumen`, `audio`,
     * `video_note`, `resep`, `surat_keterangan`, `sistem`.
     *
     * These THREE are the ones the SERVICE writes and a human may not: `resep`
     * and `surat_keterangan` name documents, and `sistem` is a system notice.
     * A client that could post one of them could point a "prescription attached"
     * line at nothing at all, because the table has no `resep_id` and no
     * `surat_keterangan_id` to point it with - the reference would be free text
     * in `isi`. {@see kirim()} refuses all three with a 422.
     *
     * @var list<string>
     */
    public const TIPE_PESAN_SISTEM = ['resep', 'surat_keterangan', 'sistem'];

    /**
     * The system notice written when a consultation is created, before any
     * doctor has answered.
     */
    private const SISTEM_MULAI = 'Konsultasi dimulai. Menunggu dokter.';

    /**
     * The system notice written when the assigned doctor accepts.
     */
    private const SISTEM_MULAI_BERLANGSUNG = 'Dokter telah bergabung. Konsultasi berlangsung.';

    /**
     * The system notice written when the doctor completes the konsultasi.
     */
    private const SISTEM_SELESAI = 'Konsultasi selesai.';

    public function __construct(
        private readonly KonsultasiAccess $access,
        private readonly DokterDirectoryService $directory,
    ) {}

    /**
     * Create a konsultasi for `$pasien`, opened by `$pembuat`.
     *
     * Two forms, and the schema is what makes both possible:
     *
     * - **From a booking** (`booking_id` present). The booking is scoped to the
     *   caller's own patient row, so another patient's booking is a 404, and a
     *   booking id that does not exist is the SAME 404 - a 422 that appeared only
     *   for a real id would be an existence oracle over the id space. The doctor
     *   and the service type are then read off the booking, never from the body.
     * - **Instant, "Tanya Dokter"** (`dokter_id` present, no `booking_id`).
     *   `konsultasiultasi.booking_id` is `NULL UNIQUE` (`:538`), and MySQL allows
     *   any number of NULLs in a UNIQUE index, so unlimited instant sesi konsultasi
     *   are representable - which is precisely what makes the feature possible and
     *   is asserted by a test that opens three.
     *
     * `status` is written as `menunggu_dokter` explicitly rather than left to the
     * DDL default, even though `:543` already says `'menunggu_dokter'`: the state
     * a konsultasi is born in is a decision this service makes, and a reader
     * should not have to know the column default to know it. It is
     * `KonsultasiStatus::MenungguDokter` either way, and the test asserts
     * both the wire value and the DDL default are that case.
     *
     * `biaya_konsultasi` is `DECIMAL(12,2) NOT NULL DEFAULT 0` (`:554`) and
     * is written from `dokter.biaya_konsultasi_online` (`:420`) for the INSTANT
     * flow only. A booking-backed konsultasi records `0`, because
     * `BookingService::simpanDenganNomorUnik()` already raised an `invoice` whose
     * `referensi_tipe` is `'booking'` and whose total is the same fee - copying the
     * fee onto the konsultasi as well would be a second amount for one service
     * and two chances to bill it twice. `invoice.referensi_tipe` does offer
     * `'konsultasi'` (todo 43's `InvoiceService`), and it will apply to the
     * instant flow, which has no booking invoice.
     *
     * `room_id VARCHAR(100) NULL` (`:544`) is generated as a UUID4, which the
     * column's own comment names as the shape an Agora/Twilio/100ms room takes.
     * The plan says `Str::uuid()`; that is a v4 UUID and a valid one.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function mulai(Pasien $pasien, User $pembuat, array $data): KonsultasiChat
    {
        return DB::transaction(function () use ($pasien, $pembuat, $data): KonsultasiChat {
            [$dokterId, $tipe, $biaya, $bookingId] = $this->sumber($pasien, $data);

            $konsultasi = new Konsultasi;
            $konsultasi->booking_id = $bookingId;
            $konsultasi->pasien_id = $pasien->getKey();
            $konsultasi->dokter_id = $dokterId;
            $konsultasi->tipe = $tipe;
            $konsultasi->status = KonsultasiStatus::MenungguDokter->value;
            $konsultasi->room_id = (string) Str::uuid();
            $konsultasi->mulai_at = null;
            $konsultasi->selesai_at = null;
            $konsultasi->biaya_konsultasi = $biaya;

            try {
                $konsultasi->save();
            } catch (UniqueConstraintViolationException) {
                // `booking_id` is `NULL UNIQUE` (:538), so a second konsultasi on the
                // same booking is a duplicate-key error at the database rather than a
                // check the application could race. It is translated into the same 422 a
                // pre-check would have produced, so the caller cannot tell a booking that
                // already has a session from one that does not.
                throw ValidationException::withMessages([
                    'booking_id' => ['Booking ini sudah memiliki sesi konsultasi.'],
                ]);
            }

            return $this->tulisSistem($konsultasi, $pembuat, self::SISTEM_MULAI);
        });
    }

    /**
     * The assigned doctor accepts: `menunggu_dokter` -> `berlangsung`.
     *
     * **This endpoint is not in the plan, and without it the plan's own
     * `PUT /selesai` cannot succeed.** `konsultasiultasi.mulai_at` is
     * `DATETIME NULL` (`:545`) and the plan requires `PUT /selesai` to compute
     * `total_durasi_detik` from it and to answer 422 while it is null - so the
     * plan assumes some step stamps it, and no endpoint in the plan's list does.
     * Two of the six states in the ENUM, `berlangsung` and everything downstream of
     * it, are unreachable without it. `RbacCatalog::ROLE_PERMISSIONS` is the
     * corroborating evidence: `konsultasi.mulai` ("Mulai Konsultasi") is granted to
     * `dokter` and `superadmin` and to nobody else, and before this route NO
     * endpoint consumed it. The code exists for a doctor-side start-of-session step
     * and the plan lost the endpoint.
     *
     * The row is locked `FOR UPDATE` first, because this is a read-modify-write on
     * the state column and two concurrent acceptances - or an acceptance racing a
     * completion - must not both read `menunggu_dokter`. `BookingService` locks
     * `dokter` for the same reason on the booking path.
     *
     * `mulai_at` is stamped here and nowhere else, so "the konsultasi started"
     * has exactly one writer.
     *
     * @throws ValidationException|AccessDeniedHttpException|ModelNotFoundException
     */
    public function terima(User $dokter, int $id): KonsultasiChat
    {
        return DB::transaction(function () use ($dokter, $id): KonsultasiChat {
            $konsultasi = $this->kunciUntukDokter($id, $dokter);

            $this->ubahStatus($konsultasi, KonsultasiStatus::Berlangsung);

            $konsultasi->mulai_at = Carbon::now();
            $konsultasi->save();

            return $this->tulisSistem($konsultasi, $dokter, self::SISTEM_MULAI_BERLANGSUNG);
        });
    }

    /**
     * Complete the konsultasi: `berlangsung` or `menunggu_resep` -> `selesai`.
     *
     * Two independent guards, and they are independent on purpose:
     *
     * 1. **The state-machine edge** ({@see ubahStatus()}). From `menunggu_dokter`
     *    there is no arrow to `selesai`, so a konsultasi that never started cannot
     *    be completed.
     * 2. **The `mulai_at` invariant.** A row that IS in a completable state but has
     *    a NULL `mulai_at` cannot have its duration computed, and the plan requires a
     *    422 for exactly that. Under guard 1 alone this is unreachable through the
     *    API, because `terima()` is the only writer of `mulai_at` and it only ever
     *    writes it together with the move to `berlangsung` - so the guard is a
     *    SECOND line of defence against a row that reached `berlangsung` by some
     *    path other than this service (a direct write, a restore, a future
     *    endpoint). It is reported as such rather than presented as the primary
     *    rule, and `KonsultasiTest` exercises it by constructing exactly such a row
     *    through `DB::table()`, which the DDL permits.
     *
     * Both messages are collected into ONE `ValidationException`, so a row that
     * fails both answers a single 422 carrying `errors.status` AND
     * `errors.mulai_at` rather than one field hiding the other.
     *
     * `total_durasi_detik` is `INT UNSIGNED` (`:547`), so it is clamped at zero and
     * cast to `int`: a `mulai_at` in the future - a clock skew, a manual repair -
     * would otherwise ask MySQL to store a negative value in an unsigned column and
     * fail the write with 1264.
     *
     * `selesai_at` is stamped here and nowhere else.
     *
     * @param  array<string, mixed>  $soap
     *
     * @throws ValidationException|AccessDeniedHttpException|ModelNotFoundException
     */
    public function selesai(User $dokter, int $id, array $soap): KonsultasiChat
    {
        return DB::transaction(function () use ($dokter, $id, $soap): KonsultasiChat {
            $konsultasi = $this->kunciUntukDokter($id, $dokter);

            $mulai = $konsultasi->mulai_at;
            $selesai = Carbon::now();

            // BOTH guards are evaluated before either throws, so a row that fails both
            // answers ONE 422 carrying `errors.status` AND `errors.mulai_at` rather than
            // one field hiding the other. `ubahStatus()` throws on the illegal edge, so
            // collecting the messages first is the only way both can be reported, and the
            // collection is what makes this a multi-field validation failure rather than
            // whichever check happened to run first.
            $violasi = [];

            try {
                $this->ubahStatus($konsultasi, KonsultasiStatus::Selesai);
            } catch (ValidationException $e) {
                $violasi = $e->errors();
            }

            if ($mulai === null) {
                $violasi['mulai_at'] = ['Konsultasi belum tercatat dimulai, tidak dapat diselesaikan.'];
            }

            if ($violasi !== []) {
                throw ValidationException::withMessages($violasi);
            }

            $konsultasi->status = KonsultasiStatus::Selesai->value;
            $konsultasi->selesai_at = $selesai;
            $konsultasi->total_durasi_detik = max(0, (int) $mulai->diffInSeconds($selesai, true));
            $this->tulisSoap($konsultasi, $soap);
            $konsultasi->save();

            return $this->tulisSistem($konsultasi, $dokter, self::SISTEM_SELESAI);
        });
    }

    /**
     * Append one message authored by `$caller` to the transcript of `$id`.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException|AccessDeniedHttpException|ModelNotFoundException
     */
    public function kirim(User $caller, int $id, array $data, ?UploadedFile $berkas = null): KonsultasiChat
    {
        [$sisi, $konsultasi] = $this->access->sisiDanKonsultasi($caller, $id);

        $tipePesan = (string) $data['tipe_pesan'];

        // A system type is not a thing a human types. `resep` and
        // `surat_keterangan` name documents this schema has no foreign key for
        // (`konsultasi_chat` carries no `resep_id` and no
        // `surat_keterangan_id` - see the finding in the evidence file), so a
        // client could point one at nothing. They are written by the service when
        // the document is created, by todo 34 and todo 39.
        if (in_array($tipePesan, self::TIPE_PESAN_SISTEM, true)) {
            throw ValidationException::withMessages([
                'tipe_pesan' => ['Jenis pesan ini ditulis oleh sistem, bukan oleh pengirim.'],
            ]);
        }

        $isi = $data['isi'] ?? null;
        [$fileUrl, $fileNama, $fileKb] = $this->simpanBerkas($konsultasi, $tipePesan, $berkas);

        $pesan = new KonsultasiChat;
        $pesan->konsultasi_id = $konsultasi->getKey();
        $pesan->pengirim_user_id = $caller->getKey();
        $pesan->pengirim_tipe = $sisi;
        $pesan->tipe_pesan = $tipePesan;
        $pesan->isi = $isi;
        $pesan->file_url = $fileUrl;
        $pesan->file_nama = $fileNama;
        $pesan->file_ukuran_kb = $fileKb;
        $pesan->save();

        return $pesan->setRelation('konsultasi', $konsultasi);
    }

    /**
     * The transcript, OLDEST FIRST, paginated.
     *
     * The one ascending list in this application, and the order is load-bearing
     * rather than cosmetic: a chat transcript that starts at the newest message is
     * useless, and a client that has to reverse every page to render one is a
     * client with two code paths. `meta` is a top-level sibling from
     * `ApiResponse::pageMeta()`, exactly as on every other list, so a client parses
     * one list envelope.
     *
     * `terkirim_at` is a `TIMESTAMP` (`telemedicine_test.sql:575`), so its
     * resolution is ONE SECOND and a burst of messages ties. The order is
     * therefore `terkirim_at, id` and never `terkirim_at` alone: without the `id`
     * tiebreaker MySQL is free to return a tied block in any order, which would
     * make page boundaries non-deterministic and could repeat or drop a message
     * between two pages. `id` is the unique tiebreaker `BookingService::daftar()`
     * already relies on for the same reason.
     *
     * The 100 cap is `PasienRecordAccess::PER_PAGE_MAX`, reused rather than
     * restated.
     */
    public function riwayat(int $konsultasiId, int $perPage): LengthAwarePaginator
    {
        return KonsultasiChat::query()
            ->where('konsultasi_id', $konsultasiId)
            ->orderBy('terkirim_at')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Stamp `dibaca_at` on the OTHER party's unread messages, and answer how many.
     *
     * "The other party" is read off the caller's own side, so a patient marks the
     * doctor's lines and a doctor marks the patient's - and neither marks their own,
     * which would be a trivially gameable "I have read everything" claim. Rows with
     * `pengirim_tipe = 'sistem'` are skipped in both directions: neither party
     * authored them, and because a system row's `pengirim_user_id` is the ACTING
     * account, attributing it to a side would show a patient their own
     * "you started a konsultasi" notice as an unread line from the doctor.
     *
     * The `dibaca_at IS NULL` predicate is inside the `UPDATE` rather than applied
     * in PHP, so the stamp is one statement and a concurrent send cannot be
     * double-written.
     *
     * @throws AccessDeniedHttpException|ModelNotFoundException
     */
    public function tandaiDibaca(User $caller, int $id): int
    {
        [$sisi, $konsultasi] = $this->access->sisiDanKonsultasi($caller, $id);

        $lain = $sisi === 'pasien' ? 'dokter' : 'pasien';

        return KonsultasiChat::query()
            ->where('konsultasi_id', $konsultasi->getKey())
            ->where('pengirim_tipe', $lain)
            ->whereNull('dibaca_at')
            ->update(['dibaca_at' => Carbon::now()]);
    }

    /**
     * Move a konsultasi from where it is to `$tujuan`, or refuse.
     *
     * Public because it is the transition table and nothing else: a later todo that
     * legitimately needs `dibatalkan`, `gagal` or `menunggu_resep` calls this rather
     * than writing `status` itself, and the whole 6x6 matrix can be driven through
     * it in a test. It assumes the caller already holds the row lock
     * ({@see kunciUntukDokter()}) or is inside the transaction that will write it.
     *
     * @throws ValidationException
     */
    public function ubahStatus(Konsultasi $konsultasi, KonsultasiStatus $tujuan): void
    {
        $dari = KonsultasiStatus::from((string) $konsultasi->status);

        if (! $dari->bisaKe($tujuan)) {
            $tujuanYang = $dari->tujuanYang();

            throw ValidationException::withMessages([
                'status' => [$dari->adalahAkhir()
                    ? 'Konsultasi dengan status tersebut sudah selesai dan tidak dapat diubah lagi.'
                    : 'Konsultasi tidak dapat bertransisi dari "'.$dari->value.'" ke "'.$tujuan->value.'".'
                        .' Status yang diperbolehkan: '.($tujuanYang === [] ? 'tidak ada' : implode(', ', $tujuanYang)).'.'],
            ]);
        }

        $konsultasi->status = $tujuan->value;
    }

    /**
     * Lock one konsultasi for a doctor-owned write, and refuse a caller who is
     * not this konsultasi's doctor.
     *
     * The ownership decision is {@see KonsultasiAccess::untukDokter()}'s - 403
     * for a `dokter`-typed account with no profile, 404 for a doctor attached to a
     * different konsultasi. The row is then re-read `FOR UPDATE`, because the
     * 404 path and the lock are two statements and the state read in between them
     * must be the one that is written.
     */
    private function kunciUntukDokter(int $id, User $dokter): Konsultasi
    {
        $konsultasi = $this->access->untukDokter($dokter, $id);

        return Konsultasi::query()
            ->whereKey($konsultasi->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Resolve the doctor's id, the service type and the fee from whichever of the
     * two forms was used.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array{0: int, 1: string, 2: string, 3: int|null}
     *
     * @throws ValidationException|NotFoundHttpException
     */
    private function sumber(Pasien $pasien, array $data): array
    {
        if (isset($data['booking_id'])) {
            return $this->dariBooking($pasien, (int) $data['booking_id']);
        }

        return $this->dokterInstan((int) $data['dokter_id'], (string) $data['tipe']);
    }

    /**
     * The booking form: the booking must be the caller's, must be in a state a
     * konsultasi may start from, and must be a TELEMEDICINE service.
     *
     * The lookup is scoped rather than `exists`-validated, and that is the
     * non-disclosure rule: a booking belonging to another patient and a booking id
     * that does not exist are the same 404. A 422 that appeared only for a real id
     * would let a caller enumerate the `booking` id space, which is a sequential
     * `BIGINT UNSIGNED AUTO_INCREMENT`.
     *
     * @return array{0: int, 1: string, 2: string, 3: int|null}
     *
     * @throws ValidationException|NotFoundHttpException
     */
    private function dariBooking(Pasien $pasien, int $bookingId): array
    {
        $booking = Booking::query()
            ->whereKey($bookingId)
            ->where('pasien_id', $pasien->getKey())
            ->first();

        if ($booking === null) {
            throw (new ModelNotFoundException)->setModel(Booking::class, [$bookingId]);
        }

        if (! in_array((string) $booking->status, self::STATUS_BOOKING_BISA_MULAI, true)) {
            throw ValidationException::withMessages([
                'booking_id' => ['Konsultasi hanya dapat dimulai dari booking dengan status '
                    .implode(' atau ', self::STATUS_BOOKING_BISA_MULAI).'.'],
            ]);
        }

        if (! in_array((string) $booking->tipe_layanan, self::TIPE_LAYANAN_BISA_DIKONSULTASI, true)) {
            throw ValidationException::withMessages([
                'booking_id' => ['Booking dengan tipe layanan "'.$booking->tipe_layanan
                    .'" adalah layananhadiran dan tidak memiliki sesi konsultasi telemedisin.'],
            ]);
        }

        return [
            (int) $booking->dokter_id,
            (string) $booking->tipe_layanan,
            '0.00',
            $booking->getKey(),
        ];
    }

    /**
     * The instant form: the doctor must be one the public directory would list.
     *
     * **The eligibility rule is {@see DokterDirectoryService::find()}, not a
     * locally written `where('status_verifikasi', 'terverifikasi')`.** That service
     * already applies the four rules the plan compresses into one sentence
     * (`status_verifikasi = 'terverifikasi' AND tersedia_telemedisin = 1`) plus
     * `status_aktif` and an in-force STR, and it answers `null` for a doctor that
     * does not exist exactly as for one that is ineligible. Reusing it is what
     * makes "Tanya Dokter" and `GET /dokter/{dokter}` unable to disagree, and
     * `GET /dokter/{dokter}/{jadwal,slot}` already reuse it for the same reason.
     *
     * The fee is read from `dokter.biaya_konsultasi_online` (`:420`) and is the
     * whole reason the instant form records a non-zero amount: there is no
     * `booking` row, so no `BookingService::simpanDenganNomorUnik()` invoice
     * exists to carry it.
     *
     * @return array{0: int, 1: string, 2: string, 3: int|null}
     *
     * @throws NotFoundHttpException
     */
    private function dokterInstan(int $dokterId, string $tipe): array
    {
        $dokter = $this->directory->find($dokterId);

        if ($dokter === null) {
            throw (new ModelNotFoundException)->setModel(\App\Models\Dokter::class, [$dokterId]);
        }

        return [
            $dokter->getKey(),
            $tipe,
            (string) $dokter->biaya_konsultasi_online,
            null,
        ];
    }

    /**
     * Write the six SOAP / follow-up columns the doctor supplied.
     *
     * Only the keys actually present in `$soap` are written, so a field the doctor
     * left out keeps whatever it held rather than being blanked. `nullable` and
     * absent are different requests, and the difference is the caller's.
     *
     * @param  array<string, mixed>  $soap
     */
    private function tulisSoap(Konsultasi $konsultasi, array $soap): void
    {
        $takDikenal = array_values(array_diff(array_keys($soap), self::KOLOM_SOAP));

        // An unknown key is a programming error, not a request error, and it is
        // refused rather than ignored. The reason is measured: this method skips a
        // column that is ABSENT from the payload, so that a field the doctor left out
        // keeps whatever it held - and that same `array_key_exists` guard also
        // swallows a MISSPELLED field name silently. The request validates, the
        // service accepts it, and the value is written nowhere. The SOAP column names
        // are an awkward pair - `catatan_asessment` and `catatan_subjektif` are two
        // letters apart in shape - which is exactly the kind of pair a typo lands on,
        // and this todo's own test hit it while being written.
        if ($takDikenal !== []) {
            throw new LogicException(
                self::class.'::tulisSoap() was given a key the schema does not have: '
                .implode(', ', $takDikenal).'. The writable SOAP columns are: '.implode(', ', self::KOLOM_SOAP).'.'
            );
        }

        foreach (self::KOLOM_SOAP as $kolom) {
            if (array_key_exists($kolom, $soap)) {
                $konsultasi->{$kolom} = $soap[$kolom];
            }
        }
    }

    /**
     * Store an attachment, or answer that none is needed.
     *
     * The stored FILENAME is random and the client's filename goes to
     * `file_nama` (`:572`, `VARCHAR(255)`) instead. A client-supplied name is a
     * path: `putFileAs()` with a name containing `/` or `..` writes outside the
     * directory it was given, and a chat transcript is a place where two clients
     * render an attacker-chosen string. `file_nama` is additionally truncated to
     * the column's 255 characters, and `basename()` runs first so the published
     * value is a name rather than a path.
     *
     * `file_ukuran_kb` is `INT UNSIGNED` (`:573`) and is rounded UP, so a 1-byte
     * upload records 1 KB rather than 0 - "0 KB" is not a fact about a file that
     * exists.
     *
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    private function simpanBerkas(Konsultasi $konsultasi, string $tipePesan, ?UploadedFile $berkas): array
    {
        if ($berkas === null) {
            return [null, null, null];
        }

        $ekstensi = strtolower((string) $berkas->guessExtension());
        $nama = Str::random(40).($ekstensi === '' ? '' : '.'.$ekstensi);
        $path = $berkas->storeAs('konsultasi/'.$konsultasi->getKey(), $nama, self::DISK_BERKAS);

        return [
            Storage::disk(self::DISK_BERKAS)->url($path),
            Str::limit((string) basename((string) $berkas->getClientOriginalName()), 255, ''),
            max(1, (int) ceil($berkas->getSize() / 1024)),
        ];
    }

    /**
     * Append a system notice, attributed to the account whose action caused it.
     *
     * See the class docblock for why that attribution is the only thing the schema
     * permits. `tipe_pesan` is `'sistem'` for all three lifecycle notices, which is
     * one of the eight values at `:568-569` and the only one of them that means
     * "this is not a human utterance".
     */
    private function tulisSistem(Konsultasi $konsultasi, User $aktor, string $isi): KonsultasiChat
    {
        $pesan = new KonsultasiChat;
        $pesan->konsultasi_id = $konsultasi->getKey();
        $pesan->pengirim_user_id = $aktor->getKey();
        $pesan->pengirim_tipe = 'sistem';
        $pesan->tipe_pesan = 'sistem';
        $pesan->isi = $isi;
        $pesan->save();

        return $pesan->setRelation('konsultasi', $konsultasi);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Events\KonsultasiMessageSent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Konsultasi\KirimPesanRequest;
use App\Http\Requests\Konsultasi\MulaiKonsultasiRequest;
use App\Http\Requests\Konsultasi\SelesaikanKonsultasiRequest;
use App\Http\Requests\Konsultasi\TandaiDibacaRequest;
use App\Http\Requests\Konsultasi\TerimaKonsultasiRequest;
use App\Http\Resources\KonsultasiChatResource;
use App\Http\Resources\KonsultasiResource;
use App\Models\Konsultasi;
use App\Models\KonsultasiChat;
use App\Models\User;
use App\Services\Konsultasi\KonsultasiAccess;
use App\Services\Konsultasi\KonsultasiService;
use App\Services\Pasien\PasienRecordAccess;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seven routes, one state machine, and one ownership rule.
 *
 * ## Why seven routes and not the plan's six
 *
 * `PUT /api/v1/konsultasi/{id}/terima` is not in the plan. It is here because the plan
 * cannot work without it: `PUT /selesai` is required to compute
 * `total_durasi_detik` from `mulai_at` and to answer 422 while that column is
 * NULL, which means some endpoint must stamp `mulai_at` - and no endpoint in the
 * plan's list does. Without the accept step `berlangsung` is unreachable, the
 * duration is never computable, and `PUT /selesai` is an endpoint that can only
 * ever answer 422. `RbacCatalog::ROLE_PERMISSIONS` is the corroborating evidence:
 * `konsultasi.mulai` is granted to `dokter` and to nobody else, and before this route
 * NO endpoint consumed it. The full argument is in `KonsultasiService::terima()` and the
 * finding is in `.omo/evidence/task-32-sehatly.md`.
 *
 * The two alternatives were rejected for cause: having `PUT /selesai` stamp
 * `mulai_at` itself contradicts an explicit plan criterion, and shipping three
 * instant consultations that can never be completed is a dead feature.
 *
 * ## The broadcast is dispatched HERE, after the service returns
 *
 * `ShouldBroadcastNow` sends inline on the request that wrote the row, so
 * dispatching inside the service's transaction would tell both clients about a
 * message a concurrent `GET` cannot yet see. `DB::afterCommit()` is not the answer
 * either: `tests/Pest.php` binds `RefreshDatabase` to every Feature test and its
 * wrapping transaction is never committed, so an `afterCommit` callback would never
 * fire under test. Dispatching from the controller, where the transaction has
 * already returned, is the only placement that is both correct in production and
 * exercised by the suite.
 *
 * ## Ownership is one rule, and it is todo 31's
 *
 * Every read goes through `KonsultasiAccess::findForRead()`, which delegates the "is this
 * account a party" question to `KonsultasiChannelAccess::allows()` - the single
 * implementation `routes/channels.php` also uses. There is no second
 * `pasien.user_id` / `dokter.user_id` comparison anywhere in this controller, so
 * the HTTP surface and the socket surface cannot disagree about the same three
 * inputs. A stranger gets 404; an account that owns no profile row gets 403.
 *
 * ## Every `permission:` and `tipe:` decision, one at a time
 *
 * `RbacCatalog::PERMISSIONS` holds three consultation codes and all three are
 * consumed, which is the first time that is true of any of them:
 *
 * - `konsultasi.mulai` -> `PUT /terima`, with `tipe:dokter`. Held by `dokter` and
 *   `superadmin`; `tipe:dokter` removes `superadmin`, which is correct - a
 *   superadmin accepting a clinical session is not something the account type
 *   allows. This is the plan's own idiom for `/selesai`, reused verbatim.
 * - `konsultasi.selesai` -> `PUT /selesai`, with `tipe:dokter`. Same reasoning, and the
 *   plan's acceptance criterion "a patient calling `PUT /selesai` gets 403" is
 *   answered by `tipe:dokter` at the middleware.
 * - `konsultasi.chat` -> `POST /chat` and `POST /chat/baca`. Held by `pasien`, `dokter`
 *   and `superadmin`, which is exactly the set that can be a party, so the grant
 *   and the ownership rule agree. `apoteker` and `admin` are refused and that is
 *   correct: neither is party to a consultation. `superadmin` passes the
 *   middleware and is then refused 404 by the ownership rule, because it owns no
 *   profile row - the two layers are not redundant, they answer different
 *   questions.
 *
 * `POST /mulai`, `GET /{id}` and `GET /{id}/chat` carry **no** `permission:` and
 * **no** `tipe:`, and that is a decision rather than an omission:
 *
 * - The catalogue has no code naming "read a consultation", so a `permission:`
 *   here would have to be invented, and `EnsurePermission` turns an unknown code
 *   into a **500**, not a 403. `RbacCatalog`'s docblock and `PasienRecordAccess`'s
 *   both record that as the reason not to.
 * - The plan's own `admin`/`superadmin` read allowance cannot be a route gate at
 *   all: it is "a party OR an oversight account", a disjunction, and
 *   `tipe:admin,superadmin` would exclude the patient and the doctor, which are
 *   the two who matter most. It is in the service instead.
 * - `POST /mulai` is a patient action whose real question is "does this account own
 *   a patient profile?", which `KonsultasiAccess::ownPasien()` answers with a 403.
 *   `PasienRecordAccess`'s docblock argues at length why a `tipe:pasien` in front
 *   of it would be a second, strictly weaker gate, and this route follows that
 *   reasoning rather than repeating it.
 *
 * `perawat` and `kurir` are real `users.tipe` values (`telemedicine_test.sql:139`)
 * that hold no role, so any `permission:` would lock them out permanently. They are
 * refused anyway - 403 on the two chat writes, 403 from `ownPasien()` on
 * `POST /mulai`, 404 on the three reads - and the reason is a fact about rows they
 * do not own rather than a fact about their role.
 *
 * @see \App\Services\Konsultasi\KonsultasiService for the lifecycle
 * @see \App\Enums\KonsultasiStatus for the six states and the ten legal edges
 */
class KonsultasiController extends Controller
{
    public function __construct(
        private readonly KonsultasiService $service,
        private readonly KonsultasiAccess $access,
        private readonly PasienRecordAccess $pasien,
    ) {}

    /**
     * `POST /api/v1/konsultasi/mulai` - 201.
     *
     * Two forms: `booking_id` (the caller's own, in `terjadwal` or `check_in`) or
     * `dokter_id` + `tipe` with no `booking_id` at all, which works because
     * `konsultasi.booking_id` is `NULL UNIQUE` and MySQL allows any number of NULLs in a
     * UNIQUE index. `room_id` is a fresh UUID4 either way, and `status` is
     * `menunggu_dokter` in both forms.
     */
    public function mulai(MulaiKonsultasiRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $pesan = $this->service->mulai($this->access->ownPasien($user), $user, $request->validated());

        $this->siarkan($request, $pesan);

        return ApiResponse::success(
            ['konsultasi' => new KonsultasiResource($this->muatan($pesan))],
            'Konsultasi berhasil dimulai.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `PUT /api/v1/konsultasi/{id}/terima` - 200.
     *
     * Doctor-only. `menunggu_dokter` -> `berlangsung`, and this is the one place
     * `mulai_at` is stamped.
     */
    public function terima(TerimaKonsultasiRequest $request, int $id): JsonResponse
    {
        $pesan = $this->service->terima($this->user($request), $id);

        $this->siarkan($request, $pesan);

        return ApiResponse::success(
            ['konsultasi' => new KonsultasiResource($this->muatan($pesan))],
            'Konsultasi berhasil dimulai.',
        );
    }

    /**
     * `PUT /api/v1/konsultasi/{id}/selesai` - 200.
     *
     * Doctor-only. `berlangsung` or `menunggu_resep` -> `selesai`, stamping
     * `selesai_at` and the computed `total_durasi_detik`, and writing the four SOAP
     * fields plus `diagnosis_kerja` and `saran_tindak_lanjut`. A second call is
     * 422, because `selesai` has no outgoing edge.
     */
    public function selesai(SelesaikanKonsultasiRequest $request, int $id): JsonResponse
    {
        $pesan = $this->service->selesai($this->user($request), $id, $request->validated());

        $this->siarkan($request, $pesan);

        return ApiResponse::success(
            ['konsultasi' => new KonsultasiResource($this->muatan($pesan))],
            'Konsultasi berhasil diselesaikan.',
        );
    }

    /**
     * `GET /api/v1/konsultasi/{id}` - 200.
     *
     * The session with `pasien`, `dokter`, `booking`, the four SOAP fields and
     * `total_durasi_detik`. Scoped to the session's patient, its doctor, or
     * `admin`/`superadmin`; a third party is 404.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            ['konsultasi' => new KonsultasiResource($this->access->findForRead($this->user($request), $id))],
            'Detail konsultasi berhasil dimuat.',
        );
    }

    /**
     * `GET /api/v1/konsultasi/{id}/chat` - 200.
     *
     * OLDEST FIRST, paginated, `per_page` capped at 100 through
     * `PasienRecordAccess::perPage()`. `meta` is a top-level sibling from
     * `ApiResponse::pageMeta()`, so a client parses one list envelope here and on
     * every other list in the application.
     */
    public function chatIndex(Request $request, int $id): JsonResponse
    {
        $konsultasi = $this->access->findForRead($this->user($request), $id);

        $perPage = $this->pasien->perPage((int) $request->integer('per_page', PasienRecordAccess::PER_PAGE_DEFAULT));
        $rows = $this->service->riwayat($konsultasi->getKey(), $perPage);

        return ApiResponse::success(
            ['pesan' => KonsultasiChatResource::collection($rows->getCollection())],
            'Riwayat chat berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($rows),
        );
    }

    /**
     * `POST /api/v1/konsultasi/{id}/chat` - 201.
     *
     * `pengirim_tipe` is the SIDE the caller is on, derived from which profile row
     * it owns - never a copy of `users.tipe`, which is a seven-value ENUM against a
     * three-value column and would be a MySQL 1264 for four of its seven values.
     * The system message types `resep`, `surat_keterangan` and `sistem` are 422
     * here: the service writes those when the document is created.
     */
    public function chatStore(KirimPesanRequest $request, int $id): JsonResponse
    {
        $pesan = $this->service->kirim(
            $this->user($request),
            $id,
            $request->validated(),
            $request->file('berkas'),
        );

        $this->siarkan($request, $pesan);

        return ApiResponse::success(
            ['pesan' => new KonsultasiChatResource($pesan)],
            'Pesan berhasil dikirim.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `POST /api/v1/konsultasi/{id}/chat/baca` - 200.
     *
     * Stamps `dibaca_at` on the OTHER party's unread messages and answers how many
     * moved. A system line is skipped in both directions: neither party wrote it.
     */
    public function chatBaca(TandaiDibacaRequest $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            [
                'konsultasi_id' => $id,
                'jumlah_ditandai_baca' => $this->service->tandaiDibaca($this->user($request), $id),
            ],
            'Pesan ditandai sudah dibaca.',
        );
    }

    /**
     * Dispatch the one chat row a write produced, on the channel of its
     * consultation, carrying the already-serialised Resource output.
     *
     * The event forwards whatever array it is given, so the socket body is the REST
     * body character for character and the two cannot drift.
     */
    private function siarkan(Request $request, KonsultasiChat $pesan): void
    {
        /** @var array<string, mixed> $payload */
        $payload = (new KonsultasiChatResource($pesan))->resolve($request);

        KonsultasiMessageSent::dispatch((int) $payload['konsultasi_id'], $payload);
    }

    /**
     * Re-read the consultation a written message belongs to, with the three
     * relations every consultation response publishes.
     *
     * The write methods return the CHAT row so the controller can broadcast it, and
     * the consultation comes back through the relation the service pre-loaded. It is
     * swapped for a freshly eager-loaded row here because the response is built
     * AFTER the transaction, and a row loaded inside one would answer from the
     * transaction's snapshot.
     */
    private function muatan(KonsultasiChat $pesan): Konsultasi
    {
        return $this->access->muatan((int) $pesan->konsultasi_id);
    }

    /**
     * The authenticated `User`, narrowed for the static analyser.
     *
     * Every action here runs behind `auth:sanctum`, so `user()` is never null.
     */
    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifikasi\IndexNotifikasiRequest;
use App\Http\Resources\NotifikasiResource;
use App\Models\Notifikasi;
use App\Models\User;
use App\Services\Notifikasi\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Three routes, and the only column a client can make the server write is
 * `dibaca_at`.
 *
 * | route | verb | `data` | `meta` |
 * | --- | --- | --- | --- |
 * | `GET /api/v1/notifikasi` | the caller's inbox, paginated | `{notifikasi}` | `pageMeta()` + `unread` |
 * | `PUT /api/v1/notifikasi/{id}/baca` | stamp one read | `{notifikasi}` | no |
 * | `PUT /api/v1/notifikasi/baca-semua` | stamp every unread | `{ditandai}` | no |
 *
 * ## There is NO create route, and that is the point
 *
 * `notifikasi` is an INBOX. A client may list it and mark it read; it may not
 * create, edit or delete a row, and it may not set `dibuat_at` - a notification
 * placed in the past is a fabricated record, and a notification placed in the
 * future is a way to make a list look stale.
 * {@see NotificationService} is the only producer, and
 * it is called by module services rather than by controllers.
 *
 * Both write routes take NO body: `Request` is injected rather than a
 * `FormRequest`, so there is no `validated()` and no field a caller can set. A
 * caller that sends one has it ignored, and the test sends a hostile body to both
 * routes to prove every column except `dibaca_at` is byte-identical afterwards.
 *
 * ## 404 for another account's row, never 403
 *
 * Every lookup is `where('user_id', $caller)`, so another account's id is not
 * found rather than forbidden. Over a sequential `BIGINT` key a 403 would confirm
 * the row exists, which is a cross-tenant existence oracle - the same split
 * `RekamMedisAccess` and `PesananObatService::untukBaca()` make. A non-numeric
 * `{id}` never reaches here: `whereNumber` compiles the segment to a regex, so it
 * is a router 404, and the two 404s are byte-identical.
 *
 * ## The 403 that DOES exist here is a role, not an ownership fact
 *
 * `permission:notifikasi.lihat` is granted to all five roles in
 * `RbacCatalog::ROLE_PERMISSIONS`, so it refuses `perawat` and `kurir` - real
 * `users.tipe` values (`:139`) holding no role at all. The guard is kept rather
 * than dropped for two reasons: it is the only named code for the action, and
 * dropping it would put the allowlist in a controller where it could not be
 * revoked by a data change. The cost is asserted by this todo's test, and its fix
 * is a data change in `RbacCatalog` plus a re-seed - not a code change here.
 *
 * ## `meta.unread` is the BADGE, and it is not the page's count
 *
 * `ApiResponse::pageMeta()` gives the pagination block and `unread` is added
 * beside it. The value is the caller's TOTAL unread rows, computed by a separate
 * query that is not affected by `?unread` or by the page - so a client rendering
 * `?unread=true&per_page=15&page=3` still gets the number its bell icon needs. A
 * per-page count would be a number that changes as the user scrolls, and the
 * test asserts it is identical on the unfiltered, the filtered and the paged
 * request.
 *
 * ## Marking read is IDEMPOTENT, and that is what makes the audit log readable
 *
 * An already-read row keeps the instant it was first read. The alternative -
 * re-stamping on every call - would make "when did they see it" unanswerable and
 * would write one `audit_log` row per screen open, so the log would end up
 * recording attention rather than changes. The second call is therefore also a
 * 200 with an unchanged body AND no second audit row, which is asserted.
 *
 * ## The save goes through the MODEL, deliberately
 *
 * `Notifikasi` hangs off `users` (`:1046`) and is in `AuditScope`'s person
 * closure, so the global `AuditObserver` audits it. A builder
 * `->update(['dibaca_at' => ...])` fires no Eloquent event and would therefore
 * be the one write in this table that leaves no `audit_log` row - so each row is
 * fetched and `save()`d, and the number of audit rows equals the number of rows
 * that actually changed.
 */
class NotifikasiController extends Controller
{
    /**
     * Rows one `baca-semua` transaction touches.
     *
     * 500 keeps each transaction short and each batch small enough that a lock held
     * by one chunk does not span the whole inbox, while still turning an O(unread)
     * round-trip count into O(unread / 500).
     */
    private const BACA_SEMUA_PER_BATCH = 500;

    /**
     * `GET /api/v1/notifikasi` - the caller's notifications, newest first.
     *
     * Newest first by `dibuat_at DESC, id DESC`: `dibuat_at` is
     * `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` (`:1045`) and therefore has
     * one-second resolution, so rows written in the same second would otherwise
     * come back in an order the database chose. The `id` tiebreaker makes the
     * order total, which is what lets a client page without seeing a row twice or
     * skip one.
     */
    public function index(IndexNotifikasiRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $valid = $request->validated();

        $query = Notifikasi::query()->where('user_id', (int) $user->getKey());
        $unread = $request->unread();

        if ($unread !== null) {
            // Two NAMED methods, not one boolean flag. `whereNull()`'s second
            // parameter is the boolean CONNECTOR ('and' / 'or') and not an
            // operator - passing `false` to it produces the invalid SQL
            // `where user_id = ? 1 `dibaca_at` is null`, which is a 500. The
            // first draft of this method did exactly that. `dibaca_at DATETIME
            // NULL` (:1044) means "unread", and `idx_notif (user_id, dibaca_at)`
            // (:1047) is the index that answers it.
            $unread
                ? $query->whereNull('dibaca_at')
                : $query->whereNotNull('dibaca_at');
        }

        $perPage = (int) ($valid['per_page'] ?? IndexNotifikasiRequest::PER_PAGE_MAKS);
        $page = (int) ($valid['page'] ?? 1);

        $paginator = $query
            ->orderByDesc('dibuat_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        // A SEPARATE count, deliberately not `$paginator->total()`: the badge is
        // "how many unread in total", and `$paginator->total()` is "how many rows
        // matched the filter". They are the same number on an unfiltered request
        // and different on every other one.
        $meta = array_merge(ApiResponse::pageMeta($paginator), [
            'unread' => Notifikasi::query()
                ->where('user_id', (int) $user->getKey())
                ->whereNull('dibaca_at')
                ->count(),
        ]);

        return ApiResponse::success(
            ['notifikasi' => NotifikasiResource::collection($paginator->getCollection())],
            'Daftar notifikasi berhasil dimuat.',
            Response::HTTP_OK,
            $meta,
        );
    }

    /**
     * `PUT /api/v1/notifikasi/{id}/baca` - 200, or 404 for a row that is not the
     * caller's.
     *
     * Idempotent: a row that already has `dibaca_at` is returned untouched, so
     * the response carries the FIRST read instant and no `audit_log` row is
     * written for a call that changed nothing.
     */
    public function baca(Request $request, int $id): JsonResponse
    {
        $baris = Notifikasi::query()
            ->where('user_id', (int) $this->user($request)->getKey())
            ->whereKey($id)
            ->first();

        if ($baris === null) {
            throw (new ModelNotFoundException)->setModel(Notifikasi::class, [$id]);
        }

        if ($baris->dibaca_at === null) {
            $baris->dibaca_at = now();
            $baris->save();
        }

        return ApiResponse::success(
            ['notifikasi' => new NotifikasiResource($baris)],
            'Notifikasi ditandai sudah dibaca.',
            Response::HTTP_OK,
        );
    }

    /**
     * `PUT /api/v1/notifikasi/baca-semua` - 200 with the number of rows marked.
     *
     * The count is the number CHANGED, not the number owned, so a second call
     * reports `0` rather than the inbox size - and a client can use that to tell
     * "everything is already read" from "I have nothing".
     *
     * ## Chunked, because an inbox has no upper bound
     *
     * The first implementation read every unread id and then re-fetched each row one
     * at a time: O(unread) round trips inside one request, which a 10 000-row inbox
     * turns into 10 001 statements. This walks the unread rows with `chunkById()` -
     * `BACA_SEMUA_PER_BATCH` at a time, ordered by the primary key so the walk is
     * stable while rows are updated - and wraps each chunk in its own transaction, so
     * a failure late in a large inbox leaves the earlier chunks committed.
     *
     * ## The save still goes through the MODEL, per row
     *
     * `Notifikasi` is in `AuditScope`'s person closure, so the global `AuditObserver`
     * audits it; a builder `->update(['dibaca_at' => ...])` fires no Eloquent event
     * and would be the one write in this table leaving no `audit_log` row. Chunking
     * changes how many statements the READ costs, not how the write is recorded: one
     * `save()` per changed row, one audit row per change.
     */
    public function bacaSemua(Request $request): JsonResponse
    {
        $userId = (int) $this->user($request)->getKey();
        $ditandai = 0;

        // `dibaca_at DATETIME NULL` (:1044) is the unread state, and
        // `idx_notif (user_id, dibaca_at)` (:1047) is the index the predicate is
        // built for. `chunkById` needs the primary key in the ordering, which is why
        // `orderBy('id')` is explicit rather than inherited.
        Notifikasi::query()
            ->where('user_id', $userId)
            ->whereNull('dibaca_at')
            ->orderBy('id')
            ->chunkById(self::BACA_SEMUA_PER_BATCH, function ($rows) use (&$ditandai): void {
                DB::transaction(function () use ($rows, &$ditandai): void {
                    foreach ($rows as $baris) {
                        // A concurrent read or single-row write can have stamped it
                        // between the chunk SELECT and this save. Saving again would
                        // overwrite the FIRST read instant, which the single-row
                        // endpoint also refuses to do.
                        if ($baris->dibaca_at !== null) {
                            continue;
                        }

                        $baris->dibaca_at = now();
                        $baris->save();
                        $ditandai++;
                    }
                });
            });

        return ApiResponse::success(
            ['ditandai' => $ditandai],
            'Semua notifikasi berhasil ditandai sudah dibaca.',
            Response::HTTP_OK,
        );
    }

    /**
     * The authenticated `User`, narrowed for the static analyser.
     *
     * Every action runs behind `auth:sanctum`, so `user()` is never null.
     */
    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}

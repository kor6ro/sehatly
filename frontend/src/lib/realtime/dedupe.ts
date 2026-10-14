/**
 * The dedupe set, and the whole of the REST-plus-realtime rule.
 *
 * ## The key is `konsultasi_chat.id`, and that is the only correct choice
 *
 * A message can reach this client three times:
 *
 * 1. in the `GET /konsultasi/{id}/chat` history page, when the screen mounts or
 *    the user pages back;
 * 2. live on the `chat.pesan` broadcast, because the sender's own client is
 *    subscribed to the same private channel it published to;
 * 3. again, from a re-subscribe after a reconnect, if the broker replays frames it
 *    considers in flight for that channel.
 *
 * (2) is not an edge case - it happens on **every single send**. The server
 * dispatches `KonsultasiMessageSent` from the controller after the transaction, and
 * a private channel fans out to every subscriber including the author.
 *
 * The two transports carry a **byte-identical** payload, which is what makes this
 * possible at all: `KonsultasiController::siarkan()` passes
 * `(new KonsultasiChatResource($pesan))->resolve($request)` - the same
 * allow-list the REST history is collected through - straight into
 * `broadcastWith()`, which returns it verbatim. So `id` on the frame and `id` in
 * the page are the same number from the same row.
 *
 * ## Why any other key silently fails
 *
 * A key that differs between the two transports suppresses nothing while looking
 * like it works:
 *
 * | candidate | why it is wrong |
 * | --- | --- |
 * | `konsultasi_id` | every message in one transcript shares it, so the second message would be dropped as a duplicate of the first |
 * | `isi` + `terkirim_at` | "the same text sent twice" is two real messages, and a paste of a quoted line would lose one |
 * | `pengirim_user_id` | both parties' messages collapse into one |
 * | arrival order / a timestamp | non-deterministic, and re-delivery is exactly the case it must catch |
 *
 * ## Why the set is bounded, and why 500
 *
 * Unbounded, a tab left open for weeks would retain every id it ever saw. Too
 * small, and an id evicted before the resync window re-delivers a genuine
 * duplicate. A consultation transcript is hundreds of rows, so 500 covers a busy
 * session with room for the whole resync window on top of it.
 *
 * Eviction is oldest-first by insertion order, not strict LRU: the only duplicate
 * that matters arrives shortly after the first delivery, which insertion order
 * already covers, and an exact LRU needs a list plus a map keyed by id.
 *
 * ## A message with no id is DELIVERED
 *
 * `id` is `0` for a frame that carried none. Suppressing those would silently drop
 * every message from a server that stopped publishing the key, which is the worst
 * failure a chat can have: it looks like a working client that has gone quiet. So
 * an unidentified row is delivered and counted instead, and the count is rendered
 * so the condition is visible rather than inferred from silence.
 */
export class MessageDedupe {
    private readonly capacity: number;

    private readonly seen = new Set<number>();

    private suppressed = 0;

    private unidentified = 0;

    constructor(capacity = 500) {
        this.capacity = capacity < 1 ? 1 : capacity;
    }

    /**
     * Record `id` as delivered, or report that it already was.
     *
     * `true` means "not seen before, now remembered" and the caller should render.
     */
    remember(id: number): boolean {
        if (this.seen.has(id)) {
            this.suppressed += 1;

            return false;
        }

        this.seen.add(id);
        this.evictIfNeeded();

        return true;
    }

    has(id: number): boolean {
        return this.seen.has(id);
    }

    /**
     * Remember rows the renderer has **already** displayed, without delivering.
     *
     * The counterpart of the resync backfill, and they are opposites: a history
     * page is on screen already, so remembering it and staying quiet is correct; a
     * resync backfill is the window the broker never replayed, so it must be
     * delivered. Seeding a backfill would mark a message known and then throw it
     * away - a healthy transcript silently missing everything sent while the
     * socket was down, which is the one failure this path exists to prevent.
     */
    seedHistory(rows: Iterable<{ id: number }>): void {
        for (const row of rows) {
            if (row.id > 0) {
                this.seen.add(row.id);
            }
        }

        this.evictIfNeeded();
    }

    countUnidentified(): void {
        this.unidentified += 1;
    }

    get duplicateSuppressedCount(): number {
        return this.suppressed;
    }

    get unidentifiedEventCount(): number {
        return this.unidentified;
    }

    get size(): number {
        return this.seen.size;
    }

    private evictIfNeeded(): void {
        for (const oldest of this.seen) {
            if (this.seen.size <= this.capacity) {
                return;
            }

            this.seen.delete(oldest);
        }
    }
}

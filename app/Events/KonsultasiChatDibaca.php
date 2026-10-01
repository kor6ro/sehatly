<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A participant's read marker moved forward on a consultation transcript.
 *
 * ## `ShouldBroadcastNow`, for the same reason as `KonsultasiMessageSent`
 *
 * A "Dibaca" receipt is as useless late as the message it acknowledges: the
 * sender is watching the bubble that just changed, and a receipt that arrives
 * when a queue worker wakes up is a receipt nobody can correlate with their own
 * send. The send is therefore inline, on the request that already committed the
 * `konsultasi_baca` row, and `KonsultasiController::chatBaca()` dispatches it
 * AFTER the service transaction returns. The full argument for that placement -
 * including why `DB::afterCommit()` cannot be used under `RefreshDatabase` - is
 * in `KonsultasiMessageSent`'s docblock and in `KonsultasiController`'s.
 *
 * ## `chat.mengetik` is deliberately NOT here
 *
 * `chat.mengetik` ("is typing…") is a CLIENT-ONLY whisper: the typing signal
 * travels over the already-authorized `private-konsultasi.{id}` channel that
 * Echo client-side whispering provides, and it must NEVER grow a server event,
 * route, database write or queue job. A typing indicator is ephemeral, it has no
 * history worth storing, and a server relay would let one party's keystrokes
 * become a durable, auditable artifact of the other. The only two server-sent
 * chat events are the two classes beside this one: `chat.pesan`
 * ({@see KonsultasiMessageSent}) and `chat.dibaca` (this class).
 *
 * ## Channel naming and the wire name
 *
 * `broadcastOn()` returns the unprefixed `PrivateChannel('konsultasi.{id}')`;
 * the framework adds `private-`, so the resolved name is
 * `private-konsultasi.{id}` - the same string `routes/channels.php` authorizes
 * and the client subscribes with. `broadcastAs()` renames the PHP class to the
 * domain event `chat.dibaca`.
 *
 * ## The payload
 *
 * `{user_id, last_read_at}` - the account whose marker moved and the moment,
 * as an ISO-8601 UTC instant. That is exactly the minimum the other client
 * needs to re-render its outgoing bubbles as "Dibaca"; the transcript's own
 * per-message `dibaca_at` remains served by `GET .../chat`.
 */
final class KonsultasiChatDibaca implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  int  $konsultasiId  Owning consultation; selects the channel.
     * @param  int  $userId  The participant whose read marker moved.
     * @param  string  $lastReadAt  The marker, as an ISO-8601 UTC instant.
     */
    public function __construct(
        public int $konsultasiId,
        public int $userId,
        public string $lastReadAt,
    ) {}

    /**
     * The channels the event should be broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('konsultasi.'.$this->konsultasiId);
    }

    /**
     * The event's wire name.
     */
    public function broadcastAs(): string
    {
        return 'chat.dibaca';
    }

    /**
     * The payload to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'last_read_at' => $this->lastReadAt,
        ];
    }
}

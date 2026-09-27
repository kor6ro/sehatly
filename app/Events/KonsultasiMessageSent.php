<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A chat message was appended to a consultation transcript.
 *
 * ## `ShouldBroadcastNow`, not `ShouldBroadcast`
 *
 * `ShouldBroadcast` defers the send to the queue, so a message is not delivered
 * until a worker picks it up. Chat delivery is the one feature in this
 * application that is useless while it is late, and this project runs
 * `QUEUE_CONNECTION=database` (`.env`), which means a worker is a separate
 * process someone has to remember to start. `ShouldBroadcastNow` sends inline
 * on the request that wrote the row, so the message reaches both participants
 * as part of the same response that persisted it.
 *
 * The cost is stated rather than hidden: the HTTP response now waits on the
 * Reverb round trip. The client is a mobile app on a socket that is already
 * open, so the added latency is the local send, and the alternative - a message
 * that sits in a `jobs` row until a worker exists - is the failure mode that
 * actually ships to users. This is also the one place in the contract where a
 * synchronous third-party dependency is acceptable, because there is exactly
 * one broadcast per message rather than a fan-out.
 *
 * ## The payload is passed in already resolved
 *
 * `broadcastWith()` returns `$this->message` verbatim. The array is not built
 * here, and this event does not hold the `KonsultasiChat` model, because the
 * Resource that shapes a chat message for the wire belongs to todo 32
 * (`App\Http\Resources\KonsultasiChatResource`). Accepting the resolved array
 * makes the drift the plan warns about structurally impossible: the same array
 * that the REST history endpoint returns is the array that goes to the socket,
 * because todo 32 passes the Resource output in and this class only forwards it.
 * Re-deriving the shape here instead would give two definitions of "a chat
 * message" in a codebase that has to keep them equal by hand.
 *
 * ## Channel naming
 *
 * `PrivateChannel('konsunikasi.{id}')` is the unprefixed name, and
 * `PrivateChannel::__construct()` adds the `private-` prefix itself, so
 * `broadcastOn()` resolves to `private-konsultasi.{id}`. That is correct on the
 * publish side and is the same string the client subscribes with. Passing an
 * already-prefixed name in would publish `private-private-konsultasi.{id}`.
 *
 * The *authorization* side is the mirror image and the reason the pattern in
 * `routes/channels.php` is written without a prefix: an incoming request
 * carries `private-konsultasi.{id}`, and
 * `UsePusherChannelConventions::normalizeChannelName()` strips the prefix before
 * the pattern is matched.
 *
 * `broadcastAs()` renames the event to `chat.pesan` so a client subscribes to a
 * domain-named event rather than to a PHP class name, which is a namespace the
 * API contract should not be leaking.
 */
final class KonsultasiMessageSent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  int  $konsultasiId  Owning consultation; selects the channel.
     * @param  array<string, mixed>  $message  The already-serialised message, as a Resource would resolve it.
     */
    public function __construct(
        public int $konsultasiId,
        public array $message,
    ) {
        //
    }

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
        return 'chat.pesan';
    }

    /**
     * The payload to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->message;
    }
}

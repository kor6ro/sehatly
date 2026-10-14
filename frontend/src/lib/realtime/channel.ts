/**
 * Channel naming, split out of `socket.ts` so it carries no transport at all.
 *
 * `konsultasi-realtime.ts` needs {@link konsultasiChannel} as a VALUE, and importing
 * it from `socket.ts` would drag `socket.ts`'s own value import - `@/lib/echo`, and
 * through it `laravel-echo`, `pusher-js` and `import.meta.env`. That makes the
 * reconnect logic unloadable outside a bundler, which is exactly where it has to be
 * testable. The naming rule is domain knowledge about the server's channel
 * conventions, not about Echo, so it belongs in its own leaf.
 */

/** The prefix the protocol puts in front of a private channel name, on the wire. */
export const PRIVATE_CHANNEL_PREFIX = 'private-';

/**
 * The LOGICAL channel name for a consultation, with no wire prefix.
 *
 * `routes/channels.php` authorises `konsultasi.{id}` - with the prefix OFF, because
 * `UsePusherChannelConventions::normalizeChannelName()` strips it before the pattern
 * is matched - and `KonsultasiMessageSent::broadcastOn()` returns `new
 * PrivateChannel('konsultasi.' . $id)`, whose constructor adds the prefix itself. So
 * `konsultasi.5` is what both sides pass around and `private-konsultasi.5` is what
 * the broker sees. Code that writes the prefix itself produces
 * `private-private-konsultasi.5`, a channel that was never authorised and a
 * subscription that never confirms.
 */
export function konsultasiChannel(konsultasiId: number): string {
    return `konsultasi.${konsultasiId}`;
}

/** The wire name, for a log line or an overlay that must match the broker's. */
export function wireName(konsultasiId: number): string {
    return `${PRIVATE_CHANNEL_PREFIX}${konsultasiChannel(konsultasiId)}`;
}

/**
 * Type-level assertions about the GENERATED client types.
 *
 * ## Why this file exists
 *
 * `web/src/types/api.d.ts` is produced by `openapi-typescript` from
 * `docs/openapi.yaml`. `tsc --noEmit` type-checks every file under `src/`,
 * including a generated one -- so if the generated types were the ONLY thing in
 * `src/`, the check would pass on a file nothing imports. A type that nothing
 * references cannot fail: an interface whose `meta` moved inside `data`, or
 * whose `errors` flattened from `string[]` to `string`, would type-check
 * perfectly well and break every screen at runtime.
 *
 * So this file imports the generated types and instantiates an assertion for
 * each fact the envelope design rests on.
 *
 * ## `Assert<...>` is load-bearing, and a bare type alias would not be
 *
 * `type X = Foo extends Bar ? true : never` is evaluated LAZILY: nothing forces
 * the conditional, so if `Foo` stops matching, `X` becomes `never` and the file
 * still compiles. Every assertion here is written as a conditional that resolves
 * to either `true` or a **string saying what broke**, instantiated through
 * `Assert<T extends true>` and exported so `noUnusedLocals` does not flag it.
 * The string branch is what turns "the check silently stopped checking" into a
 * named compile error:
 *
 *     error TS2344: Type '"errors-are-flattened-to-one-string-per-field"'
 *                   does not satisfy the constraint 'true'.
 *
 * ## Two things this file does NOT claim, because the generator does not
 * express them
 *
 * 1. **`errors` values are non-empty.** The schema declares `minItems: 1`, but
 *    `openapi-typescript` 7.13.0 does not project `minItems` onto a
 *    `[string, ...string[]]` tuple, so the assertion here is only that the value
 *    is an ARRAY of strings. A client cannot tell an empty list from a
 *    populated one at the type level, so the runtime has to. That is a real gap
 *    in the generated types and it is named here rather than papered over with an
 *    assertion that quietly checks something weaker.
 *
 * 2. **Per-operation `security`.** The document publishes `security` on every
 *    operation -- `[{sanctum: []}]` or `[]` -- but `openapi-typescript` does not
 *    project it into `operations` or `paths` at all. A generated client gets the
 *    bearer scheme in `components.securitySchemes` and nothing per endpoint. The
 *    assertions below check the STATUS codes instead, which the generator does
 *    carry, and the per-operation guard is verified in the PHP suite
 *    (`OpenApiCommandTest`) where the document itself is read.
 *
 * ## It asserts TYPES, not values
 *
 * There is no runtime code here except the one narrowing helper, which exists
 * to prove the union narrows. So the file adds no bundle weight and nothing in
 * it can rot through a stale runtime expectation.
 */

import type { components, operations, paths } from './api';

type SuccessEnvelope = components['schemas']['SuccessEnvelope'];
type PaginatedEnvelope = components['schemas']['PaginatedEnvelope'];
type ErrorEnvelope = components['schemas']['ErrorEnvelope'];
type ValidationErrorEnvelope = components['schemas']['ValidationErrorEnvelope'];
type PaginatedMeta = components['schemas']['PaginatedMeta'];

/** Forces a conditional to resolve, so a `never` becomes a compile error. */
type Assert<T extends true> = T;

/**
 * `meta` is a TOP-LEVEL FOURTH KEY on a list response -- a sibling of `data`,
 * not nested inside it.
 *
 * `ApiResponse::success()` appends `meta` after `message` precisely so adding
 * pagination cannot renumber the three keys every existing client already
 * reads. A `PaginatedEnvelope` whose `meta` moved under `data` would break
 * every list screen, and would type-check perfectly well in isolation.
 */
export type MetaIsThePaginationBlock = Assert<
    PaginatedEnvelope['meta'] extends PaginatedMeta ? true : 'meta-is-not-the-pagination-block'
>;

/**
 * The corollary, and the one a client actually trips over: `data` on a
 * paginated envelope is a LIST and carries no `meta` of its own, so
 * `body.data.meta` must not compile.
 */
export type DataHasNoNestedMeta = Assert<
    PaginatedEnvelope['data'] extends { meta: unknown } ? 'meta-is-nested-inside-data' : true
>;

/**
 * A 422 carries MULTIPLE MESSAGES PER FIELD.
 *
 * `ValidationException::errors()` returns `array<string, list<string>>` and a
 * field really can fail more than one rule, so `errors.email` is a list. If the
 * schema were flattened to `{field: string}`, a client would show the first
 * message and silently drop the rest -- the exact failure the separate
 * `ValidationErrorEnvelope` component exists to prevent.
 */
type EmailMessages = ValidationErrorEnvelope['errors']['email'];

export type ErrorsAreAListPerField = Assert<
    EmailMessages extends readonly string[] ? true : 'errors-are-flattened-to-one-string-per-field'
>;

export type ErrorMessagesAreStrings = Assert<
    EmailMessages[number] extends string ? true : 'error-messages-are-not-strings'
>;

/**
 * A SUCCESS envelope must not carry an `errors` key, because `ApiResponse`
 * omits it rather than emitting `null` -- omission is what makes "this response
 * is not a failure" unambiguous.
 */
export type SuccessEnvelopeHasNoErrorsKey = Assert<
    'errors' extends keyof SuccessEnvelope ? 'success-envelope-declares-errors' : true
>;

/**
 * The success envelopes carry a literal `success: true` and the failure
 * envelopes a literal `success: false`.
 *
 * Returns the number of messages on `email` when the body is a failure and
 * `undefined` when it is a success. The `body.errors` access in the `else`
 * branch only compiles because narrowing happened -- that is the assertion. A
 * client that has to re-check `success` by hand is a client that will
 * eventually forget to.
 */
function messageCountOnFailure(body: SuccessEnvelope | ErrorEnvelope): number | undefined {
    if (body.success) {
        return undefined;
    }

    return body.errors.email?.length;
}

/**
 * Path and method keys are the literal URIs the route table registered, so a
 * typo in a call site is a compile error rather than a 404 at runtime.
 *
 * `GET` and `POST` on `/api/v1/konsultasi/{id}/chat` are two distinct
 * operations, which is why both are pinned rather than "the consultation path
 * exists": a path with one method missing compiles exactly like a correct one
 * until a screen calls the method that is not there.
 */
type ChatHistoryOperation = paths['/api/v1/konsultasi/{id}/chat']['get'];
type ChatSendOperation = paths['/api/v1/konsultasi/{id}/chat']['post'];
type BookingCreateOperation = operations['postApiV1Booking'];
type BookingListOperation = operations['getApiV1PasienBooking'];

/**
 * Path parameters are published, so `{id}` is a REQUIRED parameter and a call
 * site cannot omit it.
 */
export type ConsultationIdParameter = NonNullable<
    ChatHistoryOperation['parameters']['path']
>['id'];

export type PathParameterIsPublished = Assert<
    NonNullable<ChatHistoryOperation['parameters']['path']> extends { id: unknown }
        ? true
        : 'path-parameter-not-published'
>;

/**
 * A write operation publishes its request body. One direction is not enough on
 * its own -- `requestBody` on the WRONG operation would type-check just as
 * cleanly as on the right one -- so the read below asserts the absence too.
 */
export type WriteOperationHasARequestBody = Assert<
    BookingCreateOperation extends { requestBody: { content: unknown } }
        ? true
        : 'a-write-operation-publishes-no-request-body'
>;

export type ReadOperationHasNoRequestBody = Assert<
    BookingListOperation extends { requestBody?: undefined }
        ? true
        : 'a-read-operation-publishes-a-request-body'
>;

/**
 * A `422` is published on every operation that has a request body, because
 * every operation with a `FormRequest` can fail validation. A client that
 * renders a form needs to know a 422 exists before the user hits submit.
 *
 * The status keys are NUMERIC, not strings: `responses` is a mapping keyed by
 * HTTP status, and TypeScript reads a bare `422:` in the emitted declaration as
 * the number 422. `422 extends keyof ...` is therefore correct and
 * `'422' extends keyof ...` is a compile error, which is a genuinely confusing
 * thing to discover at 2am -- hence the note.
 */
export type WriteOperationPublishes422 = Assert<
    422 extends keyof BookingCreateOperation['responses']
        ? true
        : 'a-write-operation-publishes-no-422'
>;

/**
 * And an authenticated operation publishes the 401 a client must handle on a
 * stale token. Numeric key, same reason as above.
 */
export type AuthenticatedOperationPublishes401 = Assert<
    401 extends keyof ChatHistoryOperation['responses']
        ? true
        : 'an-authenticated-operation-publishes-no-401'
>;

/**
 * The three exempted write routes publish NO `422`, because none of them
 * field-validates a body: `PUT /notifikasi/{id}/baca` and
 * `PUT /notifikasi/baca-semua` name nothing in the request, and the gateway
 * webhook is HMAC-verified. A client that renders a form for them would show a
 * validation error the server can never produce.
 */
export type BodylessWritePublishesNo422 = Assert<
    422 extends keyof operations['putApiV1NotifikasiBacaSemua']['responses']
        ? 'a-bodyless-write-publishes-a-422-it-can-never-produce'
        : true
>;

/**
 * And an anonymous route publishes no 401 either -- there is no token to be
 * stale.
 */
export type AnonymousOperationPublishesNo401 = Assert<
    401 extends keyof paths['/api/v1/dokter']['get']['responses']
        ? 'an-anonymous-operation-publishes-a-401'
        : true
>;;

/**
 * A paginated operation's success response references the PAGINATED envelope.
 * If the generator ever filed every 200 under `SuccessEnvelope`, the client's
 * list rendering would have no `meta` to read and the pager would show
 * "undefined of undefined".
 */
export type BookingListSuccessIsPaginated = Assert<
    NonNullable<BookingListOperation['responses']['200']['content']['application/json']> extends PaginatedEnvelope
        ? true
        : 'a-paginated-operation-does-not-reference-PaginatedEnvelope'
>;

/**
 * And a non-paginated write references the plain one, so the two envelopes are
 * not accidentally swapped.
 */
export type BookingCreateIsNotPaginated = Assert<
    NonNullable<BookingCreateOperation['responses']['201']['content']['application/json']> extends SuccessEnvelope
        ? true
        : 'a-non-paginated-operation-references-PaginatedEnvelope'
>;

/**
 * A `Rule::in(...)` closed set becomes a literal union, which is the whole
 * reason the generator reads those rules: a client cannot send a booking type
 * the database would reject with a 1264.
 *
 * `openapi-typescript` COLLAPSES a single-value-type schema carrying an `enum`
 * into the union itself, so `EnumBookingStatus` IS the union rather than an
 * object wrapping one. Reading it as `['enum']` does not compile -- which is
 * itself worth knowing, because it means the generated client gets a real
 * literal union and not `{ enum: [...] }`.
 */
type TipeLayanan = NonNullable<
    BookingCreateOperation['requestBody']['content']['application/json']
>['tipe_layanan'];

type BookingStatus = components['schemas']['EnumBookingStatus'];

export type TipeLayananIsAClosedSet = Assert<
    TipeLayanan extends 'chat' | 'video_call' | 'kunjungan_klinik' | 'home_visit'
        ? true
        : 'booking.tipe_layanan-is-not-a-closed-set-in-the-generated-types'
>;

export type BookingStatusIsAClosedSet = Assert<
    BookingStatus extends
        | 'menunggu_pembayaran'
        | 'terjadwal'
        | 'check_in'
        | 'berlangsung'
        | 'selesai'
        | 'dibatalkan'
        | 'no_show'
        | 'kadaluarsa'
        ? true
        : 'booking.status-is-not-a-closed-set-in-the-generated-types'
>;

/**
 * The ENUM catalogue is reachable from the generated types, so a status column
 * can be typed from `docs/enums.json` rather than transcribed a third time --
 * which is the whole point of reading the catalogue into the document.
 */
export type BookingStatusIsAString = Assert<
    BookingStatus extends string ? true : 'the-enum-catalogue-is-not-reachable'
>;

export { messageCountOnFailure };

export type {
    BookingCreateOperation,
    BookingListOperation,
    BookingStatus,
    ChatHistoryOperation,
    ChatSendOperation,
    EmailMessages,
    TipeLayanan,
};

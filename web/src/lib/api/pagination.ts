import type { ApiMeta } from '@/lib/http';

/**
 * The one definition of "this list is empty", shared by every list screen.
 *
 * ## Why `data.<rows> === []` is not the answer
 *
 * Two different situations produce an empty array in `data` and they need opposite copy:
 *
 * - the account genuinely has no rows - `meta.total === 0` - which is a *state* worth
 *   explaining and offering a way out of;
 * - the caller asked for page 7 of a 3-page result - `meta.total > 0` but
 *   `data.<rows> === []` - which is a *navigation* mistake and the fix is to go back.
 *
 * Deciding on the array length alone renders the same blank box for both, and the second
 * one leaves the user on a dead end with no indication that the data exists.
 *
 * ## Why `meta` is optional
 *
 * `ApiResponse` omits the key entirely on a response that is not paginated rather than
 * sending `null`, so "not paginated" and "paginated with a null block" have to be the
 * same case to the client. The fallback is the row count, which is the best answer
 * available when the server sent no block.
 */
export function isEmptyPage(meta: ApiMeta | undefined, rowCount: number): boolean {
    if (meta !== undefined) {
        return meta.total === 0;
    }

    return rowCount === 0;
}

/**
 * Is the caller looking at a page past the end of a non-empty result?
 *
 * The inverse of {@link isEmptyPage} for the same set of inputs, named separately because
 * "no rows anywhere" and "no rows on this page" are different sentences.
 */
export function isPastLastPage(meta: ApiMeta | undefined, rowCount: number): boolean {
    if (meta === undefined || rowCount > 0) {
        return false;
    }

    return meta.total > 0 && meta.current_page > meta.last_page;
}

/**
 * `from`-`to` of `total`, the project's "1-15 of 47" line.
 *
 * Returns `null` when there is nothing to count, so the caller can omit the line instead
 * of rendering "0-0 of 0" next to an empty state that already says there is nothing.
 */
export function describeRange(meta: ApiMeta | undefined): string | null {
    if (meta === undefined || meta.from === null || meta.to === null) {
        return null;
    }

    return `${meta.from}-${meta.to} dari ${meta.total}`;
}

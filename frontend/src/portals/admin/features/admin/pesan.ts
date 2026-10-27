import { ApiError } from '@/lib/http';

/**
 * The failure envelope, reduced to the one sentence a panel can show.
 *
 * A `422` from the admin writes arrives as `errors: { field: [messages] }`, and
 * the field differs per action: a schedule conflict is `errors.hari`, a delete
 * refusal is `errors.jadwal`, a duplicate leave is `errors.tanggal`. The server
 * writes a complete, human sentence on each, so this reads the field's first
 * message and falls back to the envelope's own message.
 */

export function pesanField(error: unknown, field: string): string | null {
    if (error instanceof ApiError) {
        const pesan = error.fieldErrors(field);

        return pesan.length > 0 ? pesan[0] : error.message;
    }

    if (error instanceof Error) {
        return error.message;
    }

    return null;
}

export function pesanRingkas(error: unknown): string {
    if (error instanceof ApiError) {
        const fields = error.failedFields;

        if (fields.length > 0) {
            const pertama = error.fieldErrors(fields[0]);

            if (pertama.length > 0) {
                return pertama[0];
            }
        }

        return error.message;
    }

    return error instanceof Error ? error.message : 'Terjadi kesalahan yang tidak diketahui.';
}

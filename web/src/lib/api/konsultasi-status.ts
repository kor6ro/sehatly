import type { StatusKonsultasi } from '@/lib/api/types';

/**
 * The terminal consultation statuses, mirrored from the server.
 *
 * `KonsultasiStatus::STATUS_AKHIR` on the server is `['selesai', 'dibatalkan',
 * 'gagal']` - the three states with no outgoing edge. A room in one of them
 * accepts no new message, so the composer is closed; the server refuses the
 * write regardless, and this is the client half of the same rule. It lives in a
 * leaf so a unit test can prove the list without loading the HTTP stack.
 */
export const STATUS_KONSULTASI_AKHIR: readonly StatusKonsultasi[] = [
    'selesai',
    'dibatalkan',
    'gagal',
] as const;

export function konsultasiTerkunci(status: StatusKonsultasi): boolean {
    return STATUS_KONSULTASI_AKHIR.includes(status);
}

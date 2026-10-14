import assert from 'node:assert/strict';
import test from 'node:test';
import type { StatusKonsultasi } from '@/lib/api/types';
import {
    konsultasiTerkunci,
    STATUS_KONSULTASI_AKHIR,
} from '@/lib/api/konsultasi-status';

const SEMUA: readonly StatusKonsultasi[] = [
    'menunggu_dokter',
    'berlangsung',
    'menunggu_resep',
    'selesai',
    'dibatalkan',
    'gagal',
];

test('exactly the three terminal statuses lock the composer', () => {
    assert.deepEqual([...STATUS_KONSULTASI_AKHIR], ['selesai', 'dibatalkan', 'gagal']);

    const terkunci = SEMUA.filter((status) => konsultasiTerkunci(status));

    assert.deepEqual(terkunci, ['selesai', 'dibatalkan', 'gagal']);

    // Every non-terminal status stays writable, so a room the server accepts a
    // write on is never closed by the client.
    for (const status of ['menunggu_dokter', 'berlangsung', 'menunggu_resep'] as const) {
        assert.equal(konsultasiTerkunci(status), false);
    }
});

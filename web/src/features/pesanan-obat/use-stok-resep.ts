import { useQueries } from '@tanstack/react-query';
import { stokObatOptions, type CekStokParams } from '@/lib/api/pesanan-obat';
import type { ResepItem, StokDiApotek } from '@/lib/api/types';

/**
 * The stock truth for one prescription, read from the only endpoint that publishes it.
 *
 * `GET /api/v1/obat` is `MasterObatResource` and carries no stock field at all - and it is
 * `tipe:dokter`, so a patient cannot call it. `GET /api/v1/obat/{id}/stok` is the sole
 * source, which is why this hook exists rather than a field read.
 *
 * ## Two rounds, because one round cannot answer the question
 *
 * **Without `apotek_id`** the response has `apotek: null` and `alternatif` holding every
 * active pharmacy with enough of THAT drug. Their intersection across the prescription is
 * the candidate list - the only pharmacy list this API publishes, since there is no
 * `GET /apotek`.
 *
 * **With `apotek_id`** the response carries that pharmacy's shelf per drug. This is the
 * round that decides whether checkout may proceed, because a pharmacy can be in the
 * intersection for drug A and still be short on drug B the moment another patient buys it.
 * `ApotekStokService::kurangi()` re-checks inside the checkout transaction, so anything
 * shown here is a reading and not a reservation.
 */

export type BarisStok = {
    itemId: number;
    /** `null` for a racikan: `resep_item.obat_id` is nullable and a racikan has none. */
    obatId: number | null;
    nama: string;
    jumlah: number;
    /** `null` until a pharmacy is chosen, and `null` forever for an uncheckable line. */
    apotek: StokDiApotek | null;
    /** The server's own `apotek.cukup`, or `null` when there is nothing to judge. */
    cukup: boolean | null;
};

export type StokResep = {
    /** Every line, checkable or not, so the table has no holes. */
    baris: BarisStok[];
    /** The intersection of the per-drug alternative lists, most plentiful first. */
    kandidat: Array<{ apotek_id: number; nama: string; jumlah_stok: number }>;
    /** True only when every checkable line has been read AND the server said `cukup`. */
    semuaCukup: boolean;
    /** Any checkable line the server could not answer, so the submit stays shut. */
    adaYangGagal: boolean;
    sedangMemuat: boolean;
    /** The drugs that have no pharmacy at all, named so the refusal is actionable. */
    tanpaApotek: string[];
};

function barisDasar(items: ResepItem[]): BarisStok[] {
    return items.map((item) => ({
        itemId: item.id,
        obatId: item.obat_id,
        nama: item.nama_obat,
        jumlah: item.jumlah,
        apotek: null,
        cukup: null,
    }));
}

function params(jumlah: number, apotekId: number | null): CekStokParams {
    return { jumlah, ...(apotekId === null ? {} : { apotekId }) };
}

export function useStokResep(
    items: ResepItem[],
    apotekId: number | null,
): StokResep {
    const baris = barisDasar(items);
    const cekabel = baris.filter((b) => b.obatId !== null);

    /**
     * Index-aligned with `cekabel`, so a line's shelf read can be found by position rather
     * than by re-searching for its drug. Two lines of the same drug at the same quantity
     * are therefore read once and share the answer, which is the server's own answer for
     * that drug and quantity anyway.
     */
    const kandidatQuery = useQueries({
        queries: cekabel.map((b) => stokObatOptions(b.obatId as number, params(b.jumlah, null))),
    });

    const shelfQuery = useQueries({
        queries: cekabel.map((b) =>
            stokObatOptions(b.obatId as number, params(b.jumlah, apotekId)),
        ),
    });

    /**
     * The intersection, and it is a real intersection rather than the first list.
     *
     * Taking the first item's alternatives would offer a pharmacy that the second item's
     * shelf cannot serve, which is the exact failure the stock guard exists to prevent -
     * the patient picks, the server refuses, and the screen looks like it lied.
     */
    const daftar = kandidatQuery.map((q) => q.data?.data.stok.alternatif ?? []);

    const kandidat = daftar.length === 0 ? [] : daftar.slice(1).reduce(
        (acc, list) => acc.filter((a) => list.some((b) => b.apotek_id === a.apotek_id)),
        daftar[0] as StokResep['kandidat'],
    );

    const tanpaApotek = cekabel
        .filter((_, index) => (daftar[index] ?? []).length === 0)
        .map((b) => b.nama);

    const withShelf: BarisStok[] = baris.map((b) => {
        if (b.obatId === null) {
            return b;
        }

        const index = cekabel.findIndex((c) => c.obatId === b.obatId && c.jumlah === b.jumlah);
        const apotek =
            apotekId === null ? null : (shelfQuery[index]?.data?.data.stok.apotek ?? null);

        return { ...b, apotek, cukup: apotek?.cukup ?? null };
    });

    const adaYangGagal =
        apotekId !== null &&
        shelfQuery.some((q) => q.isError || (q.isSuccess && q.data.data.stok.apotek === null));

    return {
        baris: withShelf,
        kandidat,
        semuaCukup:
            apotekId !== null &&
            cekabel.length > 0 &&
            withShelf.every((b) => b.cukup === true),
        adaYangGagal,
        sedangMemuat:
            kandidatQuery.some((q) => q.isPending) || shelfQuery.some((q) => q.isPending),
        tanpaApotek,
    };
}

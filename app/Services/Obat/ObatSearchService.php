<?php

declare(strict_types=1);

namespace App\Services\Obat;

use App\Models\MasterObat;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Medicine search that decides with the SAME normalisation the allergy engine
 * uses, so search and warning can never disagree about a spelling.
 *
 * `pasien_alergi.nama_alergen` is free text with no join key to `master_obat`,
 * which is why todo 38 built {@see NamaObat}: two spellings of one substance
 * meet on an equal normalised core, never on containment. A search that
 * normalised differently would let a doctor type a spelling, not find the
 * drug, prescribe it under another spelling, and then be handed a warning
 * about a drug they believe they cannot even look up.
 *
 * `NamaObat::inti()` is PHP, so the SQL over-fetches and PHP decides. The
 * prefilter is an ordered-subsequence REGEXP over the query's own core
 * characters, which is a PROVABLE SUPERSET of core equality: normalisation
 * only ever removes characters, so a row whose core equals the query's still
 * contains those characters in order whatever the catalogue did with the
 * characters between them. A `LIKE '%core%'` is NOT a superset - a catalogue
 * name that inserts a separator inside a query token never matches - and a
 * `LIKE` on the raw input is worse: it fires on substrings and near-misses
 * the equality then has to clean up.
 *
 * Only `status_aktif = 1` rows are ever returned (`:725`): a withdrawn drug
 * is representable in the schema and only the application can refuse it.
 */
final class ObatSearchService
{
    /**
     * Search the active catalogue.
     *
     * @param array{search?: ?string, kelas_obat?: ?string, requires_resep?: bool|int|null, page?: int, per_page?: int} $filter
     */
    public function cari(array $filter): LengthAwarePaginator
    {
        $page = max(1, (int) ($filter['page'] ?? 1));
        $perPage = max(1, (int) ($filter['per_page'] ?? 15));

        $query = MasterObat::query()->where('status_aktif', 1);

        if (($filter['kelas_obat'] ?? null) !== null && $filter['kelas_obat'] !== '') {
            $query->where('kelas_obat', $filter['kelas_obat']);
        }

        if (($filter['requires_resep'] ?? null) !== null && $filter['requires_resep'] !== '') {
            $query->where('requires_resep', (int) ((bool) $filter['requires_resep']));
        }

        $search = trim((string) ($filter['search'] ?? ''));

        if ($search === '') {
            return $query->orderBy('nama_generik')->paginate($perPage, ['*'], 'page', $page);
        }

        $inti = NamaObat::inti($search);

        if ($inti === '') {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        // Ordered-subsequence prefilter: every core character in order, anything
        // between them. Core characters are [a-z0-9] by construction, so the
        // pattern needs no escaping.
        $pola = implode('.*', str_split($inti));

        $kandidat = $query
            ->whereRaw('(LOWER(nama_generik) REGEXP ? OR LOWER(nama_brand) REGEXP ?)', [$pola, $pola])
            ->orderBy('nama_generik')
            ->get()
            ->filter(static function (MasterObat $obat) use ($inti): bool {
                if (NamaObat::inti((string) $obat->nama_generik) === $inti) {
                    return true;
                }

                $brand = (string) ($obat->nama_brand ?? '');

                return $brand !== '' && NamaObat::inti($brand) === $inti;
            })
            ->values();

        $total = $kandidat->count();

        return new LengthAwarePaginator(
            $kandidat->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
        );
    }
}

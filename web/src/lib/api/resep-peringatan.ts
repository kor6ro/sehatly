import { z } from 'zod';
import {
    PERINGKAT,
    SUMBER_PERINGATAN,
    type Acknowledgement,
    type MasterObat,
    type PeringatanGrup,
    type Resep,
    type ResepPeringatan,
    type ResepVerifikasi,
} from '@/lib/api/types';

/**
 * Module 4's pure half: the labels, the closed vocabularies, the request schemas, and the
 * grouping and severity rules the warning panel and the override gate turn on.
 *
 * ## Why it is a separate module and not part of `lib/api/resep.ts`
 *
 * `lib/api/resep.ts` imports the transport, and `lib/http.ts` reads `import.meta.env` at
 * module load to build its base URL. `node --test` runs the TypeScript sources directly
 * with no bundler, so `import.meta.env` is `undefined` there and importing the transport
 * from a unit test throws before a single assertion runs. Everything in this file is a pure
 * function of its arguments and imports only `zod`, so it is reachable from
 * `tests/unit/resep-peringatan.test.ts` unchanged.
 *
 * The split is not only about the test harness: it is the dependency direction. The clinical
 * rules - which warnings demand an acknowledgement, how the three sources group, what the
 * request bodies may contain - have no business depending on how bytes reach a server.
 */

/**
 * The six prescription and medicine endpoints, all behind `auth:sanctum`.
 *
 * | method | path | guard | success |
 * | --- | --- | --- | --- |
 * | `GET` | `/api/v1/obat` | `tipe:dokter` + `obat.cari` | 200 + `meta` |
 * | `POST` | `/api/v1/konsultasi/{id}/resep` | `tipe:dokter` + `resep.buat` | 201, WITH the warning set |
 * | `GET` | `/api/v1/resep/{id}` | `resep.lihat` | 200 + the current warning set |
 * | `GET` | `/api/v1/resep/{id}/cek-interaksi` | `resep.lihat` | 200, re-checked from the STORED items |
 * | `POST` | `/api/v1/resep/{id}/verifikasi` | `tipe:apoteker` + `resep.verifikasi` | 201 |
 * | `GET` | `/api/v1/pasien/resep` | `resep.lihat` + a `pasien` row | 200 + `meta` |
 *
 * ## There is no endpoint that lists a pharmacist's queue, and that is a FINDING
 *
 * `ApotekerVerifikasiQueue` is supposed to "list prescriptions awaiting verification", and
 * the route table has nothing that does it. `GET /pasien/resep` is the only list, and
 * `ResepAccess::riwayat()` starts with `PasienRecordAccess::ownPasien($caller)`, which
 * **throws 403 for an account that owns no `pasien` row** - so a pharmacist gets a 403, not
 * a queue. `GET /resep/{id}` is one row and needs an id the pharmacist cannot obtain.
 *
 * So the queue is addressed BY ID. That is the honest shape available today, it uses only
 * endpoints that exist, and it is reported as a finding rather than papered over with a route
 * this executor is not authorised to add. See `.omo/evidence/task-41-sehatly.md`.
 *
 * ## `catatan_dodio` is the override channel, and it is the ONLY one
 *
 * `StoreResepRequest` marks `catatan_dokter` - the `resep` COLUMN the note is stored in - as
 * `prohibited`, and accepts `catatan_dodio` instead. That asymmetry is the whole persistence
 * story for a contraindication override: a note can only be written as an ACKNOWLEDGEMENT,
 * never as a direct write, and `ResepService::catatan()` refuses the request with a 422 when
 * a `kontraindikasi` warning exists and the note is empty. So a prescription that overrides a
 * contraindication cannot exist without the note, and the note cannot be written without
 * going through the acknowledgement field. There is no migration here to point at because
 * there is no `resep_interaksi` table; `resep.catatan_dokter` IS the record.
 *
 * ## Every write is `retry: 0`
 *
 * A replayed `POST /resep/{id}/verifikasi` would spend the single `resep_verifikasi` row the
 * UNIQUE key at `:788` allows, and a replayed `POST /konsultasi/{id}/resep` would write a
 * second prescription.
 */

export const LABEL_STATUS_RESEP: Readonly<Record<string, string>> = {
    aktif: 'Aktif',
    diproses: 'Diproses',
    diverifikasi: 'Diverifikasi',
    dipenuhi: 'Dipenuhi',
    dikirim: 'Dikirim',
    selesai: 'Selesai',
    kedaluwarsa: 'Kedaluwarsa',
    dibatalkan: 'Dibatalkan',
};

export const LABEL_STATUS_VERIFIKASI: Readonly<Record<string, string>> = {
    sesuai: 'Sesuai',
    ada_koreksi: 'Ada koreksi',
    ditolak: 'Ditolak',
};

export const LABEL_SUMBER_PERINGATAN: Readonly<Record<string, string>> = {
    antar_item: 'Interaksi antar obat dalam resep ini',
    riwayat_resep: 'Interaksi dengan resep yang sedang berjalan',
    alergi: 'Alergi obat yang tercatat',
};

export const LABEL_TINGKAT_PERINGATAN: Readonly<Record<string, string>> = {
    ringan: 'Ringan',
    sedang: 'Sedang',
    berat: 'Berat',
    kontraindikasi: 'Kontraindikasi',
};

/** `master_obat.kelas_obat`, the six-value ENUM, for the catalogue filter. */
export const KELAS_OBAT = [
    'bebas',
    'bebas_terbatas',
    'keras',
    'fitofarmaka',
    'narkotika',
    'psikotropika',
] as const;

export const LABEL_KELAS_OBAT: Readonly<Record<string, string>> = {
    bebas: 'Bebas',
    bebas_terbatas: 'Bebas terbatas',
    keras: 'Keras',
    fitofarmaka: 'Fitofarmaka',
    narkotika: 'Narkotika',
    psikotropika: 'Psikotropika',
};

/**
 * `ResepStateMachine::BISA_DIVERIFIKASI` - the two states a pharmacist may still sign.
 *
 * `aktif` is where every prescription is born (`resep.status` DEFAULTs to it) and `diproses`
 * is where the pharmacy has picked it up. Everything after those two has already been signed,
 * and nothing before them exists, so offering the three outcomes for any other status would
 * be an affordance the server answers 422 to.
 */
export const STATUS_BISA_DIVERIFIKASI = ['aktif', 'diproses'] as const;

/** The three outcomes, in `ResepVerifikasiStatus`'s own (DDL) order. */
export const STATUS_VERIFIKASI = ['sesuai', 'ada_koreksi', 'ditolak'] as const;

/** `VerifikasiResepRequest`'s `catatan` ceiling; `resep_verifikasi.catatan` is `TEXT`. */
export const CATATAN_APOTEKER_MAKS = 20_000;

// ============================================================================
// Grouping and severity
// ============================================================================

/**
 * Split a flat warning list into the three `sumber` buckets, worst first inside each.
 *
 * `warning_grup` already arrives grouped, and this recomputes the same grouping from the
 * flat `warning` list. Both paths are exercised - the flat list is what a caller holds
 * before the first 201 - and deriving the groups from it means the panel has ONE code path
 * instead of two that can disagree.
 *
 * Every key of {@link SUMBER_PERINGATAN} is always present, empty or not, so `WarningPanel`
 * renders three regions unconditionally. `peringatanGrup()` walks the same list server-side;
 * matching it is what makes the three-region layout a fact rather than a presentation choice.
 */
export function grupPeringatan(peringatan: ResepPeringatan[]): PeringatanGrup {
    const grup = {
        antar_item: [] as ResepPeringatan[],
        riwayat_resep: [] as ResepPeringatan[],
        alergi: [] as ResepPeringatan[],
    };

    for (const p of peringatan) {
        if (p.sumber === 'antar_item') {
            grup.antar_item.push(p);
        } else if (p.sumber === 'riwayat_resep') {
            grup.riwayat_resep.push(p);
        } else if (p.sumber === 'alergi') {
            grup.alergi.push(p);
        }
    }

    for (const sumber of SUMBER_PERINGATAN) {
        grup[sumber] = urutkanPeringatan(grup[sumber]);
    }

    return grup;
}

/**
 * The server's own total order: severity descending, then declared source order, then `kunci`
 * byte order.
 *
 * `ObatInteraksiService::urutkan()` sorts the same three terms and `kunci` is unique within a
 * set, so the order is TOTAL - the same inputs produce byte-identical output whatever order
 * the caller listed its drugs in. The server already returns sorted output; this exists so a
 * client-built group renders identically.
 */
export function urutkanPeringatan(peringatan: ResepPeringatan[]): ResepPeringatan[] {
    return [...peringatan].sort((a, b) => {
        const tingkat = PERINGKAT[b.tingkat] - PERINGKAT[a.tingkat];

        if (tingkat !== 0) {
            return tingkat;
        }

        const sumber =
            SUMBER_PERINGATAN.indexOf(a.sumber) - SUMBER_PERINGATAN.indexOf(b.sumber);

        if (sumber !== 0) {
            return sumber;
        }

        return a.kunci < b.kunci ? -1 : a.kunci > b.kunci ? 1 : 0;
    });
}

/**
 * The warnings that demand a note, i.e. `ObatInteraksiService::wajibCatatanDokter()`.
 *
 * The `wajib_catatan_dokter` flag each warning carries is READ, not re-derived from `tingkat`.
 * The server already made the decision in the engine, and a client that compared the severity
 * string itself would duplicate a rule and drift from it the day a fifth severity is added to
 * the ENUM at `:735`.
 */
export function peringatanKontraindikasi(
    peringatan: ResepPeringatan[],
): ResepPeringatan[] {
    return peringatan.filter((p) => p.wajib_catatan_dokter);
}

/** Does this warning set require an acknowledgement before it can be committed? */
export function perluCatatan(peringatan: ResepPeringatan[]): boolean {
    return peringatanKontraindikasi(peringatan).length > 0;
}

/**
 * The count rendered in the panel's summary line.
 *
 * Counting the three groups is the same fact as counting the flat list, and doing it from the
 * groups is what proves the two agree: a panel that says "3" while rendering two rows is a
 * bug this cannot hide.
 */
export function jumlahPeringatan(grup: PeringatanGrup): number {
    return SUMBER_PERINGATAN.reduce((total, sumber) => total + grup[sumber].length, 0);
}

/** Every warning in the three groups, flattened - what {@link perluCatatan} takes. */
export function semuaPeringatan(grup: PeringatanGrup): ResepPeringatan[] {
    return SUMBER_PERINGATAN.flatMap((sumber) => grup[sumber]);
}

/**
 * The two drugs a warning names, as one sentence.
 *
 * `obat_b` is `null` on an `alergi` warning - that shape names ONE drug against a recorded
 * allergen - so the pair form cannot be used unconditionally, and stringifying the null would
 * print the word "null" beside every allergy warning.
 */
export function pasanganPeringatan(p: ResepPeringatan): string {
    if (p.obat_a === null) {
        return '-';
    }

    if (p.obat_b === null) {
        return p.obat_a.nama;
    }

    return `${p.obat_a.nama} + ${p.obat_b.nama}`;
}

// ============================================================================
// The request bodies
// ============================================================================

/**
 * One `resep_item` as `StoreResepRequest::rules()` accepts it.
 *
 * ## `.strict()` is load-bearing here for the same reason `rekam-medis.ts` argues it
 *
 * `harga_satuan`, `subtotal` and `catatan_apoteker` are `prohibited` server-side. A loose zod
 * object would strip an unknown key and the request would validate, so a composer that
 * "helpfully" sent a unit price would be refused with a message naming a field the client
 * believes it never sent. Strict fails here instead, which points at the line that did it.
 *
 * ## `obat_id` stays nullable, and that is not laziness
 *
 * `StoreResepRequest` deliberately does NOT put `required_without` on it; the
 * racikan/catalogue shape check lives in one place, `ResepService::siapkanItem()`. An
 * `obat_id: null` with `is_racikan: true` is a legal request, and a racikan is STRUCTURALLY
 * uncheckable - `ObatInteraksiService` skips NULL ids with a `whereNotNull`, so it can never
 * produce a warning.
 */
export const recipeItemSchema = z
    .object({
        obat_id: z.number().int().positive().nullable().optional(),
        nama_obat: z.string().trim().max(255).nullable().optional(),
        kekuatan: z.string().trim().max(50).nullable().optional(),
        aturan_pakai: z
            .string()
            .trim()
            .min(1, 'Aturan pakai wajib diisi.')
            .max(255),
        jumlah: z
            .number({ message: 'Jumlah wajib diisi.' })
            .int('Jumlah harus bilangan bulat.')
            .min(1, 'Jumlah minimal 1.')
            .max(65_535, 'Jumlah maksimal 65535.'),
        satuan: z.string().trim().max(30).nullable().optional(),
        is_racikan: z.boolean().nullable().optional(),
        racikan_nama: z.string().trim().max(100).nullable().optional(),
    })
    .strict();

export type ResepItemInput = z.infer<typeof recipeItemSchema>;

/**
 * `POST /konsultasi/{id}/resep`.
 *
 * The key is `catatan_dodio`, NOT `catatan_dokter`. The latter is the `resep` column the
 * service writes to and is `prohibited` on this request; sending it is a 422. Sending
 * `catatan_docter` - the single-letter typo this repository has already been bitten by once -
 * is also a 422, on a `.strict()` envelope, which is the correct outcome and the reason the
 * spelling is asserted in the unit tests.
 */
export const buatResepSchema = z
    .object({
        items: z
            .array(recipeItemSchema)
            .min(1, 'Prescription must contain at least one item.'),
        catatan_dodio: z.string().trim().max(16_000).optional(),
    })
    .strict();

export type BuatResepInput = z.infer<typeof buatResepSchema>;

/**
 * `POST /resep/{id}/verifikasi`: the pharmacist's one answer.
 *
 * `.strict()` again: `resep_id`, `apoteker_user_id` and `diverifikasi_at` are all
 * `prohibited`, so a queue that pre-filled the id from the URL would be refused for sending
 * a field the server already knows.
 */
export const verifikasiResepSchema = z
    .object({
        status: z.enum(STATUS_VERIFIKASI),
        catatan: z.string().trim().max(CATATAN_APOTEKER_MAKS).optional(),
    })
    .strict();

export type VerifikasiResepInput = z.infer<typeof verifikasiResepSchema>;

/** `GET /obat`'s query string. `per_page` is capped at 100 server-side. */
export type CariObatFilters = {
    search: string;
    kelas_obat?: (typeof KELAS_OBAT)[number] | null;
    requires_resep?: boolean | null;
    per_page?: number;
};

/** `GET /pasien/resep`'s query string. `status` narrows to one of the eight. */
export type RiwayatResepFilters = {
    page: number;
    per_page: number;
    status?: string | null;
};

/** The four response envelopes, typed at the boundary the resources publish them. */
export type BuatResepResponse = {
    resep: Resep;
    warning: ResepPeringatan[];
    warning_grup: PeringatanGrup;
    acknowledgement: Acknowledgement;
};

export type ResepDetailResponse = {
    resep: Resep;
    verifikasi: ResepVerifikasi | null;
    warning: ResepPeringatan[];
    warning_grup: PeringatanGrup;
};

export type CekInteraksiResponse = {
    resep_id: number;
    status: string;
    warning: ResepPeringatan[];
    warning_grup: PeringatanGrup;
    wajib_catatan: boolean;
};

export type VerifikasiResepResponse = {
    resep: Resep;
    verifikasi: ResepVerifikasi;
    warning: ResepPeringatan[];
    warning_grup: PeringatanGrup;
    terminal: boolean;
};

export type CariObatResponse = { obat: MasterObat[] };

export type RiwayatResepResponse = { resep: Resep[] };

import { mkdirSync } from 'node:fs';
import {
    expect,
    test,
    type Locator,
    type Page,
    type Route,
} from '@playwright/test';
import { expectNoA11yViolations, istirahatkanGerak } from './a11y';

/**
 * F03 (cari dokter) mocked end to end at 390x844 and 1280x900, in the shape the October
 * 2026 rombak left it: a heading, a count, a list of the nearest doctors, and nothing
 * else.
 *
 * ## What was deleted, because the tests for it were too
 *
 * This file used to carry `f03-ac3-chip-hapus`, `f03-ac4-reset`, `f03-ac5-sort` and
 * `f03-ac10-pagination` - four tests for four controls that no longer exist. They were
 * replaced rather than edited, because a test that asserts "there is no chip" is a
 * different test from one that asserts removing a chip does not reload the page, and
 * pretending otherwise would leave F03's AC list promising something the screen does not
 * do. `pilihOpsiUrutkan` and the chip helpers are gone with them.
 *
 * ## Where the tests open it
 *
 * There is no `/dokter` page. `bukaDirektori` opens `/?semua=1` and every door in the
 * navigation writes that address. ASKING and ANSWERING live on separate surfaces: a
 * specialisation is picked in the header (hover panel on a desktop, drawer on a phone)
 * and the answer under it carries no picker, no chips, and no page controls. The two
 * leftovers of the old address - `/dokter` and `/?dokter=` - are redirects and each has
 * its own test.
 *
 * ## Why every call is mocked
 *
 * The criteria need a response the live seed cannot produce on demand: a doctor named
 * "Rina", forty-three matches for one page, zero results, a 500, and a `jarak_km` whose
 * order is knowable in advance. Mocking also keeps the privacy fixture ("kanker") out of
 * any real server.
 *
 * ## The position is a fixture, not a request to a driver
 *
 * F03's newest criterion is a distance, so every list depends on whether the visitor
 * granted location. Neither answer can come from the browser here: this suite runs
 * against a plain-HTTP test host, and Chromium refuses `navigator.geolocation` on any
 * origin it does not consider secure - so a grant and a refusal both come back as error
 * code 1, "Only secure origins are allowed", whatever Playwright's permission says.
 * `--unsafely-treat-insecure-origin-as-secure` reaches the command line and changes
 * nothing, which was measured rather than assumed.
 *
 * So the fixture answers, in both directions, and it is the only thing that decides.
 * `pasangMock` installs the API before the first navigation: a granted context succeeds
 * with {@link POSISI_UJI}, a denied one fails with code 1 - the same code a real refusal
 * carries, and the only part of the error this application reads. Stubbing only the grant
 * was the obvious alternative and is the wrong one: it would leave "the test meant to be
 * denied" and "the host said no" looking identical from inside the assertion, which is
 * precisely how the first version of this file came to prove the wrong half of AC-5.
 *
 * ## The free-text query never reaches the URL
 *
 * The fixture word is a medical condition, which is the point of the assertion: after
 * typing it and submitting, the address bar, every `aria-label` and every toast must be
 * clean (AC-13). The input itself keeps the term, and `sessionStorage` carries it across
 * the reload AC-1 requires.
 */

const VIEWPORTS = [
    { nama: 'mobile', width: 390, height: 844 },
    { nama: 'desktop', width: 1280, height: 900 },
] as const;

type Vp = (typeof VIEWPORTS)[number];

const KATA_UJI = 'kanker';

/**
 * The fix every distance in this file is measured from.
 *
 * It is a real point in South Jakarta, chosen so the fixture venues sit between roughly
 * 0.1 km and 6 km away - close enough that `formatJarak` crosses the metre/kilometre
 * boundary (one venue renders `850 m`, the rest `n,n km`), which is the one formatting
 * rule on the card that a fixture of identical distances would never reach.
 */
const POSISI_UJI = { latitude: -6.22, longitude: 106.83 };

mkdirSync('ux/refs/f03', { recursive: true });

type TempatUji = {
    nama: string;
    alamat: string;
    latitude: number | null;
    longitude: number | null;
};

type DokterUji = {
    id: number;
    nama_lengkap: string;
    tipe: string;
    spesialisasi: string | null;
    biaya_konsultasi_online: string;
    rating_rata_rata: string;
    jumlah_konsultasi: number;
    pengalaman_tahun: number;
    jumlah_ulasan: number;
    status_verifikasi: string;
    /** Test-only handle for `?spesialisasi=`; not part of the published resource. */
    kode: string;
    /** `dokter_faskes`' main row. `null` is a real state: an online-only doctor. */
    tempat: TempatUji | null;
};

type SpesialisasiUji = {
    id: number;
    kode: string;
    nama: string;
    tipe: string;
};

const MASTER_SPESIALISASI: SpesialisasiUji[] = [
    { id: 1, kode: 'UMUM', nama: 'Dokter Umum', tipe: 'dokter_umum' },
    { id: 2, kode: 'SP.PD', nama: 'Spesialis Penyakit Dalam', tipe: 'spesialis' },
    { id: 3, kode: 'SP.A', nama: 'Spesialis Anak', tipe: 'spesialis' },
    { id: 4, kode: 'SP.OG', nama: 'Spesialis Obstetri & Ginekologi', tipe: 'spesialis' },
    { id: 16, kode: 'GIGI', nama: 'Dokter Gigi', tipe: 'spesialis' },
];

/**
 * A venue `langkah` steps south-east of {@link POSISI_UJI}, about 0.63 km per step.
 *
 * Two properties are load-bearing and neither is accidental:
 *
 * 1. **Step 1 lands below one kilometre.** `formatJarak` prints metres under 1 km and
 *    kilometres above it, and a fixture of identical or uniformly distant venues would
 *    never cross that boundary - so `f03-ac5` could not tell a correct formatter from a
 *    wrong one. One card reads `626 m`, the rest `n,n km`.
 * 2. **The steps are not handed out in order.** The default sort is the fixture's own
 *    array order, so a fixture whose venues happened to grow outward from the visitor
 *    would look "nearest first" whether or not the request asked for it. `dokterDefault`
 *    deliberately scatters the steps; the assertion that the printed distances ascend is
 *    then only satisfiable by the sort actually running.
 */
function tempatUji(langkah: number, nama: string): TempatUji {
    return {
        nama,
        alamat: `Jl. Fixture No. ${String(langkah)}, Jakarta`,
        latitude: POSISI_UJI.latitude - langkah * 0.004,
        longitude: POSISI_UJI.longitude + langkah * 0.004,
    };
}

/**
 * Which venue each fixture doctor gets, keyed by the doctor's position in the array.
 *
 * Read the values out of order on purpose: `1, 7, 3, 11, ...` is the point. The last
 * entry is `null`, which is the online-only doctor and the reason the list splits in two.
 */
const LANGKAH_DOKTER = [1, 7, 3, 11, 2, 9, 5, 10, 4, 8, 6] as const;

function dokterUji(
    id: number,
    nama: string,
    tipe: string,
    kode: string,
    spesialisasi: string,
    biaya: string,
    rating: string,
    konsultasi: number,
    pengalaman: number,
    ulasan: number,
    tempat: TempatUji | null,
): DokterUji {
    return {
        id,
        nama_lengkap: nama,
        tipe,
        kode,
        spesialisasi,
        biaya_konsultasi_online: biaya,
        rating_rata_rata: rating,
        jumlah_konsultasi: konsultasi,
        pengalaman_tahun: pengalaman,
        jumlah_ulasan: ulasan,
        status_verifikasi: 'terverifikasi',
        tempat,
    };
}

/** 8 Anak, 2 Penyakit Dalam, 2 Gigi: the fixture every criterion below starts from. */
function dokterDefault(): DokterUji[] {
    const anak = Array.from({ length: 8 }, (_unused, index) =>
        dokterUji(
            100 + index,
            index === 0
                ? 'dr. Rina Wulandari, Sp.A'
                : `dr. Sari Kusuma ${String(index + 1)}, Sp.A`,
            'dokter_spesialis',
            'SP.A',
            'Spesialis Anak',
            '85000.00',
            '4.9',
            128 - index,
            12 - index,
            128 - index,
            tempatUji(
                LANGKAH_DOKTER[index],
                `Klinik Anak Sehat ${String(index + 1)}`,
            ),
        ),
    );

    return [
        ...anak,
        dokterUji(
            200,
            'dr. Andi Pratama, Sp.PD',
            'dokter_spesialis',
            'SP.PD',
            'Spesialis Penyakit Dalam',
            '120000.00',
            '4.7',
            210,
            20,
            156,
            tempatUji(LANGKAH_DOKTER[8], 'RS Penyakit Dalam Contoh'),
        ),
        dokterUji(
            201,
            'dr. Sri Handayani, Sp.PD',
            'dokter_spesialis',
            'SP.PD',
            'Spesialis Penyakit Dalam',
            '110000.00',
            '4.8',
            180,
            15,
            98,
            tempatUji(LANGKAH_DOKTER[9], 'Klinik Interna Contoh'),
        ),
        dokterUji(
            300,
            'drg. Maya Sari',
            'dokter_gigi',
            'GIGI',
            'Dokter Gigi',
            '90000.00',
            '4.8',
            64,
            8,
            64,
            tempatUji(LANGKAH_DOKTER[10], 'Klinik Gigi Sehat Senyum'),
        ),
        // Deliberately unreviewed (AC-11: "0 ulasan must not be printed") AND deliberately
        // without a venue, which makes it the fixture for the `Bisa jarak jauh` group too:
        // one row, two absences, neither of them invented away.
        dokterUji(301, 'drg. Bagus Nugroho', 'dokter_gigi', 'GIGI', 'Dokter Gigi', '95000.00', '4.6', 52, 6, 0, null),
    ];
}

/** `n` Sp.A doctors, all with a venue, for the one-page ceiling. */
function banyakDokter(n: number): DokterUji[] {
    return Array.from({ length: n }, (_unused, index) =>
        dokterUji(
            400 + index,
            `dr. Anak Contoh ${String(index + 1)}, Sp.A`,
            'dokter_spesialis',
            'SP.A',
            'Spesialis Anak',
            '85000.00',
            '4.7',
            40 + index,
            5,
            0,
            tempatUji((index % 12) + 1, `Klinik Anak Contoh ${String(index + 1)}`),
        ),
    );
}

type State = {
    dokter: DokterUji[];
    gagal: boolean;
    tundaMs: number;
    permintaanDokter: string[];
    permintaanMaster: number;
};

type OpsiMock = {
    dokter?: DokterUji[];
    tundaMs?: number;
    /**
     * Whether this context is allowed a position - see the file header for why the
     * browser cannot be asked.
     *
     * Defaults to `true`. Both answers are produced by {@link posisiPalsu}, installed
     * before the first navigation, so the decision is always the test's and never the
     * host's scheme: `false` yields error code 1, `true` yields {@link POSISI_UJI}.
     */
    izinLokasi?: boolean;
};

async function balasJson(route: Route, status: number, body: unknown): Promise<void> {
    await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

/**
 * `navigator.geolocation` as this fixture decides it should answer.
 *
 * Installed with `page.addInitScript`, so it is in place before the application's first
 * line runs and every later navigation inherits it. It is a full REPLACEMENT rather than
 * a wrapper: delegating to the real API would go back and ask the host the question the
 * file header explains cannot be answered honestly on a plain-HTTP origin.
 *
 * The failure is code 1 - `PERMISSION_DENIED` - because that is the code
 * `use-posisi-pasien` reads, and the only one it treats as a decision rather than as
 * "try again later". It carries the browser's own wording so a trace of a denied run
 * reads like a denial and not like a fixture.
 *
 * @param diberikan Whether the visitor says yes. The whole of AC-5's two halves turns on
 * this one flag, and nothing else in the file may decide it.
 */
function posisiPalsu({
    diberikan,
    latitude,
    longitude,
}: {
    diberikan: boolean;
    latitude: number;
    longitude: number;
}): void {
    Object.defineProperty(navigator, 'geolocation', {
        configurable: true,
        value: {
            getCurrentPosition(
                sukses: (posisi: GeolocationPosition) => void,
                tolak: (galat: { code: number; message?: string }) => void,
            ): void {
                if (!diberikan) {
                    tolak({
                        code: 1,
                        message: 'User denied Geolocation',
                    });
                    return;
                }

                window.setTimeout(() => {
                    const coords = {
                        latitude,
                        longitude,
                        accuracy: 30,
                        altitude: null,
                        altitudeAccuracy: null,
                        heading: null,
                        speed: null,
                        // `GeolocationCoordinates` declares `toJSON`, which this
                        // replacement never supplies and the application never calls.
                    } as GeolocationCoordinates;

                    sukses({ coords, timestamp: Date.now() } as GeolocationPosition);
                }, 0);
            },

            watchPosition(): number {
                return 0;
            },

            clearWatch(): void {
                // Nothing to clear: nothing ever watches.
            },
        },
    });
}

/**
 * The Haversine the server runs in SQL, in the only place this file needs it: here, so
 * "nearest first" is a prediction rather than a hope. `null` (a venue without a map pin)
 * sorts last, which is what `ORDER BY jarak_km IS NULL, jarak_km` does in
 * `DokterDirectoryService`.
 */
function haversine(
    posisi: { latitude: number; longitude: number },
    tempat: TempatUji,
): number | null {
    if (tempat.latitude === null || tempat.longitude === null) {
        return null;
    }

    const rad = Math.PI / 180;
    const dLat = (tempat.latitude - posisi.latitude) * rad;
    const dLng = (tempat.longitude - posisi.longitude) * rad;
    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(posisi.latitude * rad) *
            Math.cos(tempat.latitude * rad) *
            Math.sin(dLng / 2) ** 2;

    return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/** The published `DokterResource` shape, plus the two keys the rombak added. */
function resourceDokter(row: DokterUji, posisi: URLSearchParams): Record<string, unknown> {
    const lat = posisi.get('lat');
    const lng = posisi.get('lng');
    const ada = lat !== null && lng !== null && row.tempat !== null;

    const jarak = ada
        ? haversine(
              { latitude: Number(lat), longitude: Number(lng) },
              row.tempat as TempatUji,
          )
        : null;

    const { tempat, kode, ...terbit } = row;

    return {
        ...terbit,
        faskes_utama:
            tempat === null
                ? null
                : {
                      id: 0,
                      nama: tempat.nama,
                      alamat: tempat.alamat,
                      latitude: tempat.latitude,
                      longitude: tempat.longitude,
                  },
        jarak_km: jarak === null ? null : Math.round(jarak * 1000) / 1000,
    };
}

async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<State> {
    const state: State = {
        dokter: opsi.dokter ?? dokterDefault(),
        gagal: false,
        tundaMs: opsi.tundaMs ?? 0,
        permintaanDokter: [],
        permintaanMaster: 0,
    };

    /**
     * The position, decided here and nowhere else. See the file header: the host is not a
     * secure origin, so the browser would answer code 1 either way and its answer would
     * say nothing about what this test asked for.
     */
    await page.addInitScript(posisiPalsu, {
        diberikan: opsi.izinLokasi !== false,
        latitude: POSISI_UJI.latitude,
        longitude: POSISI_UJI.longitude,
    });

    await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;

        if (path === '/api/v1/master-spesialisasi') {
            state.permintaanMaster += 1;

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar spesialisasi berhasil dimuat.',
                data: { spesialisasi: MASTER_SPESIALISASI },
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: MASTER_SPESIALISASI.length,
                    total: MASTER_SPESIALISASI.length,
                    from: 1,
                    to: MASTER_SPESIALISASI.length,
                },
            });
        }

        /**
         * `GET /dokter/{id}` - the page a card now opens.
         *
         * It is served rather than left to the catch-all because the profile dereferences
         * `dokter.faskes` unguarded: an envelope with no `dokter` key would throw inside
         * `DoctorProfileHero` and unmount the app mid-assertion, which reads as a flake in
         * a navigation test rather than as the missing fixture it is.
         */
        const detail = /^\/api\/v1\/dokter\/(\d+)$/.exec(path);

        if (detail !== null && request.method() === 'GET') {
            const row = state.dokter.find((kandidat) => String(kandidat.id) === detail[1]);

            if (row === undefined) {
                return balasJson(route, 404, {
                    success: false,
                    message: 'Profil dokter tidak ditemukan di direktori publik.',
                    errors: {},
                });
            }

            const { tempat: faskes, ...baris } = row;

            return balasJson(route, 200, {
                success: true,
                message: 'Detail dokter berhasil dimuat.',
                data: {
                    dokter: {
                        ...baris,
                        foto_profil: null,
                        bio: 'Dokter contoh untuk pengujian akhir-ke-akhir.',
                        biaya_luar_jam: '0.00',
                        durasi_default_menit: 30,
                        tersedia_telemedisin: true,
                        dibuat_at: '2026-01-01T00:00:00.000000+00:00',
                        spesialisasi: [],
                        pendidikan: [],
                        faskes:
                            faskes === null
                                ? []
                                : [
                                      {
                                          faskes_id: 1,
                                          is_utama: true,
                                          status_aktif: true,
                                          kode_faskes: 'FIXTURE-001',
                                          nama: faskes.nama,
                                          tipe: 'klinik_pratama',
                                          kelas_rs: null,
                                          alamat: faskes.alamat,
                                          latitude:
                                              faskes.latitude === null
                                                  ? null
                                                  : String(faskes.latitude),
                                          longitude:
                                              faskes.longitude === null
                                                  ? null
                                                  : String(faskes.longitude),
                                      },
                                  ],
                    },
                },
            });
        }

        if (path === '/api/v1/dokter' && request.method() === 'GET') {
            state.permintaanDokter.push(request.url());

            if (state.tundaMs > 0) {
                await new Promise((resolve) => {
                    setTimeout(resolve, state.tundaMs);
                });
            }

            if (state.gagal) {
                return balasJson(route, 500, {
                    success: false,
                    message: 'Terjadi kesalahan pada server.',
                    errors: {},
                });
            }

            const search = url.searchParams.get('search');
            const spesialisasi = url.searchParams.get('spesialisasi');
            const sort = url.searchParams.get('sort');
            const page = Number(url.searchParams.get('page') ?? '1');
            const perPage = Number(url.searchParams.get('per_page') ?? '15');

            const tersaring = state.dokter.filter((row) => {
                if (
                    search !== null &&
                    !row.nama_lengkap.toLowerCase().includes(search.toLowerCase())
                ) {
                    return false;
                }

                if (spesialisasi !== null && row.kode !== spesialisasi) {
                    return false;
                }

                return true;
            });

            /**
             * Two orderings, and only two: the default the server falls back to, and the
             * distance the criteria are about. The five other values the contract
             * publishes are deliberately unimplemented - this file tests the rombak, not
             * a control it deleted.
             */
            let terurut = [...tersaring];

            if (sort === 'terdekat') {
                const lat = url.searchParams.get('lat');
                const lng = url.searchParams.get('lng');

                if (lat === null || lng === null) {
                    // The server answers 422 here rather than falling back. Mirroring it
                    // keeps a bug that sends a half pair from passing by silently.
                    return balasJson(route, 422, {
                        success: false,
                        message: 'Data yang diberikan tidak valid.',
                        errors: { lat: ['Kolom lat wajib diisi.'], lng: ['Kolom lng wajib diisi.'] },
                    });
                }

                const asal = { latitude: Number(lat), longitude: Number(lng) };
                const jarak = new Map<number, number | null>();

                for (const row of tersaring) {
                    jarak.set(
                        row.id,
                        row.tempat === null ? null : haversine(asal, row.tempat),
                    );
                }

                terurut = [...tersaring].sort((a, b) => {
                    const kiri = jarak.get(a.id) ?? null;
                    const kanan = jarak.get(b.id) ?? null;

                    if (kiri === null && kanan === null) return 0;
                    if (kiri === null) return 1;
                    if (kanan === null) return -1;

                    return kiri - kanan;
                });
            }

            const total = terurut.length;
            const mulai = (page - 1) * perPage;
            const baris = terurut.slice(mulai, mulai + perPage);

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar dokter berhasil dimuat.',
                data: {
                    dokter: baris.map((row) => resourceDokter(row, url.searchParams)),
                },
                meta: {
                    current_page: page,
                    last_page: Math.max(1, Math.ceil(total / perPage)),
                    per_page: perPage,
                    total,
                    from: baris.length === 0 ? null : mulai + 1,
                    to: baris.length === 0 ? null : mulai + baris.length,
                },
            });
        }

        return balasJson(route, 200, {
            success: true,
            message: 'Berhasil.',
            data: {},
        });
    });

    return state;
}

function punyaPermintaan(
    state: State,
    cek: (params: URLSearchParams) => boolean,
): boolean {
    return state.permintaanDokter.some((url) => cek(new URL(url).searchParams));
}

/**
 * Every request this page made, as query strings, for assertions over the whole set.
 */
function semuaPermintaan(state: State): URLSearchParams[] {
    return state.permintaanDokter.map((url) => new URL(url).searchParams);
}

/**
 * The answer is READY when the skeleton is gone AND the count has stopped being a
 * placeholder - or when the error took its place.
 *
 * Two halves are needed because the obvious single signals both lie. The count is rendered
 * from the first paint, so waiting for it to exist passes while the request is still in
 * flight; and it is REMOVED ENTIRELY on failure, so waiting for it after a 500 waits
 * forever. The skeleton is the inverse: it is reliable while loading and simply absent on
 * an error. Requiring the skeleton to be gone first means that when the count is finally
 * asserted, it has already been populated rather than merely mounted.
 */
async function tungguJawaban(page: Page): Promise<void> {
    const seksi = page.locator('[data-slot="landing-direktori"]');

    await expect(seksi).toBeVisible({ timeout: 15_000 });
    await expect(seksi.locator('.animate-pulse')).toHaveCount(0, { timeout: 15_000 });
    await expect(
        seksi
            .locator('[data-slot="dokter-count"]')
            .filter({ hasNotText: 'Menghitung hasil…' })
            .or(seksi.locator('[data-slot="error-state"]')),
    ).toHaveCount(1, { timeout: 15_000 });
}

/**
 * The directory as this spec finds it: the LANDING page with the answer open and nothing
 * applied. `?semua=1` is the address every door in the navigation writes, and `/dokter`
 * redirects to it - see `f03-alamat-lama-beranda-mengalihkan-ke-dokter`.
 */
async function bukaDirektori(page: Page, opsi: OpsiMock = {}): Promise<State> {
    const state = await pasangMock(page, opsi);

    await page.goto('/?semua=1');
    await tungguJawaban(page);

    return state;
}

/**
 * The one search field: in the bar on a desktop, inside the drawer on a phone. The
 * answer under it has none, because a form beneath a result list is a second place to
 * ask the same question.
 */
async function kolomCari(page: Page, vp: Vp): Promise<Locator> {
    if (vp.nama === 'mobile') {
        const laci = page.locator('[role="dialog"]');

        // Reuses an already-open drawer rather than toggling it shut: a test that needs
        // the field twice must not depend on whether the first submit closed it.
        if ((await laci.count()) === 0) {
            await page.getByRole('button', { name: 'Buka menu navigasi' }).click();

            await expect(laci).toBeVisible();
        }

        return laci.getByRole('searchbox', { name: 'Cari dokter' });
    }

    return page
        .locator('[data-slot="landing-cari"]:visible')
        .getByRole('searchbox', { name: 'Cari dokter' });
}

/**
 * Closing the phone drawer. No-op on a desktop, where the bar has no drawer to close.
 *
 * Tests that reach past the drawer for something in the answer behind it need this: a
 * click that lands on the scrim closes the sheet, and Radix unmounts the drawer's copy of
 * the search bar on the way out - taking the typed term with it, because that term is
 * component state and state belongs to the instance.
 */
async function tutupLaci(page: Page, vp: Vp): Promise<void> {
    if (vp.nama !== 'mobile') {
        return;
    }

    const laci = page.locator('[role="dialog"]');

    if ((await laci.count()) === 0) {
        return;
    }

    await page.keyboard.press('Escape');

    await expect(laci).toHaveCount(0);
}

/**
 * Submitting a search and waiting for its one visible witness.
 *
 * The term never reaches the address (F03 §9), so there is no URL to wait for. The chip
 * that used to be the witness is gone with the rest of them, and what replaced it is the
 * count line - which was already carrying the question, only now it says it out loud:
 * `{n} dokter untuk "{kueri}" …`. Waiting on that is what stops every assertion below
 * from reading the answer to the question the visitor had asked BEFORE typing.
 */
async function cariDokter(page: Page, vp: Vp, kueri: string): Promise<void> {
    const kolom = await kolomCari(page, vp);

    await kolom.fill(kueri);
    await kolom.press('Enter');
    await tutupLaci(page, vp);

    const seksi = page.locator('[data-slot="landing-direktori"]');

    // A refetch with data already on screen never draws the skeleton, so the skeleton is
    // not the witness here - the count is. And on `f03-ac8-500` there will be no count at
    // all, which is why the error is an equal candidate rather than an afterthought.
    await expect(
        seksi
            .locator('[data-slot="dokter-count"]')
            .filter({ hasText: ` untuk "${kueri}"` })
            .or(seksi.locator('[data-slot="error-state"]')),
    ).toHaveCount(1, { timeout: 15_000 });
}

/**
 * Where a specialisation is chosen - the header's hover panel on a desktop, the same
 * rows inside the drawer on a phone. The answer has no picker to drive, so these helpers
 * use the surface a real visitor would use rather than editing the address.
 */
async function panelPilihan(page: Page, vp: Vp): Promise<Locator> {
    if (vp.nama === 'mobile') {
        await page.getByRole('button', { name: 'Buka menu navigasi' }).click();

        const laci = page.locator('[role="dialog"]');

        await expect(laci).toBeVisible();

        return laci.locator('[data-slot="nav-direktori-laci"]');
    }

    await page.getByRole('button', { name: 'Dokter Terdekat' }).hover();

    const panel = page.locator('[data-slot="nav-direktori-panel"]');

    await expect(panel).toBeVisible();

    return panel;
}

/**
 * Pick one thing by name. A row is a plain `<a href>`, so the click navigates (no reload)
 * and the drawer or panel closes itself on the way out - asserted rather than assumed,
 * because an overlay left open over the answer it just opened is the defect that made
 * these two surfaces separate in the first place.
 */
async function pilihSpesialisasi(page: Page, vp: Vp, nama: string): Promise<void> {
    const panel = await panelPilihan(page, vp);

    await panel.getByRole('link', { name: nama }).click();

    await tungguJawaban(page);
    await expect(page.locator('[role="dialog"]')).toHaveCount(0);
    await expect(page.locator('[data-slot="nav-direktori-panel"]')).toHaveCount(0);
}

/**
 * Every distance on screen, in DOM order, as a number of kilometres - or `null` for a card
 * that printed no distance at all.
 *
 * `850 m` is read as `0.85` rather than `850`, so a card below one kilometre and a card
 * above it land on the same scale and the ordering assertion cannot be fooled by the unit
 * changing halfway down the list.
 */
async function jarakKartu(page: Page): Promise<Array<number | null>> {
    return page
        .locator('[data-slot="dokter-jarak"]')
        .evaluateAll((elemen) =>
            elemen.map((element) => {
                const teks = (element.textContent ?? '').replace(',', '.');
                const angka = Number.parseFloat(teks);
                const meter = /\bm\b/.test(teks);

                return Number.isFinite(angka) ? (meter ? angka / 1000 : angka) : null;
            }),
        );
}

async function simpanGambar(
    page: Page,
    vp: Vp,
    keadaan: string,
    fullPage = true,
): Promise<void> {
    await page.screenshot({
        path: `ux/refs/f03/f03-${keadaan}-${vp.nama}.png`,
        fullPage,
        animations: 'disabled',
    });
}

for (const vp of VIEWPORTS) {
    test.describe(`F03 dokter terdekat ${vp.nama} ${vp.width}x${vp.height}`, () => {
        test.use({
            viewport: { width: vp.width, height: vp.height },
            timezoneId: 'Asia/Jakarta',
        });

        test.setTimeout(60_000);

        /**
         * The address the app wrote for years still answers, and it answers with the
         * doctor screen rather than a 404 or the marketing sections.
         *
         * `/dokter` is a redirect to `/?semua=1`. `search` is carried across because F03
         * §9 forbids this app from WRITING a free-text query into an address - dropping
         * one an older build already wrote would trade that rule for a wrong answer.
         * `tipe` and `telemedisin` are NOT carried: the answer stopped reading them in
         * the same rombak, so a `/?tipe=` address would render the marketing sections, and
         * forwarding a dead parameter into a working one is worse than dropping it.
         *
         * `/?direktori=semua`, which this app's own navigation used to write, becomes
         * `/?semua=1`, and `/?dokter=` - which used to open a profile in place - becomes
         * `/dokter/…`, its page.
         */
        test('f03-alamat-lama-mengalihkan', async ({ page }) => {
            const state = await pasangMock(page);

            await page.goto('/dokter');

            await expect(page).toHaveURL('/?semua=1');
            await tungguJawaban(page);

            // No headline over an unfiltered visit. The section is NAMED - assistive
            // technology still gets "Dokter terdekat" through `aria-label` - but it is not
            // printed, because a screen that has to tell somebody looking at a list of
            // doctors that they are looking at a list of doctors is a line of ink with
            // nothing to say.
            const seksi = page.locator('[data-slot="landing-direktori"]');

            await expect(seksi).toHaveAttribute('aria-label', 'Dokter terdekat');
            await expect(seksi.locator('h1')).toHaveCount(0);

            // A bookmark written by the build that had a `/dokter` page with filters.
            await page.goto('/dokter?spesialisasi=SP.A');

            await expect(page).toHaveURL('/?spesialisasi=SP.A');
            await tungguJawaban(page);
            await expect(
                page.getByRole('heading', { name: 'Spesialis Anak', level: 1 }),
            ).toBeVisible();

            // A dead parameter follows neither: keeping it would produce an address the
            // landing page renders as seven marketing sections.
            await page.goto('/dokter?tipe=dokter_spesialis&search=Rina');

            await expect(page).toHaveURL('/?search=Rina&semua=1');
            await tungguJawaban(page);
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                ' untuk "Rina"',
            );

            // The landing page's own old door.
            await page.goto('/?direktori=semua');

            await expect(page).toHaveURL('/?semua=1');

            // A question an older build wrote into the address keeps its answer, and a
            // doctor opened in place becomes the page it now belongs on.
            await page.goto('/?search=Rina');

            await tungguJawaban(page);
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                ' untuk "Rina"',
            );

            // Every request this test provoked, and not one carried a free-text query
            // until the address itself said so.
            expect(
                state.permintaanDokter.filter(
                    (url) =>
                        new URL(url).searchParams.get('search') !== null &&
                        !url.includes('search=Rina'),
                ),
            ).toHaveLength(0);

            // Before the profile, because that route draws from a fixture this mock does
            // not serve - `f03-ac12-a11y` is where the landing page's own audit lives, and
            // this assertion is only here so a redirect cannot quietly break it.
            await expectNoA11yViolations(page);

            // A doctor opened in place becomes the page it now belongs on, and the
            // specialisation it was picked under is not carried - `?dokter=` is a
            // destination, not a filter.
            await page.goto('/?spesialisasi=SP.A&dokter=2');

            await expect(page).toHaveURL('/dokter/2');
        });

        test('f03-ac1-cari-submit', async ({ page }) => {
            const state = await bukaDirektori(page);

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '12 dokter',
            );

            // Asking happens in the header. A search field beneath a result list is a
            // second place to ask the same question.
            await expect(
                page.locator('[data-slot="landing-direktori"] form'),
            ).toHaveCount(0);
            await expect(page.getByText('Sering dicari:')).toHaveCount(0);

            await cariDokter(page, vp, 'Rina');

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '1 dokter',
            );
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(1);
            await expect(page.locator('[data-slot="dokter-kartu"]').first()).toContainText(
                'dr. Rina Wulandari, Sp.A',
            );

            const pencarian = state.permintaanDokter.filter(
                (url) => new URL(url).searchParams.get('search') === 'Rina',
            );

            expect(pencarian.length).toBe(1);

            await simpanGambar(page, vp, 'hasil');

            // AC-13: the query survives a reload through state, never through the URL.
            expect(page.url()).not.toContain('Rina');

            await page.reload();
            await tungguJawaban(page);

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                ' untuk "Rina"',
            );
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(1);
            expect(page.url()).not.toContain('Rina');

            await expectNoA11yViolations(page);
        });

        /**
         * AC-2 used to be "a filter sheet on a phone", then "remove the chip you just
         * earned". Both are gone. What is left to prove is the part that actually matters
         * after the rombak: a pick made in the header is obeyed down here, its name is
         * the heading, and this screen holds no control that could contradict it.
         */
        test('f03-ac2-pilihan-di-navbar', async ({ page }) => {
            const state = await bukaDirektori(page);

            // The four controls the old AC-2/3/4/5/10 tested. Five assertions, one screen,
            // zero elements - this is the test that would fail if any of them came back.
            await expect(page.locator('[data-slot="dokter-urutkan"]')).toHaveCount(0);
            await expect(page.locator('[data-slot="dokter-chip"]')).toHaveCount(0);
            await expect(
                page.getByRole('button', { name: 'Hapus semua filter' }),
            ).toHaveCount(0);
            await expect(page.locator('[data-slot="dokter-halaman"]')).toHaveCount(0);
            await expect(
                page.locator('[data-slot="landing-direktori"] form'),
            ).toHaveCount(0);

            await pilihSpesialisasi(page, vp, 'Spesialis Anak');

            await expect(page).toHaveURL('/?spesialisasi=SP.A');
            await expect(
                page.getByRole('heading', { name: 'Spesialis Anak', level: 1 }),
            ).toBeVisible();
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '8 dokter',
            );
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(8);

            // The way out, in both nodes, with the heading naming where the visitor is.
            //
            // Scoped by the nav's OWN label rather than by "whichever nav holds a link
            // called Dokter Terdekat", which is two of them: the footer column carries the
            // same words as a link to the same address, so the looser filter matched the
            // breadcrumb and the footer together and the assertion died on strict mode
            // rather than on anything it was measuring. `exact` because `getByRole` treats
            // `name` as a substring by default, and the solution tile's link is "Dokter
            // Terdekat Cari dokter spesialis terdekat dari lokasimu".
            const jejak = page.getByRole('navigation', { name: 'Breadcrumb' });

            await expect(jejak).toBeVisible();
            await expect(
                jejak.getByRole('link', { name: 'Home', exact: true }),
            ).toHaveAttribute('href', '/');
            await expect(
                jejak.getByRole('link', { name: 'Dokter Terdekat', exact: true }),
            ).toHaveAttribute('href', '/?semua=1');

            expect(
                punyaPermintaan(
                    state,
                    (params) => params.get('spesialisasi') === 'SP.A',
                ),
            ).toBe(true);

            await simpanGambar(page, vp, 'terfilter');

            await expectNoA11yViolations(page);
        });

        /**
         * The criterion the rombak exists for.
         *
         * A fixture that says yes means the request carries a position and the list arrives
         * in distance order; the numbers on screen are the ones the mock computed, so
         * "nearest first" is checked against arithmetic rather than against the order the
         * fixture happened to be written in - which is why the venue steps in
         * `LANGKAH_DOKTER` are handed out in a deliberately scrambled order.
         */
        test('f03-ac5-terdekat-dengan-posisi', async ({ page }) => {
            const state = await bukaDirektori(page);

            for (const params of semuaPermintaan(state)) {
                expect(params.get('sort')).toBe('terdekat');
                expect(params.get('lat')).toBe(String(POSISI_UJI.latitude));
                expect(params.get('lng')).toBe(String(POSISI_UJI.longitude));
                // The ceiling, sent on every request whether or not the position is known.
                expect(params.get('per_page')).toBe('20');
            }

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                'diurutkan menurut jarak dari lokasimu',
            );

            // The button is gone once the position is known: there is nothing left to ask.
            await expect(
                page.locator('[data-slot="dokter-minta-lokasi"]'),
            ).toHaveCount(0);

            const jarak = await jarakKartu(page);

            // Eleven of the twelve rows have a venue; the twelfth is in the second group
            // and so never reaches this list.
            expect(jarak).toHaveLength(11);
            expect(jarak.every((nilai) => nilai !== null)).toBe(true);

            const naik = jarak as number[];

            for (let i = 1; i < naik.length; i += 1) {
                expect(naik[i]).toBeGreaterThanOrEqual(naik[i - 1] ?? 0);
            }

            // Strictly ascending where the fixture is, which is what proves the ORDER is
            // the distance's and not a coincidence of DOM order.
            expect(naik[1]).toBeGreaterThan(naik[0] ?? 0);

            // The first venue is 0.9 km out, which is the only way `formatJarak`'s
            // metre/kilometre rule can be exercised: one card says `850 m`, the rest `km`.
            expect(naik[0]).toBeLessThan(1);

            await simpanGambar(page, vp, 'terdekat');

            await expectNoA11yViolations(page);
        });

        /**
         * The other half of the same criterion, and the one with no data behind it.
         *
         * `izinLokasi: false` makes {@link posisiPalsu} answer with error code 1, which is
         * the code a visitor pressing "Block" also produces. What must NOT happen is the
         * thing the backend commit was careful about: no position means no `sort`, no
         * `lat`/`lng`, and no invented number anywhere on screen.
         */
        test('f03-ac5-lokasi-ditolak', async ({ page }) => {
            const state = await bukaDirektori(page, { izinLokasi: false });

            for (const params of semuaPermintaan(state)) {
                expect(params.get('sort')).toBeNull();
                expect(params.get('lat')).toBeNull();
                expect(params.get('lng')).toBeNull();
                expect(params.get('per_page')).toBe('20');
            }

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                'lokasi tidak aktif — tanpa urutan jarak',
            );
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(12);

            // Not one distance: a card that printed `0 km` would be a claim about where
            // the visitor is standing, and nobody asked where they were.
            await expect(page.locator('[data-slot="dokter-jarak"]')).toHaveCount(0);

            // The way back from a refusal, still reachable while offline is false.
            const tombol = page.locator('[data-slot="dokter-minta-lokasi"]');

            await expect(tombol).toBeVisible();
            await expect(tombol).toContainText('Aktifkan lokasi');
            await expect(tombol).not.toHaveAttribute('aria-disabled', 'true');

            await simpanGambar(page, vp, 'tanpa-lokasi');

            await expectNoA11yViolations(page);
        });

        test('f03-ac6-count', async ({ page }) => {
            const state = await bukaDirektori(page);

            const jumlah = page.locator('[data-slot="dokter-count"]');

            await expect(jumlah).toHaveAttribute('role', 'status');
            await expect(jumlah).toHaveAttribute('aria-live', 'polite');
            await expect(jumlah).toContainText('12 dokter');

            // Searching is what changes the count, so the count is what says so.
            await cariDokter(page, vp, 'Rina');

            await expect(jumlah).toContainText('1 dokter');
            await expect(jumlah).toContainText(' untuk "Rina"');

            // Nothing stale: `Menghitung hasil…` is what the line says while a request is
            // in flight, so a second fetch must pass through it rather than skip it. The
            // assertion has to be made DURING the flight - `cariDokter` returns once the
            // answer has landed, by which time the sentence it is waiting for has already
            // replaced the placeholder - hence the delay and the raw submit below.
            state.tundaMs = 600;

            const kolom = await kolomCari(page, vp);

            await kolom.fill('Kusuma');
            await kolom.press('Enter');
            await tutupLaci(page, vp);

            await expect(jumlah).toContainText('Menghitung hasil…', { timeout: 5000 });
            await expect(jumlah).toContainText(' untuk "Kusuma"', { timeout: 15_000 });

            // Seven, not eight: `Kusuma` is the surname seven of the eight Anak doctors
            // share. The eighth is the one named `Rina`, which is what makes this a count
            // rather than a restatement of the previous assertion.
            await expect(jumlah).toContainText('7 dokter');
            state.tundaMs = 0;

            await expectNoA11yViolations(page);
        });

        /**
         * Zero results has no chips to remove, so the recovery has to be two doors that
         * both exist: clear the term that caused it, or leave the question entirely.
         */
        test('f03-ac7-zero-result', async ({ page }) => {
            await bukaDirektori(page);

            await cariDokter(page, vp, KATA_UJI);

            const kosong = page.locator('[data-slot="empty-state"]');

            await expect(kosong).toBeVisible();
            await expect(kosong).toContainText('Tidak ada dokter dengan nama itu.');
            await expect(
                page.getByRole('button', { name: 'Hapus pencarian' }),
            ).toBeVisible();
            await expect(
                page.getByRole('link', { name: 'Lihat dokter terdekat' }),
            ).toBeVisible();

            await simpanGambar(page, vp, 'kosong');

            await page
                .getByRole('link', { name: 'Lihat dokter terdekat' })
                .click();

            await expect(page).toHaveURL('/?semua=1');
            await tungguJawaban(page);
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(12);

            await expectNoA11yViolations(page);
        });

        test('f03-ac8-500', async ({ page }) => {
            const state = await bukaDirektori(page);

            await cariDokter(page, vp, 'Rina');
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(1);

            state.gagal = true;

            await cariDokter(page, vp, 'Kusuma');

            await expect(page.locator('[data-slot="error-state"]')).toBeVisible();
            await expect(
                page.getByRole('button', { name: 'Coba lagi' }),
            ).toBeVisible();

            // The question is still on the screen and still in the request: a failure
            // that silently cleared it would answer with a list nobody asked for.
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(0);

            state.gagal = false;
            await page.getByRole('button', { name: 'Coba lagi' }).click();

            await tungguJawaban(page);
            await expect
                .poll(() =>
                    punyaPermintaan(
                        state,
                        (params) => params.get('search') === 'Kusuma',
                    ),
                )
                .toBe(true);
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                ' untuk "Kusuma"',
            );

            await expectNoA11yViolations(page);
        });

        /**
         * Offline does not stop the answer from being read; it stops the one thing that
         * would need somebody else's consent and a round trip to get: the position.
         *
         * The control is `aria-disabled` and not `disabled`, following the banner it sits
         * beside - a natively disabled button cannot receive focus, so a screen-reader
         * user could never ask it why it does nothing.
         */
        test('f03-ac9-offline', async ({ page, context }) => {
            // Denied, because the control this criterion is about is the way back from a
            // refusal - and with a position in hand there is no such control to disable.
            await bukaDirektori(page, { izinLokasi: false });

            const tombol = page.locator('[data-slot="dokter-minta-lokasi"]');

            await expect(tombol).not.toHaveAttribute('aria-disabled', 'true');

            await context.setOffline(true);

            await expect(
                page.locator('[data-testid="offline-banner"]'),
            ).toContainText('Anda sedang offline');

            await expect(tombol).toHaveAttribute('aria-disabled', 'true');
            await expect(tombol).toHaveAttribute(
                'aria-describedby',
                'dokter-alasan-offline',
            );

            await context.setOffline(false);

            await expect(page.locator('[data-testid="offline-banner"]')).toHaveCount(0);
            await expect(tombol).not.toHaveAttribute('aria-disabled', 'true');
            await expect(tombol).not.toHaveAttribute(
                'aria-describedby',
                'dokter-alasan-offline',
            );

            await expectNoA11yViolations(page);
        });

        test('f03-ac11-kartu-ulasan', async ({ page }) => {
            await bukaDirektori(page);

            const kartu = page.locator('[data-slot="dokter-kartu"]');

            // A count above zero is printed with its icon and its label...
            await expect(kartu.first().locator('[data-slot="dokter-ulasan"]')).toContainText(
                '128 ulasan',
            );
            await expect(
                kartu.first().locator('[data-slot="dokter-pengalaman"]'),
            ).toContainText('tahun pengalaman');
            await expect(kartu.first().locator('[data-slot="dokter-biaya"]')).toContainText(
                'Mulai Rp',
            );
            await expect(kartu.first()).toContainText('Tersedia telemedisin');

            // ...and zero is omitted rather than printed as a scoreboard for a doctor who
            // has simply not been reviewed. `drg. Bagus Nugroho` is the only row with
            // `jumlah_ulasan: 0`, and it is also the only row with no venue, so it is the
            // one card this assertion can find without a name lookup.
            const daring = kartu.filter({
                hasText: 'Tanpa tempat praktik — lewat chat & video',
            });

            await expect(daring).toHaveCount(1);
            await expect(daring).toContainText('drg. Bagus Nugroho');
            await expect(daring.locator('[data-slot="dokter-ulasan"]')).toHaveCount(0);
            await expect(daring.locator('[data-slot="dokter-jarak"]')).toHaveCount(0);

            await expectNoA11yViolations(page);
        });

        test('f03-ac12-a11y', async ({ page }) => {
            await istirahatkanGerak(page);
            await bukaDirektori(page);

            await simpanGambar(page, vp, 'a11y');
            await expectNoA11yViolations(page);
        });

        /**
         * The privacy fixture, and the reason this criterion exists at all: a free-text
         * doctor query may name a condition, and the address is the thing people paste
         * into chats.
         */
        test('f03-ac13-privasi', async ({ page }) => {
            await bukaDirektori(page);

            await cariDokter(page, vp, KATA_UJI);

            // The address the visitor would copy is the front page, with no query string
            // on it at all - not a redacted one, not a token: nothing.
            const alamat = new URL(page.url());

            expect(alamat.search).toBe('');
            expect(page.url()).not.toContain(KATA_UJI);
            expect(page.url()).not.toContain('search=');

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                ` untuk "${KATA_UJI}"`,
            );

            // Nothing else on the screen may carry it either: an `aria-label` or a toast
            // would be a second address for the same word, just a harder-to-share one.
            const tersirat = await page.evaluate((kata) => {
                const beracun: string[] = [];

                for (const elemen of document.querySelectorAll<HTMLElement>(
                    '[aria-label], [title], [aria-description]',
                )) {
                    for (const sifat of ['aria-label', 'title', 'aria-description']) {
                        const nilai = elemen.getAttribute(sifat);

                        if (nilai?.toLowerCase().includes(kata.toLowerCase())) {
                            beracun.push(`${sifat}=${nilai}`);
                        }
                    }
                }

                return beracun;
            }, KATA_UJI);

            expect(tersirat).toEqual([]);

            // The term lives in state and in the input, which is where the visitor put it.
            expect(
                await page.evaluate(
                    (kata) =>
                        (window.sessionStorage.getItem('sehatly.dokter.kueri') ?? '')
                            .toLowerCase()
                            .includes(kata.toLowerCase()) ||
                        (window.sessionStorage.getItem('sehatly.dokter.kueri.antar') ?? '')
                            .toLowerCase()
                            .includes(kata.toLowerCase()),
                    KATA_UJI,
                ),
            ).toBe(true);

            await expectNoA11yViolations(page);
        });

        /**
         * The ceiling, and the line that admits it exists.
         *
         * There is no page control left to press, so the only honest thing a list that
         * stops short can do is say what it kept and on what basis.
         */
        test('f03-ac14-batas-20', async ({ page }) => {
            const state = await bukaDirektori(page, {
                dokter: banyakDokter(43),
            });

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '43 dokter',
            );
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(20);

            const potongan = page.locator('[data-slot="dokter-terpotong"]');

            await expect(potongan).toBeVisible();
            // "Nearest" only because a position was in hand; `f03-ac14-potongan-tanpa-posisi`
            // covers the other wording of the same line.
            await expect(potongan).toContainText('Menampilkan 20 terdekat dari 43.');

            await expect(page.locator('[data-slot="dokter-halaman"]')).toHaveCount(0);
            await expect(
                page.getByRole('button', { name: /halaman berikutnya|Halaman \d/ }),
            ).toHaveCount(0);

            for (const params of semuaPermintaan(state)) {
                // `page` is SENT, and always the same one. The address of "the second page"
                // is a number no control on this screen can change, so the assertion is not
                // that the parameter is absent - that would fail on a parameter nobody can
                // abuse - but that it never moves off the first page.
                expect(params.get('page')).toBe('1');
                expect(params.get('per_page')).toBe('20');
            }

            await simpanGambar(page, vp, 'batas-20');

            await expectNoA11yViolations(page);
        });

        /**
         * The line that admits the list stopped is a line that can be WRONG, so it is
         * asserted from both sides.
         */
        test('f03-ac14-tanpa-potongan', async ({ page }) => {
            // Twelve of a possible twenty, with a position: nothing held back.
            await bukaDirektori(page);

            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(12);
            await expect(page.locator('[data-slot="dokter-terpotong"]')).toHaveCount(0);
        });

        test('f03-ac14-potongan-tanpa-posisi', async ({ page }) => {
            // Forty-three of a possible twenty, with no position: the line must still
            // appear, and must NOT claim the order it does not have.
            await bukaDirektori(page, {
                dokter: banyakDokter(43),
                izinLokasi: false,
            });

            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(20);
            await expect(page.locator('[data-slot="dokter-terpotong"]')).toContainText(
                'Menampilkan 20 dari 43.',
            );
            await expect(page.locator('[data-slot="dokter-terpotong"]')).not.toContainText(
                'terdekat',
            );
        });

        /**
         * A doctor with no `dokter_faskes` row cannot be "nearest": there is no place to
         * measure from. Folding them into the list would put an unmeasurable row among
         * measured ones with no way to tell them apart, and hiding them would remove real,
         * verified, consultable doctors from a patient's options for the crime of
         * practising by chat - so they get a group that says what they are.
         */
        test('f03-grup-daring', async ({ page }) => {
            await bukaDirektori(page);

            await expect(
                page.getByRole('heading', { name: 'Bisa jarak jauh', level: 2 }),
            ).toBeVisible();

            const kartu = page.locator('[data-slot="dokter-kartu"]');
            const daring = kartu.filter({
                hasText: 'Tanpa tempat praktik — lewat chat & video',
            });

            await expect(daring).toHaveCount(1);

            // The group sits BELOW every doctor that has a place, and its card carries no
            // distance - not `0`, not a dash.
            const semua = await kartu.evaluateAll((elemen) =>
                elemen.map((element) => element.textContent ?? ''),
            );
            const indeksDaring = semua.findIndex((teks) =>
                teks.includes('Tanpa tempat praktik — lewat chat & video'),
            );
            const indeksBerjarak = semua.findIndex((teks) =>
                teks.includes('km dari lokasimu'),
            );

            expect(indeksDaring).toBeGreaterThan(indeksBerjarak);
            await expect(daring.locator('[data-slot="dokter-jarak"]')).toHaveCount(0);

            await simpanGambar(page, vp, 'grup-daring');

            await expectNoA11yViolations(page);
        });

        /**
         * The card is a link to a page, not to a panel that opens over the list it came
         * from. `/?dokter=` was the second body the landing page could render and it is
         * gone; this is the assertion that would catch it coming back.
         */
        test('f03-kartu-buka-halaman-profil', async ({ page }) => {
            await bukaDirektori(page);

            const kartuPertama = page.locator('[data-slot="dokter-kartu"]').first();

            await expect(kartuPertama.locator('a')).toHaveAttribute(
                'href',
                /^\/dokter\/\d+$/,
            );

            await kartuPertama.locator('a').click();

            await expect(page).toHaveURL(/\/dokter\/\d+$/);
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

            // Nothing on the address that opened it, and no second body on the landing
            // page to open in place.
            expect(page.url()).not.toContain('?dokter=');
        });
    });
}

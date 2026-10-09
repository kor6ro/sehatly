import {
    Activity,
    Baby,
    BellRing,
    Brain,
    CalendarDays,
    Droplets,
    FileText,
    FlaskConical,
    HeartPulse,
    MessagesSquare,
    Pill,
    ShieldCheck,
    Smile,
    Sparkles,
    Stethoscope,
    Sun,
    Timer,
    Weight,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/**
 * Everything the landing page renders that is NOT fetched.
 *
 * ## Why this file exists at all
 *
 * The page is seven sections long and none of THEM has an endpoint behind it: the
 * fetching moved into the header. `PanelDirektori` asks for `GET /master-spesialisasi`,
 * and the carousel - and that same menu, on the same query key - asks for `GET /hero`,
 * the gallery the admin edits at `/admin/hero`. The carousel's copy in THIS file is its
 * fallback - what a signed-out visitor sees while the table is empty or the API is
 * down - which is why `SLIDE_HERO` is still worth keeping rather than deleted the
 * moment the data moved behind a route. Everything else is editorial copy: the
 * product's own description of what it does. Keeping that copy in one module rather
 * than inline means a wording change is a data change, and it keeps the section
 * components free of the part that has nothing to do with layout.
 *
 * ## The rule every entry here obeys: `to` is a registered route
 *
 * A landing page's job is to move somebody somewhere, so every link in this file points
 * at a path `app/router.tsx` actually registers. There are no `#` anchors and no
 * "coming soon" destinations: a signed-out visitor who picks "Resep & Apotek" lands on
 * `RequireAuth`, which hands them to `/login` - the correct outcome, reached through the
 * router rather than through a link that goes nowhere.
 *
 * The two sections with no route to offer - the health-check tiles and the glossary -
 * therefore carry no `to` at all and render as content, not as controls. That is the
 * difference between "not built yet" and "not a lie".
 */

/** A slide in the hero carousel. `gradien` is a Tailwind background class. */
export type Slide = {
    id: string;
    eyebrow: string;
    judul: string;
    deskripsi: string;
    cta: { label: string; to: string };
    gradien: string;
};

/**
 * The three hero slides.
 *
 * The gradients are deliberately different hues: the carousel's dots are the only
 * indication that anything moved, so two slides that looked alike would make the control
 * look broken. Every `cta.to` is a destination a signed-out visitor can open - the
 * doctor directory's is a section of this same page (`/?direktori=semua`), and the two
 * guarded ones resolve through `RequireAuth` to the login dialog's own door.
 */
export const SLIDE_HERO: Slide[] = [
    {
        id: 'dokter-pilihan',
        eyebrow: 'Dokter spesialis tepercaya',
        judul: 'Bingung Pilih Dokter?',
        deskripsi:
            'Ratusan dokter berlisensi dengan rating, pengalaman, dan ulasan pasien - pilih yang cocok tanpa perlu antre.',
        cta: { label: 'Konsultasi dengan Dokter Pilihan!', to: '/?direktori=semua' },
        gradien: 'from-rose-500 via-rose-400 to-orange-400',
    },
    {
        id: 'konsultasi',
        eyebrow: 'Chat & video kapan saja',
        judul: 'Kesehatanmu di Ujung Jari',
        deskripsi:
            'Ceritakan keluhanmu lewat chat atau video call, lalu terima resep elektroniknya dalam satu sesi.',
        cta: { label: 'Mulai Konsultasi', to: '/konsultasi' },
        gradien: 'from-violet-600 via-indigo-500 to-sky-400',
    },
    {
        id: 'janji-temu',
        eyebrow: 'Booking tanpa antre',
        judul: 'Janji Temu jadi Sesuai Jadwal',
        deskripsi:
            'Lihat jadwal praktik dan slot kosong tiap dokter, pilih waktumu, dan datang tepat saat giliranmu.',
        cta: { label: 'Booking Janji Temu', to: '/booking' },
        gradien: 'from-emerald-600 via-teal-500 to-cyan-400',
    },
];

/**
 * The gradient rotation a slide arrives WITHOUT one gets.
 *
 * {@link SLIDE_HERO} carries its own hues because it is the source of truth for the
 * built-in slides. A slide from `GET /hero`, however, is a row: it stores no Tailwind
 * class (the server must not know this bundle's class names), so the carousel paints
 * one of these by position. The three hues are `SLIDE_HERO`'s own, in order, which is
 * what keeps a published strip indistinguishable in character from the fallback one -
 * and rotating by index is what keeps two adjacent slides from looking alike, since
 * the dots are the only indication that anything moved.
 */
export const GRADIEN_HERO: readonly string[] = [
    'from-rose-500 via-rose-400 to-orange-400',
    'from-violet-600 via-indigo-500 to-sky-400',
    'from-emerald-600 via-teal-500 to-cyan-400',
];

/** "Solusi Kesehatan di Tanganmu" - the six service entry points. */
export type Layanan = {
    judul: string;
    deskripsi: string;
    ikon: LucideIcon;
    to: string;
};

export const LAYANAN: Layanan[] = [
    {
        judul: 'Chat dengan Dokter',
        deskripsi: 'Konsultasi teks & video, siap 24 jam',
        ikon: MessagesSquare,
        to: '/konsultasi',
    },
    {
        judul: 'Booking Janji Temu',
        deskripsi: 'Pilih jadwal dokter pilihanmu',
        ikon: CalendarDays,
        to: '/booking',
    },
    {
        judul: 'Resep & Apotek',
        deskripsi: 'Resep elektronik, diantar ke rumah',
        ikon: Pill,
        to: '/pasien/resep',
    },
    {
        judul: 'Rekam Medis',
        deskripsi: 'Riwayat kesehatan dalam satu tempat',
        ikon: FileText,
        to: '/rekam-medis',
    },
    {
        judul: 'Direktori Dokter',
        deskripsi: 'Cari dokter spesialis berdasarkan kebutuhan',
        ikon: Stethoscope,
        to: '/?direktori=semua',
    },
    {
        judul: 'Pengingat Obat',
        deskripsi: 'Alarm minum obat supaya tidak lupa',
        ikon: BellRing,
        to: '/pengingat',
    },
];

/** "Promo & Penawaran Hari Ini" - campaign cards, each landing on a real route. */
export type Promo = {
    judul: string;
    deskripsi: string;
    cta: string;
    to: string;
    gradien: string;
};

export const PROMO: Promo[] = [
    {
        judul: 'Konsultasi Hemat',
        deskripsi: 'Tarif konsultasi chat dokter umum mulai Rp15.000.',
        cta: 'Pilih Dokter',
        to: '/?direktori=semua',
        gradien: 'from-rose-500 to-pink-500',
    },
    {
        judul: 'Booking Tanpa Antre',
        deskripsi: 'Amankan slot praktik hari ini, bayar setelah selesai.',
        cta: 'Lihat Jadwal',
        to: '/booking',
        gradien: 'from-indigo-500 to-violet-500',
    },
    {
        judul: 'Resep Langsung Diantar',
        deskripsi: 'Dari dokter ke pintumu, tanpa ribet ke apotek.',
        cta: 'Cara Kerjanya',
        to: '/?direktori=semua',
        gradien: 'from-emerald-500 to-teal-500',
    },
    {
        judul: 'Riwayat Satu Genggaman',
        deskripsi: 'Semua rekam medis dan alergi tersimpan aman.',
        cta: 'Buka Rekam Medis',
        to: '/rekam-medis',
        gradien: 'from-amber-500 to-orange-500',
    },
];

/** "Beli Obat & Suplemen Kesehatan" - categories, with no price and no brand. */
export type KategoriObat = {
    nama: string;
    deskripsi: string;
    ikon: LucideIcon;
};

export const KATEGORI_OBAT: KategoriObat[] = [
    {
        nama: 'Vitamin & Suplemen',
        deskripsi: 'Daya tahan tubuh, mulai dari kebutuhan harian.',
        ikon: Sparkles,
    },
    {
        nama: 'Perawatan Kulit',
        deskripsi: 'Pembersih, pelembap, dan perlindungan sinar matahari.',
        ikon: ShieldCheck,
    },
    {
        nama: 'Obat Resep',
        deskripsi: 'Hanya diterbitkan dokter setelah konsultasi.',
        ikon: FlaskConical,
    },
    {
        nama: 'Ibu & Anak',
        deskripsi: 'Kebutuhan kehamilan, menyusui, dan si kecil.',
        ikon: Baby,
    },
];

/**
 * "Telusuri Kamus Kesehatan" - the glossary.
 *
 * Each entry is a short, plain-language definition. The section renders these as
 * buttons that reveal the definition in place rather than as links, because there is no
 * glossary route to link to: the honest shape of "content we can show you right here" is
 * an inline disclosure, not a URL that does not exist.
 */
export type Istilah = {
    istilah: string;
    definisi: string;
};

export const KAMUS: Istilah[] = [
    {
        istilah: 'Hipertensi',
        definisi:
            'Tekanan darah terus-menerus lebih tinggi dari normal (130/80 mmHg ke atas). Sering tak bergejala, jadi pemeriksaan rutin penting.',
    },
    {
        istilah: 'Diabetes',
        definisi:
            'Kadar gula darah terlalu tinggi karena produksi atau pemakaian insulin terganggu. Ditangani dengan pola makan, obat, dan kontrol berkala.',
    },
    {
        istilah: 'Kolesterol',
        definisi:
            'Lemak dalam darah yang dibutuhkan tubuh, tetapi kadarnya yang berlebihan menumpuk di pembuluh darah dan menaikkan risiko penyakit jantung.',
    },
    {
        istilah: 'Anemia',
        definisi:
            'Jumlah sel darah merah atau kadar hemoglobin kurang, sehingga oksigen ke jaringan berkurang. Gejalanya mudah lelah, pucat, dan pusing.',
    },
    {
        istilah: 'Stroke',
        definisi:
            'Aliran darah ke sebagian otak terputus, sehingga sel-sel otak rusak. GEJALA mendadak: wajah miring, satu tangan lemah, bicara pelo - segera bawa ke IGD.',
    },
    {
        istilah: 'Demam Berdarah',
        definisi:
            'Infeksi virus dengue yang ditularkan nyamuk Aedes. Tandanya demam tinggi mendadak, nyeri di belakang mata, dan penurunan trombosit.',
    },
    {
        istilah: 'BMI',
        definisi:
            'Body Mass Index: berat badan (kg) dibagi tinggi badan (m) kuadrat. Alat skrining kasar untuk mengategorikan berat badan, bukan diagnosis.',
    },
    {
        istilah: 'Alergi',
        definisi:
            'Tanggungan berlebihan sistem imun terhadap zat yang seharusnya aman - makanan, serbuk sari, atau obat. Riwayatnya perlu dicatat di profil.',
    },
];

/** "Baca Artikel Kesehatan Terkini" - editorial tips, shown in place. */
export type Tips = {
    judul: string;
    kategori: string;
    ringkas: string;
};

export const TIPS: Tips[] = [
    {
        judul: 'Kenali Tanda Darah Tinggi Sebelum Komplikasi',
        kategori: 'Jantung',
        ringkas: 'Sakit kepala belakang dan mimisan bisa jadi pertanda, bukan sekadar kelelahan.',
    },
    {
        judul: 'Cukup Tidur Bukan Berarti Tidur Lama',
        kategori: 'Tidur',
        ringkas: 'Kualitas tidur menentukan pulih atau tidaknya tubuh, bukan hanya jumlah jamnya.',
    },
    {
        judul: 'Minum Obat Harus Berhenti Saat Dosnya Habis?',
        kategori: 'Obat',
        ringkas: 'Antibiotik wajib dihabiskan; obat demam boleh dihentikan gejala mereda.',
    },
    {
        judul: 'Berapa Langkah Sehari yang Masuk Akal?',
        kategori: 'Aktivitas',
        ringkas: 'Mulai dari yang bisa dilakukan hari ini, naik bertahap tanpa memaksa.',
    },
    {
        judul: 'Stres Kronis Menyerang Tubuh, Bukan Cuma Perasaan',
        kategori: 'Mental',
        ringkas: 'Sulit tidur, perut tidak nyaman, dan mudah sakit bisa bermula dari tekanan.',
    },
    {
        judul: 'Makanan Tinggi Serat untuk Pencernaan Tenang',
        kategori: 'Nutrisi',
        ringkas: 'Sayur, buah, dan biji-bijian; naikkan porsinya pelan-pelan supaya perut adaptif.',
    },
];

/**
 * "Cek Kesehatan Mandiri" - the self-check tiles.
 *
 * No `to`, deliberately: Sehatly ships no screening tool yet, and a tile that looked
 * tappable while leading nowhere is the one thing worse than a plain tile. They render as
 * content with a one-line description each, which is what the section can honestly claim.
 */
export type CekMandiri = {
    label: string;
    deskripsi: string;
    ikon: LucideIcon;
};

export const CEK_MANDIRI: CekMandiri[] = [
    { label: 'Cek Stres', deskripsi: 'Seberapa besar tekananmu pekan ini', ikon: Brain },
    { label: 'Kalkulator BMI', deskripsi: 'Tinggi, berat, lalu kategorinya', ikon: Weight },
    { label: 'Risiko Jantung', deskripsi: 'Kebiasaan yang membebani jantung', ikon: HeartPulse },
    { label: 'Risiko Diabetes', deskripsi: 'Riwayat keluarga dan pola makan', ikon: Droplets },
    { label: 'Kadar Gula Darah', deskripsi: 'Kapan sebaiknya mulai cek rutin', ikon: Activity },
    { label: 'Tes Depresi', deskripsi: 'Skrining awal suasana hati', ikon: Smile },
    { label: 'Pengingat Obat', deskripsi: 'Atur jadwal minum obat harian', ikon: Timer },
    { label: 'Kalender Kehamilan', deskripsi: 'Hitung usia kehamilan & HPL', ikon: CalendarDays },
    { label: 'Kebutuhan Kalori', deskripsi: 'Estimasi kebutuhan energi harian', ikon: Sun },
];

/** "Kata Mereka tentang Sehatly" - signed-off quotes, initials only. */
export type Testimoni = {
    kutipan: string;
    nama: string;
    peran: string;
};

export const TESTIMONI: Testimoni[] = [
    {
        kutipan:
            'Tengah malam anakku demam, chat dokternya dibalas dalam beberapa menit dan resepnya langsung bisa ditebus. Tidak perlu keluar rumah.',
        nama: 'R. Wiyono',
        peran: 'Orang tua, Semarang',
    },
    {
        kutipan:
            'Yang saya suka: tidak ada lagi kata sandi yang harus diingat. Cukup nomor ponsel, masuk, selesai - seperti belanja online saja.',
        nama: 'L. Indraswari',
        peran: 'Pasien, Bandung',
    },
    {
        kutipan:
            'Booking jadwalnya jelas, dan rekam medisnya kebaca lagi di kunjungan berikutnya. Dokternya tidak perlu menebak dari nol.',
        nama: 'A. Felayati',
        peran: 'Pasien, Surabaya',
    },
];

/** The footer's link columns. Same rule as everywhere: `to` must be registered. */
export type FooterKolom = {
    judul: string;
    tautan: { label: string; to: string }[];
};

export const FOOTER_KOLOM: FooterKolom[] = [
    {
        judul: 'Bantuan & Panduan',
        tautan: [
            { label: 'Syarat & Ketentuan', to: '/syarat-ketentuan' },
            { label: 'Kebijakan Privasi', to: '/kebijakan-privasi' },
            { label: 'Direktori Dokter', to: '/?direktori=semua' },
        ],
    },
    {
        judul: 'Layanan',
        tautan: [
            { label: 'Chat dengan Dokter', to: '/konsultasi' },
            { label: 'Booking Janji Temu', to: '/booking' },
            { label: 'Rekam Medis', to: '/rekam-medis' },
            { label: 'Resep & Apotek', to: '/pasien/resep' },
        ],
    },
    {
        judul: 'Akun',
        tautan: [
            { label: 'Masuk', to: '/login' },
            { label: 'Dasbor', to: '/dashboard' },
            { label: 'Pengaturan Profil', to: '/profil' },
            { label: 'Privasi & Data', to: '/profil/privasi' },
        ],
    },
];

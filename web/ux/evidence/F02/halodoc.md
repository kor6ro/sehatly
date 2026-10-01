# F02 — Halodoc (kebijakan privasi & model persetujuan)

Flow: pasien diminta menyetujui pemrosesan data kesehatan → keputusan tercatat dan bisa ditarik.
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa akun, tanpa login, tanpa submit form.

## Sumber

| # | Sumber | Jenis | Versi/platform terlihat |
|---|---|---|---|
| S1 | https://www.halodoc.com/kebijakan-privasi?_single=true [akses 2026-10-01] | Pemberitahuan privasi resmi (Bahasa Indonesia) | "Terakhir diubah: 1 Juli 2024" |
| S2 | https://www.halodoc.com/syarat-dan-ketentuan-perlindungan-data-pasien [akses 2026-10-01] | SOP perlindungan data pasien (resmi) | "berlaku sejak: 1 Juli 2025" |
| S3 | https://play.google.com/store/apps/details?id=com.linkdokter.halodoc.android&hl=id [akses 2026-10-01] | Store listing Google Play | Halodoc; **diperbarui 30 Sep 2026**; 4,8★ / 504 rb ulasan; 10 jt+ unduhan |
| S4 | https://www.halodoc.com/syarat-dan-ketentuan [akses 2026-10-01] | Syarat & ketentuan (ditautkan dari [S2]) | **isi tidak di-fetch** → TIDAK TERVERIFIKASI |

- **Kandidat masih aktif di 2026**: Play diperbarui 30 Sep 2026 [S3]; juga muncul sebagai
  "similar apps" pada listing SATUSEHAT [akses 2026-10-01].
- **Batasan riset**: aplikasi native → layar persetujuan tidak terlihat; yang dipelajari
  adalah **dokumen persetujuan resmi** (kebijakan privasi & SOP).

## Langkah terlihat (fakta dari dokumen resmi)

1. **Persetujuan eksplisit untuk pemrosesan**: "Anda setuju dan memberikan **persetujuan
   eksplisit** Anda kepada Kami untuk Memproses Data Pribadi Anda" [S1].
2. **Penggunaan platform = persetujuan** (implied): "Penggunaan Platform … merupakan bentuk
   persetujuan Anda terhadap Ketentuan Penggunaan dan Pemberitahuan Privasi ini" [S1].
3. **Pemasaran terbungkus**: "Dengan menggunakan Platform, Anda memberikan persetujuan Anda
   untuk menerima … materi pemasaran" — pemasaran disetujui lewat penggunaan platform, bukan
   pilihan terpisah [S1].
4. **Data orang lain dianggap menyetujui**: bila pengguna memasukkan data pihak lain, "pihak
   lain dianggap telah memberikan persetujuan" [S1].
5. **Penarikan**: "Persetujuan ini bersifat **sukarela dan dapat Anda tarik kapan saja**";
   pemasaran bisa dihentikan lewat "instruksi berhenti berlangganan" [S1].
6. **Data kesehatan = data sensitif**: daftar data sensitif memuat "informasi kesehatan,
   data biometrik, data genetika" [S1][S2].
7. **Hak menolak di konteks klinis**: "Pengguna memiliki hak untuk menolak memberikan Data
   Pribadi tersebut" (bila klinis/mitra meminta data tambahan) [S2].
8. Data safety Play: **may share** Lokasi, Info pribadi + 4 lainnya; **may collect** Lokasi,
   Info pribadi + 7 lainnya; enkripsi transit; bisa minta hapus data [S3].

## Hitungan

Titik awal: **pasien sudah masuk, diminta menyetujui pemrosesan data kesehatan** → tugas
inti: **satu keputusan tercatat dan bisa ditarik**.

| Skenario | Layar | Ketukan | Field |
|---|---|---|---|
| Memberi persetujuan pertama | **TIDAK TERVERIFIKASI** (tidak ada layar yang terlihat dari sumber publik) | — | — |
| Menarik persetujuan ("tarik kapan saja") | **TIDAK TERVERIFIKASI** (mekanisme disebut di teks, alurnya tidak) | — | — |

Dokumen menyebut haknya, bukan urutan langkahnya → **tidak ada hitungan yang sah**.

## State terlihat

- **TIDAK TERVERIFIKASI**: loading, kosong, error, sukses, offline layar persetujuan.
- Terdokumentasi (bukan state layar): ketentuan berhenti berlangganan pemasaran [S1];
  permintaan hapus data di Play [S3].

## Red flag

**MERAH (diskualifikasi sebagai sumber pola — rubrik: bundled/consent terselip):**

1. **Konsensi terbungkus**: persetujuan pemasaran ikut diberikan karena "menggunakan
   Platform" — bukan pilihan terpisah yang aktif [S1]. Ini persis "bundled consent" yang
   dilarang EDPB/ICO/DRCF (`edpb-ico-consent.md` [S4][S6][S7]).
2. **Consent terselip / implied**: penggunaan platform dianggap persetujuan atas
   Ketentuan+Privasi; persetujuan pihak ketiga dianggap ada ("dianggap telah memberikan
   persetujuan") [S1] — bertentangan dengan syarat "aktif, ambigu-ganda" EDPB.

Konsekuensi: Halodoc **tidak boleh menjadi sumber pola** untuk F02 (aturan benchmark §7),
meski kriteria lain punya nilai. Sisi baik tetap dicatat sebagai fakta: penarikan disebut
"tarik kapan saja" dan data kesehatan dinyatakan sensitif [S1].

## Yang TIDAK bisa diverifikasi

1. Seluruh layar aplikasi (pemicu, checklist, penarikan) — tanpa akun/login.
2. Ada tidaknya banner cookie / kotak tercentang awal pada situs (perilaku sisi klien tidak
   terbaca dari fetch HTML; **tidak ada interaksi "accept all" yang dilakukan**).
3. Mekanisme nyata penarikan persetujuan (satu ketukan? formulir? email?) [S1 hanya teks].
4. Isi `syarat-dan-ketentuan` [S4] — URL ditautkan dari [S2] tetapi tidak di-fetch.
5. State loading/kosong/error/offline layar persetujuan; versi dokumen yang ditampilkan.

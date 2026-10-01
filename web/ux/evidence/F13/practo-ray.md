# F13 — Practo Ray (kalender, antrean pasien, resep otomatis, mode offline)

Titik awal hitungan: **dokter membuka aplikasi di sela pasien → konsultasi dimulai / resep ditulis**.

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://help.practo.com/practo-ray/calendar/understanding-the-patient-queue/ | Help center resmi | Antrean: Check-in → Engage → Check Out, timer | 2026-10-01 |
| S2 | https://help.practo.com/practo-ray/calendar/calendar-overview/ | Help center resmi | "Calendar is the default view when you log into your Ray account" | 2026-10-01 |
| S3 | https://help.practo.com/practo-ray/emr/using-automated-prescriptions/ | Help center resmi | EMR → Prescriptions → Add → template/drug → Save → Print/SMS/Email | 2026-10-01 |
| S4 | https://help.practo.com/practo-ray/emr/global-drug-catalogue/ | Help center resmi | Katalog "almost 100,000" obat, type-ahead | 2026-10-01 |
| S5 | https://help.practo.com/practo-ray/app/how-to-access-ray-offline/ | Help center resmi | Mode offline: antrian edit lalu sync; **iOS read-only**; billing online-only | 2026-10-01 |
| S6 | https://help.practo.com/practo-ray/navigation/ | Help center resmi | Nav dokter memuat Profiles/Feedback/Reach/Consult (cross-sell) | 2026-10-01 |
| S7 | https://www.practo.com/providers/clinics/ray | Halaman produk resmi | "Used by 50,000+ doctors"; "1-click scheduling for the entire treatment duration" (klaim marketing) | 2026-10-01 |
| S8 | https://cdnbbsr.s3waas.gov.in/s39fb7b048c96d44a0337f049e0a61ff06/uploads/2025/08/202508081355021472.pdf | Dokumen pengadilan/audit (pihak ketiga) | Audit 2025: isu kritis di 7 dari 9 layar iOS; perbaikan ±70% | 2026-10-01 |
| S9 | https://www.bananaip.com/intellepedia/wp-content/uploads/2022/08/Practo-Case-No.-13205I-ll02-1202.pdf | Dokumen pengadilan CCPD 2022 | Perintah buat aplikasi aksesibel (RPwD Act) | 2026-10-01 |
| S10 | https://www.itechguides.com/products/practo-ray/ | Tinjauan pihak ketiga | "Facts checked 24 Sep 2026" | 2026-10-01 |
| S11 | https://www.timesofindia.indiatimes.com/business/india-business/practo-appoints-new-ceo-shashank-nd-becomes-exec-chairman/articleshow/134314023.cms | Berita independen | 17 Sep 2026 — Praktis aktif, 700.000+ penyedia | 2026-10-01 |
| S12 | https://omega.practo.com/ dan https://github.com/practo/meridian-docs | Design system publik (docs) | Omega component library; Meridian explorer (gh-pages, 2026) | 2026-10-01 |

**Verifikasi 2026:** aktif — berita Sep 2026 (S11), facts-check Sep 2026 (S10), repo design system 2026 (S12).

## Langkah terlihat (fakta)

1. **Kalender = tampilan default saat login** [S2]; hari cepat via tombol Today ("Quick Day View") [help.practo.com using-your-calendar, akses 2026-10-01].
2. **Antrean pasien di kanan kalender** [S1]: tombol per status — **Check-in** (pasien tiba → timer tunggu mulai → tombol Engage muncul) → **Engage** (dokter siap → timer engagement mulai) → **Check Out** [S1].
3. **Resep (jalur terpisah dari kalender)**: menu **EMR → Prescriptions → Add prescriptions** → pilih pasien → pilih template obat/satu obat → isi instruksi/catatan → **Save prescription** → **Print** atau dropdown **SMS/Email** [S3].
4. **Pencarian obat**: type-ahead "first few few characters… list of all matching drugs" di katalog ±100.000 [S4].
5. **Offline**: mode offline aktif untuk kebanyakan fungsi; edit diantre lalu sync saat online kembali; **iOS read-only** saat offline; billing/reminders online-only [S5].
6. **Nav dokter** memuat item Profiles/Feedback/Reach/Consult (produk silang Practo) [S6].

## Hitungan

Dari **dokter membuka aplikasi** → **konsultasi dimulai** (asumsi: pasien sudah Check-in, dokter siap):

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | 1 (kalender + antrean kanan) | S1, S2 |
| Ketukan | 1 (**Engage** dari antrean) | S1 |
| Field | 0 | S1 |

**Konsultasi → resep ditulis**: 6+ langkah terdokumentasi (EMR menu → Prescriptions → Add → pilih pasien → pilih template/obat → Save → Print/SMS/Email) + field instruksi; jumlah total layar/ketukan presisi **TIDAK TERVERIFIKASI** (help tidak merilis angka) [S3].

## State terlihat

- **Offline**: terdokumentasi penuh — antrean edit → sync; iOS read-only; billing butuh online [S5].
- **Timer status**: timer tunggu mulai saat Check-in, timer engagement saat Engage [S1] → status terbaca tanpa warna saja (ada teks aksi).
- **Loading/kosong/error UI**: **TIDAK TERVERIFIKASI** di sumber publik.
- **Aksesibilitas**: perintah pengadilan 2022 [S9] + audit 2025 menemukan isu kritis di 7/9 layar iOS [S8] → **bukti negatif nyata** untuk kritikia aksesibilitas.

## Red flag

- **Aksesibilitas rusak parah** (audit 2025, pengadilan ingatkan denda) [S8] — bukan dark pattern rubrik, tetapi penalti kuat pada kriteria 4; praktis **tidak layak jadi sumber pola aksesibilitas**.
- **Cross-sell di nav** [S6] — kebisingan; bukan diskualifikasi (tidak menutupi aksi utama).
- **Harga tidak tercantum** di halaman produk (laporan pihak ketiga: pricing unlisted + biaya per-apotemen) [cufront.com blog prakto-ray-pricing-india-worth-it-2026, akses 2026-10-01] — dicatat; untuk F13 (produk internal, tanpa harga) tidak relevan.
- Tidak ditemukan hitung mundur palsu/izin tanpa konteks pada sumber resmi.

## Yang TIDAK bisa diverifikasi

- Layout kalender/antrean asli, state loading/kosong/error, shortcut keyboard.
- Jumlah ketukan presisi login → resep (angka kasar dari langkah berlabel).
- Versi aplikasi & tanggal rilis (help center tidak menampilkan versi).
- Klaim "1-click scheduling" [S7] = marketing, tidak diverifikasi dari help.

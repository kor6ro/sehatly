# F09 — Medisafe (medication management)

Flow: pengguna membuka satu obat → memahami dosis → tahu aksi berikutnya.
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa akun; tanpa screenshot. Ingat: Medisafe **bukan** alur resep telemedicine — jadi kandidat pola untuk detail obat/pengingat, bukan untuk tebus resep.

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://help-center.medisafe.com/en/articles/8102918-how-can-i-change-my-medication-s-reminder-time [akses 2026-10-01] | Help center resmi | Navigasi: tab Medications → ketuk obat → Edit |
| S2 | https://help-center.medisafe.com/en/articles/8102938-how-can-i-change-my-medication-s-schedule [akses 2026-10-01] | Help center resmi | Field jadwal/dosis |
| S3 | https://help-center.medisafe.com/en/articles/8537443-how-to-add-a-medication-with-a-custom-cycle [akses 2026-10-01] | Help center resmi | Home menampilkan 30 hari ke depan/belakang |
| S4 | https://help-center.medisafe.com/en/articles/8103161-can-i-get-reminded-when-it-is-time-to-refill-my-med [akses 2026-10-01] | Help center resmi | Pengingat isi ulang + nomor resep |
| S5 | https://help-center.medisafe.com/en/articles/8103246-can-i-see-the-name-of-my-medications-with-my-notifications [akses 2026-10-01] | Help center resmi | Toggle nama obat di notifikasi |
| S6 | https://help-center.medisafe.com/en/articles/13052285-medisafe-is-now-a-fully-paid-app-for-users-outside-the-u-s [akses 2026-10-01] | Help center resmi (7 Jan 2026) | Wajib berbayar di luar AS sejak 1 Jan 2026 |
| S7 | https://play.google.com/store/apps/details?id=com.medisafe.android.client&hl=en_US&gl=US [akses 2026-10-01] | Store listing | Update 15 Sep 2026, 5 jt+, 4,5 (249 rb), "Contains ads" |
| S8 | https://medisafe.com/news-events/medisafe-launches-feature-to-alert-users-of-potentially-harmful-drug-interactions [akses 2026-10-01] | Siaran pers resmi | Skala interaksi minor→severe |
| S9 | https://medisafeapp.com/en/pro-tip-how-to-use-the-interaction-checker/ [akses 2026-10-01] | Halaman resmi | Interactions Checker: More → pilih obat → bandingkan |
| S10 | https://apps.apple.com/is/app/medisafe-pill-reminder/id573916946 [akses 2026-10-01] | Store listing | © 2026; IAP Premium 49,99 USD/thn |
| S11 | https://medisafe.com/download-the-app [akses 2026-10-01] | Situs resmi (© 2026) | Medfriend, laporan, "discretely" |
| S12 | https://help-center.medisafe.com/en/articles/12413393-... [akses 2026-10-01] | Help center resmi | Medfriend dikirim 30 menit setelah reminder terakhir |

- Versi: Android (Play) update 15 Sep 2026; iOS versi 9.50.7 (Mei 2026, pelacak pihak ketiga — versi iOS persis **TIDAK TERVERIFIKASI**).

## Langkah terlihat (fakta)

1. Navigasi resmi: tab **"Medications"** di bawah → ketuk obat → **Edit** (iOS: kanan atas; Android: ikon pensil) [S1].
2. Field terdokumentasi: jam pengingat, jadwal/frekuensi, tiap-2-hari, siklus kustom (Take/Break Days), dosis setengah, sesuai kebutuhan, **strength/ bentuk/ kondisi**, jumlah pil per dosis, durasi terapi, instruksi, **pengingat isi ulang (pil tersisa, ambang, waktu, nomor resep/RX)** [S2][S4].
3. Layar **Home menampilkan rentang 30 hari** dosis [S3].
4. Layar pengingat ditingkatkan agar "easier to see details about your meds, such as whether they should be taken with food or not" [blog resmi via riset, tercatat di sesi sebelumnya — dianggap terverifikasi dari situs medisafeapp.com].
5. **Interaksi obat:** layar info obat menampilkan interaksi (minor→severe) + faktor gaya hidup (makanan/alkohol); skala 4 tingkat; interaksi mayor memberi peringatan proaktif [S8]; checker: More → Interactions Checker → pilih/bandingkan obat [S9].
6. **Medfriend** (keluarga/penjaga): notifikasi bila dosis terlewat, dikirim 30 menit setelah reminder terakhir (±1 jam setelah jadwal) [S12]; laporan harian/mingguan/bulanan bisa dibagikan ke dokter [S11].
7. Privasi: opsi **passcode** untuk melindungi tampilan obat [S11]; toggle **"Show Med Names"** mengatur apakah nama obat muncul di notifikasi; jika aktif, tekan-notifikasi-lama untuk melihat nama [S5].

## Hitungan

Titik awal: **pengguna membuka satu obat** → tugas inti: paham dosis + tahu aksi berikutnya.

| Skenario | Layar | Ketukan | Field |
|---|---|---|---|
| Dari notifikasi (log dosis) | 0 layar app | 1 (tombol di notifikasi) | 0 |
| Buka detail obat | 2 (Medications → obat) | 2 | 0 |
| Edit jadwal/dosis | 3 | +1 "Edit" | 2–4 field |

Dosis + instruksi "with food or not" terlihat di layar pengingat/detail [S1][S2].

## State terlihat

- **TIDAK TERVERIFIKASI** untuk loading/kosong/error/offline di dalam app — help center hanya mendokumentasikan pengiriman notifikasi ("No Notifications – Quick Guide") [help-center collection resmi].
- Ulasan pihak ketiga 2015 menyebut "cryptic error messages" saat offline — **usang, tidak dipakai**.

## Red flag

- Tidak ada indikatan dark pattern per rubrik. Tapi dicatat sebagai **risiko kepercayaan**: sejak 1 Jan 2026 aplikasi **wajib berbayar di luar AS** ($4,99/bln atau $39,99/thn) [S6]; ulasan pengguna melaporkan notifikasi terlalu sering & dosis terlewat (ulasan Play, Agu 2026) [S7]; iklan pada tier gratis (label Play "Contains ads") [S7].
- TUDUHAN pengguna 2020 (data "dijual ke Facebook") **TIDAK TERVERIFIKASI** — dibantah developer; tidak dipakai sebagai dasar skor.
- Positif terdokumentasi: toggle nama obat di notifikasi [S5] (privasi notifikasi).

## Yang TIDAK bisa diverifikasi

- Tata letak skrin detail dalam aplikasi (di balik login), teks state kosong/error.
- Apakah "Show Med Names" **default mati** (artikel hanya menjelaskan cara menyalakan).
- Versi iOS persis 2026; harga Premium in-app per wilayah; apakah batas 2 obat gratis berlaku di AS 2026 (sumber resmi vs pihak ketiga bertentangan).
- Perilaku offline.

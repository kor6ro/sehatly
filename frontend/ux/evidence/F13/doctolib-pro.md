# F13 — Doctolib Pro / Doctolib Médecin (agenda, status janji, teleconsultation)

Titik awal hitungan: **dokter membuka aplikasi di sela pasien → konsultasi dimulai / resep ditulis**.

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://doctolib.zendesk.com/hc/fr/articles/360053383571-Démarrer-et-faire-une-consultation-pour-un-patient-suivi | Help center resmi | Klik kanan janji → "Démarrer la consultation" → Kartu Vitale/skip → status "En consultation"; **hanya versi berbayar**; komputer/akun sama | 2026-10-01 |
| S2 | https://doctolib.zendesk.com/hc/fr/articles/360057956272-Démarrer-et-faire-une-téléconsultation-avec-Doctolib-Médecin | Help center resmi | 3 jalur mulai teleconsult: notifikasi tunggu → Rejoindre; tab video; kartu janji | 2026-10-01 |
| S3 | https://doctolib.zendesk.com/hc/fr/articles/206497403-Utiliser-les-statuts-de-rendez-vous | Help center resmi | Status: En salle d'attente (suara + waktu tunggu), En consultation, Vu; ubah dari fiche/klik kanan/list | 2026-10-01 |
| S4 | https://doctolib.zendesk.com/hc/fr/articles/360019766911-Gérer-la-salle-d-attente-lors-d-une-téléconsultation | Help center resmi | Ruang tunggu virtual: "En attente", sambung-ulang, sesi untuk ditutup/ditagih | 2026-10-01 |
| S5 | https://doctolib.zendesk.com/hc/fr/articles/115002356943-Naviguer-dans-l-agenda-Doctolib | Help center resmi (tangkapan 2026) | Agenda Day/Week(default)/Month + **List view**; filter status/motif; cetak hari | 2026-10-01 |
| S6 | https://doctolib.zendesk.com/hc/fr/articles/4402394446868-Consulter-et-gérer-les-listes-d-attente-patients | Help center resmi | Daftar tunggu: auto-notifikasi 8 pasien pertama yang eligible (aksi massal) | 2026-10-01 |
| S7 | https://community.doctolib.fr/t/les-details-qui-font-la-difference-avril-a-juin-2026/184920 | Komunitas resmi (rilis produk) | "generate summary in 1 click" (AI), template catatan, AI assistant 2026 | 2026-10-01 |
| S8 | https://media.doctolib.com/image/upload/mkg/file/doctolib_corporate_presentation_2025.pdf | Materi korporat resmi | Pasar 2025: Prancis, Jerman, Italia, Belanda | 2026-10-01 |
| S9 | https://play.google.com/store/apps/details?hl=en_US&id=fr.doctolib.pro | Store listing | Doctolib Pro, update **30 Sep 2026** | 2026-10-01 |
| S10 | https://media.doctolib.com/image/upload/mkg/file/impact_report_fr_digital.pdf | Laporan dampak resmi | Penerbitan deklarasi aksesibilitas masih "objective" (belum ada) | 2026-10-01 |
| S11 | https://www.linkedin.com/posts/christopheporteneuve_rejoignez-doctolib-activity-7387507780213170177-4hDi | Komentar publik pihak ketiga | Okt 2025: "Toujours pas de déclaration d'accessibilité ?" | 2026-10-01 |
| S12 | https://www.cbinsights.com/company/siilo | Basis data independen | Akuisisi Siilo (Belanda) Mar 2023 → **masuk, bukan keluar**, pasar Belanda bertahan di 2025–2026 | 2026-10-01 |

**Verifikasi 2026:** aktif — update store 30 Sep 2026 (S9), catatan rilis komunitas 2026 (S7). **Koreksi premis:** klaim "Doctolib keluar dari Belanda/Spanyol 2023" **TIDAK didukung bukti** — Belanda justru lewat akuisisi Siilo 2023 dan masih tercantum sebagai pasar 2025 (S8, S12); Spanyol tidak pernah muncul sebagai pasar Doctolib [S12]. Batas pasar = catatan fakta, bukan penilaian.

## Langkah terlihat (fakta)

1. **Mulai konsultasi tatap muka (3 langkah bernomor)**: klik kanan janji di agenda → **"Démarrer la consultation"** → sisip **Kartu Vitale** atau lewati → status otomatis **"En consultation"** [S1]. Alternatif dari search bar: cari pasien → pilih → Démarrer [S1].
2. **Teleconsultation**: pasien masuk ruang tunggu virtual → notifikasi **"Rejoindre la consultation"** (1 ketukan) ATAU tab Consultation vidéo → Rejoindre (2) ATAU kartu janji → "Démarrer la consultation vidéo" [S2].
3. **Catatan + resep**: setelah Observations Médicales → bagian **Documents → Ordonnance de pharmacie** → tulis → tanda tangan saat billing → cetak/bagikan (dokumen menyebut "applies only to paid versions only") [S1].
4. **Status janji**: En salle d'attente (dengan **suara** + waktu tunggu), En consultation, Vu; bisa diubah dari fiche janji, klik kanan, atau list view [S3].
5. **Template**: "pinned consultation template auto-loads on opening a consultation" (data diingat) [doctolib.zendesk.com artikel konsultasi yang sama, S1].
6. **Aksi massal**: daftar tunggu otomatis memberi tahu 8 pasien pertama yang eligible; auto-isi slot yang kosong [S6].
7. **AI**: "generate summary in 1 click" pada catatan konsultasi [S7].

## Hitungan

Dari **dokter membuka aplikasi** → **konsultasi dimulai** (tatap muka, janji sudah terjadwal):

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | 1 (agenda) | S1, S5 |
| Ketukan | 2–3 (klik kanan janji → Démarrer → Kartu Vitale/lewati) | S1 |
| Field | 0–1 (Kartu Vitale opsional) | S1 |

**Teleconsult**: 1 ketukan dari notifikasi "Rejoindre" [S2]. **Konsultasi → resep**: Documents → Ordonnance → tulis → sign (jumlah langkah presisi **TIDAK TERVERIFIKASI**; fitur versi berbayar) [S1].

## State terlihat

- **Ruang tunggu**: "En attente" (pasien masuk, video belum mulai), daftar sesi tertunda, sambung-ulang setelah putus [S4].
- **Notifikasi suara** menunggu [S3] + waktu tunggu tampil.
- **Offline**: agenda **tidak** mendukung offline (laporan pengguna komunitas 2020; mode offline hanya untuk penagihan, dan harus dimatikan untuk teletransmisi) [doctolib.zendesk.com/hc/fr/articles/19319858632468, akses 2026-10-01].
- **Loading/kosong error**: **TIDAK TERVERIFIKASI**.
- **Aksesibilitas**: **tidak ada deklarasi** — laporan dampak resmi menyebut penerbitannya masih tujuan masa depan [S10]; konfirmasi publik Okt 2025 [S11].

## Red flag

- **Pembayaran fitur inti**: alur "Démarrer la consultation" + resep di dokumentasikan **"applies only to paid versions only"** [S1] — bukan dark pattern tersembunyi (harga transparan di luar produk), tetapi untuk benchmark dicatat: pola tier ini **tidak relevan** untuk Sehatly (satu produk).
- **Kunci komputer sama**: konsultasi harus dimulai & diakhiri di komputer/akun yang sama [S1] — pembatasan alur, dicatat sebagai anti-pola (Sehatly harus boleh dua perangkat dengan guard status).
- **Suara notifikasi menunggu** [S3] — wajar klinis, bukan overload.
- Tidak ditemukan hitung mundur palsu/tombol tersembunyi.

## Yang TIDAK bisa diverifikasi

- Layout agenda/fiche asli (di balik login) dan jumlah ketukan total presisi.
- State loading/kosong.
- Kebenaran "paid versions only" untuk semua wilayah (satu artikel help).
- Ketersediaan mode offline terkini (laporan komunitas 2020, mungkin usang).
- Declarasi aksesibilitas formal (memang tidak ada → tidak bisa diverifikasi tingkat kepatuhannya).

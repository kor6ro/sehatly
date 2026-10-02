# Evidence F11 — SATUSEHAT Mobile (pengingat minum obat)

Tanggal akses semua sumber: **2026-10-02**.

## Sumber

| # | URL | Jenis | Versi/platform | Catatan akses |
|---|---|---|---|---|
| S1 | https://satusehat.kemkes.go.id/mobile/faq/topic?categoryId=9f8d2345-27ba-48a5-8b6d-1bc1c7ecca72 | Kategori FAQ resmi Kemenkes “Pengingat Minum Obat” — 5 pertanyaan | Web resmi | Dibaca penuh 2026-10-02; judul + “Terakhir diperbarui: 30 April 2024” |
| S2 | https://satusehat.kemkes.go.id/mobile/faq/topic/20298568-7895-4b0f-89b2-6e20d8a41c15 | FAQ resmi “Bagaimana cara mengaktifkan fitur…” | Web resmi | Halaman terbuka; **isi jawaban dirender JS (“Loading...”)**; hanya judul + tanggal update yang terbaca |
| S3 | https://satusehat.kemkes.go.id/mobile/faq/topic/70feec30-382e-4c11-99e0-5d1870d6387e | FAQ resmi “Kapan pengguna menerima Pengingat Minum Obat?” | Web resmi | Indeks pencarian 2026-10-02; isi JS |
| S4 | https://kemkes.go.id/id/satusehat-dikembangkan-langsung-oleh-kemenkes | Rilis resmi Kemenkes, 31 Okt 2024: SATUSEHAT Mobile punya fitur “pengingat minum obat” | Situs pemerintah | Indeks pencarian 2026-10-02 |
| S5 | https://www.liputan6.com/health/read/5361647/3-fitur-baru-di-satusehat-mobile-bak-asisten-pribadi-ada-pengingat-minum-obat | Liputan6 (media independen), 4 Agu 2023 — detail fitur versi 5.7.1 | Media | Indeks pencarian 2026-10-02 |
| S6 | https://voi.id/berita/292671/minum-obat-diingatkan-platform-satusehat-tambahkan-fitur-asisten-pribadi | VOI (media independen), 10 Jul 2023, mengutip ANTARA — detail fitur | Media | Indeks pencarian 2026-10-02 |
| S7 | https://play.google.com/store/apps/details?id=com.telkom.tracencare&hl=en | Listing Google Play — **versi 8.9.1 diperbarui 27 Agu 2026**; catatan versi 8.8.2 (Jun 2026): aktivasi pengingat minum obat untuk resep Cek Kesehatan Gratis (CKG) | Android | Indeks pencarian 2026-10-02 |
| S8 | https://www.apkmirror.com/apk/ministry-of-health-republic-of-indonesia/pedulilindungi/satusehat-mobile-8-9-0-release/ | APKMirror — 8.9.0 (13 Agu 2026); riwayat rilis bulanan Feb–Agu 2026, minimum Android 8.0 | Android (mirror pihak ketiga) | Indeks pencarian 2026-10-02 |
| S9 | https://govinsider.asia/indo-en/article/satusehat-mobile-akan-didukung-fitur-asisten-kesehatan-berbasis-ai | GovInsider — rencana fitur asisten AI 2026 dengan “notifikasi, pengingat, dan rekomendasi” | Media | Indeks pencarian 2026-10-02; **roadmap, bukan fitur terkirim** |
| S10 | https://apps.apple.com/it/app/satusehat-mobile/id1504600374 | App Store listing | iOS | Indeks pencarian 2026-10-02 |

## Langkah terlihat (fakta)

1. **Ada pengingat terjadwal berbasis notifikasi ponsel**: “memberikan notifikasi terjadwal pada ponsel” (S5, S6).
2. **Field saat membuat pengingat** (dinyatakan pers resmi, bukan screenshot): jenis obat, **dosis**, **tanggal mulai**, **lama konsumsi**, **jumlah konsumsi harian**, **waktu pengingat**, dan **aturan minum obat** (S5, S6) → minimal **7 field**.
3. **Izin notifikasi wajib**: “Berikan akses notifikasi ke SATUSEHAT Mobile untuk mendapatkan pengingat minum obat yang telah dibuat” (S5, S6); “Berikan akses notifikasi … untuk mendapatkan pengingat” (S6).
4. **Terintegrasi master data nasional**: “Kamus Farmasi dan Alat Kesehatan Kemenkes RI”, sehingga pengguna memilih obat terdaftar (S5, S6); rilis Kemenkes 2024 mengonfirmasi fitur ini sebagai fitur resmi aplikasi (S4); GovInsider 2026 menyebut integrasi master data obat nasional (S9).
5. **Bisa lebih dari satu obat**: “Pengguna juga dapat menambahkan lebih dari satu jenis obat” (S5, S6).
6. **Masih aktif dan dikembangkan 2026**: versi 8.9.1 (27 Agu 2026, S7); versi 8.8.2 (Jun 2026) menambahkan jalan pintas aktivasi pengingat obat dari resep CKG (S7); rilis bulanan konsisten Feb–Agu 2026 (S8).
7. **Kapan pengingat diterima** dan **siapa yang bisa memakai** adalah topik FAQ resmi (S1/S3), tetapi isi jawaban tidak terbaca (JS) → langkah detail TIDAK TERVERIFIKASI.

## Hitungan (titik awal sama)

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | **TIDAK TERVERIFIKASI** | tidak ada hitungan langkah resmi yang terbaca |
| Ketukan | **TIDAK TERVERIFIKASI** | isi FAQ JS |
| Field | **≥ 7** (obat, dosis, tanggal mulai, lama, jumlah harian, waktu, aturan) | S5, S6 (pernyataan resmi yang dikutip media) |

## State terlihat

- **Sukses (terjadwal):** notifikasi pengingat pada ponsel (S5, S6). ✓
- **Izin ditolak:** implisit dari kalimat “berikan akses notifikasi … untuk mendapatkan pengingat” — tanpa izin, pengingat tidak sampai; UI penjelasan **TIDAK TERVERIFIKASI**.
- Loading/kosong/error/offline, snooze, tandai sudah diminum: **tidak ada sumber** → N/V.

## Red flag

- Tidak ditemukan dark pattern notifikasi pada sumber F11 yang diakses.
- Catatan dari F10 (`web/ux/evidence/F10/satusehat-mobile.md`, 2026-10-01): kebijakan platform menyatakan pengiriman data kesehatan **by default** dengan akses BPJS/Dinas — ini isu kebijakan privasi platform, bukan pola UI; tidak boleh disalin dan wajib dihindari Sehatly (consent eksplisit F02).

## Yang TIDAK bisa diverifikasi

- Langkah persis mengaktifkan/mengubah/menghapus pengingat; susunan layar; apakah ada halaman daftar pengingat.
- Snooze, riwayat kepatuhan, pengingat untuk anggota keluarga.
- Preferensi kanal, jam tenang, status pengiriman.
- Apakah nama obat tampil di layar kunci (tidak dibahas di sumber mana pun).
- Isi badan FAQ (JS) — lima pertanyaan resmi hanya terbaca judulnya.
- Aksesibilitas UI.

## Independensi sumber

- Penerbit resmi: satusehat.kemkes.go.id + kemkes.go.id + listing Play/App Store (satu organisasi Kemenkes).
- Penerbit independen: Liputan6 (S5), VOI (S6, mengutip ANTARA), GovInsider (S9).
→ ≥2 penerbit independen **terpenuhi**; yang gagal adalah **bobot terverifikasi** (lihat `scores/F11.md`), bukan independensi.

## Aktivitas 2026 (verifikasi kandidat)

Aktif. Rilis 8.9.1 (27 Agu 2026) dan 8.8.2 (Jun 2026) dari Play (S7); riwayat APKMirror sampai Agu 2026 (S8). Bukan aplikasi mati/berganti nama.

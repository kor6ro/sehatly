# Evidence F11 — NHS App (notifikasi + kotak masuk pesan)

Tanggal akses semua sumber: **2026-10-02**. Aplikasi native tidak dapat diakses langsung; fakta dari dokumentasi publik.

## Sumber

| # | URL | Jenis | Versi/platform | Catatan akses |
|---|---|---|---|---|
| S1 | https://www.nhs.uk/nhs-app/help/profile/managing-notifications/ | Halaman bantuan resmi NHS — “Managing notifications” | Web; halaman ditinjau 22 Des 2025; media ditinjau 24 Mar 2026 | Dibaca penuh 2026-10-02 |
| S2 | https://www.nhs.uk/nhs-app/help/messages/ | Halaman bantuan resmi NHS — “Messages” (kelola pesan) | Web; ditinjau 22 Des 2025 | Dibaca penuh 2026-10-02 |
| S3 | https://digital.nhs.uk/services/nhs-app/nhs-app-features/notifications-and-messaging-in-the-nhs-app | Halaman fitur resmi NHS England Digital | Web; dipublikasikan/diperbarui **14 Sep 2026** | Fetch langsung **HTTP 403**; isi via indeks pencarian URL kanonik 2026-10-02 |
| S4 | https://practice365.co.uk/uploads/sites/1918/2026/04/How-to-enable-Notifications-NHS-APP-Android.pdf | Panduan PDF pihak ketiga (penyedia layanan praktik GP), Apr 2026 | Android | Indeks pencarian 2026-10-02 |
| S5 | https://www.phghdoctors.nhs.uk/2025/11/17/how-to-turn-on-notifications-on-your-nhs-app/ | Panduan praktik GP (penerbit berbeda), 17 Nov 2025 | Android/iOS | Indeks pencarian 2026-10-02 |
| S6 | https://www.nhs.uk/nhs-app/about/privacy-legal-information/nhs-app-accessibility-statement/ | Pernyataan aksesibilitas NHS App **versi 10.1**, ditinjau 22 Des 2025; diuji WCAG 2.2 AA Okt 2025 oleh Dig Inclusion, **35 layar**, iOS/Android/browser | Web | Dibaca penuh 2026-10-02 |
| S7 | https://www.youtube.com/watch?v=R8otAic3xHY | Video resmi NHS (kanal NHS), 2024 | Video | Indeks pencarian 2026-10-02 |

## Langkah terlihat (fakta)

**Menyalakan notifikasi (S1, diperkuat S4, S5, S7):**
1. Layar utama → **Profil**.
2. → **Notifications**.
3. → tautan **ke pengaturan perangkat** (OS), lalu kembali dan **buka ulang aplikasi** untuk mengonfirmasi registrasi (S5/S7).
   - Perubahan “may take up to 24 hours to take effect” (S1). Jika multi-perangkat: izin harus diberikan **di tiap perangkat** (S1).
   - Notifikasi **tidak** datang bila memakai layanan NHS App lewat situs NHS, memasang aplikasi di *private space* Android, atau menyembunyikan aplikasi di iOS (S1).
   - Perangkat bersama: “anyone using the NHS App on that device may see your notifications” (S1).

**Mengelola kotak masuk pesan (S2):**
1. Menu bawah → **Messages** (atau “View your messages” dari beranda) — 2 ketukan dari app terbuka.
2. **Filter** berdasarkan **Status atau Sender**.
3. Per pesan: **Flag/Unflag**; **Remove message** + konfirmasi — dan **tidak bisa menghapus permanen**; pesan yang dihapus bisa dipulihkan lewat **Removed messages → Restore message** + konfirmasi.
4. Bisa ada **beberapa kotak masuk** (mis. PKB): pesan baru masuk ke “Your messages” (S2).
5. Notice tetap: “Messaging is for non-urgent advice”; arahan ke 111/GP untuk kebutuhan mendesak (S2).

**Perilaku notifikasi (S3, via indeks):** pasien mendapat notifikasi saat pesan masuk **asalkan** notifikasi menyala; preferensi bisa sampai 24 jam; bila lebih dari satu pasien login di perangkat yang sama, notifikasi hanya untuk satu akun NHS dan dapat membingungkan.

**Aksesibilitas (S6):** pernyataan resmi — **patuh sebagian** terhadap regulasi aksesibilitas; diuji WCAG 2.2 AA pada Okt 2025 (35 layar, tiga platform, penguji eksternal Dig Inclusion); daftar isu diketahui: label ARIA tombol/link tidak selalu tepat, komponen daftar (mis. janji temu) tidak selalu memakai tag list, beberapa masalah kontras, teks iOS tidak ikut resize; dua isu iOS diakui sebagai *disproportionate burden*. Tidak ada klaim palsu “fully accessible”.

## Hitungan (titik awal sama)

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | Baca 1 pesan: buka app → Messages → pilih pesan = **1 layar + 2 ketukan**; menyalakan push = **3 langkah di app** + pengaturan OS + buka ulang (tersebar dua sistem) | S1, S2, S4, S5 |
| Ketukan | 2 (baca pesan); ≥4 untuk opt-in push (Profil → Notifications → tautan OS → izin) | S1, S2 |
| Field | 0 (hanya toggle/izin) | S1 |
| Latensi diketahui | perubahan preferensi **hingga 24 jam** | S1, S3 |

## State terlihat

- **Sukses:** pesan tampil + notifikasi saat pesan baru (S2, S3). ✓
- **Gagal/parsial terdokumentasi:** daftar kondisi “When you will not get notifications” (login via web, private space, app disembunyikan) — pengguna diberi tahu alasannya (S1). ✓
- **Pemulihan:** pesan terhapus dapat di-restore; tidak ada penghapusan permanen (S2). ✓
- **Latensi:** 24 jam sampai preferensi berlaku (S1) — state menunggu yang jujur diumumkan.
- **Multi-akun satu perangkat:** notifikasi bisa milik akun lain (S3) — kondisi membingungkan yang diakui.
- Loading/kosong/error jaringan: **tidak didokumentasikan** → N/V.

## Red flag

- Tidak ditemukan dark pattern. Justru kebalikannya: batas (tidak bisa hapus permanen), kondisi tidak-dapat-notifikasi, dan peringatan perangkat bersama dinyatakan terbuka.
- Catatan yang **tidak boleh disalin**: latensi 24 jam dan pemisahan opt-in app↔OS sebagai pengalaman utama; Sehatly harus berlaku segera atau memberi status “menunggu izin perangkat”.

## Yang TIDAK bisa diverifikasi

- Tampilan UI native (warna, hierarki, target sentuh) — tidak ada screenshot resmi yang dikutip; aplikasi tidak dipasang.
- Apakah isi pesan tampil di layar kunci (privasi preview) — **tidak didokumentasikan** di halaman yang diakses.
- Pengingat janji temu / pengingat terjadwal: **tidak ada** di halaman yang diakses → untuk F11, NHS App hanya menjadi bukti pola **kotak masuk + preferensi notifikasi**, bukan pengingat.
- Jam tenang, status pengiriman per notifikasi, snooze.

## Independensi sumber

- NHS England: nhs.uk (S1, S2, S6), digital.nhs.uk (S3), kanal YouTube NHS (S7) → **1 organisasi penerbit**.
- Pihak ketiga: practice365.co.uk (S4, vendor layanan praktik) dan phghdoctors.nhs.uk (S5, praktik GP) → 2 penerbit terpisah (mengikuti konvensi `scores/F10.md` yang menghitung panduan faskes sebagai penerbit independen).
→ Syarat ≥2 sumber independen **terpenuhi** (NHS England + 2 panduan pihak ketiga).

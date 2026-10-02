# Evidence F11 — Medisafe (pengingat obat, preferensi, privasi notifikasi)

Tanggal akses semua sumber: **2026-10-02**.

## Sumber

| # | URL | Jenis | Versi/platform | Catatan akses |
|---|---|---|---|---|
| S1 | https://help-center.medisafe.com/en/collections/5306026-medication-reminders-notifications | Pusat bantuan resmi — koleksi “Medication Reminders & Notifications” (9 artikel) | Web | Dibaca penuh 2026-10-02 |
| S2 | https://help-center.medisafe.com/en/articles/8098013-how-do-i-change-the-snooze-interval-for-my-meds | Artikel resmi — snooze (16 Apr 2025) | Android/iOS | Dibaca penuh 2026-10-02 |
| S3 | https://help-center.medisafe.com/en/articles/8103246-can-i-see-the-name-of-my-medications-with-my-notifications | Artikel resmi — “Show med names” (16 Apr 2025) | Android/iOS | Dibaca penuh 2026-10-02 |
| S4 | https://help-center.medisafe.com/en/articles/8098169-how-can-i-get-my-med-reminders-later-on-the-weekend | Artikel resmi — Weekend Mode (16 Apr 2025) | Android (iOS tidak ada) | Dibaca penuh 2026-10-02 |
| S5 | https://help-center.medisafe.com/en/articles/8102964-how-do-i-stop-medisafe-from-sending-medication-reminders | Artikel resmi — menghentikan pengingat (22 Agu 2023) | Android/iOS | Dibaca penuh 2026-10-02 |
| S6 | https://help-center.medisafe.com/en/articles/8098031-can-i-turn-off-the-med-reminder-popup-that-appears-in-the-app | Artikel resmi — popup in-app (16 Apr 2025) | Android (iOS tidak ada) | Dibaca penuh 2026-10-02 |
| S7 | https://pmc.ncbi.nlm.nih.gov/articles/PMC11751649/ | Studi peer-reviewed (PMC), 22 Des 2024 — survei kepuasan pengguna Medisafe (n=30, populasi kurang terlayani) | Penelitian independen | Indeks pencarian 2026-10-02 |
| S8 | https://www.minimalistjourneys.com/medisafe-app-review/ | Review independen, diperbarui 15 Des 2024 (penulis menerima langganan premium; disclosure) | Review | Indeks pencarian 2026-10-02 |
| S9 | https://apps.apple.com/us/app/medisafe-medication-management/id573916946 | App Store listing — versi 9.52.1, © 2026 (aktif) | iOS | Indeks pencarian 2026-10-02 |
| S10 | https://apps.apple.com/ca/app/medisafe-pill-reminder/id573916946?platform=iphone&see-all=reviews | Ulasan pengguna App Store (Des 2025–2026) | iOS | Indeks pencarian 2026-10-02 |

## Langkah terlihat (fakta)

**Snooze (S2):** default **10 menit** bila notifikasi diabaikan tanpa aksi; mengubah interval = Manage → App Settings → General Settings → Medication Reminders → Snooze Duration (5 langkah). Ada opsi Snooze “until home/work” (S1, S8).

**Nama obat di notifikasi (S3):** fitur **“Show med names”**; Android: Manage → App Settings → General Settings → Medication Reminders → centang “Show med names” (4 langkah); iOS: Manage → App Settings → toggle “Show My Meds”. Artinya **default = nama obat TIDAK tampil**; untuk melihat nama, pengguna menekan-lama notifikasi (S3).

**Weekend Mode (S4):** Android: Manage → App Settings → General Settings → Weekend Mode → pilih hari + jam pagi akhir pekan (5 langkah). **Tidak tersedia di iOS** (dinyatakan terbuka).

**Menghentikan pengingat (S5):** **tidak ada tombol stop global di aplikasi**; pilihan: matikan notifikasi di pengaturan OS (app terus menjadwalkan dosis; popup in-app tetap muncul) atau **Suspend** per obat (Medications → med → Edit → Suspend). Obat “as needed” tidak bisa di-suspend. Peringatan dini: mematikan notifikasi tidak mematikan penjadwalan.

**Popup in-app (S6):** bisa dimatikan di Android; **tidak bisa** di iOS (dinyatakan terbuka).

**Aksi dari notifikasi (S8, review independen):** dari pengingat pengguna dapat **take / skip / snooze**; notifikasi dapat disetel “requires action before I can use my other phone functions”. Artikel resmi “Can I mark a dose from the notification?” ada di koleksi (S1), tetapi badan artikel tidak berhasil dibuka dari riset ini (URL tebakan 404) → detail resmi **TIDAK TERVERIFIKASI**; fakta dari review independen.

**Bukti hasil (S7):** survei peer-reviewed 30 partisipan: 93% setuju pengingat membantu minum obat pada waktu yang benar; 93% merasa aplikasi mudah dipakai; 100% akan merekomendasikan. Ini bukti *persepsi kegunaan*, bukan pengukuran UI.

**Aktivitas 2026 (S9):** versi 9.52.1, © 2026 → aktif.

## Hitungan (titik awal sama)

| Tugas | Layar/ketukan | Field | Dasar |
|---|---|---|---|
| Tandai dosis dari notifikasi | 1 aksi (take/skip/snooze) | 0 | S8 |
| Ubah snooze | 5 langkah | 1 pilihan interval | S2 |
| Nyalakan nama obat di notifikasi | 4 langkah (Android) | 1 centang | S3 |
| Weekend mode | 5 langkah | hari + jam | S4 |
| Stop pengingat (global) | **tidak tersedia in-app**; OS/`Suspend` per obat | — | S5 |

## State terlihat

- **Pengingat muncul:** normal (S1, S2). ✓
- **Tidak direspons:** snooze otomatis 10 menit (S2). ✓
- **Dosis terlewat:** notifikasi ke **Medfriend**; review independen mencatat keterlambatan hingga ~4 jam (S8). ✓ (dengan risiko)
- **Badge ikon aplikasi:** hilang begitu aplikasi dibuka **tanpa aksi apa pun**; tidak bisa diubah (artikel resmi 9145944, via indeks) → state “sudah dilihat” tidak sama dengan “sudah diminum”.
- **Mati notifikasi:** penjadwalan tetap jalan, popup in-app tetap muncul (S5).
- Loading/kosong/error jaringan: tidak didokumentasikan → N/V.

## Red flag (WAJIB: tidak boleh ditiru)

1. **Paywall pada fungsi keselamatan obat.** Ulasan App Store (Des 2025–2026, S10) melaporkan fungsi yang dahulu gratis (mis. >2 obat) kini di balik langganan ~US$6,99/bulan; pengguna jangka panjang kehilangan akses ke pengingat tanpa peringatan di awal. Ini kategori rubrik “biaya baru muncul setelah pengguna sudah berkomitmen”. **Medisafe tidak boleh dipakai sebagai pola utuh**; hanya mekanisme spesifik (snooze, show-med-names default mati, weekend mode) yang boleh dipelajari, dengan paywall **ditolak eksplisit**.
2. Ulasan independen mencatat: tidak bisa mematikan jenis notifikasi tertentu (mis. laporan ringkasan) dan tidak ada pencarian di FAQ (S8).

## Yang TIDAK bisa diverifikasi

- Badan artikel resmi “Can I mark a dose from the notification?” (S1 hanya judul).
- Jumlah layar total pembuatan pengingat pertama; tampilan UI.
- Status pengiriman (sent/delivered/failed) per notifikasi; **tidak didokumentasikan**.
- Jam tenang sebenarnya (Weekend Mode ≠ jam tenang; tidak ada quiet hours terdokumentasi).
- Aksesibilitas UI.

## Independensi sumber

- Resmi: help-center.medisafe.com (satu penerbit). Listing App Store diterbitkan Medisafe (S9).
- Independen: studi PMC (S7) dan review Minimalist Journeys (S8), ulasan pengguna App Store (S10).
→ ≥2 sumber independen **terpenuhi** untuk klaim terpilih (snooze/show names/stop reminders), tetapi **red flag paywall mendiskualifikasi Medisafe sebagai pemenang** sesuai aturan rubrik.

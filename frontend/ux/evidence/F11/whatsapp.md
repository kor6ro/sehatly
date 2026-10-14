# Evidence F11 — WhatsApp (status pengiriman + privasi pratinjau notifikasi)

Tanggal akses semua sumber: **2026-10-02**.

## Sumber

| # | URL | Jenis | Versi/platform | Catatan akses |
|---|---|---|---|---|
| S1 | https://faq.whatsapp.com/797069521522888/ | Pusat bantuan resmi — “How to manage your notifications” (Message notifications, **Show previews**, reaction notifications, background sync, sounds; mute per chat) | Web/Android/iOS | Fetch langsung **HTTP 400**; isi via indeks pencarian URL kanonik 2026-10-02 |
| S2 | https://faq.whatsapp.com/665923838265756/ | Pusat bantuan resmi — “How to check read receipts” (centang sent/delivered/read; ikon jam; message info; read receipts bisa dimatikan dua arah) | Web/Android | Indeks pencarian 2026-10-02 |
| S3 | https://faq.whatsapp.com/1313491802751163/ | Pusat bantuan resmi — “How to stay safe on WhatsApp” (read receipts & privasi; pratinjau tautan mencurigakan disembunyikan) | Web | Indeks pencarian 2026-10-02 |
| S4 | https://www.indianexpress.com/article/technology/techook/how-to-disable-whatsapp-message-preview-on-lock-screen-android-ios/ | Media independen (Indian Express), 4 Des 2020 — langkah mematikan pratinjau; iOS mengganti isi dengan “Message” | iOS/Android | Indeks pencarian 2026-10-02 |
| S5 | https://www.digitalcitizen.life/how-to-hide-the-contents-sensitive-notifications-android/ | Panduan independen, 31 Mei 2024 — menyembunyikan isi notifikasi di layar kunci Android (per aplikasi, “Show sensitive content only when unlocked”) | Android 14 | Indeks pencarian 2026-10-02 |
| S6 | https://play.google.com/store/apps/details?id=com.whatsapp | Listing Google Play (aplikasi aktif 2026) | Android | Indeks pencarian 2026-10-02 |

## Langkah terlihat (fakta)

**Preferensi notifikasi (S1):**
- Semua setelan notifikasi pesan/panggilan ada di **WhatsApp Settings**, bukan tersembunyi di menu pengaturan perangkat saja.
- Opsi yang terdokumentasi: **Message notifications** (on/off), **Show previews** (menampilkan isi teks di notifikasi), **Show reaction notifications**, **Background sync**, **Incoming sounds**, **Outgoing sounds**.
- Ada jalur terpisah untuk **mute notifikasi per chat/grup** (dinyatakan di artikel yang sama).
- Catatan resmi: setelan perangkat **menimpa** setelan WhatsApp; bila pengguna membuat notifikasi kustom per chat, sistem membuat kategori notifikasi baru di setelan perangkat.

**Pratinjau isi & privasi (S4, S5):**
- iOS: WhatsApp → Settings → Notifications → **Show Preview** dimatikan; notifikasi hanya menampilkan nama pengirim + label **“Message”** (S4).
- Android: isi di layar kunci dikontrol di setelan **perangkat** per aplikasi/kategori (“Hide content”, “Show sensitive content only when unlocked”) (S5).
- Pratinjau untuk pesan dengan **tautan mencurigakan** bisa tetap disembunyikan meski pratinjau menyala (S3).

**Status pengiriman (S2):**
- **Satu centang** = pesan berhasil dikirim dari perangkat.
- **Dua centang abu** = terkirim ke perangkat penerima (atau salah satu perangkat tertautnya).
- **Dua centang biru** = penerima sudah membaca.
- **Ikon jam** = belum terkirim/diterima, bisa karena masalah koneksi.
- **Message info** menampilkan Delivered vs Read/Seen per pesan.
- Read receipt bisa dimatikan (dua arah); grup selalu mengirim read receipt; kondisi “tidak ada centang biru” didaftarkan lengkap (dimatikan, diblokir, belum dibuka, masalah koneksi, penerima pertama kali, jam perangkat salah).

## Hitungan (titik awal sama)

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | Setelan notifikasi: Settings → Notifications = **2 langkah**; tidak ada layar tambahan untuk status | S1 |
| Ketukan | Baca notifikasi = buka chat (1); **status terkirim/dibaca muncul otomatis tanpa ketukan**; mute per chat ≥2 ketukan | S1, S2 |
| Field | 0 field teks; 6 toggle terdokumentasi | S1 |

Hitungan diturunkan dari dokumentasi, bukan uji langsung (tanpa pemasangan aplikasi).

## State terlihat

- **Sukses berjenjang:** sent → delivered → read, dengan legenda eksplisit (S2). ✓
- **Pending/gagal:** ikon jam + penjelasan koneksi; halaman pemecahan masalah “tidak bisa kirim/terima” ada (S2). ✓
- **Tidak ada centang biru:** enam penyebab didaftarkan (S2). ✓
- Loading/kosong: tidak berlaku → N/V.
- Offline: jam + “Waiting for network” (dari `evidence/F08/whatsapp.md`, 2026-10-01, sumber resmi yang sama). ✓

## Red flag

- Tidak ditemukan dark pattern pada sumber yang diakses.
- Catatan penting untuk adaptasi: **“online” ≠ “dibaca”** ditegaskan FAQ (S2) — Sehatly harus memisahkan status kirim dan status baca.
- Privasi: pratinjau isi **menyala secara default**; pengguna harus mematikannya. Untuk data medis, Sehatly harus **default aman** (isi generik), bukan mengandalkan pengguna mematikan pratinjau (jangan meniru default WhatsApp; lihat Medisafe “Show med names” default mati).

## Yang TIDAK bisa diverifikasi

- Tampilan UI, aksesibilitas, ukuran target, kontras.
- Jam tenang: WhatsApp **tidak** mendokumentasikan quiet hours → TIDAK TERVERIFIKASI (jangan diklaim).
- Konteks Indonesia: dokumentasi seluruhnya Inggris; tidak ada bukti desain untuk jaringan rendah (walaupun perilaku offline terdokumentasi).
- Status “failed” permanen vs pending: FAQ hanya menyatakan ikon jam + penyebab koneksi, tidak ada state “gagal” terminal → TIDAK TERVERIFIKASI.

## Independensi sumber

Resmi WhatsApp/Meta (S1–S3, S6) + media independen Indian Express (S4) + Digital Citizen (S5) → **≥2 penerbit independen terpenuhi** (mengikuti konvensi `scores/F08.md`).

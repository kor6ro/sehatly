# Evidence F11 — Android (SUMBER POLA: kanal notifikasi, izin, DND)

**Bukan kandidat aplikasi yang dinilai.** Dokumentasi platform untuk memahami batas yang diwarisi aplikasi kesehatan apa pun. Tanggal akses: **2026-10-02** (via indeks pencarian; sebagian halaman juga tersedia langsung).

## Sumber

| # | URL | Jenis | Isi |
|---|---|---|---|
| S1 | https://developer.android.com/develop/ui/compose/notifications/channels | Dokumentasi resmi Android | **Semua notifikasi wajib punya channel** sejak Android 8.0 (API 26); perilaku visual/auditori per channel; **pengguna** yang memegang kendali setelah dibuat; aplikasi tidak bisa mengubah level importance secara programatik; sediakan jalan dari pengaturan aplikasi ke setelan channel sistem (`ACTION_CHANNEL_NOTIFICATION_SETTINGS`) |
| S2 | https://developer.android.com/develop/ui/compose/notifications/notification-permission | Dokumentasi resmi Android | **POST_NOTIFICATIONS** (Android 13+): model **opt-in**; aplikasi baru → notifikasi mati sampai diminta; upgrade → pre-grant bila sudah punya channel dan tidak dinonaktifkan; pengguna dapat mencabut kapan saja; sistem **menampilkan jumlah notifikasi harian** aplikasi ke pengguna; metode `areNotificationsEnabled()` untuk memeriksa |
| S3 | https://developer.android.com/develop/ui/views/notifications/time-sensitive | Dokumentasi resmi Android | Metadata DND: `addPerson()` / `setCategory(Notification.CATEGORY_MESSAGE)` dapat **menembus Do Not Disturb**; channel punya `setBypassDnd()` (butuh akses kebijakan DND) |
| S4 | https://source.android.com/docs/core/display/notification-perm | Dokumentasi AOSP | Alasan kebijakan: opt-in mengurangi banjir notifikasi dan memberi kontrol ke pengguna |

## Fakta yang dipakai

1. Izin notifikasi adalah **milik OS, bukan aplikasi**: pengguna dapat mencabutnya kapan saja; aplikasi harus memeriksa status sebelum mengirim (S2).
2. Kanal notifikasi adalah **kontrak dengan pengguna**: setelah dibuat, hanya pengguna yang bisa mengubah perilakunya; aplikasi hanya bisa menyediakan jalan pintas ke setelan sistem (S1).
3. Aplikasi harus **menghormati DND** dan hanya menembusnya untuk kategori yang benar-benar mendesak, dengan metadata eksplisit (S3). Di Android 13+, pengguna melihat **berapa notifikasi per hari** yang dikirim aplikasi (S2).
4. Preferensi in-app tidak menggantikan izin OS, dan sebaliknya: keduanya bisa berbeda; UI harus menampilkan **status izin OS yang sebenarnya** (mis. lewat `areNotificationsEnabled()`) alih-alih mengasumsikan (S2).

## Relevansi untuk F11 Sehatly

- Karena Sehatly web (SPA) tidak mengontrol kanal OS, pola web harus: (a) memakai **preferensi in-app** sebagai sumber kebenaran untuk frekuensi/jenis; (b) menampilkan **status izin browser/perangkat** apa adanya (tidak bisa dipastikan dari server); (c) tidak menjanjikan “pasti sampai”.
- Matriks preferensi per tipe (booking/pembayaran/resep/chat) meniru **channel** Android di level aplikasi: satu jenis = satu keputusan yang bisa dimatikan tanpa mematikan semuanya (S1).
- Untuk FCM mobile (backend sudah ada), channel `booking`, `pembayaran`, `resep`, `chat` harus dibuat di aplikasi mobile (di luar repo ini), dan **tidak boleh** ada channel untuk `lab`/`promo` selama tidak ada produser (`NotifikasiTipe::nilaiYangDipakai`).
- Pesan konsultasi adalah kategori yang di Android lazim menembus DND (CATEGORY_MESSAGE, S3) — keputusan produk: apakah pesan dokter menembus jam tenang pasien? (lihat Pertanyaan terbuka pattern).

## Red flag

Tidak berlaku.

## Yang TIDAK bisa diverifikasi

- Perilaku konkret di perangkat/OS Indonesia tertentu (Xiaomi/OPPO/vivo) yang mengubah manajemen notifikasi (autostart, battery optimization) — tidak ada sumber resmi yang dikutip di sesi ini.

# Evidence F11 — Google Calendar (pengingat terjadwal + preferensi per item)

Tanggal akses semua sumber: **2026-10-02**.

## Sumber

| # | URL | Jenis | Versi/platform | Catatan akses |
|---|---|---|---|---|
| S1 | https://support.google.com/calendar/answer/37242?co=GENIE.Platform%3DDesktop&hl=en | Bantuan resmi “Change Google Calendar notifications” (desktop) — ©2026 | Web | Dibaca penuh 2026-10-02 |
| S2 | https://support.google.com/calendar/answer/37242?co=GENIE.Platform%3DAndroid&hl=en | Versi Android dari halaman yang sama | Android | Indeks pencarian 2026-10-02 |
| S3 | https://developers.google.com/workspace/calendar/api/concepts/reminders | Dokumentasi API resmi — model reminder (default per kalender vs override per event; metode popup/email) | Web/dev | Indeks pencarian 2026-10-02 |
| S4 | https://developers.google.com/workspace/calendar/api/v3/reference/calendarList | Referensi API — `defaultReminders[]` (0–40320 menit), `notificationSettings` per tipe (eventCreation/eventChange/eventCancellation/eventResponse/agenda) | Web/dev | Indeks pencarian 2026-10-02 |
| S5 | https://support.google.com/calendar/answer/12200012 | Bantuan resmi “Troubleshoot missing Google Calendar notifications” | Web | Indeks pencarian 2026-10-02 |

## Langkah terlihat (fakta)

1. Notifikasi bisa lewat **tiga kanal**: ponsel/aplikasi, **desktop notification** (browser), dan **email** (S1). Pengguna memilih kanal per notifikasi.
2. **Tiga tingkat pengaturan**: (a) global — Settings → **Notification settings** (2 klik) dengan pilihan Off/Desktop notifications/Alerts, suara, “Show snoozed notifications”, dan opsi “Notify me only if I have responded Yes or Maybe”; (b) **per kalender** — “Event notifications” dan “All-day event notifications”, tiap baris punya **metode** (notification/email) + **waktu sebelumnya**, bisa tambah/hapus; (c) **per event** — override di layar edit event, tombol Add notification/Remove (S1).
3. **Model data yang eksplisit** (S3): reminder bisa **default per kalender** dan **override per event**; `useDefault=false` + daftar override; reminder bersifat **privat per pengguna** (tidak dibagikan ke peserta lain); pengaturan per kalender disimpan di koleksi `CalendarList` milik pengguna.
4. **Rentang waktu** pengingat: 0–40320 menit (4 minggu) sebelum acara (S4).
5. **Jenis notifikasi email per kategori** (S4): eventCreation, eventChange, eventCancellation, eventResponse, agenda (agenda pagi hari) — hanya email; popup hanya untuk reminder.
6. **Pemulihan terdokumentasi** (S5): bila notifikasi tidak muncul → cek setelan calendar + izin notifikasi browser; langkah per Chrome/Firefox/Safari/Edge.
7. Prinsip yang dinyatakan: “Your notification settings are **personal** and apply only to your account”; “No one else can change your notification settings”; perubahan orang lain tidak memengaruhi notifikasi Anda (S1).

## Hitungan (titik awal sama)

| Tugas | Layar/ketukan | Field | Dasar |
|---|---|---|---|
| Ubah preferensi global | 2 klik → pilih opsi | 1 dropdown + opsi | S1 |
| Atur default satu kalender | ±3–4 langkah (Settings → pilih kalender → event notifications → pilih/tambah) | metode + waktu | S1 |
| Override satu event | buka event → Edit → Notifications → ubah/tambah → Simpan | metode + waktu (bisa >1) | S1 |
| Snooze | tombol Snooze pada notifikasi terakhir (Chrome) — “Show snoozed notifications” custom | waktu snooze | S1 |

## State terlihat

- **Sukses:** notifikasi/email sesuai setelan (S1). ✓
- **Gagal:** halaman khusus troubleshooting notifikasi hilang + langkah verifikasi izin browser (S5). ✓
- **Multi-kanal:** email tetap dikirim walau tidak membuka Calendar (S1). ✓
- Loading/kosong/offline: tidak berlaku/tidak didokumentasikan → N/V.

## Red flag

- Tidak ditemukan dark pattern.
- **Tidak boleh ditiru**: tiga tingkat pengaturan (global → kalender → event) terlalu berat untuk pasien lansia; Sehatly memakai **dua tingkat** (default per jenis + override per item).

## Yang TIDAK bisa diverifikasi

- Tampilan UI (screenshot tidak dikutip; halaman bantuan berbasis teks + gambar yang tidak diekstrak).
- Aksesibilitas pengukuran (kontras/target/pembaca layar) — ada halaman “Use Google Calendar with a screen reader” tetapi tidak diukur di riset ini → N/V.
- Jam tenang (Google Calendar tidak mendokumentasikan quiet hours; “working hours” hanya info, bukan penahan notifikasi) → **TIDAK TERVERIFIKASI**.
- Status pengiriman per notifikasi (sent/delivered/failed) → tidak ada.
- Konteks Indonesia: antarmuka tersedia dalam Bahasa Indonesia, tetapi tidak ada bukti desain untuk kondisi jaringan/Android kelas bawah.

## Independensi sumber

Seluruh sumber adalah **Google** (support.google.com + developers.google.com) → **1 penerbit**. Selain itu tidak ada penerbit independen yang dipakai untuk klaim di atas.
→ **Tidak memenuhi syarat ≥2 sumber independen**; Google Calendar tidak boleh ditetapkan sebagai pemenang, hanya sumber pola langkah (model default+override) dengan skor tinggi yang dicatat apa adanya.

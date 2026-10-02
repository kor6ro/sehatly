# Evidence F11 — Slack (SUMBER POLA: jam tenang / notification schedule)

**Bukan kandidat aplikasi yang dinilai** (seperti `evidence/F10/wcag-22-tables.md`): dipakai sebagai sumber pola langkah untuk “jam tenang”. Tanggal akses: **2026-10-02**.

## Sumber

| # | URL | Jenis | Catatan |
|---|---|---|---|
| S1 | https://slack.com/help/articles/214908388-Pause-your-Slack-notifications | Bantuan resmi — pause + **notification schedule** | Dibaca penuh 2026-10-02 |
| S2 | https://slack.com/help/articles/360025054173-Set-up-Slack-for-work-hours | Bantuan resmi — work hours & DND | Indeks pencarian 2026-10-02 |
| S3 | https://slack.com/help/articles/214888418-Set-default-Do-Not-Disturb-hours | Bantuan resmi — admin dapat menetapkan DND default; anggota dapat menimpanya | Indeks pencarian 2026-10-02 |
| S4 | https://slack.com/help/articles/201355156-Configure-Your-Slack-notifications | Bantuan resmi — kanal mobile vs desktop, delay notifikasi mobile | Indeks pencarian 2026-10-02 |
| S5 | https://www.howtogeek.com/679560/how-to-stop-slack-notifications-on-the-weekend/ | Media independen (How-To Geek), 26 Jun 2020 | Indeks pencarian 2026-10-02 |

## Fakta pola

1. **Jadwal notifikasi** (S1): pengguna memilih **Every day / Weekdays / Custom**, lalu **jam mulai dan jam selesai**. Di luar jendela itu, notifikasi **dijeda**. Langkah: Profil → Preferences → “Allow notifications” → pilih opsi → atur jam (desktop); mobile: Profil → Notifications → Notification schedule.
2. **Pause manual** (S1): durasi cepat (“15 mins”, “until 17:00”, “until tomorrow morning”) atau custom; resume eksplisit; pengguna dapat **meninjau pesan yang masuk saat dijeda** setelah resume.
3. **Override urgensi** (S1): pengirim DM dapat menembus DND **sekali per hari** untuk pesan mendesak; ikon snooze terlihat oleh orang lain.
4. **Admin default, anggota menang** (S3): workspace dapat menetapkan DND default; **jadwal anggota menimpa setelan admin**.
5. **Kanal & perangkat** (S4): preferensi mobile terpisah dari desktop; opsi kapan notifikasi mobile dikirim (segera, saat tidak aktif, atau dengan delay tambahan).
6. **Transparansi**: status snooze terlihat oleh orang lain (S1) — pengguna tidak “menghilang” diam-diam.

## Relevansi untuk F11 Sehatly

- Pola “**jadwal yang bisa dipilih** (setiap hari / hari kerja / kustom) + jam mulai–selesai” diadopsi untuk **Jam tenang**.
- Prinsip “**tampilkan apa yang terlewat saat resume**” → di Sehatly: setelah jam tenang berakhir, push yang ditahan dikirim **satu ringkasan** (bukan burst — NN/g), dan inbox in-app tetap lengkap.
- Prinsip “**default admin dapat ditimpa pengguna**” tidak relevan (Sehatly tanpa admin F14); yang diadopsi: **pengguna selalu memegang kendali**, bukan pihak lain.
- **Tidak ditiru**: istilah “snooze/DND” versi kerja, override sekali sehari (tidak ada konsep DM mendesak di Sehatly), dan integrasi status profil.

## Red flag

Tidak ada. Jadwal bersifat opsional, terlihat, dan mudah dimatikan.

## Yang TIDAK bisa diverifikasi

- UI visual (tidak diakses langsung), aksesibilitas, perilaku notifikasi saat jam tenang untuk aplikasi mobile native.

# F13 — Doxy.me (sisi penyedia: dasbor, antrean, mulai sesi)

Titik awal hitungan (sama semua kandidat F13): **dokter membuka aplikasi di sela pasien dan ingin memulai konsultasi berikutnya** → tugas inti: **konsultasi dimulai / resep ditulis**.

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://helpcenter.doxy.me/en/articles/9966881-dashboard | Help center resmi (Free/Premium plans) | "Dashboard" diperbarui 17 Agu 2026 | 2026-10-01 |
| S2 | https://helpcenter.doxy.me/en/articles/8273381-start-a-doxy-me-session | Help center resmi | "Start a doxy.me session" diperbarui 14 Mei 2026 | 2026-10-01 |
| S3 | https://help.doxy.me/en/articles/2426546-patient-queue-overview | Help center resmi (Legacy Clinic plans) | Antrean pasien: warna tunggu, aksi per baris | 2026-10-01 |
| S4 | https://helpcenter.doxy.me/en/articles/15068889-accessibility-at-doxy-me | Help center resmi | Artikel aksesibilitas 13 Agu 2026; menyebut ACR/WCAG | 2026-10-01 |
| S5 | https://status.doxy.me/ | Halaman status resmi | Semua sistem operasional, 1 Okt 2026 | 2026-10-01 |
| S6 | https://doxy.me/ dan https://doxy.me/en/features | Situs marketing resmi | Klaim "Patient Queue… jump between patients quickly"; © 2026 Doxy.me Inc. | 2026-10-01 |
| S7 | https://help.doxy.me/en/articles/847704-does-doxy-me-have-an-ehr | Help center resmi | Pernyataan: dirancang "work in parallel with any EHR" | 2026-10-01 |
| S8 | https://www.g2.com/products/doxy-me/reviews | Aggregator ulasan independen | 98 ulasan, 4,5/5; ringkasan pro/kontra AI per 2026 | 2026-10-01 |
| S9 | https://www.trustradius.com/products/doxy-me/reviews | Aggregator ulasan independen | Skor 9,3/10; kutipan "occasional connection issues" | 2026-10-01 |
| S10 | https://platform.tracxn.com/a/d/company/554beef4e4b0719e68393073/doxy.me | Basis data perusahaan independen | Pendanaan terakhir 17 Jul 2025; ±120 karyawan; aktif | 2026-10-01 |

**Verifikasi 2026:** aktif — artikel help 2026 (S1, S2), status page 1 Okt 2026 (S5), funding 2025 (S10). Dua help center resmi (Free/Premium vs Legacy) — isi bisa beda versi.

## Langkah terlihat (fakta)

1. Dokter membuka **Dashboard**: antrian pasien, alat undang, tautan ruang tunggu, Self-Preview. "If you have a patient waiting for an appointment, their Patient card will appear here." [S1]
2. **Kartu pasien berisi**: nama, waktu di antrean, tombol **Start video call**, Chat, tombol Info (lokasi, perangkat, OS, browser, status mikrofon/kamera), tombol More (audio call, remove) [S1].
3. **Mulai sesi**: "After you invite your patient and they check in… Click **Start video call** to begin a session. If multiple patients are waiting, select the patient you want to have a call with." [S2]. Versi Legacy: antrean di kiri dasbor, klik pasien untuk mulai video [S3].
4. **Warna waktu tunggu** (Legacy): merah >10 mnt, oranye 6–10 mnt, hijau <5 mnt; aksi per baris: chat, audio-only, file, payments, remove [S3].
5. **Ruang tunggu** (Premium): urutkan antrean per ruang / tunggu terpanjang / terpendek + filter [S1].
6. **E-resep tidak ada**: "We focus on providing the best video experience… work in parallel with any EHR" — catatan klinis di EHR eksternal [S7]. Marketing menyebut "SOAP and DAP Notes" dalam produk [S6] (berbeda dengan S7 — kontradiksi dicatat).

## Hitungan

Dari **dokter membuka dasbor** → **konsultasi dimulai** (dihitung dari langkah terdokumentasi, bukan observasi langsung):

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | 1 (dasbor dengan kartu pasien) | S1, S6 |
| Ketukan | 1–2 (pilih pasien bila antrean >1 → "Start video call") | S2, S3 |
| Field | 0 | S2 |

Resep: **tidak mungkin di produk ini** (tidak ada e-prescribing; dokumentasi di EHR eksternal) [S7] → tugas inti "resep ditulis" TIDAK tercapai di Doxy.me; hitungan hanya berlaku untuk "konsultasi dimulai".

## State terlihat

- **Antrean kosong**: kartu pasien hanya muncul bila ada yang menunggu [S1] — copy empty-state dasbor **TIDAK TERVERIFIKASI**.
- **Sedia/tidak**: indikator status **Available / Offline / On Call** di ruang tunggu [help.doxy.me waiting-room-overview, akses 2026-10-01].
- **Error perangkat/koneksi**: panduan izin kamera/mikrofon, "solving common video issues", refresh browser; sisi pasien: "provider is unavailable… occupied, offline, or on another call" [helpcenter.doxy.me/en/articles/8272838, akses 2026-10-01].
- **Layanan mati**: status page (S5).
- **Loading / offline-in-product**: **TIDAK TERVERIFIKASI**.

## Red flag

- **Upsell/fitur berbayar**: Custom Waiting Room, Virtual Background (Premium), Closed Captions (Pro), passcode ruang tunggu (berbayar) [helpcenter.doxy.me/en/articles/8272808; /11170798; /10273091, akses 2026-10-01] — tidak menutupi aksi utama "Start video call" (tetap gratis), jadi **bukan diskualifikasi**; dicatat sebagai pola tier.
- **Notifikasi check-in** (sound/email/SMS) bersifat opsional/konfigurabel [S helpcenter 10273091] — tidak memenuhi red flag "notification overload" rubrik.
- Tidak ditemukan hitung mundur palsu, tombol batal tersembunyi, atau izin tanpa konteks pada sumber resmi.

## Yang TIDAK bisa diverifikasi

- Layout dasbor sesungguhnya, copy empty-state, loading/offline state — di balik login (tidak dibuat akun).
- Total ketukan presisi dari "buka aplikasi" (angka 1–2 adalah ketukan terdokumentasi dari dasbor yang sudah terbuka).
- Level kepatuhan WCAG (ACR disebut ada di Trust Center tapi dokumennya gating) [S4].
- Kontradiksi SOAP notes (marketing [S6] vs "dokumentasi di EHR" [S7]) — mana yang benar-benar di produk.
- Keyboard shortcut global untuk antrean (hanya ESC fullscreen + whiteboard keys terdokumentasi).

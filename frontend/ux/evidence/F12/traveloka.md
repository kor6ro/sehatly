# Evidence F12 — Traveloka (reschedule + refund; travel lokal)

Tanggal akses semua sumber: **2026-10-02**. Sumber publik; tidak ada akun dibuat, tidak ada form dikirim. Traveloka dipakai sebagai kelas “pemesanan berbayar dengan jendela kebijakan + pembayaran selisih” yang paling dekat dengan booking dokter berbayar.

## Sumber

| # | URL | Jenis | Versi/platform | Catatan akses |
|---|---|---|---|---|
| S1 | https://www.traveloka.com/en-en/help/flight/flight-managing-booking/flight-changes/reschedule/how-do-i-reschedule-my-flight | Pusat Bantuan resmi — langkah reschedule penerbangan (desktop + app) | Web | Dibaca penuh 2026-10-02 |
| S2 | https://www.traveloka.com/en-en/refund/hotel | Prosedur refund resmi — kebijakan akomodasi, Q&A refund | Web | Dibaca penuh 2026-10-02 |
| S3 | https://www.traveloka.com/en-id/help/flight/flight-managing-booking/flight-changes/refund_article_travel-voucher-terms-and-condition | Indeks artikel “Refund, Reschedule, and other Changes” | Web | **Hanya indeks** (daftar judul) 2026-10-02 |
| S4 | https://www.traveloka.com/en-en/help/hotel/accommodation-booking/post-booking/cancelation-refund/whats-my-refund-status-hotel | Artikel status refund hotel | Web | **Hanya cuplikan indeks:** “Hotel: within 5 working days… reflected… within 14 days” 2026-10-02 |
| S5 | https://www.traveloka.com/en-en/termsandconditions | Syarat & Ketentuan | Web | **Hanya cuplikan indeks:** refund dibalik ke metode asal; jika tidak bisa, ke rekening terdaftar; persetujuan supplier |
| S6 | https://www.traveloka.com/en-en/flight/100-refund-guarantee | Halaman jaminan refund 100% | Web | **Fetch HTTP 403**; cuplikan indeks: ajukan ≥24 jam sebelum keberangkatan; tidak berlaku bila penerbangan sudah di-reschedule |
| S7 | https://www.traveloka.com/en-en/help/flight/flight-managing-booking/flight-changes/refund/refund-duration | Artikel durasi refund penerbangan | Web | **Hanya cuplikan indeks** (proses tergantung maskapai) |

## Langkah terlihat (fakta)

**Jadwal ulang penerbangan — jalur app (S1):**
1. Buka **My Booking** → buka e-ticket penerbangan → tab **Refund & Reschedule** → **Reschedule**.
2. **Request Reschedule** → pilih penerbangan & penumpang → pilih tipe reschedule → Continue.
3. Isi detail (origin, destination, tanggal, kelas) → **Search** → pilih penerbangan baru.
4. Review + **Price Details** → Continue. **Jika harga baru lebih tinggi, pengguna dikenai selisih.**
5. **Bayar** dalam batas waktu yang diberitahukan; sistem mengirim notifikasi **30–60 menit** setelah pengajuan berisi pembayaran + batas waktunya.
6. E-ticket baru tersedia di My Booking + email **±60 menit setelah pembayaran dikonfirmasi**.
   - **Jaminan aman:** “Your original flight will not be changed until you have completed the payment.” Jika pembayaran tidak dilanjutkan / permintaan dibatalkan, **tiket tetap sesuai jadwal lama** (S1).
   - Kebijakan harus dibaca dan **disetujui (centang “I agree”)** sebelum lanjut (S1, desktop langkah 2).

**Refund (S2):**
1. Login → **My Booking** → **Details** → tombol **Refund** → isi detail permintaan.
2. **Perkiraan jumlah refund terlihat saat mengisi detail permintaan** (S2 Q&A “How much will I get back”).
3. Status refund dipantau di **My Booking → Details** (S2 Q&A “Where can I check the status”).
4. Hotel: proses **hingga 5 hari setelah disetujui**; lalu **hingga 90 hari** sampai dana terlihat di rekening, tergantung bank (dua angka berbeda muncul di halaman yang sama, S2).
5. Penerbangan: **biaya Traveloka Rp30.000 per penumpang per rute** + biaya pembatalan maskapai (S2).
6. Booking non-refundable: refund **diajukan**, keputusan di pihak hotel; jika disetujui dikenai biaya bank (jika ada) + **biaya penanganan 10%** + biaya pembatalan hotel (S2).
7. Kupon **tidak dapat direfund**; **tidak ada refund sebagian** untuk hotel (berlaku seluruh masa inap & semua kamar per Booking ID) (S2).
8. Refund ke kartu kredit (jika bayar kartu) atau rekening bank; **hanya ke pemesan/salah satu penumpang**; jika tidak, refund ditahan (S2).

## Hitungan (titik awal yang sama)

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar/ketukan reschedule | **6 langkah** (app) sampai pengajuan; +1 langkah pembayaran setelah notifikasi 30–60 menit | S1 |
| Field reschedule | tipe reschedule (pilih), penerbangan+penumpang (centang), origin/destination/tanggal/kelas (cari), persetujuan kebijakan | S1 |
| Jendela | Jaminan refund 100%: ajukan **≥24 jam** sebelum keberangkatan (dan gugur bila sudah reschedule) | S6 (indeks) |
| Waktu proses refund | Hotel: ≤5 hari setelah persetujuan; dana terlihat ≤90 hari; penerbangan: tergantung maskapai | S2, S4 (indeks), S7 (indeks) |
| Biaya | Rp30.000/penumpang/rute + biaya maskapai; non-refundable: +10% handling | S2 |

## State terlihat

- **Menunggu pembayaran reschedule:** notifikasi 30–60 menit + batas waktu; tiket lama tetap berlaku (state aman, eksplisit) (S1). ✓
- **Gagal/tidak dibayar:** permintaan batal → tiket tetap seperti semula (S1). ✓
- **Menunggu persetujuan refund:** hotel memutuskan; refund berstatus (S2). ✓
- **Refund disetujui, dana belum terlihat:** ada janji 5 hari proses + 90 hari bank (S2). ✓
- **Jumlah refund:** estimasi tampil **saat pengisian permintaan**, bukan saat booking (S2). ⚠ dicatat sebagai pola yang tidak disalin.
- Loading/kosong/error/offline: tidak didokumentasikan → N/V.

## Red flag

- **Tidak ada dark pattern terverifikasi** (pembatalan dapat dilakukan sendiri, biaya dinyatakan, status dapat dilacak).
- **Yang tidak boleh disalin:**
  1. **Biaya & estimasi refund baru muncul di langkah pengajuan** (S2), bukan saat booking/janji dibuat. Untuk Sehatly: kebijakan harus tampil **sebelum** komitmen (batal = dialog berisi akibat konkret).
  2. **Dua janji waktu yang berbeda** di halaman yang sama (proses 5 hari vs dana terlihat 90 hari; S2) dan **frasa jaminan “as fast as 1 hour”** di halaman pemasaran vs proses nyata (S6 indeks) → Sehatly menampilkan satu SLA yang dapat diuji.
  3. **Biaya per penumpang/per rute** dan 10% handling terlalu kompleks untuk konsultasi dokter — Sehatly memakai kebijakan tunggal sederhana.

## Yang TIDAK bisa diverifikasi

1. UI asli di app (warna, hierarki, target sentuh) — hanya langkah tekstual + screenshot bantuan.
2. Layar status refund yang sebenarnya (hanya dinyatakan ada di My Booking → Details).
3. Jumlah hari refund penerbangan (S7 hanya indeks; S2: tergantung maskapai).
4. Isi S3, S4, S5, S6, S7 penuh; klaim dari sana ditandai “indeks”.
5. Apakah pembatalan oleh penyedia (maskapai/hotel) punya alur berbeda di UI (S5 menyebut reschedule/refund atas permintaan).

## Independensi sumber

- Semua domain `traveloka.com` → **satu penerbit**. Tidak boleh menang rubrik; dipakai sebagai **sumber pola langkah** (reschedule berbayar dengan pengaman “tiket lama berlaku sampai bayar”) dan **daftar state** (menunggu bayar, gagal bayar, menunggu persetujuan, refund dalam proses).

# Evidence F12 — Booking.com (jendela pembatalan + kalkulasi biaya sebelum konfirmasi; travel global)

Tanggal akses semua sumber: **2026-10-02**. Sumber publik. Booking.com dipakai untuk pola **kebijakan terstruktur (deadline + penalti), kalkulasi biaya otomatis yang ditampilkan sebelum konfirmasi, zona waktu deadline, dan pengecualian belas kasihan** — bukan untuk UI.

## Sumber

| # | URL | Jenis | Versi/platform | Catatan akses |
|---|---|---|---|---|
| S1 | https://www.booking.com/articles/how-to-cancel-hotel-reservations.en-gb.html | Artikel resmi konsumen “Know your rights: how to cancel hotel reservations” | Web; **dipublikasikan 10 Juli 2026** | Dibaca penuh 2026-10-02 |
| S2 | https://partner.booking.com/en-us/help/reservations/manage/handling-reservation-cancellations | Bantuan mitra (partner) — menangani pembatalan | Web; **diperbarui 17 Jul 2026** | **Hanya cuplikan indeks** 2026-10-02 |
| S3 | https://www.booking.com/content/terms.html | Syarat layanan konsumen | Web; 25 Agu 2025 | **Hanya cuplikan indeks:** “if it offers free cancellation, you'll be able to cancel it for free, as long as you do so in time. You can cancel for free up to 24 hours…” |
| S4 | https://developers.booking.com/connectivity/docs/codes-bccp | Dokumentasi developer — **kode kebijakan pembatalan** | Web | **Hanya cuplikan indeks:** tabel terstruktur = penalti % setelah deadline + deadline (D/H) + deskripsi bahasa alami |
| S5 | https://partner.booking.com/en-gb/solutions/cancellation-deposit-and-prepayment-policies | Halaman mitra — kebijakan fleksibel vs kustom | Web | **Hanya cuplikan indeks** |

## Langkah terlihat (fakta)

**Kebijakan & jendela (S1):**
- “Refundable rates … allow **free cancellation within a specified timeframe, usually 24–48 hours before your scheduled arrival**.” Kebijakan berbeda per properti; kamar non-refundable mengikat (S1).
- **Deadline dihitung di zona waktu hotel, bukan zona pengguna** — “Missing this window by even a few minutes can result in charges” (S1).
- **Kalkulasi otomatis saat membatalkan:** “The system will **automatically calculate any applicable fees and show you what refund to expect**” — sebelum konfirmasi (S1).
- **Pengecualian:** keadaan luar biasa (berduka, sakit berat, perubahan besar) dapat membebaskan biaya meski non-refundable, dengan dokumen pendukung; menghubungi hotel langsung sering lebih berhasil (S1).
- Pola mitra (S2, indeks): permintaan batal oleh tamu tersedia **hingga 48 jam sebelum check-in**; reservasi **tetap aktif sampai tamu mengonfirmasi**; mitra dapat **melepaskan (waive) biaya**; kebijakan menjelaskan **full/partial/no refund**.

**Struktur kebijakan (S4, indeks):** setiap kebijakan direpresentasikan sebagai **penalti (%) setelah deadline + deadline (hari/jam) + deskripsi bahasa alami**. Contoh dari cuplikan: “The guest can cancel free of charge until 4 days before arrival. The guest will be charged the total price if they cancel in the 4 days before arrival.”

## Hitungan (titik awal yang sama)

| Besaran | Nilai | Dasar |
|---|---|---|
| Langkah batal | “log in → locate your reservation → look for ‘cancel’ or ‘modify’” (tidak dirinci jumlah ketukan; akun) | S1 |
| Jendela | umumnya **24–48 jam sebelum kedatangan**, per properti | S1, S3 |
| Tampilan biaya | **dihitung & ditampilkan sebelum konfirmasi** | S1 |
| Waktu refund | **TIDAK TERVERIFIKASI** — angka “3–5 hari kerja” hanya muncul di PDF pihak ketiga; tidak dipakai |
| Field | tidak dirinci (akun) | S1 |

## State terlihat

- **Dalam jendela gratis vs setelahnya:** perbedaan konsekuensi dinyatakan; sistem menghitung biaya (S1). ✓
- **Non-refundable:** mengikat; pengecualian hanya lewat eskalasi manusia (S1). ✓
- **Pengecualian belas kasihan:** ada jalur + dokumen (S1). ✓
- **Mitra melepaskan biaya:** state “waive” ada (S2 indeks). ✓
- Loading/kosong/error/offline: tidak didokumentasikan → N/V.

## Red flag

- Tidak ada dark pattern terverifikasi di artikel konsumen: kebijakan dinyatakan di muka, biaya dihitung sebelum konfirmasi, ada jalur pengecualian.
- **Yang tidak boleh disalin:** (a) **variasi kebijakan per properti** (terlalu kompleks untuk layanan kesehatan — Sehatly: satu kebijakan); (b) **deadline zona hotel** saja — Sehatly menampilkan zona pengguna + zona jadwal bila berbeda (`_global.md` §5); (c) **non-refundable mengikat tanpa pengecualian otomatis** — perlu jalur banding manusia untuk alasan medis darurat (kebijakan Sehatly, keputusan pemilik).

## Yang TIDAK bisa diverifikasi

1. UI booking.com saat membatalkan (di balik login) — langkah hanya dari artikel.
2. **Waktu proses refund konsumen** — tidak ada angka resmi di halaman yang dibuka; jangan mengutip PDF pihak ketiga.
3. Jumlah pasti ketukan/field; non-refundable waiver flow.
4. S2, S3, S4, S5 hanya cuplikan indeks; dikutip sebagai “indeks”.
5. Apakah pengguna melihat estimasi refund **setelah** konfirmasi juga — tidak dinyatakan.

## Independensi sumber

- S1–S5 domain `booking.com`/`partner.booking.com`/`developers.booking.com` → **satu penerbit**. Tidak boleh menang rubrik; dipakai sebagai sumber pola **kebijakan terstruktur + biaya sebelum konfirmasi + pengecualian**.

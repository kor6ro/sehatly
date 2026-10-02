# Evidence F11 — NN/g (SUMBER POLA: riset notifikasi & umpan balik status)

**Bukan kandidat aplikasi yang dinilai.** Dipakai sebagai pedoman ilmiah untuk sintesis pola. Tanggal akses: **2026-10-02** (via indeks pencarian; kutipan panjang dari artikel).

## Sumber

| # | URL | Judul | Tanggal artikel | Jenis |
|---|---|---|---|---|
| S1 | https://www.nngroup.com/articles/push-notification/ | “Five Mistakes in Designing Mobile Push Notifications” | 18 Nov 2018 | Riset NN/g |
| S2 | https://www.nngroup.com/articles/transactional-notifications/ | “Transactional Notifications: Their Characteristics and When to Use Them” | 6 Nov 2022 | Riset NN/g (diary study 4 negara) |
| S3 | https://www.nngroup.com/articles/indicators-validations-notifications/ | “Indicators, Validations, and Notifications: Pick the Correct Communication Option” | 17 Jan 2024 | Riset NN/g |

## Fakta yang dipakai

**S1 — lima kesalahan push:**
1. **Meminta izin notifikasi segera setelah instal** — pengguna belum memahami nilai; dalam banyak studi, respons langsung adalah “Don’t Allow”. Beri nilai dulu, minta izin di sesi berikutnya.
2. **Tidak menjelaskan isi notifikasi** — pesan izin OS generik (“X ingin mengirim notifikasi”) tidak menyebut manfaat; jelaskan jenis notifikasi yang akan dikirim.
3. **Notifikasi beruntun (burst)** — banyak notifikasi dalam interval singkat membuat pengguna mematikan notifikasi atau menghapus aplikasi; bila >5 notifikasi sekaligus, **gabung menjadi satu pesan**.
4. **Konten tidak relevan** — tiap notifikasi adalah interupsi; kirim hanya yang relevan.
5. **Sulit mematikan notifikasi** — jangan sembunyikan; sediakan preferensi **di dalam aplikasi** (jangan memaksa ke setelan OS), di bagian Settings, mudah ditemukan.
   Catatan penting: “riset menunjukkan sebagian besar pengguna tidak repot menyesuaikan sistem” → **preferensi bukan alasan mengirim terlalu banyak**; default harus tenang.

**S2 — notifikasi transaksional:**
- Headline harus merangkum tujuan; jangan ulangi nama aplikasi (sudah tampil di notifikasi); frontload informasi kunci; batas push 50–240 karakter.
- Push cocok untuk **non-urgent** dan status-change yang mengarahkan kembali ke aplikasi; SMS/kanal lebih tahan untuk informasi yang perlu disimpan/dibalas.
- **Push bergantung konektivitas** — di sinyal lemah notifikasi bisa sulit sampai (relevan untuk konteks Indonesia).
- Bila memakai beberapa kanal, **izinkan opt-out per kanal**.
- Pengguna hanya mengharapkan notifikasi untuk konten yang penting.

**S3 — membedakan notifikasi vs validasi vs indikator:**
- Notifikasi **action-required** butuh desain berbeda dari **passive**; jangan pakai toast (hilang dalam detik) untuk hal penting/error — pengguna bisa melewatkannya.
- Indikator/badge pada elemen yang tepat membantu menunjukkan di mana notifikasi berlaku.

**Studi pendukung (independen, dari indeks yang sama):**
- ACM TOCHI “Alert Now or Never” (2023): pengguna lebih memilih **menahan alert** daripada menundanya; faktor isi notifikasi lebih sering disebut daripada faktor konteks.
- Pielot dkk. (MobileHCI 2018, “Dismissed!”): median 56 notifikasi/hari; sebagian besar dari aplikasi pesan.

## Relevansi untuk F11 Sehatly

- Izin push **jangan** diminta saat login pertama; minta **setelah pengguna menerima nilai** (mis. setelah booking pertama sukses atau membuka `/notifikasi`), dengan penjelasan jenis notifikasi (S1 #1–#2).
- Body push **generik**, satu pesan per peristiwa, dilarang burst; event beruntun (mis. beberapa chat) → ringkas (S1 #3).
- Preferensi kanal & jam tenang ada **di dalam aplikasi** (`/profil/notifikasi`), bukan hanya mengandalkan setelan OS (S1 #5).
- Status penting (booking dibatalkan, pembayaran gagal) tidak boleh hanya toast (S3).
- Push bukan satu-satunya kanal: inbox in-app tetap sumber kebenaran karena sinyal lemah (S2).

## Red flag

Tidak berlaku (sumber pedoman, bukan aplikasi).

## Yang TIDAK bisa diverifikasi

- Angka efek kuantitatif untuk konteks Indonesia (tidak ada studi lokal yang dikutip); semua generalisasi barat/global.

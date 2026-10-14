# Evidence F01 — Baymard (input nomor telepon & validasi form)

Tanggal akses semua sumber: **2026-10-02**. Jenis: riset usability e-commerce publik. **Tidak ada artikel Baymard khusus OTP** — hanya pola nomor telepon & validasi yang relevan untuk F01.

## Sumber

| # | URL | Catatan |
|---|---|---|
| S1 | https://baymard.com/blog/explain-phone-number-field | "Phone Number UX: Always Explain Why the 'Phone Field' Is Required (39% Don't)" — Edward Scott, diperbarui 29 Jul 2025 |
| S2 | https://baymard.com/blog/input-fields | "8 Recommendations for Creating Effective Input Fields" — Christian Holst, 6 Des 2021 |
| S3 | https://baymard.com/research-articles/inline-form-validation | "Usability Testing of Inline Form Validation" |

## Temuan persis

- **S1:** **14%** pembeli online tidak akan pernah memberi nomor telepon ke toko online; **14%** menolak memberi gender; **27%** menolak tanggal lahir. **39%** situs meminta nomor telepon **tanpa penjelasan**. Tanpa penjelasan, partisipan **mengisi nomor palsu ("9999") atau meninggalkan checkout**. Solusi: penjelasan inline alasan nomor diperlukan / tandai opsional / hapus.
- **S2:** **Guideline #658** — gunakan **input mask terlokalisasi** untuk "Phone"; **89%** partisipan desktop mengabaikan contoh format dan mengetik variasi lain. **Guideline #668** — minimalkan field yang terlihat (10–15+ field membuat pengguna mundur).
- **S3:** validasi inline langsung vs setelah submit; ringkasan indeks: **31% situs tidak punya validasi inline, 4% salah menerapkannya** (angka dari cuplikan; halaman yang dibuka adalah S1).

## Relevansi ke Sehatly (fakta kode)

`AuthRequest.php:63-66` mencocokkan `no_telepon` **persis** tanpa normalisasi; regex registrasi menerima `+` opsional (`RegisterRequest.php:63`) → `0812...` dan `+62812...` menjadi akun berbeda. UI login menyediakan toggle Telepon/Email (`login-page.tsx:213-221`) tetapi tidak menunjukkan contoh format `08xx`/`+62`.

## Red flag

Tidak ada dark pattern. Temuan "nomor palsu bila tidak dijelaskan" adalah risiko langsung untuk Sehatly: nomor adalah identifier utama + tujuan OTP.

## Yang TIDAK bisa diverifikasi

Panduan Baymard khusus timer/resend/kode kedaluwarsa OTP (tidak dipublikasikan gratis); angka inline validation yang persis (hanya cuplikan).

## Independensi sumber

Satu penerbit (Baymard), tetapi independen dari aplikasi kandidat; dipakai bersama NN/g untuk pola input nomor & validasi.

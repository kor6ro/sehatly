# Template Pattern: `web/ux/patterns/<flow-id>.md`

Salin struktur ini untuk setiap flow. Semua bagian wajib diisi. File ini dibaca agent saat implementasi, jadi tulis ringkas, konkret, dan bisa diuji.

```markdown
# <ID> <Nama flow>

Status: draf | disetujui   |   Tanggal riset: YYYY-MM-DD   |   Keyakinan: tinggi/sedang/rendah

## 1. Tujuan pengguna dan metrik sukses
- Peran: ...
- Tujuan: ...
- Metrik (terukur): mis. "<= 4 layar dari daftar dokter sampai konfirmasi booking"

## 2. Pola terpilih
Tabel per langkah:
| Langkah | Pola yang dipakai | Terinspirasi dari (sumber + tanggal) | Skor | Alasan berbasis fakta |
|---|---|---|---|---|

## 3. Yang sengaja TIDAK ditiru
- Elemen, dark pattern, atau kebiasaan yang ada di aplikasi acuan tapi ditolak, dan alasannya.

## 4. Adaptasi ke Sehatly
- Komponen shadcn/Radix yang dipakai (nama komponen).
- Token yang dipakai (warna status, radius, spacing).
- Copy Bahasa Indonesia (judul, tombol, pesan error, pesan kosong). Ditulis ulang, bukan salinan.
- Data dari API: endpoint dan field yang dibutuhkan; state realtime (Reverb) bila ada.

## 5. Wireframe teks
Mobile 390 px:
~~~
[ASCII]
~~~
Desktop 1280 px:
~~~
[ASCII]
~~~

## 6. State lengkap
Loading | Kosong | Error (jaringan, validasi, server) | Offline | Sesi habis | Sukses | Parsial.
Untuk tiap state: apa yang dilihat pengguna dan tindakan lanjutnya.

## 7. Edge case medis dan bisnis
Mis. slot bentrok saat konfirmasi, dokter batal, konsultasi terputus, resep kedaluwarsa, zona waktu berbeda, pasien membuka di dua perangkat.

## 8. Aksesibilitas
Urutan fokus, label, target sentuh >= 44 px, kontras, pengumuman pembaca layar untuk perubahan status, dukungan teks besar.

## 9. Privasi
Data yang tampil, yang disamarkan, yang tidak boleh muncul di toast/URL/judul tab/notifikasi.

## 10. Kriteria penerimaan (terukur)
- [ ] AC-1: ...
- [ ] AC-2: ...

## 11. Skenario Playwright
Satu skenario per kriteria penerimaan: nama tes, langkah, asersi, viewport (390 dan 1280).

## 12. Pertanyaan terbuka
Keputusan yang butuh persetujuan pemilik produk.
```

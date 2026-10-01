# F13 — NN/g dan Baymard (kepadatan dasbor/tabel yang terbaca)

**Jenis:** sumber riset UX independen (bukan aplikasi). Dipakai untuk kritikia 1/4/7 dan bagian kepadatan pattern. Bukan objek penilaian skor aplikasi.

## Sumber

| # | Sumber | Jenis | Tanggal/Status di halaman | Akses |
|---|---|---|---|---|
| S1 | https://www.nngroup.com/articles/data-tables/ | Artikel riset NN/g (gratis) | 3 Apr 2022 | 2026-10-01 |
| S2 | https://www.nngroup.com/articles/complex-application-design/ | Artikel riset NN/g (gratis) | 8 Nov 2020 | 2026-10-01 |
| S3 | https://www.nngroup.com/articles/dashboards-preattentive/ | Artikel riset NN/g (gratis) | 18 Jun 2017 (metadata pencarian menampilkan 2018-01-06; tanggal halaman yang dipakai) | 2026-10-01 |
| S4 | https://www.nngroup.com/articles/progressive-disclosure/ | Artikel riset NN/g (gratis) | 3 Des 2006 | 2026-10-01 |
| S5 | https://www.nngroup.com/articles/ten-usability-heuristics/ | Heuristik NN/g (gratis) | 24 Apr 1994; direview 30 Jan 2024 | 2026-10-01 |
| S6 | https://baymard.com/blog/line-length-readability | Artikel Baymard (gratis) | 10 Mei 2022 | 2026-10-01 |
| S7 | https://baymard.com/product/ux-best-practice-guidelines | Koleksi guideline Baymard | **Berbayar (Premium)** — guideline kepadatan per komponen tidak gratis | 2026-10-01 |

## Fakta terverifikasi (ringkas, parafrase)

**NN/g Data Tables (S1):** empat tugas tabel = cari rekaman, bandingkan, lihat/ubah satu baris, aksi massal; kolom pertama = pengenal manusiawi (bukan ID misterius); urutan kolom sesuai kepentingan; **bekukan header baris/kolom** di atas layar; sembunyikan/urutkan kolom harus mudah + ada indikator filter aktif; untuk edit baris tunggal pakai panel samping non-modal (modal menutupi konteks); aksi massal = checkbox baris + tombol di atas/bawah + **Select All**.

**NN/g Complex Applications (S2):** 8 pedoman — dorong belajar-sambil-kakal (most users satisfice: beri petunjuk metode lebih cepat di tempat, mis. tooltip), kurangi kekacauan tanpa mengurangi kemampuan (**staged disclosure**), transisi info primer↔sekunder tanpa tinggalkan layar (hover tooltip grafik), info penting dibuat menonjol (**menghapus elemen non-esensial sama efektifnya dengan menekan**), bantu lacak tindakan saat terputus.

**NN/g Dashboards (S3):** dasbor = satu halaman sekilas untuk konsumsi cepat, **bukan** untuk eksplorasi; bedakan dasbor operasional (time-sensitive, update terus, aksi segera) vs analitis; pakai **panjang & posisi 2D** (bar/line/scatter) untuk kuantitatif; hindari pie/donut/treemap/gauge/3D untuk pembacaan cepat; warna = penguat sekunder (±8% pria punya defisit warna).

**NN/g Progressive Disclosure (S4):** tampilkan hanya opsi terpenting di awal; dua hal harus tepat: pembagian awal↔sekunder dan jalur lanjut yang jelas; tingkatkan learnability/efisiensi; **>2 tingkat biasanya buruk**; hindari beberapa jalur ke level sekunder.

**Heuristik (S5):** #6 Recognition over recall; #7 Flexibility/efficiency — sediakan **accelerator seperti keyboard shortcut yang tersembunyi dari pemula**; #8 informasi ekstra bersaing dengan informasi relevan.

**Baymard (S6):** panjang baris optimal 50–60 karakter (maks 75; WCAG 1.4.8 = ≤80 karakter, CJK ≤40); `max-width: 70ch/34em`; line-height 1.5em, spacing paragraf 2em, spasi kata 0.16em, spasi huruf 0.12em. **Kepadatan komponen spesifik Baymard = berbayar (S7) → TIDAK TERVERIFIKASI gratis.**

## Hitungan / State / Red flag

- Hitungan: N/A (riset, bukan aplikasi). State: N/A. Red flag: tidak ada.
- **TIDAK bisa diverifikasi:** penerapan pedoman ini di aplikasi manapun (hanya rekomendasi); studi kasus tabel medis spesifik (tidak ada artikel NN/g khusus tabel medis).

## Batasan

- S2/S4/S5 berbasis studi generik, bukan alur dokter telemedicine; dipakai sebagai pedoman desain, bukan bukti langkah aplikasi.
- Tanggal artikel lama (2006–2017) — tetap berlaku sebagai pedoman heuristik, dicatat usianya.

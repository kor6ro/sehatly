# Evidence F08 — WhatsApp (pola chat)

Titik awal hitungan (berlaku untuk semua kandidat F08): **user membuka ruang percakapan/konsultasi**. Tugas inti: **satu pesan terkirim dan diakui**, atau sesi ditutup.

## Sumber

| # | URL | Jenis sumber | Tanggal akses | Versi/platform |
|---|---|---|---|---|
| 1 | https://www.whatsapp.com/coronavirus/get-started?lang=en | Halaman resmi WhatsApp (langkah pakai, termasuk "Start a chat") | 2026-10-01 | Web, halaman resmi WhatsApp |
| 2 | https://faq.whatsapp.com/665923838265756 | Pusat bantuan resmi — status centang (sent/delivered/read), ikon jam | 2026-10-01 | FAQ resmi, semua platform |
| 3 | https://faq.whatsapp.com/1244276003970936 | Pusat bantuan resmi — indikator "sedang mengetik" (tiga titik) | 2026-10-01 | FAQ resmi |
| 4 | https://faq.whatsapp.com/419827870318306 | Pusat bantuan resmi — last seen/online + privasi | 2026-10-01 | FAQ resmi |
| 5 | https://faq.whatsapp.com/5155925751185676 | Pusat bantuan resmi — gagal kirim / offline / "Waiting for network" | 2026-10-01 | FAQ resmi |
| 6 | https://faq.whatsapp.com/453914586839706 | Pusat bantuan resmi — batas lampiran (video 64/100 MB, dokumen 2 GB, 100 media) | 2026-10-01 | FAQ resmi |
| 7 | https://faq.whatsapp.com/6614640168569481 dan https://faq.whatsapp.com/1370476507114859 | Pusat bantuan resmi — edit (≤15 menit, label "edited") dan hapus (≤2 hari untuk semua; undo 5 detik) | 2026-10-01 | FAQ resmi |
| 8 | https://faq.whatsapp.com/481135090640375 | Pusat bantuan resmi — cadangan chat (satu cadangan lama tertimpa) | 2026-10-01 | FAQ resmi |
| 9 | https://faq.whatsapp.com/668538004658079 dan https://web.whatsapp.com/ | Pusat bantuan + landing resmi WhatsApp Web (teks fitur) | 2026-10-01 | Web |
| 10 | https://play.google.com/store/apps/details?id=com.whatsapp | Listing Google Play (deskripsi + tangkapan layar aplikasi) | 2026-10-01 | Android |
| 11 | https://www.wikihow.com/Send-Messages-on-WhatsApp | Tutorial pihak ketiga independen (langkah ketik + kirim) | 2026-10-01 | Android/iOS/Desktop |

**Catatan metode (keterbatasan terdokumentasi):** fetch langsung `faq.whatsapp.com` dari riset ini mengembalikan **HTTP 400** (proteksi bot; dicoba pada URL kanonik). Isi FAQ diverifikasi lewat crawl indeks pencarian atas URL kanonik yang sama pada tanggal yang sama. Halaman resmi #1 dan tutorial #11 dapat diakses/di-crawl penuh. Tanpa akun dan tanpa pemasangan aplikasi: **tidak ada tangkapan layar chat yang diambil sendiri**; tidak ada akun dibuat, tidak ada data dikirim.

## Langkah terlihat (fakta)

1. Buka percakapan → kolom teks di bawah layar ("Enter a message in the text field", sumber #1, langkah "Start a chat").
2. Ketik pesan → ketuk tombol kirim di samping kolom teks (sumber #1 dan #11).
3. Status di samping bubble (sumber #2): centang tunggal = pesan terkirim dari perangkat; centang ganda abu = terkirim ke perangkat penerima; centang ganda **biru** = sudah dibaca; **ikon jam = belum terkirim/diterima**, "could be due to connectivity issues" (juga #5).
4. Indikator lawan bicara mengetik: tiga titik "..." di thread, muncul di chat individu dan grup (#3).
5. `last seen`/`online` **bukan** tanda pesan dibaca: "this doesn't mean the user has read your message" (#4).
6. Offline: pesan menampilkan jam / "Waiting for network" (#5). Halaman resmi **tidak** menyatakan secara eksplisit pengiriman ulang otomatis atau jaminan urutan pesan.
7. Edit pesan ≤15 menit dengan label "edited" di samping waktu; hapus untuk semua ≤2 hari; "Delete for me" punya jendela undo 5 detik (#7).
8. Batas lampiran resmi: dokumen maks 2 GB; video default 64 MB (koneksi lambat) / 100 MB (koneksi cepat); hingga 100 foto/video sekaligus (#6).
9. WhatsApp Web: deskripsi fitur teks ("Drag and drop documents to send", "View photos on a larger screen") (#9) — tanpa galeri tangkapan layar resmi yang bisa dikutip.

## Hitungan (titik awal: membuka percakapan → satu pesan terkirim & diakui)

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | 1 | Percakapan terbuka = tugas selesai di layar yang sama (#1, #11) |
| Field | 1 | Kolom teks (#1) |
| Ketukan | 1 | Ketuk tombol kirim setelah mengetik; lampiran opsional +1 (#11) |
| Acknowledgement | otomatis | Centang delivered/datang tanpa ketukan tambahan; "dibaca" oleh penerima (#2) |

Hitungan **diturunkan dari langkah terdokumentasi**, bukan hasil uji langsung (tanpa akun/aplikasi).

## State terlihat

- **Sukses:** status centang sent/delivered/read dengan legenda resmi (#2). ✓
- **Offline/gagal:** ikon jam + "Waiting for network" (#5); pesan tetap tampil di thread. ✓
- **Error kirim:** FAQ pemecahan masalah khusus "Can't send or receive messages" (#5). ✓
- **Loading/kosong:** tidak terdokumentasi di sumber resmi → N/V.
- **Reconnect:** kehilangan ikon jam setelah terkirim; mekanisme pemulihan otomatis tidak dinyatakan eksplisit → sebagian.

## Red flag

- Tidak ditemukan dark pattern pada sumber resmi yang diakses.
- Risiko salah tafsir yang perlu dihindari saat mengadaptasi: "online" ≠ "dibaca" (ditegaskan FAQ #4) — pola Sehatly harus memisahkan status kirim dan status baca.

## Yang TIDAK bisa diverifikasi

- Tampilan visual chat (warna, hierarki, ukuran target sentuh) — tidak ada galeri tangkapan layar resmi; UI tidak diakses langsung.
- Urutan pesan dan jaminan antrean pengiriman saat offline — tidak ada halaman resmi.
- Batas ukuran foto inline (mis. 5 MB) — tidak ada di halaman resmi yang diakses.
- Aksesibilitas (kontras, target sentuh, pembaca layar) dan privasi notifikasi layar kunci.
- **Rubrik: K4 aksesibilitas = N/V, K7 beban kognitif = N/V** (UI tak teramati).

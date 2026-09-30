# Evidence F08 — NN/g dan Baymard (pedoman riset usability chat/error)

**Jenis:** sumber pedoman riset (bukan aplikasi). Dipakai sebagai dasar pola langkah dan sebagai pembanding fakta aplikasi. Bukan objek penilaian skor aplikasi.

## Sumber

| # | URL | Jenis sumber | Tanggal akses | Catatan |
|---|---|---|---|---|
| 1 | https://www.nngroup.com/articles/chat-ux/ | Artikel riset NN/g — "The User Experience of Customer-Service Chat: 20 Guidelines" (Raluca Budiu, 13 Jan 2019); **di-fetch penuh oleh riset ini** | 2026-10-01 | Studi 8 partisipan; publik |
| 2 | https://www.nngroup.com/articles/error-message-guidelines/ | Artikel riset NN/g — pedoman pesan error (14 Mei 2023) | 2026-10-01 | Publik |
| 3 | https://www.nngroup.com/articles/errors-forms-design-guidelines/ | Artikel riset NN/g — 10 pedoman error form (3 Feb 2019, direviu 12 Des 2024) | 2026-10-01 | Publik |
| 4 | https://www.nngroup.com/articles/response-times-3-important-limits/ | Artikel riset NN/g — ambang 0,1 s / 1,0 s / 10 s | 2026-10-01 | Publik |
| 5 | https://www.nngroup.com/articles/progress-indicators/ | Artikel riset NN/g — indikator progres (26 Okt 2014) | 2026-10-01 | Publik |
| 6 | https://www.nngroup.com/articles/usability-for-senior-citizens/ | Artikel riset NN/g — lansia (8 Sep 2019) | 2026-10-01 | Publik |
| 7 | https://baymard.com/blog/inline-form-validation | Baymard (artikel gratis, 9 Jan 2024) | 2026-10-01 | Konteks e-commerce checkout, bukan chat |
| 8 | https://baymard.com/blog/adaptive-validation-error-messages | Baymard (artikel gratis, 14 Des 2023) | 2026-10-01 | Sama |
| 9 | https://baymard.com/guidelines/722-using-adaptive-error-messages | Baymard Guideline #722 — **TERPAYWALL** (Premium) | 2026-10-01 | **Tidak dipakai sebagai bukti**; ringkasannya ada di #8 |

## Fakta/pedoman yang terverifikasi (kutipan pendek) dan relevansinya ke F08

1. **Indikator "sedang mengetik"** (#1): peserta "were generally pleased to see the message *Agent is typing*… it did help them wait more patiently. However, if the agent's message took too long to appear, they started becoming suspicious and felt deceived." → typing indicator harus muncul-hilang dan jangan menggantung.
2. **Perkirakan waktu respons** (#1): "Periodic messages that communicate the state of the agent are essential and serve the same function as progress indicators"; antrian "You're number 5 in the queue" kurang membantu dibanding estimasi waktu. → dasar state "Menunggu dokter" dengan estimasi.
3. **Keadaan ambigu dilarang** (#1): "Agent is paused" membuat orang bertanya apakah agen pergi ("caused people to wonder whether the agent had left"). → label status harus eksplisit (contoh Sehatly: "Mode REST, tanpa realtime" perlu diterjemahkan ke bahasa pengguna).
4. **Stempel waktu** (#1): "Show time stamps for messages to put response times in the right perspective."
5. **Diferensiasi pesan pengguna** (#1): "Several users commented positively when the messages of the agent were shown in a different color than the messages of the user"; pesan sistem beda lagi dan diberi label "System". → relevan: `chat-window.tsx` Sehatly saat ini memakai gaya gelembung identik untuk semua pihak.
6. **Interruption/pemutusan** (#1): "ensure that if the user does attempt to resume an interrupted chat session, the context (and the progress) is saved and the user doesn't have to reenter questions or start over."
7. **Lampiran dalam chat, wajib jalan di mobile** (#1): "Allow People to Upload Documents During the Chat Session"; contah kegagalan: tombol lampiran Dell "did not work on a mobile device".
8. **Simpan transkrip** (#1): tawarkan email/PDF; "the transcript could serve as a reference later".
9. **Jangan ketik ulang** (#1) dan **bedakan pesan sistem otomatis** (#1): pesan sistem tampil sama seperti pesan agen → pengguna salah paham ("Your session has expired…" dikira pesan agen).
10. **Pesan error** (#2): tampilkan di dekat sumber error; "never use exclusively color or animation to indicate errors"; bahasa manusiawi, spesifik, konstruktif, tanpa menyalahkan pengguna; **pertahankan input pengguna** ("Preserve the user's input").
11. **Form** (#3): validasi inline; jangan validasi sebelum input selesai; hapus error begitu diperbaiki; bantuan ekstra bila error sama terulang **≥3 kali**.
12. **Waktu respons** (#4): 0,1 s (seketika), 1,0 s (alur pikir), 10 s (perhatian); indikator persen untuk operasi > ±10 detik.
13. **Indikator progres** (#5): umpan balik seketika selalu; spinner untuk aksi 2–10 detik; **jangan** indikator statis "Loading…"; umpan balik bergerak menaikkan kesediaan menunggu hingga 3× lipat.
14. **Lansia** (#6): teks kecil/kontras buruk/target nyaris tak terlihat adalah hambatan utama; "When older users encounter error handling, simplicity is even more important than usual"; pengguna 65+ **43% lebih lambat** dan hampir 2× lebih mudah menyerah.
15. **Baymard** (#7, #8): "31% of sites don't have any inline validation at all"; hapus pesan error saat field diperbaiki; pesan adaptif spesifik per masalah (mis. "missing the @ character"); rekomendasi 4–7 pesan untuk input kompleks. **Konteks checkout, bukan chat.**

## Hitungan / State / Red flag

- **Hitungan:** N/V (pedoman, bukan aplikasi).
- **State terlihat:** tidak ada; pedoman tentang waiting/error/loading diadopsi sebagai acuan state di pattern.
- **Red flag:** tidak ada.
- **Yang TIDAK bisa diverifikasi:** pedoman NN/g tidak memuat studi khusus **read receipt**, pemulihan jaringan chat, atau telemedicine; tidak memvalidasi angka 4–7 Baymard di luar konteks form.

## Batasan

- Semua URL di atas diakses 2026-10-01; artikel Baymard #9 terpaywall → dikeluarkan dari bukti.
- Pedoman ≠ bukti bahwa aplikasi mana pun mengikutinya; dipakai untuk menilai *kualitas pola*, bukan untuk skor aplikasi.

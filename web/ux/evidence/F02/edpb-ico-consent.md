# F02 — Standar persetujuan EDPB / ICO / WP29 (bukan aplikasi: sumber fakta standar)

Format: `[URL] [akses 2026-10-01]`. Tidak dinilai sebagai aplikasi; dipakai untuk kriteria
2, 4, 6, 7 dan sebagai sumber langkah pada `web/ux/patterns/F02.md`.

## Sumber

| # | Sumber | Jenis | Versi/tanggal terlihat |
|---|---|---|---|
| S1 | https://www.edpb.europa.eu/our-work-tools/our-documents/guidelines/guidelines-052020-consent-under-regulation-2016679_en [akses 2026-10-01] | Pedoman regulator UE (EDPB) | 04 Mei 2020; halaman hidup 2026 (ada PDF ringkasan 2026-04) |
| S2 | https://www.edpb.europa.eu/system/files/documents/files/file1/edpb_guidelines_202005_consent_en.pdf [akses 2026-10-01] | PDF pedoman (wp259.rev01, v1.1) | Diadopsi 4 Mei 2020 |
| S3 | https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/lawful-basis/consent/ [akses 2026-10-01] | Pedoman regulator UK (ICO) | "Latest updates – 04 August 2026" |
| S4 | https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/lawful-basis/consent/what-is-valid-consent/ [akses 2026-10-01] | Pedoman ICO | dalam peninjauan DUAA |
| S5 | https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/lawful-basis/consent/how-should-we-obtain-record-and-manage-consent/ [akses 2026-10-01] | Pedoman ICO | dalam peninjauan DUAA |
| S6 | https://ico.org.uk/for-organisations/direct-marketing-and-privacy-and-electronic-communications/guidance-on-the-use-of-storage-and-access-technologies/how-do-we-manage-consent-in-practice/ [akses 2026-10-01] | Pedoman PECR ICO | aset/gambar bertanggal 2026 |
| S7 | https://www.drcf.org.uk/publications/papers/ico-cma-joint-paper-on-harmful-design-in-digital-markets [akses 2026-10-01] | Makalah bersama ICO + CMA (DRCF) | 9 Agustus 2023 |
| S8 | https://ec.europa.eu/justice/article-29/documentation/opinion-recommendation/files/2011/wp187_en.pdf [akses 2026-10-01] | Opini WP29 15/2011 (WP187) | 13 Juli 2011; URL hidup (isi dibaca lewat indeks pencarian) |
| S9 | https://ec.europa.eu/justice/article-29/documentation/opinion-recommendation/files/2013/wp208_en.pdf [akses 2026-10-01] | Working Document WP29 02/2013 (WP208) | 2013 |

## Langkah terlihat (fakta yang disyaratkan/direkomendasikan)

1. **Empat syarat**: "freely given, specific, informed and unambiguous", lewat pernyataan
   atau **tepat sama/surat pernyataan afirmatif yang jelas** [S1][S2, par. 8/11].
2. **Pilihan bebas**: menolak/menarik persetujuan **tanpa kerugian** [S2, par. 13; S4];
   pengikatan persetujuan dengan layanan/persetujuan lain diduga **tidak bebas**
   (Pasal 7(4) GDPR) [S2, par. 26].
3. **Bukan diam/preset**: "Silence, pre-ticked boxes or inactivity should not therefore
   constitute consent" (Recital 32 dikutip ICO) [S4]; ICO: tidak ada "opt-out consent",
   tidak boleh mengandalkan "default settings, pre-ticked boxes … inertia, inattention or
   default bias" [S4]; mekanisme persetujuan **"harus semudah menolak adalah menerima"**
   dan meminta **tindakan positif**; "Silence or inactivity doesn't qualify" [S6];
   larangan "pre-ticked boxes (or equivalents such as 'on' sliders)" + pilihan per tujuan
   (granular) [S6].
4. **Penarikan semudah pemberian** (Pasal 7(3)): ICO mengharapkan "easily accessible
   one-step process", idealnya cara yang sama [S5].
5. **Gelap/gelap desain (dark patterns)**: praktik berbahaya dinamai: "harmful nudges and
   sludge", "confirmshaming", "biased framing", "bundled consent", "default settings";
   penolakan harus **setara** — tombol "Reject all" setara menonjol di samping "Accept all",
   tanpa langkah tambahan [S7].
6. **WP29 klasik**: persetujuan = "active indication of the user's wishes"; pengguna harus
   mengambil "tindakan yang jelas dan positif"; diam/tanpa tindakan sulit dianggap persetujuan
   tidak ambigu [S9]; mekanisme opt-out berbasis cookie tidak memenuhi persetujuan efektif
   [S8].
7. **EDPB 2020**: bagian Conditionality (par. 38–41) soal "cookie wall" dan par. 86 soal
   *scrolling* direvisi dalam versi 4 Mei 2020 [S2].

## Hitungan

Bukan aplikasi — tidak dihitung.

## State terlihat

Tidak berlaku (dokumen pedoman, bukan UI). Persyaratan state yang bisa diturunkan:
keputusan harus bisa dibaca ulang (catatan persetujuan), bisa ditarik, dan penolakan
setara dengannya [S5][S7].

## Red flag

Sumber ini adalah **anti-red-flag**: mendefinisikan bundled consent, pre-ticked,
opt-out-by-default, dan consent wall sebagai pelanggaran [S4][S6][S7].

## Yang TIDAK bisa diverifikasi

- **"WP29 Opinion 05/2010 on the concept of consent (wp170)"**: premis tidak terbukti —
  WP170 adalah Work Programme 2010–2011 dan "Opinion 5/2010" adalah tentang RFID; opini
  persetujuan WP29 adalah **WP187 / Opinion 15/2011**. Tidak ada halaman EDPB berjudul
  "Opinion 05/2010 on the concept of consent" → **TIDAK TERVERIFIKASI**.
- Teks verbatim EDPB par. 38–41 dan par. 86 (hanya lingkup revisi yang terbaca dari
  prakata PDF [S2]).
- Teks verbatim WP187 (PDF ter-fetch sebagai biner; isi dibaca lewat indeks pencarian).
- Halaman terpisah "dark patterns" di ico.org.uk (URL tebak 404); dipakai [S6] + [S7].

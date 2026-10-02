# Evidence F12 — Doctolib (batal + pindah janji; telemedicine global) — **RED FLAG, tidak dipakai sebagai pola utuh**

Tanggal akses semua sumber: **2026-10-02**. Sumber help center publik (Zendesk) + artikel resmi. **Peringatan:** Doctolib sudah **didiskualifikasi di sesi F11** karena red flag privasi terverifikasi (consent riset AI kesehatan default-ikut/opt-out; sumber `web/ux/report.md` §4.5, The Local, 4 Agu 2026). Sesuai aturan rubrik #8, Doctolib **tidak boleh menjadi pemenang atau pola untuk ditiru**; file ini mendokumentasikan **mekanisme** dan **apa yang harus dihindari**, bukan untuk disalin.

## Sumber

| # | URL | Jenis | Versi/platform | Catatan akses |
|---|---|---|---|---|
| S1 | https://doctolibpatient.zendesk.com/hc/de/articles/14141943711132-I-want-to-cancel-my-appointment | Help center resmi — membatalkan janji | Web; **terakhir diubah 7 Agu 2025** (konten Inggris di locale /hc/de) | Dibaca penuh 2026-10-02 |
| S2 | https://doctolibpatient.zendesk.com/hc/de/articles/14141937839516-I-want-to-move-my-appointment | Help center resmi — memindahkan janji | Web; **diubah 7 Agu 2025** | Dibaca penuh 2026-10-02 |
| S3 | https://doctolibpatient.zendesk.com/hc/de/articles/14141921737116-Can-I-call-Doctolib-to-book-or-change-an-appointment | Help center — booking/perubahan hanya lewat app | Web | **Hanya cuplikan indeks** 2026-10-02 |
| S4 | https://www.doctolib.de/gesundheit/en/cancelling-a-doctors-appointment | Artikel resmi “Cancelling a doctor’s appointment: how to avoid cancellation fees” | Web; dipublikasikan **11 Jun 2026** | **Hanya cuplikan indeks** 2026-10-02 |
| S5 | https://doctolib.zendesk.com/hc/en-gb/categories/360005494291-Doctolib-Patient | Kategori help center pasien | Web | Hanya daftar kategori |

## Langkah terlihat (fakta)

**Membatalkan janji (S1) — 8 langkah terdokumentasi:**
1. Login → 2. **Appointments** → 3. bagian **Upcoming** → 4. pilih janji → **Cancel appointment** → 5. **jika janji <48 jam**: pilih **Continue** (lanjut batal), **keep the appointment** (pertahankan), atau **convert to a video consultation** → 6. pilih **alasan** dari dropdown → 7. (opsional) tulis detail alasan → 8. **Confirm cancellation** → email konfirmasi.
- **Jendela pembatalan diatur praktik:** “May vary between **30 minutes and 16 hours** before the appointment; may vary from one reason of visit to another.” Jika jendela terlewat: “it is **essential to contact the practice by phone**” (S1).
- **Alasan wajib** (dropdown), detail opsional (S1).
- **Peringatan privasi eksplisit pada kolom bebas:** jangan menuliskan **informasi kesehatan** pengguna/keluarga (data sensitif), jangan memakainya untuk permintaan resep/gejala/permintaan kontak darurat (S1). Ini praktik yang sejalan dengan UU PDP.

**Memindahkan janji (S2) — 5 langkah:**
1. Login → 2. **Appointments** → 3. pilih janji → 4. **Reschedule** → 5. ketuk **slot baru** untuk memvalidasi → email konfirmasi; “Your appointment has been moved successfully.”
- **Jendela pindah online bergantung spesialisasi:** **1 jam** sebelum janji (dokter umum, anak, kulit, THT, radiologi); **2 jam** (mata, kandungan, jantung, gigi, anestesi, jiwa, urologi, bidan, ortopedi, trauma); **4 jam** untuk spesialisasi lain (S2).
- Jika batas terlampaui → hubungi praktik **via telepon** (S2).
- Bisa juga dari **email/SMS konfirmasi/pengingat** (tautan Reschedule/Cancel) (S1, S2).
- **Tanpa biaya** yang dinyatakan untuk reschedule; pembayaran di Doctolib terjadi di praktik (bukan prepaid) — konteks berbeda dari Sehatly (S1, S2 tidak menyebut refund).

**Kanal (S3, indeks):** Doctolib **tidak** melayani booking/perubahan lewat telepon ke customer support; urusan medis/administratif ke praktik. Ini pembatasan kanal, bukan penyembunyian tombol.

## Hitungan (titik awal yang sama)

| Besaran | Nilai | Dasar |
|---|---|---|
| Langkah batal | **8** (termasuk langkah retensi <48 jam) | S1 |
| Langkah pindah | **5** | S2 |
| Field batal | alasan (dropdown, wajib) + detail (opsional) | S1 |
| Jendela | batal 30 menit–16 jam (praktik); pindah 1/2/4 jam (spesialisasi) | S1, S2 |
| Refund | **TIDAK ADA** — pembayaran bukan di platform | S1, S2 |

## State terlihat

- **Jendela terlewat:** pembatalan online diblokir + arahan telepon ke praktik (S1, S2). ✓ (jujur, tetapi pemulihan tidak satu ketukan)
- **Retensi <48 jam:** penawaran mempertahankan janji atau **ubah ke video** sebelum batal (S1). ⚠ bukan dark pattern (masih ada Continue), tetapi harus diuji agar tidak menutupi tombol batal.
- **Konfirmasi:** email setelah batal/pindah (S1, S2). ✓
- Loading/kosong/error/offline: tidak didokumentasikan → N/V.

## Red flag

1. **RED FLAG WARISAN (diskualifikasi):** consent riset AI kesehatan default-ikut/opt-out — diverifikasi di sesi F11 (`report.md` §4.5; The Local, 4 Agu 2026). **Doctolib tidak boleh menjadi pemenang atau pola utuh.**
2. **Bukan dark pattern, tapi tidak boleh disalin:** saat jendela terlewat, **satu-satunya jalan adalah menelepon** — pengguna jaringan buruk/lansia bisa gagal total. Sehatly harus tetap menyediakan jalur in-app (permintaan batal ke klinik) atau tombol dengan konsekuensi jelas, bukan menghilangkan aksi.
3. **Yang justru layak diadopsi (prinsip, bukan tampilan):** peringatan **“jangan tulis data kesehatan di kolom alasan”** — sejalan UU PDP dan langsung berlaku untuk `alasan_pembatalan` Sehatly yang saat ini bebas teks.
4. Langkah retensi <48 jam tidak boleh menutupi tombol pembatalan.

## Yang TIDAK bisa diverifikasi

1. UI native/visual Doctolib — hanya langkah help center; tidak ada screenshot.
2. Apakah reschedule tersedia jika pembayaran sudah dilakukan di platform (mis. Doctolib Jerman) — tidak didokumentasikan di sumber ini.
3. Apakah ada biaya/no-show fee — S4 (indeks) membahas “avoid cancellation fees”, isi tidak dibuka; **jangan disimpulkan**.
4. Berapa lama review bila praktik harus menyetujui reschedule (alur tampak instan, tetapi tidak dinyatakan untuk semua praktik).

## Independensi sumber

- S1, S2, S3, S5 `zendesk.com` (help center resmi Doctolib, satu penerbit); S4 `doctolib.de`. **Satu penerbit.**
- Status: **red flag → tidak memenuhi syarat pemenang**; hanya mekanisme yang dicatat, dengan seluruh red flag dan batasnya.

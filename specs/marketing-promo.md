# Spec: `MarketingPromoPage` — Daftar, Buat, Detail, Edit, Nonaktifkan Promo

**Proyek:** Sehatly — WS-7 Marketing  
**Path target:** `frontend/src/pages/marketing-promo-page.tsx` (ganti stub 18 baris)  
**Status:** DESIGN (Phase 1) — implementasi oleh frontend Phase 2

---

## 1. Tujuan

Halaman manajemen promo: daftar promo dalam tabel, tombol buat baru, detail, edit, dan nonaktifkan (soft delete via `status_aktif = 0`).

---

## 2. Alur layar

```
PageHeader
  title: "Kelola Promo"
  description: "Buat dan kelola kode promo untuk pasien."
  action: Button "Buat Promo" (+ icon Plus) → buka Dialog atau navigasi ke /marketing/promo/baru

[State: Loading | Kosong | Error | Data]

[Jika Data: Tabel Promo]
  Kolom: Kode | Judul | Tipe Diskon | Nilai | Kuota | Berlaku | Status | Aksi
  Baris: kode, judul, tipe diskon, nilai diskon, kuota, tanggal berlaku, badge status, tombol Aksi
```

### 2.1 Tabel promo

Tidak ada `table.tsx` di `components/ui/` — gunakan elemen HTML semantik (`<table>`, `<thead>`, `<tbody>`, `<tr>`, `<th>`, `<td>`) dengan styling token.

| Kolom | Konten | Styling |
|---|---|---|
| Kode | Kode promo (huruf besar) | `font-mono` atau `text-sm font-medium`, `text-foreground` |
| Judul | Teks | `text-sm`, `text-foreground` |
| Tipe Diskon | Teks (persen / nominal) | `text-muted-foreground`, `text-sm` |
| Nilai | Angka, `tabular-nums` | `text-sm`, `tabular-nums` |
| Kuota | "X tersisa" atau "Tak terbatas" | `text-muted-foreground`, `text-sm` |
| Berlaku | Tanggal range `04 Okt – 31 Okt 2026` | `text-muted-foreground`, `text-sm` |
| Status | Badge `successSubtle` (aktif) / `destructiveSubtle` (nonaktif) | teks + ikon + warna |
| Aksi | Detail / Edit / Nonaktifkan | `Button` size `icon` / `size-sm`, variant `outline` |

Row hover: `bg-accent`, focus ring `--ring`.

### 2.2 Badge status promo

| `status_aktif` | Varian badge | Ikon | Label |
|---|---|---|---|
| `true` | `successSubtle` | `CheckCircle` | Aktif |
| `false` | `destructiveSubtle` | `Ban` | Nonaktif |

---

## 3. Form create/edit promo

**Pertanyaan terbuka:** Form dalam `Dialog` (modal) atau halaman terpisah (`/marketing/promo/create`, `/marketing/promo/:id/edit`)?

Field form (react-hook-form + zod resolver):

| Field | Tipe | Validasi | Label |
|---|---|---|---|
| `kode` | teks | required, uppercase, max 50 | Kode Promo |
| `judul` | teks | required, max 200 | Judul Promo |
| `deskripsi` | textarea | optional | Deskripsi |
| `tipe_diskon` | select | required | Tipe Diskon |
| `nilai_diskon` | angka | required, > 0 | Nilai Diskon |
| `kuota` | angka | optional, ≥ 0 | Kuota Penggunaan |
| `berlaku_dari` | date picker | required | Berlaku Dari |
| `berlaku_sampai` | date picker | required, ≥ berlaku_dari | Berlaku Sampai |
| `status_aktif` | checkbox | optional | Aktif |

Tombol simpan: `Button` variant `primary` ("Simpan promo"), tombol batal: `Button` variant `outline` ("Batal").

Error validasi (422): inline di bawah field, bukan toast.

Tanggal: format `id-ID`, zona waktu WIB/WITA/WIT. Pakai `@daypicker/react`.

---

## 4. Detail promo

Tampilkan:
- Kode promo besar
- Judul
- Deskripsi
- Tipe diskon & nilai
- Kuota & terpakai
- Tanggal berlaku
- Status badge
- Tanggal dibuat & diubah
- Aksi: Edit, Nonaktifkan, Kembali

---

## 5. Nonaktifkan promo (soft delete)

Aksi berisiko → `Dialog` konfirmasi:
- Judul: "Nonaktifkan promo?"
- Isi: "Promo ini tidak bisa lagi digunakan oleh pasien. Tindakan ini dapat diubah kembali."
- Tombol konfirmasi: `variant="destructive"`, label "Nonaktifkan promo"
- Tombol batal: variant `outline`, label "Batal"

Setelah sukses: toast `sonner` ("Promo dinonaktifkan.") + invalidate promo list query.

---

## 6. State (3 state wajib)

| State | Tampilan |
|---|---|
| **Loading** | `LoadingState` "Memuat daftar promo..." + `SkeletonRows` di tabel |
| **Kosong** | `EmptyState` judul "Belum ada promo", deskripsi, tombol "Buat promo pertama" |
| **Error** | `ErrorState` + tombol "Coba lagi" |

---

## 7. Copy (Bahasa Indonesia)

| Elemen | Copy |
|---|---|
| Judul halaman | Kelola Promo |
| Deskripsi | Buat dan kelola kode promo untuk pasien. |
| Tombol buat | Buat Promo |
| Kolom tabel | Kode, Judul, Tipe Diskon, Nilai, Kuota, Berlaku, Status, Aksi |
| Detail | Detail Promo |
| Edit | Edit Promo |
| Nonaktifkan konfirmasi judul | Nonaktifkan promo? |
| Nonaktifkan konfirmasi isi | Promo ini tidak bisa lagi digunakan oleh pasien. |
| Nonaktifkan tombol | Nonaktifkan promo |
| Sukses toast | Promo disimpan. / Promo dinonaktifkan. |
| Loading | Memuat daftar promo... |
| Kosong judul | Belum ada promo |
| Kosong deskripsi | Mulai dengan membuat promo pertama. |
| Error judul | Gagal memuat promo |
| Error tombol | Coba lagi |

---

## 8. Responsive

- 390 px: tabel scroll horizontal, tombol aksi stacked
- 768 px: tabel penuh
- 1280 px: tabel lebar, semua kolom terlihat

---

## 9. Pertanyaan terbuka

1. Form create/edit: `Dialog` atau halaman terpisah?
2. `tipe_diskon` enum values — perlu konfirmasi dari `master_promo.tipe_diskon` (lihat `docs/enums.json` baris 236).
3. `nilai_diskon` adalah `Decimal` (string di JSON) — perlu parsing sebelum ditampilkan dalam input angka.
4. Apakah daftar promo perlu filter status aktif/nonaktif dan pencarian kode/judul?

# Spec: `MarketingArtikelPage` — Daftar, Buat, Detail, Edit, Arsip Artikel

**Proyek:** Sehatly — WS-7 Marketing  
**Path target:** `frontend/src/pages/marketing-artikel-page.tsx` (ganti stub 18 baris)  
**Status:** DESIGN (Phase 1) — implementasi oleh frontend Phase 2

---

## 1. Tujuan

Halaman manajemen artikel: daftar artikel dalam tabel, tombol buat baru, detail, edit, dan arsip (soft delete via `status='arsip'`).

---

## 2. Alur layar

```
PageHeader
  title: "Kelola Artikel"
  description: "Buat dan kelola artikel kesehatan untuk halaman publik."
  action: Button "Buat Artikel" (+ icon Plus) → buka Dialog atau navigasi ke /marketing/artikel/baru

[State: Loading | Kosong | Error | Data]

[Jika Data: Tabel Artikel]
  Kolom: Judul | Status | Kategori | Penulis | Diperbarui | Aksi
  Baris: judul (teks), badge status (teks+ikon+warna), kategori, penulis, tanggal diubah, tombol Aksi (Detail / Edit / Arsip)
```

### 2.1 Tabel artikel

Tidak ada `table.tsx` di `components/ui/` — gunakan elemen HTML semantik (`<table>`, `<thead>`, `<tbody>`, `<tr>`, `<th>`, `<td>`) dengan styling token.

| Kolom | Konten | Styling |
|---|---|---|
| Judul | Teks, tidak dipotong ellipsis (jika panjang, baris 2) | `text-foreground`, `text-sm` |
| Status | Badge `*-subtle` + ikon + label | `badgeVariants({variant: 'successSubtle'|'warningSubtle'|'destructiveSubtle'})` |
| Kategori | Teks atau "-" jika null | `text-muted-foreground`, `text-sm` |
| Penulis | Teks atau "-" jika null | `text-muted-foreground`, `text-sm` |
| Diperbarui | Tanggal `04 Okt 2026` | `text-muted-foreground`, `text-sm`, `tabular-nums` |
| Aksi | Tombol ikon kecil (Detail, Edit, Arsip) | `Button` size `icon` / `size-sm`, variant `outline` |

Row hover: `bg-accent` (ringan), focus ring `--ring`.

### 2.2 Badge status artikel

| `status` | Varian badge | Ikon | Label |
|---|---|---|---|
| `draft` | `secondary` (atau netral) | `Edit` | Draft |
| `review` | `warningSubtle` | `AlertCircle` | Review |
| `terbit` | `successSubtle` | `CheckCircle` | Terbit |
| `arsip` | `destructiveSubtle` | `Archive` | Arsip |

> **Pertanyaan terbuka:** `draft` pakai varian apa? Tidak ada `mutedSubtle` di `badge.tsx`. Opsi: gunakan `outline` untuk draft, atau tambah varian `mutedSubtle` di `badge.tsx` (perlu izin ubah komponen).

---

## 3. Form create/edit artikel

**Pertanyaan terbuka:** Form dalam `Dialog` (modal) atau halaman terpisah (`/marketing/artikel/create`, `/marketing/artikel/:id/edit`)? Pola repo saat ini (`kurir`) pakai detail page terpisah. Untuk marketing, disarankan `Dialog` untuk create/edit sederhana, atau halaman terpisah jika form panjang.

Field form (react-hook-form + zod resolver):

| Field | Tipe | Validasi | Label |
|---|---|---|---|
| `judul` | teks | required, min 3, max 200 | Judul |
| `slug` | teks | optional, max 200 | Slug |
| `ringkasan` | textarea | optional, max 500 | Ringkasan |
| `isi` | textarea | optional | Isi artikel |
| `status` | select | required, enum draft/review/terbit/arsip | Status |
| `kategori_id` | select | optional | Kategori |
| `tampil_di_halaman_umum` | checkbox | optional | Tampil di halaman umum |

Tombol simpan: `Button` variant `primary` ("Simpan artikel"), tombol batal: `Button` variant `outline` ("Batal").

Error validasi (422): inline di bawah field yang bersangkutan, bukan toast.

---

## 4. Detail artikel

Lihat artikel dalam `Dialog` atau halaman terpisah. Tampilkan:
- Judul besar
- Status badge
- Ringkasan
- Isi
- Penulis, kategori
- Tanggal dibuat & diubah (`04 Okt 2026`, zona waktu)
- Aksi: Edit, Arsip, Kembali

---

## 5. Arsip artikel (soft delete)

Aksi berisiko → `Dialog` konfirmasi:
- Judul: "Arsip artikel?"
- Isi: "Artikel ini akan ditandai arsip dan tidak lagi tampil di halaman umum. Tindakan ini dapat dibatalkan."
- Tombol konfirmasi: `variant="destructive"`, label "Arsipkan artikel"
- Tombol batal: variant `outline`, label "Batal"

Setelah sukses: toast `sonner` ("Artikel diarsipkan.") + invalidate artikel list query.

---

## 6. State (3 state wajib)

| State | Tampilan |
|---|---|
| **Loading** | `LoadingState` "Memuat daftar artikel..." + `SkeletonRows` (3 baris placeholder) di tabel |
| **Kosong** | `EmptyState` judul "Belum ada artikel", deskripsi, tombol "Buat artikel pertama" |
| **Error** | `ErrorState` + tombol "Coba lagi" |

---

## 7. Copy (Bahasa Indonesia)

| Elemen | Copy |
|---|---|
| Judul halaman | Kelola Artikel |
| Deskripsi | Buat dan kelola artikel kesehatan untuk halaman publik. |
| Tombol buat | Buat Artikel |
| Kolom tabel | Judul, Status, Kategori, Penulis, Diperbarui, Aksi |
| Detail | Detail Artikel |
| Edit | Edit Artikel |
| Arsip konfirmasi judul | Arsip artikel? |
| Arsip konfirmasi isi | Artikel ini akan ditandai arsip dan tidak lagi tampil di halaman umum. |
| Arsip tombol | Arsipkan artikel |
| Sukses toast | Artikel disimpan. / Artikel diarsipkan. |
| Loading | Memuat daftar artikel... |
| Kosong judul | Belum ada artikel |
| Kosong deskripsi | Mulai dengan membuat artikel pertama. |
| Error judul | Gagal memuat artikel |
| Error tombol | Coba lagi |

---

## 8. Responsive

- 390 px: tabel dapat di-scroll horizontal (`overflow-x-auto`), tombol aksi stacked atau dropdown
- 768 px: tabel penuh, 2 kolom aksi
- 1280 px: tabel lebar, semua kolom terlihat

---

## 9. Pertanyaan terbuka

1. Form create/edit: `Dialog` atau halaman terpisah?
2. `slug` di-generate otomatis dari `judul` di frontend, atau dibiarkan backend?
3. Apakah daftar artikel perlu filter status dan pencarian judul? (Jika ya, tambah `Select` filter + `Input` pencarian di atas tabel.)
4. Apakah `penulis_id` diisi otomatis dari user yang login, atau dipilih dari daftar?

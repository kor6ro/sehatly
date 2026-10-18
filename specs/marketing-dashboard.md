# Spec: `MarketingDashboardPage` — Dashboard Portal Marketing

**Proyek:** Sehatly — WS-7 Marketing  
**Path target:** `frontend/src/pages/marketing-dashboard-page.tsx` (ganti stub 66 baris)  
**Status:** DESIGN (Phase 1) — implementasi oleh frontend Phase 2

---

## 1. Tujuan

Mengganti stub dashboard (2 kartu placeholder + pesan "endpoint belum tersedia") dengan layar nyata yang menampilkan data artikel dan promo dari API, dengan 3 state: loading, kosong, error.

---

## 2. Layout & struktur

```
<div className="flex flex-col gap-6">  (mobile-first, penuh lebar)
  <PageHeader
    title="Dashboard Marketing"
    description="Kelola konten artikel dan promosi Sehatly."
  />

  <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-2">
    <!-- Kartu Artikel -->
    <Card>
      <CardHeader className="flex flex-row items-center justify-between pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">Artikel</CardTitle>
        <FileText aria-hidden className="text-muted-foreground size-4" />
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <!-- Statistik: total, draft, terbit, arsip -->
        <!-- Tombol: Kelola artikel -->
      </CardContent>
    </Card>

    <!-- Kartu Promo -->
    <Card>
      <CardHeader className="flex flex-row items-center justify-between pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">Promo</CardTitle>
        <Tag aria-hidden className="text-muted-foreground size-4" />
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <!-- Statistik: total, aktif, nonaktif -->
        <!-- Tombol: Kelola promo -->
      </CardContent>
    </Card>
  </div>
</div>
```

- Tidak ada card di dalam card.
- 2 kartu samping-samping di desktop (`sm:grid-cols-2`), bertumpuk di mobile (390 px).
- Radius kartu: `--radius` (tidak `rounded-2xl`).
- Border: `border-border`, tidak ada shadow tebal, tidak ada border + shadow bersamaan.

---

## 3. Data & query

Pakai `lib/api/marketing.ts`:
- `artikelListOptions()` → daftar artikel (untuk hitung total per status)
- `promoListOptions()` → daftar promo (untuk hitung total aktif/nonaktif)

**Statistik artikel:**
- Total: jumlah semua artikel
- Draft: `status === 'draft'`
- Terbit: `status === 'terbit'`
- Arsip: `status === 'arsip'`

**Statistik promo:**
- Total: jumlah semua promo
- Aktif: `status_aktif === true`
- Nonaktif: `status_aktif === false`

---

## 4. State (3 state wajib)

| State | Tampilan |
|---|---|
| **Loading** | `LoadingState` label "Memuat dashboard..." + `SkeletonRows` di dalam setiap kartu (grafik batang placeholder) |
| **Kosong** | `EmptyState` judul "Belum ada artikel/promo", deskripsi, tombol "Buat artikel pertama" / "Buat promo pertama" |
| **Error** | `ErrorState` + tombol "Coba lagi" yang refetch kedua query |

Setiap kartu bisa sendiri-sendiri loading/error (mis. artikel sukses, promo gagal), atau seluruh halaman satu state. Disarankan: tiap kartu independen.

---

## 5. Badge statistik (opsional, di dalam kartu)

Jika ditampilkan jumlah kecil (mis. "3 draft"), pakai teks kecil `text-muted-foreground` tanpa badge berwarna, kecuali untuk status utama yang sudah punya warna (terbit = hijau, arsip = merah).

---

## 6. Aksi

- Tombol "Kelola artikel" → navigasi ke `/marketing/artikel`
- Tombol "Kelola promo" → navigasi ke `/marketing/promo`
- Semua tombol: `Button` variant `outline`, size `sm`, `w-full`, target sentuh ≥ 44 px

---

## 7. Copy (Bahasa Indonesia)

| Elemen | Copy |
|---|---|
| Judul halaman | Dashboard Marketing |
| Deskripsi | Kelola konten artikel dan promosi Sehatly. |
| Kartu artikel judul | Artikel |
| Kartu artikel deskripsi | Buat dan kelola artikel kesehatan untuk halaman publik. |
| Kartu promo judul | Promo |
| Kartu promo deskripsi | Buat dan kelola kode promo untuk pasien. |
| Tombol artikel | Kelola artikel |
| Tombol promo | Kelola promo |
| Loading label | Memuat dashboard... |
| Kosong judul | Belum ada konten |
| Kosong deskripsi | Mulai dengan membuat artikel atau promo pertama. |
| Error judul | Gagal memuat dashboard |
| Error tombol | Coba lagi |

---

## 8. Responsive

- 390 px: kartu bertumpuk (1 kolom), gap-4
- 768 px: 2 kolom (sm:grid-cols-2)
- 1280 px: 2 kolom, lebih lebar

---

## 9. Pertanyaan terbuka

1. Apakah statistik kartu menampilkan angka kasar atau juga ringkasan terbaru (artikel terakhir dipublikasikan, promo kadaluarsa)?
2. Apakah ada tombol "Buat baru" langsung di kartu dashboard, atau hanya di halaman daftar?

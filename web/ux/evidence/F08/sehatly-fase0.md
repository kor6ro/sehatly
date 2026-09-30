# Evidence F08 — Sehatly Fase 0 (baseline dari KODE, bukan ingatan)

**Jenis sumber:** kode aplikasi sendiri (bukan aplikasi pihak ketiga). Diverifikasi dengan membaca file; path + baris dikutip. Tanggal verifikasi: **2026-10-01**. Tahap riset = READ-ONLY; tidak ada kode yang diubah.

## Sumber (berkas yang dibaca)

| Berkas | Fakta yang diambil |
|---|---|
| `web/src/pages/konsultasi-page.tsx` | Halaman `/konsultasi/:id`: ambil riwayat (halaman 1, `PER_HALAMAN = 50`), resync saat reconnect, wiring `onMarkRead`/`onResubscribe`, layout grid `lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]`, gate SOAP `user?.tipe === 'dokter'`, tombol "Tandai sudah dibaca" |
| `web/src/features/konsultasi/chat-window.tsx` | Kirim teks (Enter kirim / Shift+Enter baris baru), draft dipulihkan saat gagal, strip status realtime + tombol "Hubungkan ulang", statistik dedupe/resync, tampilan lampiran (toggle "Lihat"/"Sembunyikan"), centang `Check`/`CheckCheck` + `aria-label="Sudah dibaca"` |
| `web/src/features/konsultasi/status-badge.tsx` | 6 status: `menunggu_dokter` (Hourglass, warning, "Menunggu dokter"), `berlangsung` (CircleCheck, success, "Berlangsung"), `menunggu_resep` (Clock, warning, "Menunggu resep"), `selesai` (FileSignature, muted, "Selesai"), `dibatalkan` (Ban, destructive, "Dibatalkan"), `gagal` (XCircle, destructive, "Gagal") — teks + ikon + warna, bukan warna saja |
| `web/src/features/konsultasi/soap-form.tsx` | 6 field SOAP → `PUT /konsultasi/{id}/selesai` (juga menutup sesi), tombol "Simpan catatan SOAP", verifikasi balasan server per kolom |
| `web/src/hooks/use-konsultasi-channel.ts` | Klien singleton (dedupe bertahan antar-navigasi), subscribe + seed riwayat |
| `web/src/lib/realtime/{konsultasi-realtime,dedupe,transcript,socket,channel}.ts` | Kanal `konsultasi.{id}` → wire `private-konsultasi.{id}`; event `chat.pesan`; watchdog 12 detik; urutan resume = subscribe dulu lalu resync; `MessageDedupe` (kapasitas 500, kunci `konsultasi_chat.id`); merge `gabungTranscript` urut `(terkirim_at, id)` |
| `web/src/lib/api/konsultasi.ts` | Endpoint + `kirimBerkas` (FormData, **tidak pernah dipanggil UI**), `mulaiKonsultasi`/`terimaKonsultasi` (**tidak pernah dipanggil UI**) |
| `web/src/styles/app.css` | Token nyata: `--background/foreground/card/primary/secondary/muted/accent/destructive/success/warning/border/input/ring/radius` + chart/sidebar; `--font-sans: 'Instrument Sans'`; **tidak ada** `--spacing-*`/`--font-size-*` |
| `web/src/components/ui/` (27) | alert, avatar, badge, breadcrumb, button, card, checkbox, collapsible, dialog, dropdown-menu, icon, input, input-otp, label, navigation-menu, placeholder-pattern, select, separator, sheet, sidebar, skeleton, sonner, spinner, textarea, toggle, toggle-group, tooltip. **Tidak ada** `tabs`, `radio-group`, `popover`, `progress`, `alert-dialog`, `switch`, `slider`, `stepper`, `calendar` |
| `web/src/components/states/*`, `web/src/components/form/field.tsx` | `loading-state` (SkeletonRows), `empty-state`, `error-state`, `Field/FieldTextarea` |

Endpoint backend (dari `web/ux/flows.md` + pemakaian di kode): `POST /konsultasi/mulai`, `GET /konsultasi/{id}`, `PUT /konsultasi/{id}/terima`, `GET/POST /konsultasi/{id}/chat`, `POST /konsultasi/{id}/chat/baca`, `PUT /konsultasi/{id}/selesai`. Batas lampiran server: `BERKAS_MAKS_KB = 10240` (10 MB) + allow-list MIME per tipe (`app/Services/Konsultasi/KonsultasiService.php`).

## Langkah terlihat (fakta, dari kode)

1. Buka `/konsultasi/:id` → PageHeader "Konsultasi #{id}" + `KonsultasiStatusBadge`; riwayat `GET /konsultasi/{id}/chat?page=1&per_page=50` (urut naik `(terkirim_at, id)`); kanal Reverb disubscribe + `seedHistory`.
2. Ketik di composer (1 field, label "Tulis pesan") → Enter / ketuk "Kirim" → `POST /konsultasi/{id}/chat` `{tipe_pesan:'teks', isi}` → query diinvalidate → bubble muncul untuk kedua pihak lewat `chat.pesan`.
3. Ack: bubble memakai `Check` (belum dibaca) / `CheckCheck` (`aria-label="Sudah dibaca"`) dari field `dibaca_at` — **hanya lewat REST** (`POST /chat/baca`), tanpa event realtime; pembaca dijalankan otomatis saat baris tak-terbaca tampil dan lewat tombol "Tandai sudah dibaca".
4. Putus koneksi → strip status: "Tersambung realtime" / "Menghubungkan kanal" / "Kanal ditolak server" / "Mode REST, tanpa realtime", plus "duplikat ditahan: n", "resync: n", dan tombol **"Hubungkan ulang"** (`role="status"`, `aria-live="polite"`). Watchdog 12 detik; saat pulih: resubscribe → fetch halaman 1 → backfill melewati gerbang dedupe.
5. Dokter: form SOAP 6 field → `PUT /konsultasi/{id}/selesai` → status `selesai`; pasien hanya melihat badge + ringkasan.
6. Gagal kirim → draft dipulihkan + pesan "Pesan gagal dikirim." (tanpa tombol coba lagi otomatis).
7. Lampiran hanya **ditampilkan** (toggle lihat + ukuran KB); **tidak ada UI pengiriman** (`kirimBerkas` tak terpanggil).

## Hitungan (titik awal: user membuka ruang konsultasi → satu pesan terkirim & diakui)

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | 1 | Satu halaman `/konsultasi/:id` (desktop: split 2 bagian, tetapi satu layar) |
| Field | 1 | Composer teks |
| Ketukan | 1 | Ketuk "Kirim" (atau Enter) setelah mengetik |
| Ack | otomatis saat pihak lain membaca + refetch | `dibaca_at` via `POST /chat/baca`; **tanpa event realtime** → bisa tertunda hingga refetch berikutnya |

Sesi ditutup (jalur dokter): +6 field SOAP + 1 ketukan "Simpan catatan SOAP".

## State terlihat

- **Loading:** skeleton halaman/riwayat (`SkeletonRows`). ✓
- **Kosong:** "Belum ada pesan" (deskripsi berisi jargon teknis endpoint di halaman). ✓
- **Error:** `ErrorState` + "Pesan gagal dikirim."; jargon teknis di beberapa deskripsi. ✓
- **Offline/reconnect:** strip realtime 4 status + "Hubungkan ulang" + statistik dedupe/resync; **tanpa** deteksi `navigator.onLine`/banner offline/antrean tulis. ✓ sebagian
- **Sesi habis:** refresh single-flight → redirect (polanya ada di F15). ✓ (per `flows.md`)
- **Sukses:** pesan terkirim; badge status. ✓
- **Parsial:** "Mode REST, tanpa realtime" saat Reverb mati — REST tetap jalan. ✓

## Red flag (dalam kode Sehatly sendiri — untuk diperbaiki, bukan ditiru)

1. Deskripsi UI memuat istilah teknis endpoint ("Dibaca dari GET /api/v1/konsultasi/{id}…", "private-konsultasi.{id}") → kebisingan untuk pasien.
2. Placeholder composer: "Tulis pesan untuk Patienten atau Dokter." — kata asing "Patienten" (typo).
3. Teks "Percakapan ini sudah ditutup…" ada di kode tetapi **tidak pernah tampil** (`disabled` tidak pernah dikirim halaman) → status sesi tutup tak terlihat pasien.
4. Tidak ada aksi "terima"/"mulai" padahal endpoint `PUT /konsultasi/{id}/terima` ada; tidak ada aksi akhir sesi untuk pasien; tidak ada indikator mengetik; tidak ada UI video.

## Yang TIDAK bisa diverifikasi (baseline)

- Kontras aktual komponen (tidak diukur di runtime) → K4 dinilai sebagian.
- Perilaku lintas-perangkat (dua perangkat membuka konsultasi sama) — tidak diuji di riset ini.
- Tidak ada tangkapan layar Playwright yang diambil pada fase riset ini (fase gap analysis Fase 6 belum dijalankan).

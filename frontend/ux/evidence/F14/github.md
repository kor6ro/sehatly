# F14 — GitHub (audit log organisasi/enterprise, peran, export)

Titik awal hitungan: **owner organisasi membuka audit log → temukan siapa melakukan apa** (tugas inti: menjawab satu pertanyaan audit).

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://docs.github.com/en/organizations/keeping-your-organization-secure/managing-security-settings-for-your-organization/reviewing-the-audit-log-for-your-organization | Dokumentasi resmi | Audit log: pelaku, aksi, waktu; **180 hari**; **hanya owner**; **tanpa pencarian teks bebas** — hanya filter/qualifier (`actor:`, `action:`, `operation:`, `repo:`, `created:`); export **JSON/CSV** dengan dropdown Export; ada batas keras export (disarankan memperkecil dataset) | 2026-10-02 |
| S2 | https://docs.github.com/en/organizations/keeping-your-organization-secure/managing-security-settings-for-your-organization/audit-log-events-for-your-organization | Dokumentasi resmi | Skema event: `@timestamp`, `action`, `actor`, `actor_id`, `business`, `operation_type`, `org`, `repo`, `user`, `user_agent`, `token_id`, `token_scopes`, dsb.; Git events retensi khusus **7 hari** + akses via REST API; contoh `org.audit_log_export` | 2026-10-02 |
| S3 | https://docs.github.com/en/enterprise-cloud@latest/admin/concepts/security-and-compliance/audit-log-for-an-enterprise | Dokumentasi resmi | Audit log enterprise: 180 hari; Git events 7 hari; field pelaku/affected user/repo/aksi/negara/waktu/identitas SAML-SCIM; "for actions outside of the web UI, how the user (actor) authenticated"; opsional source IP; akses lewat API untuk mengarsipkan salinan data | 2026-10-02 |
| S4 | https://docs.github.com/en/enterprise-server@3.18/admin/monitoring-activity-in-your-enterprise/reviewing-audit-logs-for-your-enterprise/configuring-the-audit-log-for-your-enterprise | Dokumentasi resmi | Retensi audit log dapat dikonfigurasi; data melewati periode **"permanently removed from disk"**; Git events opt-in; retensi harus bukan infinite untuk mengaktifkan Git events | 2026-10-02 |
| S5 | https://csrc.nist.gov/pubs/sp/800/92/final | Standar independen (NIST SP 800-92) | Log management: retensi, preservasi, proteksi integritas; "Ensuring that the original logs are not altered supports their use for evidentiary purposes"; log reduction/rotation/clearing | 2026-10-02 |

**Verifikasi 2026:** aktif — dokumentasi dapat diakses 2026-10-02. Versi: organization/enterprise cloud + GHES 3.18.

## Langkah terlihat (fakta)

1. **Buka audit log** [S1]: hanya **owner**; rentang default 180 hari; Git events 7 hari (Enterprise Cloud via REST) [S1,S2].
2. **Cari event** [S1]: **tidak ada pencarian teks bebas**; gunakan filter: `operation:create/modify/remove/access/authentication/restore/transfer`, `action:repo/team/org/...`, `actor:`, `repo:`, `created:` (ISO8601).
3. **Lihat detail event** [S2,S3]: pelaku, aksi, objek, waktu, user agent, token/scopes; untuk aksi non-UI: **cara autentikasi**; opsional source IP; identitas SAML/SCIM.
4. **Export** [S1]: dropdown **Export** → JSON atau CSV; ada hard limit; dokumentasi menyarankan memperkecil dataset dahulu.
5. **Retensi** [S4]: GHES memungkinkan set periode retensi; melewati periode = **dihapus permanen dari disk**; Git events opt-in dan butuh retensi non-infinite.
6. **Arsip via API** [S3]: API audit log untuk menjaga salinan data (debugging + kepatuhan).

## Hitungan

Dari **owner membuka audit log** → **jawaban satu pertanyaan audit**:

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | 1 (halaman audit log) | S1 |
| Ketukan | 1–2 (isi filter, Enter) untuk pengguna yang hafal sintaks; tanpa bantuan builder | S1 |
| Field | 1 query/filter (bisa kompleks) | S1 |

**Export**: 2–3 ketukan (Export → format → confirm) + batas ukuran [S1].

## State terlihat

- **Batas yang diungkap di muka**: 180 hari, hanya owner, tanpa text search, batas export, Git events 7 hari [S1–S3].
- **Penghapusan permanen** pada retensi GHES dinyatakan eksplisit [S4].
- **Tanpa UI edit/hapus event** — audit log bersifat baca (append-only di sumber) [S1].
- **Loading/kosong/error**: **TIDAK TERVERIFIKASI**.

## Red flag

- Tidak ditemukan dark pattern. Tidak ada aksi destruktif dari UI audit; retensi yang menghapus data adalah konfigurasi admin dengan peringatan eksplisit [S4].
- Catatan UX negatif: **tanpa pencarian teks bebas** dan **tanpa filter builder** = beban sintaks untuk admin non-teknis; tidak ditiru mentah (Sehatly memakai filter terstruktur).

## Yang TIDAK bisa diverifikasi

- Layout halaman audit log, state kosong/loading/error, aksesibilitas.
- Perilaku export untuk dataset besar (hanya "hard limit" tanpa angka pasti di halaman yang diakses).
- Audit trail untuk perubahan peran/permission secara rinci (kategori event ada, tetapi tidak semua isi dibaca).

# Aturan Orkestrasi Multi-Agent

Dokumen ini mengikat. Semua sub-agent bekerja di bawah aturan ini, dan
controller (agent utama) bertanggung jawab menegakkannya.

Sumber otoritas: `docs/04-Agent-Task-Pack.md` bagian 6 (Dependency Graph),
13 (Parallelization Strategy), 14 (Merge Order), dan `AGENTS.md`.

---

## 1. Peran

| Peran | Tanggung jawab |
|---|---|
| **Controller** (agent utama) | Menulis aturan, memilih wave, membuat sub-agent, memvalidasi hasil, menjalankan test, menolak hasil yang tidak lolos, menulis laporan akhir |
| **Sub-agent** | Mengerjakan tepat satu Agent Task Pack (satu nomor agent), tidak lebih |

Sub-agent **tidak boleh** membuat sub-agent sendiri.

---

## 2. Aturan global yang mengikat setiap sub-agent

Diambil langsung dari `AGENTS.md` dan Task Pack bagian 1–2.

**Wajib:**
- Baca `AGENTS.md` + 4 dokumen arsitektur sebelum menulis kode
- PSR-4, PSR-12, `declare(strict_types=1)`
- Prepared statement; tanpa ORM
- Constructor dependency injection
- `SiteContext` untuk semua data tenant
- Typed application exception
- Unit + integration test

**Dilarang:**
- Laravel, Node.js runtime, Redis, Elasticsearch, Docker, Supervisor
- Query tabel milik modul lain
- Hardcode `site_id = 1`
- Mengubah frozen contract secara diam-diam
- `SELECT *` pada query hot path
- N+1 query
- Menambah fitur yang tidak diminta

**Jika butuh mengubah kontrak beku:** berhenti, laporkan
`CONTRACT_CHANGE_REQUEST` dengan format 10-field dari `AGENTS.md`. Jangan
membuat interface tandingan.

---

## 3. Batas kepemilikan file

Setiap sub-agent hanya boleh menyentuh:

```text
app/Modules/<Module>/
database/migrations/<timestamp>_<module>_*.php
tests/Unit/Modules/<Module>/
tests/Integration/Modules/<Module>/
docs/reports/Agent-XX-Report.md
```

Dilarang menyentuh tanpa instruksi eksplisit dari controller:

```text
app/Core/**            (milik Agent 01)
app/Shared/Contracts/** (frozen)
config/**              (kecuali controller mengizinkan)
composer.json
phpunit.xml
public/.htaccess
```

Konflik file antar-wave dicegah dengan aturan: **satu wave tidak pernah berisi
dua agent yang menulis file yang sama.**

---

## 4. Urutan wave (dari Task Pack bagian 13)

```text
Wave 1  Agent 01  Core              ✅ SELESAI (116 tests green)
Wave 2  Agent 02  Multisite   ┐
        Agent 03  Identity    ┘ paralel
Wave 3  Agent 04  Content     ┐
        Agent 06  Media       ┘ paralel
Wave 4  Agent 05  Taxonomy    ┐
        Agent 07  Revision    │ paralel
        Agent 11  Blocks      ┘
Wave 5  Agent 08  Workflow
Wave 6  Agent 09  Cache       ┐
        Agent 10  SEO         │ paralel
        Agent 12  Theme       ┘
Wave 7  Agent 13  API         ┐
        Agent 14  Features    ┘ paralel
Wave 8  Agent 15  WP Migration
Wave 9  Integration / Hardening
```

---

## 5. Aturan gerbang (gate) — siapa menunggu siapa

Ini jawaban atas "mana yang dikerjakan duluan, mana yang menunggu".

**Aturan G1 — Wave berurutan.**
Wave N+1 tidak dimulai sebelum **seluruh** agent di Wave N lulus validasi.
Tidak ada pengecualian, meski sebagian agent sudah selesai lebih cepat.

**Aturan G2 — Paralel di dalam wave.**
Agent dalam satu wave dijalankan **bersamaan** dan tidak saling menunggu,
karena wave disusun agar tidak ada dependency antar-anggota wave.

**Aturan G3 — Gate kontrak.**
Sebuah wave hanya boleh dimulai jika kontrak yang dikonsumsinya sudah ada di
`app/Shared/Contracts/` dan **tidak berubah** selama wave berjalan. Jika
seorang sub-agent melaporkan `CONTRACT_CHANGE_REQUEST`, wave **dihentikan**
sampai controller memutuskan.

**Aturan G4 — Gate test.**
Sebuah agent dinyatakan lulus hanya jika:
1. `composer test` hijau untuk seluruh suite (bukan hanya test miliknya)
2. Tidak ada test lama yang jadi merah
3. Tidak ada warning / risky test
4. Migration berjalan maju **dan** rollback bersih

**Aturan G5 — Larangan forward dependency.**
Sub-agent dilarang mengimpor atau memanggil modul yang belum diimplementasi
(wave berikutnya). Jika butuh, gunakan interface yang sudah ada; jika belum
ada, laporkan ke controller.

**Aturan G6 — Satu wave, satu review.**
Controller memvalidasi hasil wave secara agregat sebelum lanjut. Hasil yang
gagal dikembalikan ke sub-agent yang sama untuk diperbaiki, bukan dikerjakan
ulang oleh agent lain.

**Aturan G7 — Tidak ada kerja paralel lintas-wave.**
Untuk mencegah merge conflict dan dependency gelap, controller tidak memulai
wave berikutnya lebih awal hanya karena ada slot kosong.

---

## 6. Alur kerja controller per wave

```text
1. Pre-flight
   - Verifikasi kontrak yang dibutuhkan wave ada dan tidak berubah
   - Verifikasi suite hijau sebelum wave dimulai (baseline)
   - Catat daftar file yang akan disentuh tiap agent

2. Dispatch
   - Buat satu sub-agent per agent di wave, dalam satu pesan (paralel)
   - Prompt berisi: mission, ownership, must provide, forbidden,
     kontrak yang dikonsumsi, path test, format laporan

3. Kumpulkan
   - Tunggu seluruh sub-agent selesai
   - Kumpulkan laporan masing-masing

4. Validasi (controller, bukan sub-agent)
   - Baca diff file yang ditulis
   - Cek batas kepemilikan: tidak ada file di luar scope
   - Cek tidak ada perubahan frozen contract
   - Jalankan `composer test` penuh
   - Jalankan migration maju + rollback
   - Cek tidak ada `site_id = 1` hardcoded, `SELECT *`, raw SQL di controller

5. Putuskan
   - LULUS  → catat, lanjut wave berikutnya
   - REVISI → kirim temuan spesifik ke sub-agent yang sama, ulangi 4
   - TOLAK  → jika melanggar kontrak/scope, hentikan dan lapor ke user

6. Catat
   - Tulis ringkasan wave ke docs/reports/
```

---

## 7. Format laporan sub-agent (wajib)

Setiap sub-agent mengembalikan tepat format ini:

```text
AGENT_REPORT
Agent:
Module:
Status: DONE | BLOCKED

Files added:
Files modified:

Contracts consumed:
Contracts provided:

Database migrations:
Events emitted:
Events consumed:

API changes:
Cache impact:
Multisite impact:
Security impact:

Tests added:
Tests passed:

Known limitations:
CONTRACT_CHANGE_REQUEST: none | <detail 10-field>
```

Laporan tanpa bagian ini dianggap tidak lengkap dan wave tidak divalidasi.

---

## 8. Kriteria penolakan otomatis

Controller menolak tanpa diskusi jika ditemukan:

```text
- file ditulis di luar scope agent
- frozen contract diubah
- site_id hardcoded
- SELECT * pada query hot path
- query ke tabel modul lain
- dependency terlarang (Redis/Node/Docker/dll)
- test baru tapi test lama jadi merah
- migration tanpa rollback
- CONTRACT_CHANGE_REQUEST tanpa format lengkap
```

---

## 9. Definisi selesai (project-level)

Proyek selesai ketika Wave 9 (Integration / Hardening) lulus dan:

```text
- seluruh suite hijau
- tidak ada CONTRACT_CHANGE_REQUEST terbuka
- cache-first path terverifikasi 0 query untuk halaman publik anonim
- isolasi multisite terbukti lewat test
- tidak ada kebocoran SQL/path/secret di mode produksi
```

---

## 10. Yang dilakukan controller sekarang

Menunggu persetujuan user atas aturan ini, lalu memulai **Wave 2**
(Agent 02 Multisite + Agent 03 Identity) secara paralel.
</content>
</content>
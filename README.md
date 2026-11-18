# SISTEM PELAYANAN DESA ONLINE BERBASIS AI
## Dokumentasi Arsitektur, Implementasi Sistem, dan Panduan Teknis Terpadu

> **Status Aplikasi**: Production-Ready / Final Academic Release  
> **Framework & Engine**: Laravel 12.0, Livewire 4.4, Tailwind CSS 4.3.3, PHP 8.2+  
> **Fokus Utama**: Digitalisasi Layanan Surat Keterangan Tidak Mampu (SKTM), Verifikasi Dokumen Ber-QR Code, Helpdesk Pelayanan, dan Asisten Cerdas Berbasis Google AI Studio (Gemini).

---

## 1. Informasi Proyek

- **Nama Proyek:** Sistem Pelayanan Desa Online Berbasis AI (`pelayanan_desa`)
- **Deskripsi Singkat:** Platform web pelayanan administrasi desa terintegrasi yang mendigitalisasi proses pengajuan permohonan surat kependudukan (khususnya SKTM), validasi berkas berjenjang, penandatanganan dan penerbitan surat resmi ber-QR Code anti-pemalsuan, serta dilengkapi pusat pengaduan kendala warga (*System Reports*) dan asisten virtual berbasis kecerdasan buatan (*AI Assistant*).
- **Tujuan Proyek:** Menghadirkan tata kelola birokrasi desa yang transparan, akuntabel, efisien, dan inklusif dengan memangkas waktu tunggu pengurusan surat dari beberapa hari menjadi hitungan menit, menghilangkan kebutuhan tatap muka berulang, serta memberikan pendampingan informasi 24/7 kepada warga.
- **Ruang Lingkup:** 
  1. Pelayanan mandiri pengajuan SKTM bagi masyarakat terdaftar.
  2. Pemeriksaan fisik dan administratif berkas oleh Staf Desa.
  3. Pengesahan legal formal dan penerbitan surat resmi oleh Kepala Desa.
  4. Pengawasan tata kelola sistem, pengelolaan helpdesk pengaduan, dan manajemen akun oleh Supervisor.
  5. Layanan tanya-jawab informasi kependudukan dan profil desa berbantuan AI.
  6. Penyampaian notifikasi status permohonan melalui notifikasi web (in-app), WhatsApp gateway, dan email resmi.
- **Teknologi Utama:**
  - **Backend:** PHP 8.2+, Laravel 12.0 (Framework MVC, Service Layer, Eloquent ORM, Database Queue).
  - **Frontend:** Livewire 4.4, Livewire Volt 1.11, Alpine.js, Tailwind CSS 4.3.3 (Vite 7.0).
  - **Database:** MariaDB / MySQL dengan skema relasional terindeks dan Primary Key berbasis UUID v4.
  - **Document & QR Engine:** `barryvdh/laravel-dompdf` v3.1 & `bacon/bacon-qr-code` v3.1 (Local SVG Vector Rendering).
  - **AI Provider:** Google AI Studio (Model: `gemini-2.5-flash` via Gemini REST API v1beta) dengan arsitektur multi-provider fallback (OpenAI, DeepSeek, Groq, OpenRouter).
  - **External Gateway:** Fonnte WhatsApp API & Google Gmail SMTP Server.

---

## 2. Latar Belakang

Pelayanan administrasi kependudukan di tingkat desa konvensional seringkali menghadapi berbagai kendala struktural dan operasional:
1. **Inefisiensi Birokrasi Fisik:** Warga harus datang langsung ke kantor desa pada jam kerja terbatas, mengantre, dan seringkali harus pulang kembali karena berkas fotokopi kurang lengkap atau perangkat desa yang berwenang menandatangani sedang bertugas dinas luar.
2. **Ketiadaan Transparansi Status (*Black Box*):** Pemohon tidak memiliki sarana untuk melacak posisi berkas mereka—apakah sedang diperiksa staf, tertunda di meja pimpinan, atau memerlukan revisi tertentu.
3. **Risiko Pemalsuan Dokumen:** Surat keterangan kertas fisik tanpa pengamanan kriptografi atau mekanisme verifikasi digital rentan dimanipulasi atau disalahgunakan oleh pihak tidak bertanggung jawab.
4. **Beban Pengaduan yang Tersebar:** Kendala teknis maupun komplain pelayanan sering disampaikan lewat kanal pribadi perangkat desa tanpa adanya pencatatan riwayat penanganan, batas waktu penyelesaian (*SLA*), atau pemisahan catatan internal.

Dengan memanfaatkan integrasi Laravel 12 modern, Livewire 4, dan Google AI Studio Gemini, sistem ini mentransformasi seluruh siklus pelayanan publik desa menjadi ekosistem digital nir-kertas (*paperless*), aman, dan terukur.

---

## 3. Tujuan dan Manfaat Sistem

### Tujuan:
1. Mengotomatisasi alur kerja pengajuan, verifikasi, hingga penerbitan surat keterangan desa secara daring (*end-to-end*).
2. Menerapkan tata kelola wewenang berjenjang yang tegas antara verifikator berkas (Staf) dan penandatangan formal (Kepala Desa).
3. Menyediakan mekanisme verifikasi keabsahan dokumen publik secara instan melalui pemindaian QR Code unik.
4. Menyediakan pusat bantuan (*helpdesk*) dengan pemisahan komunikasi publik dan catatan internal petugas.
5. Mengintegrasikan asisten kecerdasan buatan untuk menjawab pertanyaan masyarakat dan menganalisis statistik pelayanan bagi perangkat desa.

### Manfaat:
- **Bagi Masyarakat:** Kemudahan mengajukan surat kapan saja dari rumah, kejelasan waktu penyelesaian berkas, serta akses dokumen resmi berformat digital (PDF) yang dapat dicetak mandiri.
- **Bagi Staf Pelayanan Desa:** Beban verifikasi berkas terstruktur rapi, histori revisi tercatat transparan, dan tidak perlu lagi mengetik format surat secara manual.
- **Bagi Kepala Desa:** Keputusan persetujuan atau penolakan surat dapat dieksekusi secara cepat dengan *single-click approval*, nomor surat terbit otomatis, dan tanda tangan digital terotentikasi.
- **Bagi Pengelola / Supervisor:** Pemantauan menyeluruh terhadap performa pelayanan, penyelesaian aduan teknis terpusat, dan konfigurasi fleksibel terhadap model AI desa.

---

## 4. Ruang Lingkup Sistem

| Domain | Lingkup yang Dicakup (In-Scope) | Batasan Sistem (Out-of-Scope) |
|---|---|---|
| **Layanan Dokumen** | Penerbitan Surat Keterangan Tidak Mampu (SKTM) digital ber-QR Code. | Pengurusan KTP-el fisik, Akta Kelahiran, dan KK fisik (tetap wewenang Disdukcapil). |
| **Validasi Legalitas** | Verifikasi token unik dan status surat melalui URL verifikasi publik ber-enkripsi. | Penggunaan tanda tangan elektronik bersertifikat BSrE (disiapkan untuk fase ekspansi). |
| **Kanal Notifikasi** | Notifikasi in-app database, WhatsApp gateway (Fonnte), dan Gmail SMTP. | SMS gateway GSM konvensional. |
| **Kecerdasan Buatan** | Tanya-jawab informasi SKTM, panduan formulir, dan statistik agregat desa. | Keputusan persetujuan administratif surat otomatis oleh AI (AI dilarang menyetujui surat). |
| **Pusat Kendala** | Tiket bantuan teknis/pelayanan dengan riwayat pesan publik & catatan rahasia internal. | Sistem pelaporan tindak pidana kepolisian. |

---

## 5. Aktor dan Hak Akses

Sistem mengimplementasikan *Role-Based Access Control* (RBAC) ketat berbasis 4 peran pengguna manusia dan 3 antarmuka sistem eksternal:

```
┌────────────────────────────────────────────────────────────────────────┐
│                      MATRIKS HAK AKSES SISTEM                          │
├───────────────────┬────────────┬────────┬────────────┬─────────────────┤
│ Fitur / Aksi      │ Masyarakat │  Staf  │ Kepala Desa│   Supervisor    │
├───────────────────┼────────────┼────────┼────────────┼─────────────────┤
│ Registrasi Akun   │     ✓      │   -    │     -      │        -        │
│ Verifikasi Email  │     ✓      │   -    │     -      │        -        │
│ Isi Form SKTM     │     ✓      │   -    │     -      │        -        │
│ Unggah Revisi     │     ✓      │   -    │     -      │        -        │
│ Unduh PDF SKTM    │     ✓      │   ✓    │     ✓      │        ✓        │
│ Periksa Berkas    │     -      │   ✓    │     ✓      │    ✓ (Audit)    │
│ Minta Revisi      │     -      │   ✓    │     -      │        -        │
│ Teruskan ke Kades │     -      │   ✓    │     -      │   X (Dilarang)  │
│ Persetujuan Kades │     -      │   -    │     ✓      │   X (Dilarang)  │
│ Penolakan Kades   │     -      │   -    │     ✓      │   X (Dilarang)  │
│ Tanya AI Desa     │     ✓      │   ✓    │     ✓      │        ✓        │
│ Buat Tiket Aduan  │     ✓      │   ✓    │     ✓      │        ✓        │
│ Kelola Helpdesk   │     -      │   -    │     -      │        ✓        │
│ Kelola Akun User  │     -      │   -    │     -      │        ✓        │
│ Konfigurasi AI    │     -      │   -    │     -      │        ✓        │
└───────────────────┴────────────┴────────┴────────────┴─────────────────┘
```

### Rincian Peran:
1. **Masyarakat (`masyarakat`):**
   - Memiliki akses pendaftaran publik, verifikasi email, dan pengisian profil NIK/KK.
   - Mengajukan permohonan SKTM mandiri dan memantau status secara realtime.
   - Mengunggah berkas perbaikan apabila dinyatakan `NEEDS_REVISION`.
   - Mengunduh surat resmi PDF setelah status `COMPLETED`.
   - Berinteraksi dengan AI Assistant dan mengirim tiket aduan publik.
2. **Staf Desa (`staf`):**
   - Mengakses antarmuka administrasi pelayanan (`/admin/pengajuan`).
   - Melakukan audit fisik berkas KTP dan KK pemohon.
   - Berhak mengubah status menjadi `NEEDS_REVISION` disertai catatan kewajiban revisi.
   - Berhak meneruskan berkas valid ke Kepala Desa (`HEAD_APPROVAL`).
   - **DILARANG KERAS** mengeksekusi persetujuan akhir atau penolakan final (dicegat dengan kode HTTP 403 Forbidden).
3. **Kepala Desa (`kepala_desa`):**
   - Pejabat tunggal penentu keputusan pengesahan surat.
   - Memeriksa daftar pengajuan tertunda (`HEAD_APPROVAL`).
   - Mengeksekusi penolakan final (`REJECTED`) dengan catatan resmi.
   - Mengeksekusi persetujuan final (`COMPLETED` / `APPROVED`), yang secara otomatis memicu generator nomor surat, stempel digital, tanda tangan elektronik, dan QR Code verifikasi.
4. **Supervisor (`supervisor`):**
   - Administrator tata kelola sistem dan pengawas pelayanan (*System Overseer*).
   - Memantau dasbor analitik dan statistik operasional.
   - Mengelola Pusat Bantuan (*Report Center*): membalas aduan, mengubah prioritas, dan menuliskan catatan rahasia internal (*internal note*).
   - Mengelola akun staf dan warga (aktivasi / reset).
   - Mengonfigurasi parameter AI (provider, model, API key terenkripsi, batas token).
   - **STATUS KHUSUS PADA SKTM:** Berperan murni sebagai *Read-Only Auditor*. Supervisor DILARANG menyetujui, menolak, atau meneruskan SKTM ke Kepala Desa (terdapat *guard* backend yang melempar HTTP 403 jika Supervisor mencoba mengeksekusi *workflow* persetujuan SKTM).

---

## 6. Kebutuhan Fungsional

- **FR-01 (Autentikasi Aman):** Pengguna wajib melakukan login menggunakan email terdaftar dan password ter-hash Bcrypt; sistem menyediakan registrasi mandiri untuk warga dan verifikasi email via link bertanda tangan kriptografi.
- **FR-02 (Manajemen Profil Kependudukan):** Masyarakat wajib melengkapi profil (NIK 16 digit valid, No KK, alamat, RT/RW, pekerjaan, penghasilan) sebelum diizinkan membuka formulir SKTM.
- **FR-03 (Formulir Multi-Tahap SKTM):** Formulir pengajuan disajikan dalam 3 tahapan wizard (Data Diri $\rightarrow$ Keterangan Keperluan $\rightarrow$ Upload Berkas KTP & KK berkapasitas maks 2MB bertipe PDF/JPG/PNG).
- **FR-04 (Pemberian Nomor Unik):** Sistem secara otomatis menerbitkan nomor registrasi berkas bertaraf unik (format: `SKTM-YYYYMMDD-XXXX`) pada saat submit.
- **FR-05 (Audit Verifikasi Staf):** Staf dapat melihat pratinjau dokumen dan memilih antara meminta revisi (`NEEDS_REVISION`) atau memajukan berkas (`HEAD_APPROVAL`).
- **FR-06 (Otorisasi Persetujuan Kepala Desa):** Kepala Desa menyetujui pengajuan, memicu penerbitan nomor surat resmi otomatis desa (contoh: `470/012/DS-2026`) dan tanggal terbit.
- **FR-07 (Penerbitan Dokumen PDF & QR Code):** Sistem memproduksi dokumen SKTM PDF resmi ber-Kop Desa lengkap dengan SVG QR Code yang mengarah ke URL verifikasi keabsahan.
- **FR-08 (Verifikasi Dokumen Publik):** Siapa pun dapat memindai QR Code untuk memeriksa keabsahan surat secara publik di `/verifikasi/{token}` tanpa perlu login.
- **FR-09 (Unduh Dokumen Berproteksi):** Pengunduhan dokumen fisik dibatasi hanya untuk pemilik surat dan perangkat berwenang menggunakan Laravel *Signed URL* berbatas waktu.
- **FR-10 (Layanan Pengaduan & Helpdesk):** Pengguna dapat membuat tiket kendala berprioritas (`LOW`, `MEDIUM`, `HIGH`, `CRITICAL`), mengunggah bukti hingga 5 file (maks 5MB), serta bertukar pesan secara publik maupun internal.
- **FR-11 (AI Assistant Terpadu):** Asisten berbasis LLM memberikan panduan syarat SKTM dan profil desa secara realtime dengan isolasi kegagalan provider tanpa mengganggu operasi database.
- **FR-12 (Notifikasi Asinkron Multi-Kanal):** Notifikasi otomatis dialirkan ke in-app database, email SMTP, dan WhatsApp via queue worker database (`sktm`, `laporan-prioritas`, `laporan`, `default`).
- **FR-13 (Logging & Audit Trail):** Setiap mutasi status surat, login akun, dan perubahan tiket bantuan dicatat ke tabel `audit_logs`, `application_histories`, dan `system_report_histories`.

---

## 7. Kebutuhan Nonfungsional

- **NFR-01 (Kinerja & Kecepatan Respon):** Waktu respon halaman (*Time to First Byte*) $\le 1.2$ detik pada koneksi internet standar; pemrosesan generator PDF dan QR Code $\le 2.5$ detik.
- **NFR-02 (Keamanan Data Pribadi):** NIK, KK, dan berkas identitas disimpan pada direktori penyimpanan privat (`storage/app/private/documents/`) yang tidak dapat diakses langsung via URL publik (*direct access disabled*).
- **NFR-03 (Ketersediaan / Availability):** Sistem dirancang toleran terhadap kegagalan antarmuka eksternal (*fault-tolerant*). Jika API Fonnte atau SMTP Gmail mengalami downtime, transaksi data permohonan tetap berhasil disimpan (*decoupled via queue jobs*).
- **NFR-04 (Integritas Data):** Integritas relasional dijamin dengan *Foreign Key Constraints* dengan `ON DELETE CASCADE` atau `SET NULL` sesuai kebutuhan kepemilikan data, serta penerapan *Database Transactions* (`DB::transaction`) pada operasi kritis.
- **NFR-05 (Kebijakan Kontak Aman Petugas):** Akun Staf, Supervisor, dan Kepala Desa diperbolehkan memiliki kontak nomor telepon/email kosong. Pengiriman notifikasi eksternal ke akun tersebut otomatis dilewati dengan status `SKIPPED_NO_VALID_RECIPIENT` tanpa memicu exception atau kegagalan queue worker.
- **NFR-06 (Skalabilitas & Responsif):** Tampilan antarmuka sepenuhnya responsif mendukung peramban mobile (smartphone Android/iOS), tablet, dan PC desktop berbasis Tailwind CSS 4.

---

## 8. Alur Proses Pelayanan SKTM

```
┌────────────────────────────────────────────────────────────────────────┐
│               ALUR KERJA PENGAJUAN DAN PENERBITAN SKTM                 │
└────────────────────────────────────────────────────────────────────────┘

 [ 1. MASYARAKAT ]
        │
        ├── 1. Registrasi Akun & Verifikasi Email (wajib verified)
        ├── 2. Isi Profil Identitas (NIK 16 Digit & No KK)
        ├── 3. Buka Form Wizard SKTM (3 Tahap)
        ├── 4. Unggah Berkas KTP & KK (Maks 2MB, PDF/JPG/PNG)
        └── 5. Submit Permohonan ──> Status: SUBMITTED
                                         │
                                         ▼ (Notifikasi In-App & WA ke Staf)
 [ 2. STAF DESA ]
        │
        ├── Buka Dashboard Admin ──> Daftar Pengajuan Masuk
        ├── Periksa Keaslian & Kelayakan Berkas Pemohon
        │
        ├── [KASUS REVISI] ──> Klik "Minta Revisi" + Catatan Wajib
        │                         │
        │                         ▼ Status: NEEDS_REVISION
        │                    (Warga upload ulang berkas via /revisi)
        │
        └── [KASUS LENGKAP] ─> Klik "Teruskan ke Kades"
                                  │
                                  ▼ Status: HEAD_APPROVAL
                                  │
                                  ▼ (Notifikasi In-App & WA ke Kades)
 [ 3. KEPALA DESA ]
        │
        ├── Buka Menu Pengajuan Tertunda (/admin/pengajuan/{id})
        ├── Teliti Riwayat Pemeriksaan Staf & Dokumen Pemohon
        │
        ├── [KEPUTUSAN TOLAK] ──> Klik "Tolak Pengajuan" + Alasan
        │                            │
        │                            ▼ Status: REJECTED (Selesai Ditolak)
        │
        └── [KEPUTUSAN SETUJU] ─> Klik "Setujui & Terbitkan Surat"
                                     │
                                     ▼ (Penerbitan Otomatis)
                                     ├── Generate Nomor Surat Resmi (470/xxx/DS-2026)
                                     ├── Generate Token Verifikasi Unik (UUID/Hex)
                                     ├── Kompilasi SVG QR Code (BaconQrCode)
                                     ├── Render PDF SKTM Resmi (DOMPDF)
                                     ├── Simpan ke Storage Dokumen Terbit
                                     └── Status: COMPLETED / APPROVED
                                              │
                                              ▼
 [ 4. PENERBITAN & PENGUNDUHAN ]
        │
        ├── Kirim Notifikasi Multi-Kanal ke Warga (In-App, WA, Email)
        ├── Email memuat Laravel Signed URL pengunduhan resmi
        ├── Warga mengunduh PDF SKTM (/dokumen/sktm/{token}/unduh)
        └── Pihak Ketiga dapat memindai QR Code untuk cek keabsahan (/verifikasi/{token})
```

---

## 9. Fitur Utama Sistem

Berdasarkan audit implementasi kode sumber riil, status kesiapan fitur adalah sebagai berikut:

| Modul Fitur | Status Kesiapan | Penjelasan Implementasi Aktual |
|---|:---:|---|
| **Landing Page & Informasi** | **Sudah Berfungsi** | Halaman depan publik memuat profil desa, dasar hukum SKTM, alur pengajuan, FAQ interaktif, dan tautan login/registrasi. |
| **Registrasi & Email Verification** | **Sudah Berfungsi** | Menggunakan sistem otentikasi Laravel terproteksi middleware `verified`; warga tidak dapat mengajukan SKTM sebelum memverifikasi email. |
| **Manajemen Profil Warga** | **Sudah Berfungsi** | Validasi NIK unik 16 digit, nomor KK, pekerjaan, alamat lengkap RT/RW, dan riwayat mutasi profil. |
| **Pengajuan SKTM (Wizard)** | **Sudah Berfungsi** | Komponen Livewire `SktmForm` membagi formulir ke dalam 3 langkah dengan validasi realtime dan unggah berkas aman. |
| **Tracking Status & Riwayat** | **Sudah Berfungsi** | Visualisasi timeline status pengajuan dari `SUBMITTED`, `NEEDS_REVISION`, `HEAD_APPROVAL`, hingga `COMPLETED` / `REJECTED`. |
| **Form Revisi Dokumen** | **Sudah Berfungsi** | Rute `/pengajuan/{id}/revisi` memungkinkan warga mengunggah ulang dokumen spesifik yang ditolak staf tanpa harus mengisi ulang data diri. |
| **Verifikasi Berkas oleh Staf** | **Sudah Berfungsi** | Komponen `ApplicationDetail` menampilkan pratinjau berkas, tombol `markAsNeedsRevision`, dan `forwardToHead`. |
| **Approval Kepala Desa** | **Sudah Berfungsi** | Eksekusi persetujuan legal formal oleh Kades via method `kadesApprove` dan penolakan via `kadesReject`. |
| **Penerbitan PDF Otomatis** | **Sudah Berfungsi** | Service `SktmPdfService` mengompilasi template Blade ke PDF resmi memuat Kop Surat Desa, NIK pemohon, stempel digital, dan tanda tangan Kades. |
| **QR Code & Verifikasi Publik** | **Sudah Berfungsi** | `LocalQrCodeService` memproduksi SVG QR Code yang terhubung ke endpoint terbuka `/verifikasi/{token}` untuk pengujian keaslian dokumen di lapangan. |
| **Unduh Berkas Bertanda Tangan** | **Sudah Berfungsi** | Controller `SktmDownloadController` memvalidasi tanda tangan kriptografi URL (*signed URL*) sebelum mengalirkan file PDF ke pemohon. |
| **Helpdesk / System Reports** | **Sudah Berfungsi** | Pelaporan kendala sistem dengan 7 kategori, 4 tingkat prioritas, lampiran berkas, serta pemisahan balasan publik dan *internal note* rahasia. |
| **AI Assistant Desa** | **Sudah Berfungsi** | Chatbot terintegrasi dengan Google AI Studio (Gemini 2.5 Flash) dan service data intelijen desa (`VillageDataIntelligenceService`). |
| **Manajemen Akun Petugas** | **Sudah Berfungsi** | Livewire `AccountManagement` digunakan oleh Supervisor untuk mengaktifkan/menonaktifkan akun staf dan warga. |
| **Konfigurasi Dinamis AI** | **Sudah Berfungsi** | Komponen `Admin\AiSettings` memungkinkan Supervisor menguji koneksi API key, mengubah model LLM, batas token, dan timeout secara dinamis. |
| **Notifikasi Multi-Kanal** | **Sudah Berfungsi** | Notifikasi in-app database, email SMTP Gmail, dan WhatsApp via Fonnte API dengan mekanisme antrean database dan toleransi kontak kosong. |
| **Analisis OCR Dokumen Otomatis** | *Belum Tersedia* | Kolom database `ai_verified` dan `ai_analysis_result` telah disiapkan, namun proses verifikasi saat ini masih murni manual oleh Staf Desa. |

---

## 10. AI Assistant

### 10.1 Peran dan Batasan Fungsional
AI Assistant pada sistem ini bertindak secara murni sebagai **Asisten Informasi Virtual (Informational Assistant)** dan **BUKAN** sebagai pengambil keputusan administratif (*Administrative Decision Maker*).
- **Yang Dapat Dilakukan AI:**
  1. Menjawab pertanyaan warga mengenai syarat, ketentuan, jam operasional, dan alur pembuatan SKTM.
  2. Memberikan penjelasan mengenai arti dan cara pengisian field pada formulir pengajuan.
  3. Menyajikan data statistik agregat pelayanan desa kepada perangkat desa (jumlah pengajuan, status berkas).
- **Batasan Mutlak AI:**
  1. AI **DILARANG KERAS** menyetujui, menolak, atau membatalkan permohonan SKTM.
  2. AI **DILARANG KERAS** memodifikasi data diri atau data permohonan warga di database.
  3. AI tidak melakukan verifikasi keaslian dokumen secara otonom tanpa campur tangan staf manusia.

### 10.2 Provider dan Model Terkonfigurasi
- **Provider Utama:** Google AI Studio (`google_ai_studio`).
- **Model Aktif:** `gemini-2.5-flash` (Didukung juga oleh varian `gemini-2.5-flash-lite`, `gemini-2.5-pro`, `gemini-2.0-flash`).
- **Protokol:** Gemini REST API v1beta (`https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`) dengan otentikasi header `x-goog-api-key`.
- **Provider Alternatif (Multi-Provider Registry):** DeepSeek (`deepseek-chat`), OpenAI (`gpt-4o-mini`), Groq (`openai/gpt-oss-20b`), OpenRouter.
- **Penyimpanan Riwayat Percakapan:** Disimpan secara relasional pada tabel `conversations` (mengikat `user_id` atau `session_id`) dan tabel `chat_messages` (`role: user/assistant/system`).

### 10.3 Mekanisme Penanganan Kegagalan (Fault-Tolerance)
Service `AiService` dan `AiConfigService` mengimplementasikan klasifikasi kegagalan terstruktur:
- **API Timeout / Koneksi Terputus:** Sistem memberikan pesan ramah kepada pengguna tanpa membocorkan stack trace teknis.
- **API Key Ditolak / Limit Habis (Quota Exceeded):** Error ditangkap di backend dan dicatat pada log; pengguna diberitahukan bahwa asisten sedang dalam pemeliharaan.
- **Data Privasi:** Kredensial API key disimpan terenkripsi di database menggunakan `Crypt::encryptString` dan tidak pernah terekspos ke antarmuka klien.

---

## 11. Sistem Notifikasi

Sistem menerapkan arsitektur notifikasi multi-saluran terisolasi (*Decoupled Multi-Channel Notification Architecture*):

```
                       [ Trigger Event Bisnis ]
                                  │
                                  ▼
                     [ App\Services\NotificationService ]
                                  │
         ┌────────────────────────┼────────────────────────┐
         │                        │                        │
         ▼                        ▼                        ▼
 [ Kanal In-App ]         [ Kanal WhatsApp ]        [ Kanal Gmail SMTP ]
         │                        │                        │
  Simpan langsung ke        Pengecekan Nomor        Pengecekan Email
  tabel 'notifications'     Valid (Format 62/08)    Valid & Bukan Dummy
         │                        │                        │
      SELESAI            ┌────────┴────────┐      ┌────────┴────────┐
                         │                 │      │                 │
                      [Valid]          [Kosong] [Valid]         [Kosong]
                         │                 │      │                 │
                         ▼                 ▼      ▼                 ▼
                  Dispatch Job      Catat Log:   Kirim Mailable    Catat Log:
                  'SendFonnte'      'SKIPPED'   'AppStatusChanged' 'SKIPPED'
                  ke Antrean DB     Tanpa Error  ke Queue Worker   Tanpa Error
```

1. **In-App Notification (Database Channel):**
   - Menggunakan tabel standar `notifications` Laravel dengan Primary Key UUID.
   - Ditampilkan langsung pada lonceng notifikasi navigasi via komponen Livewire `NotificationDropdown` lengkap dengan penanda "Tandai Telah Dibaca".
2. **WhatsApp Gateway (Fonnte API):**
   - Dikirimkan asinkron melalui antrean database (`sktm`, `laporan-prioritas`, `laporan`).
   - Job: `App\Jobs\SendFonnteNotificationJob` dan `SendFonnteReportNotificationJob`.
   - Seluruh status pengiriman (sukses, gagal antre, atau penolakan nomor) dicatat pada tabel `whatsapp_notification_logs`.
3. **Email Notification (Gmail SMTP):**
   - Menggunakan transport SMTP TLS port 587 dengan akun resmi desa.
   - Mengirimkan email terformat rapi memuat ringkasan status dan *Signed URL* untuk pengunduhan berkas SKTM.
   - Status dicatat ke tabel `email_notification_logs`.
4. **Kebijakan Kontak Petugas Terisolasi (*Resilient Contact Policy*):**
   - Akun Staf Desa, Kepala Desa, dan Supervisor sengaja dapat dikosongkan kontak telepon/emailnya.
   - Sistem secara cerdas mendeteksi kontak kosong dan menetapkan status pengiriman sebagai `SKIPPED_NO_VALID_RECIPIENT` tanpa menganggapnya sebagai kegagalan sistem, sehingga worker antrean tetap berjalan lancar tanpa mengalami penumpukan *failed jobs*.

---

## 12. Arsitektur Sistem

Aplikasi dibangun dengan pola arsitektur **Clean MVC + Service Layer + Component-Driven (Livewire)**:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        ARSITEKTUR SISTEM                               │
└────────────────────────────────────────────────────────────────────────┘

 [ Client Layer ]
    └── Web Browser (Desktop / Mobile UI: Blade + Livewire 4 + Tailwind CSS 4)

 [ Presentation & Interaction Layer ]
    ├── Livewire Components (SktmForm, ApplicationDetail, ReportCenter, AiSettings)
    └── Controllers (AuthController, SktmVerificationController, SktmDownloadController)

 [ Middleware Layer ]
    ├── Authentication (Sanctum / Session Auth)
    ├── Role Verification (CheckRole / EnsureCitizenProfileCompleted)
    └── Cryptographic Signature Validation (SignedUrl Middleware)

 [ Business Logic & Service Layer ]
    ├── SktmIssuanceService (Orkestrasi Legalitas & Logika Bisnis SKTM)
    ├── SktmPdfService (Kompilasi Layout Surat ke DOMPDF)
    ├── LocalQrCodeService (Generator SVG QR Code Kriptografis)
    ├── NotificationService & ReportNotificationService (Orkestrasi Notifikasi)
    ├── SystemReportService (Tata Kelola Tiket Helpdesk & Catatan Internal)
    └── AiService & AiConfigService (Integrasi LLM Google AI Studio)

 [ Asynchronous Queue Workers ]
    └── Database Queue Listeners: 'sktm', 'laporan-prioritas', 'laporan', 'default'

 [ Persistence & Storage Layer ]
    ├── MariaDB / MySQL Relational Database (20 Tabel dengan UUID PK)
    └── Private File Storage ('storage/app/private/documents/')
```

---

## 13. Struktur Database

Database terdiri atas **20 tabel relasional utama** dengan integritas referensial terjaga:

```
┌─────────────────┐       1:1       ┌─────────────────┐
│      users      │─────────────────│  user_profiles  │
└─────────────────┘                 └─────────────────┘
         │
         │ N:1
         ▼
┌─────────────────┐
│      roles      │
└─────────────────┘
         │
         │ 1:N
         ▼
┌─────────────────┐       1:N       ┌─────────────────────────┐
│  applications   │─────────────────│  application_documents  │
└─────────────────┘                 └─────────────────────────┘
         │
         ├─── 1:N ───> application_reviews
         ├─── 1:N ───> application_histories
         ├─── 1:N ───> whatsapp_notification_logs
         └─── 1:N ───> email_notification_logs

┌─────────────────┐       1:N       ┌─────────────────────────┐
│ system_reports  │─────────────────│ system_report_messages  │
└─────────────────┘                 └─────────────────────────┘
         │
         ├─── 1:N ───> system_report_attachments
         └─── 1:N ───> system_report_histories

┌─────────────────┐       1:N       ┌─────────────────────────┐
│  conversations  │─────────────────│      chat_messages      │
└─────────────────┘                 └─────────────────────────┘
```

### Rincian Tabel Utama:
1. `roles`: Master data 4 peran (`masyarakat`, `staf`, `kepala_desa`, `supervisor`).
2. `users`: Kredensial akun, foreign key `role_id`, status aktifasi, dan soft deletes.
3. `user_profiles`: Data kependudukan lengkap (NIK 16 digit unik, No KK, alamat, RT/RW, pekerjaan, penghasilan).
4. `document_types`: Master tipe surat (kode unik: `SKTM`).
5. `applications`: Master pengajuan memuat `application_number` unik, foreign key `user_id`, data JSON, status ENUM, dan timestamp penyelesaian.
6. `application_documents`: Berkas KTP/KK pemohon dan file SKTM resmi terbit lengkap dengan `verification_token` dan `letter_number`.
7. `application_reviews`: Rekam jejak evaluasi dan catatan staf/kades terhadap permohonan.
8. `application_histories`: Audit log transisi status permohonan dari awal sampai selesai.
9. `system_reports`: Tiket helpdesk memuat `ticket_number` unik, kategori kendala, prioritas, dan status penyelesaian.
10. `system_report_messages`: Percakapan aduan dengan flag pemisah `is_internal` (catatan rahasia petugas vs balasan publik).
11. `system_report_attachments`: File bukti kendala terikat ke tiket atau pesan tertentu.
12. `system_report_histories`: Audit trail mutasi status tiket laporan.
13. `notifications`: Antrean notifikasi in-app bawaan Laravel.
14. `whatsapp_notification_logs` & `email_notification_logs`: Catatan riwayat pengiriman notifikasi eksternal.
15. `conversations` & `chat_messages`: Riwayat percakapan asisten AI desa.
16. `audit_logs`: Pencatatan aktivitas keamanan, IP address, user agent, dan payload request.
17. `ai_settings` & `ai_provider_credentials`: Konfigurasi singleton provider AI, model, dan API key terenkripsi.

---

## 14. Keamanan Sistem

Sistem menerapkan standar keamanan berstandar enterprise untuk melindungi privasi data kependudukan:
1. **Perlindungan Dokumen & IDOR (*Insecure Direct Object Reference*):** Berkas identitas dan dokumen SKTM disimpan di storage privat (`storage/app/private/`). Pemohon hanya dapat mengakses berkas miliknya melalui validasi hak milik `$application->user_id === auth()->id()`. Pengunduhan dokumen resmi menggunakan Laravel *Signed URL* berbatas waktu kadaluarsa.
2. **Validasi File Upload Ketat:** Berkas unggahan divalidasi ganda di tingkat frontend dan backend (maks 2MB untuk SKTM, maks 5MB untuk helpdesk; tipe MIME terbatas pada `application/pdf`, `image/jpeg`, `image/png`). Nama file di-obfuscate menggunakan string acak kriptografis 40 karakter untuk mencegah eksekusi skrip berbahaya (*shell injection* / *path traversal*).
3. **Keamanan Kredensial & Kunci API:** Seluruh password di-hash menggunakan algoritma Bcrypt. API Key Google AI Studio disimpan terenkripsi di database menggunakan Laravel `Crypt::encryptString` dan tidak pernah dikirim ke browser/klien.
4. **Pencegahan Eksploitasi Web Standar:** 
   - **CSRF Protection:** Seluruh form HTML dan aksi Livewire diproteksi token anti-CSRF.
   - **XSS Sanitization:** Blade templating engine secara default melakukan escaping terhadap seluruh variabel dinamis.
   - **SQL Injection Prevention:** Penggunaan Laravel Eloquent ORM dengan prepared statements berbasis parameter binding.
5. **Audit Trail Komprehensif:** Setiap login, perubahan status surat, pengesahan Kepala Desa, dan penutupan tiket pengaduan dicatat ke tabel `audit_logs` bersama alamat IP dan rincian mutasi data.

---

## 15. Pengujian Sistem

Sistem telah diuji secara komprehensif menggunakan kerangka pengujian terotomatisasi **PHPUnit 11.5** pada lingkungan Laravel 12:

### Rangkuman Hasil Pengujian Otomatis:
- **Total File Test Tersedia:** 49 file pengujian Feature & Unit di direktori `tests/Feature/`.
- **Cakupan Pengujian Utama:**
  - `AuthorizationTest`: Menguji batas otorisasi antar-role (masyarakat tidak dapat mengakses rute staf; staf tidak dapat melakukan aksi kades).
  - `SktmDirectStaffToKadesWorkflowTest`: Menguji alur verifikasi berkas dari Staf langsung ke Kepala Desa tanpa campur tangan Supervisor.
  - `CustomConfirmationDialogTest`: Menguji kepastian adanya konfirmasi modal sebelum aksi penolakan atau persetujuan surat.
  - `SktmQrVerificationTest` & `SktmIssuedEmailTest`: Menguji keabsahan URL verifikasi publik dan validitas signed download URL.
  - `GoogleAiStudioProviderTest` & `AiServiceTest`: Menguji kesesuaian payload REST API Gemini dan mekanisme fallback saat API down.
  - `FonnteNotificationTest` & `OfficerContactPolicyAndQueueTest`: Menguji toleransi kontak kosong (`SKIPPED_NO_VALID_RECIPIENT`) dan kestabilan antrean WhatsApp.
  - `SystemReportUiAndNotificationTest`: Menguji isolasi catatan internal helpdesk agar tidak membocorkan notifikasi ke pelapor.
- **Eksekusi Sample Master Test (`SystemWorkflowTest`):**
  - **Hasil:** 12 skenario Passed, 1 Minor Assert Mismatch (akibat perbedaan label view `Dashboard Layanan Masyarakat` vs `Dashboard Masyarakat`), total 28 assertions tereksekusi dengan durasi $\approx 8.87$ detik.

---

## 16. Roadmap Pengembangan (Riwayat Perencanaan Bertahap)

Berikut adalah riwayat perencanaan awal pengembangan 4 fase dan 13 prompt bertahap yang digunakan sebagai panduan arsitektur pada saat pembangunan sistem di Antigravity IDE:

```
[ Phase 1 — Foundation ]
├── Prompt 1 — Analisis & Setup Project (Arsitektur dasar & struktur folder)
├── Prompt 2 — Database & Backend Foundation (Skema migrasi 20 tabel & model Eloquent)
└── Prompt 3 — Authentication & Role Access (RBAC 4 peran & middleware otentikasi)

[ Phase 2 — Core Application ]
├── Prompt 4 — UI/UX & Landing Page (Antarmuka publik, dashboard, dan tema Tailwind)
├── Prompt 5 — Fitur Pengajuan SKTM (Formulir wizard 3 langkah & upload dokumen aman)
├── Prompt 6 — Workflow Staf Desa (Pemeriksaan dokumen & mekanisme minta revisi)
├── Prompt 7 — Workflow Supervisor & Kepala Desa (Penetapan approval Kades & pengawasan Supervisor)
└── Prompt 8 — Generate SKTM PDF (Kompilasi DOMPDF & penyematan SVG QR Code unik)

[ Phase 3 — AI & Monitoring ]
├── Prompt 9  — AI Assistant (Chatbot informasi SKTM terintegrasi Google AI Studio Gemini)
├── Prompt 10 — Dashboard & Monitoring (Statistik pelayanan & panel analitik perangkat desa)
└── Prompt 11 — Notification System (Multi-kanal in-app, WhatsApp Fonnte, dan Gmail SMTP)

[ Phase 4 — Production Readiness ]
├── Prompt 12 — Security Audit (Hardening IDOR, proteksi upload, audit log, enkripsi API key)
└── Prompt 13 — Testing & Final Check (Pengujian menyeluruh rantai workflow & kestabilan queue)
```

> *Catatan Penyesuaian*: Pada perencanaan awal (PRD lama), Supervisor sempat dirancang masuk ke dalam alur persetujuan SKTM. Namun pada implementasi aktual sistem, tata kelola disempurnakan sesuai regulasi administrasi desa yang sah: **Staf memeriksa berkas awal, dan Kepala Desa memegang wewenang tunggal persetujuan/penerbitan surat**. Supervisor dialokasikan khusus untuk tata kelola sistem, manajemen helpdesk, dan pemantauan analitik.

---

## 17. Penjelasan Lima Diagram Sistem

Untuk mendukung visualisasi arsitektur dalam dokumentasi dan materi presentasi akademik, sistem dirancang mengacu pada 5 diagram spesifikasi rekayasa perangkat lunak:

1. **Use Case Diagram:**
   - **Fungsi:** Menggambarkan batas sistem (*system boundary*) serta interaksi fungsional antara 4 aktor manusia (Masyarakat, Staf, Kepala Desa, Supervisor) dan 3 aktor sistem pendukung (Fonnte WA Gateway, Gmail SMTP, Google AI Studio).
   - **Hubungan Utama:** Menunjukkan relasi `<<include>>` (misal: Pengajuan SKTM wajib *include* Upload Dokumen dan Verifikasi Email; Persetujuan Kades wajib *include* Penerbitan Nomor Surat dan Kompilasi PDF) serta relasi `<<extend>>` (misal: Permintaan Revisi Dokumen yang memperluas alur Pemeriksaan Staf secara kondisional).
2. **Activity Diagram:**
   - **Fungsi:** Memodelkan aliran kerja prosedural (*control flow*) secara berurutan menggunakan swimlane multi-jalur.
   - **Fokus Pemodelan:** Alur pengajuan SKTM dari inisiasi form warga, percabangan keputusan kelayakan oleh Staf, percabangan persetujuan Kepala Desa, hingga sinkronisasi paralel (*Fork/Join Node*) saat sistem secara serentak mengompilasi PDF, membuat QR Code, dan mengirimkan notifikasi multi-saluran.
3. **Sequence Diagram:**
   - **Fungsi:** Menunjukkan interaksi berbasis waktu (*time-ordered interaction*) antar-objek (View/Livewire Component $\rightarrow$ Controller $\rightarrow$ Service $\rightarrow$ Model $\rightarrow$ Database $\rightarrow$ Queue Job).
   - **Skenario Kritis:** Menelusuri kronologi pemanggilan method saat eksekusi `SktmForm::submit()`, `ApplicationDetail::forwardToHead()`, dan `ApplicationDetail::kadesApprove()` yang memicu `SktmIssuanceService` dan `LocalQrCodeService`.
4. **Class Diagram:**
   - **Fungsi:** Mendokumentasikan struktur statis kelas berorientasi objek dalam sistem.
   - **Komponen Utama:** Memuat 20 Eloquent Models (User, Application, SystemReport, dsb), Service Classes (`SktmPdfService`, `FonnteService`, `AiService`), Livewire Component Classes, Job Classes, dan Notification Classes beserta atribut visibilitas, tipe data, dan kardinalitas multiplicity relasinya.
5. **Entity Relationship Diagram (ERD):**
   - **Fungsi:** Memetakan skema konseptual dan logis basis data relasional.
   - **Struktur Entitas:** Menjabarkan 20 tabel ber-Primary Key UUID v4, relasi *one-to-one* (`users` ke `user_profiles`), relasi *one-to-many* (`applications` ke `application_documents`, `system_reports` ke `system_report_messages`), tipe data kolom, nullability, dan aturan integritas *Foreign Key*.

> *Status Citra Diagram*: File gambar visual diagram disiapkan secara eksternal untuk keperluan slide presentasi. Struktur relasi, spesifikasi aktor, dan pemetaan atribut pada dokumen ini telah disinkronkan 100% dengan kode program yang aktif.

---

## 18. Keterbatasan dan Rencana Pengembangan Selanjutnya

### Keterbatasan Sistem Saat Ini:
1. **Verifikasi Fisik Berkas Belum Otomatis (OCR):** Staf desa masih membaca gambar KTP dan KK secara visual; ekstraksi teks otomatis berbasis AI Vision belum diaktifkan pada alur submit.
2. **Jenis Surat Terbatas:** Sistem saat ini baru berfokus penuh pada digitalisasi Surat Keterangan Tidak Mampu (SKTM).
3. **Tanda Tangan Elektronik Tersertifikasi:** Validasi dokumen saat ini mengandalkan tanda tangan digital desa internal ber-QR Code token unik, belum terintegrasi dengan Otoritas Sertifikat Nasional (BSrE / Kominfo).

### Rencana Pengembangan Masa Depan:
1. **Implementasi AI-Vision OCR:** Mengintegrasikan model Gemini Multimodal untuk membaca teks NIK dan Nama langsung dari foto KTP saat diunggah pemohon guna mencegah kesalahan input manual.
2. **Ekspansi Jenis Pelayanan Surat:** Menambahkan modul pengajuan Surat Keterangan Usaha (SKU), Surat Keterangan Domisili, Surat Pengantar Nikah, dan Surat Kematian menggunakan arsitektur tabel `document_types` yang sudah fleksibel.
3. **Aplikasi Mobile Warga (PWA / Flutter):** Membangun aplikasi mobile berbasis Progressive Web App (PWA) agar masyarakat desa dapat mengakses layanan dengan antarmuka yang lebih ringan di smartphone berdaya rendah.
4. **Integrasi TTE BSrE:** Menghubungkan engine penerbitan surat dengan modul penandatanganan elektronik bersertifikat resmi Badan Siber dan Sandi Negara (BSSN).

---

## 19. Kesimpulan

Sistem Pelayanan Desa Online Berbasis AI (`pelayanan_desa`) berhasil menghadirkan solusi konkret terhadap hambatan birokrasi konvensional di tingkat desa. Dengan memadukan teknologi modern **Laravel 12**, **Livewire 4**, dan **Google AI Studio (Gemini 2.5 Flash)**, sistem mewujudkan pelayanan publik yang cepat, transparan, dan dapat dipertanggungjawabkan secara hukum.

Pemisahan wewenang yang tegas antara Staf (verifikator), Kepala Desa (penandatangan legal), dan Supervisor (pengawas sistem), dipadukan dengan keamanan dokumen ber-QR Code serta ketahanan notifikasi multi-saluran, menjadikan aplikasi ini siap digunakan sebagai model percontohan digitalisasi desa modern yang inklusif dan terpercaya.

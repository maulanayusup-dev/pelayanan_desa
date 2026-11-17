# Sistem Informasi Pelayanan Desa Digital Terpadu

Proyek Akhir Mandiri — Rekayasa Perangkat Lunak (TIKB2503021)
Program Studi S1 Teknik Informatika, STMIK IKMI Cirebon

## Identitas Mahasiswa


|---------------------|--------------------------------------|
| Nama                | Maulana Yusup                        |
| NIM                 | 41257116                             |
| Kelompok            | DEVLYN                               |
| Tema Topik Kelompok | Pelayanan Desa                       |
| Dosen Pengampu      | Yudhistira Arie Wijaya, M.Kom., CDS. |

## Deskripsi Aplikasi

Aplikasi web untuk mendigitalkan pelayanan surat di desa, khususnya Surat Keterangan Tidak Mampu (SKTM). Warga mengajukan surat secara daring, Staf Desa memverifikasi berkas, Kepala Desa menyetujui, lalu sistem menerbitkan PDF SKTM ber-QR Code yang keasliannya bisa diverifikasi publik.

**Fitur utama**
- Pengajuan SKTM mandiri (form 3 langkah + unggah KTP & KK)
- Verifikasi berjenjang Staf Desa → Kepala Desa
- Penerbitan PDF SKTM otomatis dengan nomor surat dan QR Code
- Verifikasi keaslian surat via QR Code dan unduh aman (signed URL)
- Pusat pengaduan (helpdesk) dengan tiket, balasan publik, dan catatan internal
- Notifikasi in-app, email, dan WhatsApp

## Fitur AI

**AI Assistant Layanan Desa** — chatbot berbasis LLM yang menjawab pertanyaan warga tentang persyaratan layanan dan profil desa.
- Provider utama: Google Gemini (Google AI Studio)
- Fallback otomatis: Groq, bila Gemini gagal atau timeout
- Jawaban diperkaya konteks data profil desa (Village Data Intelligence Service)
- Riwayat percakapan tersimpan di tabel `conversations` dan `chat_messages`

## Video Presentasi

|                  Tahap                  |           Tautan              |
|-----------------------------------------|-------------------------------|
| Video Progres 1 — PRD & Konsep Aplikasi | [tautan YouTube/Google Drive] |
| Video Progres 2 — Pemodelan UML & UI/UX | [tautan YouTube/Google Drive] |
| Video Final — Live Demo                 | [tautan YouTube/Google Drive] |

## Dokumen

- PRD: [`docs/PRD.pdf`](docs/PRD.pdf)
- SDD: [`docs/SDD.pdf`](docs/SDD.pdf)
- Prototipe Figma: [tautan Figma]

## Teknologi

Laravel 11 · Livewire 3 · Tailwind CSS · MySQL · DOMPDF · Fonnte API · Gmail SMTP · Google Gemini · Groq

## Petunjuk Instalasi (Setup Instruction)

### Prasyarat
- PHP 8.2+ dan Composer
- Node.js 18+ dan npm
- MySQL 8

### Langkah instalasi

```bash
# 1. Clone repositori
git clone https://github.com/[username]/proyek-rpl-[nama-aplikasi].git
cd proyek-rpl-[nama-aplikasi]

# 2. Install dependensi
composer install
npm install

# 3. Siapkan file environment
cp .env.example .env
php artisan key:generate
```

Lalu isi `.env`:

```env
DB_DATABASE=pelayanan_desa
DB_USERNAME=root
DB_PASSWORD=

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=[email Gmail]
MAIL_PASSWORD=[App Password Gmail]
MAIL_ENCRYPTION=tls

QUEUE_CONNECTION=database

# Sesuaikan nama variabel dengan yang dipakai di config proyek
FONNTE_TOKEN=[token Fonnte]
GEMINI_API_KEY=[API key Google AI Studio]
GROQ_API_KEY=[API key Groq]
```

```bash
# 4. Migrasi database dan data awal
php artisan migrate --seed

# 5. Hubungkan storage
php artisan storage:link

# 6. Build aset frontend
npm run build

# 7. Jalankan aplikasi (dua terminal)
php artisan serve
php artisan queue:work --queue=sktm,laporan-prioritas,laporan,default
```

Buka `http://localhost:8000`.

### Akun demo
| Peran | Email | Password |
|---|---|---|
| Masyarakat | [email] | [password] |
| Staf Desa | [email] | [password] |
| Kepala Desa | [email] | [password] |
| Supervisor | [email] | [password] |

## Pengujian & CI/CD

```bash
php artisan test
```

Pipeline GitHub Actions: [`.github/workflows/ci.yml`](.github/workflows/ci.yml) — menjalankan unit test otomatis di setiap push.

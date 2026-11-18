# Sistem Pelayanan Desa Online Berbasis AI

Proyek Akhir Mandiri — Rekayasa Perangkat Lunak (TIKB2503021)
Program Studi S1 Teknik Informatika, STMIK IKMI Cirebon

## Identitas Mahasiswa

| | |
|---|---|
| Nama | Maulana Yusup |
| NIM | 41257116 |
| Kelompok | DEVLYN |
| Tema Topik Kelompok | Pelayanan Desa |
| Dosen Pengampu | Yudhistira Arie Wijaya, M.Kom., CDS. |

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
- Provider utama: Google AI Studio, model `gemini-2.5-flash`
- Registry multi-provider: DeepSeek, OpenAI, Groq, OpenRouter (provider cadangan bila Gemini gagal)
- Jawaban diperkaya konteks data profil desa (Village Data Intelligence Service)
- Riwayat percakapan tersimpan di tabel `conversations` dan `chat_messages`

## Video Presentasi

| Tahap | Tautan |
|---|---|
| Video Progres 1 — PRD & Konsep Aplikasi | https://youtu.be/Lj8RrQoKI6o?si=k6N4ZEY1JBjpx9O5 |
| Video Progres 2 — Pemodelan UML & UI/UX | [[tautan YouTube/Google Drive]](https://youtu.be/wXWTCDC7EDw?si=wuTq2zSxgkry1qkK) |
| Video Final — Live Demo | [tautan YouTube/Google Drive] |

## Dokumen

- PRD: (https://drive.google.com/file/d/1IieYhq5Rsob5UQ-m8Owuin0ZoyPlpWKy/view?usp=sharing)
- SDD: (https://drive.google.com/file/d/1DW3RAtKKAf2CKir6hTKK9gL0bIWEylLU/view?usp=sharing)
- Prototipe Figma: (https://www.figma.com/design/RTl2rVcHjV8xI5z6ROa1fJ/Untitled?node-id=0-1&t=SVvpQc6u1vijxz0T-1)

## Teknologi

Laravel 12 · Livewire 4 (Volt) · Alpine.js · Tailwind CSS 4 · Vite 7 · MariaDB/MySQL · DOMPDF · BaconQrCode · Fonnte API · Gmail SMTP · Google Gemini 2.5 Flash

## Petunjuk Instalasi (Setup Instruction)

### Prasyarat
- PHP 8.2+ dan Composer
- Node.js 20.19+ dan npm (dibutuhkan Vite 7)
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
| Masyarakat | [masyrakat@desa.id] | [password] |
| Staf Desa | [staf@desa.id] | [password] |
| Kepala Desa | [kades@desa.id] | [password] |
| Supervisor | [supervisor@desa.id] | [password] |

## Pengujian & CI/CD

```bash
php artisan test
```

Pipeline GitHub Actions: [`.github/workflows/ci.yml`](.github/workflows/ci.yml) — menjalankan unit test otomatis di setiap push.

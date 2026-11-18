@extends('emails.layout', ['title' => 'Uji Coba Konfigurasi SMTP'])

@section('content')
    <div class="greeting">Halo, Administrator Pelayanan Desa!</div>

    <p class="text">
        Ini adalah email uji coba untuk memverifikasi integrasi <strong>Gmail SMTP</strong> pada Sistem Pelayanan Desa.
        Jika Anda menerima pesan ini, konfigurasi SMTP dan Google App Password Anda telah <strong>berhasil terhubung</strong> dan berfungsi dengan baik.
    </p>

    <div class="info-card">
        <div class="info-row">
            <span class="info-label">Status Koneksi:</span>
            <span class="info-value">
                <span class="badge badge-success">TERHUBUNG & AKTIF</span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">Mailer Driver:</span>
            <span class="info-value">{{ config('mail.default') }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">SMTP Host:</span>
            <span class="info-value">{{ config('mail.mailers.smtp.host') }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">SMTP Port:</span>
            <span class="info-value">{{ config('mail.mailers.smtp.port') }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Pengirim (From):</span>
            <span class="info-value">{{ config('mail.from.address') }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Waktu Pengujian:</span>
            <span class="info-value">{{ now()->translatedFormat('d F Y, H:i:s') }} WIB</span>
        </div>
    </div>

    <p class="text" style="font-size: 13px; color: #475569;">
        Sistem notifikasi email kini siap mengirimkan email verifikasi warga, konfirmasi pengajuan surat, notifikasi perubahan status, serta pemberitahuan tugas kepada petugas desa.
    </p>

    <div class="btn-wrapper">
        <a href="{{ app(\App\Services\PublicUrlService::class)->to('/') }}" class="btn" target="_blank">
            Kunjungi Website Pelayanan Desa
        </a>
    </div>
@endsection

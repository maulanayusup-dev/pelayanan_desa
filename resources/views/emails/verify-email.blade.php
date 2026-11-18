@extends('emails.layout', ['title' => 'Verifikasi Alamat Email'])

@section('content')
    <div class="greeting">Halo, {{ $user->name }}!</div>
    
    <p class="text">
        Terima kasih telah mendaftarkan akun warga pada <strong>Sistem Pelayanan Desa Digital</strong>.
        Untuk mengamankan akun Anda dan dapat mengakses layanan surat mandiri, silakan lakukan verifikasi alamat email Anda.
    </p>

    <div class="info-card">
        <div class="info-row">
            <span class="info-label">Nama Pemohon:</span>
            <span class="info-value">{{ $user->name }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Alamat Email:</span>
            <span class="info-value">{{ $user->email }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Waktu Pendaftaran:</span>
            <span class="info-value">{{ now()->translatedFormat('d F Y, H:i') }} WIB</span>
        </div>
    </div>

    <div class="btn-wrapper">
        <a href="{{ $verificationUrl }}" class="btn" target="_blank">
            Verifikasi Alamat Email Sekarang
        </a>
    </div>

    <p class="text" style="font-size: 13px; color: #64748b;">
        Tautan verifikasi ini berlaku selama 60 menit. Jika tombol di atas tidak dapat diklik, salin dan buka tautan berikut di peramban Anda:
        <br>
        <a href="{{ $verificationUrl }}" style="color: #cb0c9f; word-break: break-all; font-size: 12px;">{{ $verificationUrl }}</a>
    </p>

    <div class="divider"></div>

    <p class="text" style="font-size: 12px; color: #94a3b8; margin-bottom: 0;">
        <strong>Perhatian:</strong> Jika Anda tidak merasa membuat akun pada Sistem Pelayanan Desa, abaikan email ini. Tidak ada tindakan lanjutan yang diperlukan.
    </p>
@endsection

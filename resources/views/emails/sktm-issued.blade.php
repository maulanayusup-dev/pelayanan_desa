@extends('emails.layout', ['title' => 'SKTM Telah Diterbitkan'])

@section('content')
    <div class="greeting">Halo, {{ $application->user->name }}!</div>

    <p class="text">
        Pengajuan Surat Keterangan Tidak Mampu (SKTM) Anda telah <strong>disetujui oleh Kepala Desa</strong>
        dan dokumen resmi dalam format PDF sudah diterbitkan.
    </p>

    <div class="info-card">
        <div class="info-row">
            <span class="info-label">Nomor Pengajuan:</span>
            <span class="info-value">{{ $application->application_number }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Jenis Layanan:</span>
            <span class="info-value">Surat Keterangan Tidak Mampu (SKTM)</span>
        </div>
        <div class="info-row">
            <span class="info-label">Status:</span>
            <span class="info-value">
                <span class="badge badge-info">Disetujui &amp; PDF Diterbitkan</span>
            </span>
        </div>
    </div>

    <p class="text">
        Klik tombol di bawah ini untuk langsung mengunduh surat SKTM Anda.
    </p>

    <div class="btn-wrapper">
        <a href="{{ $downloadUrl }}" class="btn" target="_blank">
            DOWNLOAD SURAT SKTM
        </a>
    </div>

    <p class="text" style="font-size: 12px; color: #94a3b8; text-align: center;">
        Tautan berlaku hingga {{ $expiresAt->copy()->timezone('Asia/Jakarta')->translatedFormat('d F Y, H:i') }} WIB.
        Setelah itu, surat tetap dapat diunduh melalui menu <em>Riwayat Pengajuan</em> setelah login.
    </p>

    <p class="text" style="font-size: 11px; color: #94a3b8; text-align: center; margin-bottom: 0;">
        Jangan meneruskan email ini kepada orang lain. Tautan download bersifat pribadi untuk pemohon.
    </p>
@endsection

@extends('emails.layout', ['title' => 'Konfirmasi Pengajuan Surat'])

@section('content')
    <div class="greeting">Halo, {{ $application->user->name }}!</div>

    <p class="text">
        Pengajuan surat Anda berhasil disimpan dan telah masuk ke dalam antrean verifikasi petugas kantor desa.
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
            <span class="info-label">Waktu Pengajuan:</span>
            <span class="info-value">{{ ($application->submitted_at ?? $application->created_at)->translatedFormat('d F Y, H:i') }} WIB</span>
        </div>
        <div class="info-row">
            <span class="info-label">Status Saat Ini:</span>
            <span class="info-value">
                <span class="badge badge-info">Masuk Antrean (SUBMITTED)</span>
            </span>
        </div>
    </div>

    <p class="text">
        Petugas desa akan memeriksa keabsahan dan kelengkapan berkas Anda. Anda akan menerima notifikasi email berikutnya ketika berkas diproses atau terdapat catatan perbaikan.
    </p>

    <div class="btn-wrapper">
        <a href="{{ app(\App\Services\PublicUrlService::class)->route('pengajuan.show', $application->id) }}" class="btn" target="_blank">
            Pantau Status Pengajuan
        </a>
    </div>

    <p class="text" style="font-size: 12px; color: #94a3b8; text-align: center; margin-bottom: 0;">
        Anda juga dapat memantau proses permohonan melalui menu <em>Riwayat Pengajuan</em> di dashboard akun Anda.
    </p>
@endsection

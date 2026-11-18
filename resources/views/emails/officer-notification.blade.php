@extends('emails.layout', ['title' => 'Pemberitahuan Tugas Pelayanan Desa'])

@section('content')
    <div class="greeting">Halo, {{ $officer->name }}!</div>

    <p class="text">
        Terdapat berkas pengajuan warga yang membutuhkan tindakan atau review Anda sesuai peran dan kewenangan (<strong>{{ strtoupper($officer->role->name ?? 'PETUGAS') }}</strong>).
    </p>

    <div class="info-card">
        <div class="info-row">
            <span class="info-label">Tindakan Diperlukan:</span>
            <span class="info-value" style="color: #cb0c9f;">{{ $actionTitle }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Nomor Pengajuan:</span>
            <span class="info-value">{{ $application->application_number }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Nama Pemohon:</span>
            <span class="info-value">{{ $application->user?->name ?? 'Warga Pemohon' }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Status Saat Ini:</span>
            <span class="info-value">
                <span class="badge badge-info">{{ $application->status }}</span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">Waktu Kejadian:</span>
            <span class="info-value">{{ now()->translatedFormat('d F Y, H:i') }} WIB</span>
        </div>
    </div>

    <div style="background-color: #f8fafc; border: 1px dashed #cbd5e1; padding: 12px 16px; border-radius: 8px; margin-bottom: 24px; font-size: 13px; color: #475569;">
        {{ $actionDescription }}
    </div>

    <div class="btn-wrapper">
        <a href="{{ app(\App\Services\PublicUrlService::class)->route('admin.pengajuan.show', $application->id) }}" class="btn" target="_blank">
            Buka Halaman Verifikasi Petugas
        </a>
    </div>

    <p class="text" style="font-size: 11px; color: #94a3b8; text-align: center; margin-bottom: 0;">
        Pemberitahuan internal khusus aparatur desa berwenang. Kerahasiaan data warga dilindungi peraturan desa.
    </p>
@endsection

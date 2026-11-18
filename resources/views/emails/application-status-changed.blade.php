@extends('emails.layout', ['title' => 'Pembaruan Status Pengajuan'])

@section('content')
    <div class="greeting">Halo, {{ $application->user->name }}!</div>

    <p class="text">
        Terdapat pembaruan status terhadap permohonan surat administrasi Anda pada <strong>Sistem Pelayanan Desa Digital</strong>.
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
            <span class="info-label">Status Baru:</span>
            <span class="info-value">
                @php
                    $badgeClass = match($application->status) {
                        'APPROVED', 'COMPLETED' => 'badge-success',
                        'NEEDS_REVISION' => 'badge-warning',
                        'REJECTED' => 'badge-error',
                        default => 'badge-info',
                    };
                @endphp
                <span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">Waktu Pembaruan:</span>
            <span class="info-value">{{ now()->translatedFormat('d F Y, H:i') }} WIB</span>
        </div>
    </div>

    <div style="background-color: #f1f5f9; border-left: 4px solid #cb0c9f; padding: 14px 18px; border-radius: 8px; margin-bottom: 22px;">
        <p style="margin: 0; font-size: 13px; color: #334155; font-weight: 500;">
            {{ $statusDescription }}
        </p>
        @if(!empty($note))
            <p style="margin: 8px 0 0 0; font-size: 13px; color: #475569; font-style: italic;">
                <strong>Catatan Petugas:</strong> "{{ $note }}"
            </p>
        @endif
    </div>

    @if(in_array($application->status, ['APPROVED', 'COMPLETED']))
        <p class="text" style="color: #16a34a; font-weight: 600;">
            Surat keterangan Anda telah resmi disetujui. Anda dapat mengunduh dokumen digital bertanda tangan elektronik melalui tombol di bawah.
        </p>
    @elseif($application->status === 'NEEDS_REVISION')
        <p class="text" style="color: #d97706; font-weight: 600;">
            Silakan periksa catatan perbaikan dan unggah kembali dokumen perbaikan Anda melalui tautan di bawah.
        </p>
    @endif

    <div class="btn-wrapper">
        <a href="{{ app(\App\Services\PublicUrlService::class)->route('pengajuan.show', $application->id) }}" class="btn" target="_blank">
            Lihat Detail Pengajuan
        </a>
    </div>

    <p class="text" style="font-size: 12px; color: #94a3b8; text-align: center; margin-bottom: 0;">
        Terima kasih telah menggunakan layanan digital mandiri Desa.
    </p>
@endsection

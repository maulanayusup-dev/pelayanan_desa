@extends('emails.layout', ['title' => $eventTitle])

@section('content')
    <div class="greeting">Halo, {{ $recipient->name }}!</div>

    <p class="text">
        {{ $eventDescription }}
    </p>

    <div class="info-card">
        <div class="info-row">
            <span class="info-label">Nomor Tiket:</span>
            <span class="info-value">{{ $report->ticket_number }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Judul Laporan:</span>
            <span class="info-value">{{ $report->title }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Pelapor:</span>
            <span class="info-value">{{ $reporterName }} ({{ $reporterRoleLabel }})</span>
        </div>
        <div class="info-row">
            <span class="info-label">Kategori:</span>
            <span class="info-value">{{ $categoryLabel }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Prioritas:</span>
            <span class="info-value">{{ $priorityLabel }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Status Saat Ini:</span>
            <span class="info-value">
                <span class="badge badge-info">{{ $statusLabel }}</span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">Waktu Notifikasi:</span>
            <span class="info-value">{{ now()->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</span>
        </div>
    </div>

    <div class="btn-wrapper">
        <a href="{{ $actionUrl }}" class="btn" target="_blank">
            {{ $actionLabel }}
        </a>
    </div>

    <p class="text" style="font-size: 11px; color: #94a3b8; text-align: center; margin-bottom: 0;">
        Pemberitahuan otomatis dari Sistem Pelayanan Desa Digital. Jangan balas langsung ke email otomatis jika tidak diperlukan.
    </p>
@endsection


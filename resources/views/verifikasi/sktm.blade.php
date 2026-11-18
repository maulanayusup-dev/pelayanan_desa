<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Verifikasi Keaslian SKTM | Pelayanan Desa Digital</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body style="margin:0; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background:#f8fafc; color:#0f172a;">
    <div style="min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:24px 16px;">
        <div style="width:100%; max-width:640px; background:#ffffff; border:1px solid #e2e8f0; border-radius:20px; box-shadow:0 10px 30px rgba(15,23,42,0.06); overflow:hidden;">
            @php
                $isValid = $verification['isValid'] ?? false;
                $state = $verification['state'] ?? 'NOT_FOUND';
                $bannerBg = $isValid ? '#065f46' : ($state === 'REJECTED' ? '#9f1239' : ($state === 'UNISSUED' ? '#92400e' : '#334155'));
                $badgeBg = $isValid ? '#d1fae5' : ($state === 'REJECTED' ? '#ffe4e6' : ($state === 'UNISSUED' ? '#fef3c7' : '#e2e8f0'));
                $badgeText = $isValid ? '#065f46' : ($state === 'REJECTED' ? '#9f1239' : ($state === 'UNISSUED' ? '#92400e' : '#334155'));
            @endphp

            <div style="background:{{ $bannerBg }}; color:#ffffff; padding:24px;">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
                    <span style="font-size:11px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; opacity:0.9;">
                        Portal Verifikasi Resmi &bull; Pemerintah Desa Pelayanan
                    </span>
                    <span style="background:{{ $badgeBg }}; color:{{ $badgeText }}; font-size:11px; font-weight:800; padding:4px 10px; border-radius:9999px; letter-spacing:0.04em;">
                        {{ $verification['badgeLabel'] }}
                    </span>
                </div>
                <h1 style="margin:0; font-size:20px; font-weight:800; line-height:1.3;">
                    {{ $verification['headline'] }}
                </h1>
            </div>

            <div style="padding:24px;">
                <p style="margin:0 0 20px 0; font-size:14px; line-height:1.6; color:#475569;">
                    {{ $verification['description'] }}
                </p>

                @if (! empty($verification['fields']))
                    <div style="border:1px solid #e2e8f0; border-radius:14px; background:#f8fafc; overflow:hidden;">
                        <table style="width:100%; border-collapse:collapse; font-size:13px;">
                            @foreach ($verification['fields'] as $label => $value)
                                <tr style="border-bottom:1px solid #e2e8f0;">
                                    <th style="text-align:left; padding:11px 14px; width:40%; color:#64748b; font-weight:600; vertical-align:top;">
                                        {{ $label }}
                                    </th>
                                    <td style="padding:11px 14px; color:#0f172a; font-weight:700; vertical-align:top;">
                                        {{ $value }}
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endif

                <div style="margin-top:20px; padding:12px 14px; border-radius:12px; background:#f1f5f9; font-size:12px; color:#475569; line-height:1.5;">
                    Untuk melindungi privasi pemohon, halaman verifikasi publik ini tidak menampilkan data kependudukan pribadi (NIK, No. KK, alamat rumah, maupun lampiran berkas) dan tidak menyediakan tautan unduhan dokumen.
                </div>

                <div style="margin-top:20px; text-align:center;">
                    <a href="/" style="display:inline-block; padding:10px 18px; border-radius:10px; background:#1e293b; color:#ffffff; font-size:12px; font-weight:700; text-decoration:none;">
                        Kembali ke Beranda Pelayanan Desa
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>


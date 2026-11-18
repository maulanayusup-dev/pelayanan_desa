<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Pelayanan Desa' }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f6f9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #344767;
            -webkit-font-smoothing: antialiased;
        }
        table {
            border-spacing: 0;
        }
        td {
            padding: 0;
        }
        img {
            border: 0;
        }
        .wrapper {
            width: 100%;
            table-layout: fixed;
            background-color: #f4f6f9;
            padding: 30px 0;
        }
        .main-container {
            background-color: #ffffff;
            margin: 0 auto;
            width: 100%;
            max-width: 600px;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px 0 rgba(0, 0, 0, 0.05);
            border: 1px solid #edf2f7;
        }
        .header {
            background: linear-gradient(310deg, #141727 0%, #3a416f 100%);
            padding: 28px 30px;
            text-align: center;
        }
        .header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .header p {
            color: #cbd5e1;
            margin: 6px 0 0 0;
            font-size: 13px;
        }
        .content {
            padding: 32px 30px;
            line-height: 1.6;
        }
        .greeting {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 16px;
        }
        .text {
            font-size: 14px;
            color: #475569;
            margin-bottom: 20px;
        }
        .info-card {
            background-color: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 18px 20px;
            margin: 22px 0;
        }
        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .info-row:last-child {
            margin-bottom: 0;
        }
        .info-label {
            display: table-cell;
            width: 40%;
            color: #64748b;
            font-weight: 500;
        }
        .info-value {
            display: table-cell;
            width: 60%;
            color: #1e293b;
            font-weight: 600;
            text-align: right;
        }
        .btn-wrapper {
            text-align: center;
            margin: 28px 0 20px 0;
        }
        .btn {
            display: inline-block;
            background: linear-gradient(310deg, #cb0c9f 0%, #e91e63 100%);
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 28px;
            font-size: 14px;
            font-weight: 700;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(233, 30, 99, 0.25);
            letter-spacing: 0.2px;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
        }
        .badge-success {
            background-color: #dcfce7;
            color: #166534;
        }
        .badge-info {
            background-color: #e0f2fe;
            color: #0369a1;
        }
        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }
        .badge-error {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .footer {
            background-color: #f8fafc;
            border-top: 1px solid #edf2f7;
            padding: 22px 30px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.5;
        }
        .footer a {
            color: #cb0c9f;
            text-decoration: none;
        }
        .divider {
            height: 1px;
            background-color: #e2e8f0;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <table class="main-container" align="center" cellpadding="0" cellspacing="0">
            <!-- Header -->
            <tr>
                <td class="header">
                    <h1>Sistem Pelayanan Desa Digital</h1>
                    <p>Layanan Administrasi & Surat Keterangan Mandiri</p>
                </td>
            </tr>

            <!-- Content -->
            <tr>
                <td class="content">
                    @yield('content')
                </td>
            </tr>

            <!-- Footer -->
            <tr>
                <td class="footer">
                    <p style="margin: 0 0 6px 0;">&copy; {{ date('Y') }} Pemerintah Desa &bull; Sistem Pelayanan Desa Terpadu</p>
                    <p style="margin: 0; font-size: 11px; color: #cbd5e1;">
                        Email ini dikirim otomatis oleh sistem notifikasi resmi. Mohon untuk tidak membalas email ini secara langsung.
                    </p>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>

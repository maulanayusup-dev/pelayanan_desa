<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Keterangan Tidak Mampu</title>
    <style>
        body { font-family: 'Times New Roman', Times, serif; margin: 20px; line-height: 1.5; }
        .header { text-align: center; border-bottom: 3px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .header h3, .header h2, .header p { margin: 2px 0; }
        .title { text-align: center; margin-bottom: 30px; text-decoration: underline; font-weight: bold; }
        .content { margin-bottom: 30px; }
        .data-table { margin-left: 20px; margin-bottom: 20px; }
        .data-table td { padding: 3px; }
        .footer { float: right; width: 250px; text-align: center; }
        .signature { margin-top: 60px; font-weight: bold; text-decoration: underline; }
        .qr-code { float: left; width: 170px; margin-top: 18px; text-align: center; }
        .qr-code img { display: block; margin: 0 auto; border: 1px solid #cbd5e1; padding: 3px; background: #ffffff; }
    </style>
</head>
<body>
    <div class="header">
        <h3>PEMERINTAH KABUPATEN DESA</h3>
        <h2>KECAMATAN CONTOH</h2>
        <h2>KANTOR KEPALA DESA PELAYANAN</h2>
        <p>Jl. Raya Desa No. 123, Kode Pos 12345</p>
    </div>

    <div class="title">
        SURAT KETERANGAN TIDAK MAMPU (SKTM)<br>
        Nomor: {{ $letterNumber }}
    </div>

    <div class="content">
        <p>Yang bertanda tangan di bawah ini, Kepala Desa Pelayanan, Kecamatan Contoh, dengan ini menerangkan bahwa:</p>
        
        @php 
            $data = is_array($app->form_data) ? $app->form_data : (json_decode($app->form_data, true) ?? []); 
            $genderRaw = $data['gender'] ?? $data['jenis_kelamin'] ?? '-';
            $genderLabel = $genderRaw === 'L' ? 'Laki-laki' : ($genderRaw === 'P' ? 'Perempuan' : $genderRaw);
            $birthPlace = $data['place_of_birth'] ?? $data['tempat_lahir'] ?? '-';
            $birthDate = $data['date_of_birth'] ?? $data['tanggal_lahir'] ?? '-';
            $occupation = $data['occupation'] ?? $data['pekerjaan'] ?? '-';
        @endphp
        <table class="data-table">
            <tr>
                <td width="150">Nama Lengkap</td>
                <td width="10">:</td>
                <td>{{ $data['name'] ?? $app->user->name }}</td>
            </tr>
            <tr>
                <td>NIK</td>
                <td>:</td>
                <td>{{ $data['nik'] ?? '-' }}</td>
            </tr>
            <tr>
                <td>Tempat, Tgl Lahir</td>
                <td>:</td>
                <td>{{ $birthPlace }}, {{ $birthDate }}</td>
            </tr>
            <tr>
                <td>Jenis Kelamin</td>
                <td>:</td>
                <td>{{ $genderLabel }}</td>
            </tr>
            <tr>
                <td>Pekerjaan</td>
                <td>:</td>
                <td>{{ $occupation }}</td>
            </tr>
            <tr>
                <td>Alamat</td>
                <td>:</td>
                <td>{{ $data['address'] ?? '-' }}, RT {{ $data['rt'] ?? '-' }}/RW {{ $data['rw'] ?? '-' }}, Desa {{ $data['desa'] ?? '-' }}</td>
            </tr>
        </table>

        <p>Orang tersebut di atas adalah benar-benar penduduk/warga Desa Pelayanan yang berdomisili di alamat tersebut. Berdasarkan pengamatan dan data yang ada pada kami, yang bersangkutan benar-benar termasuk keluarga prasejahtera / TIDAK MAMPU.</p>

        <p>Surat Keterangan ini dibuat untuk keperluan: <strong>{{ $app->purpose }}</strong>.</p>
        
        <p>Demikian surat keterangan ini dibuat dengan sebenarnya untuk dipergunakan sebagaimana mestinya.</p>
    </div>

    <div class="qr-code">
        <img src="{{ $qrCodeUrl }}" alt="QR Code Verifikasi SKTM" width="115" height="115">
        <p style="font-size: 9px; margin-top: 5px; line-height: 1.3;">Pindai QR untuk verifikasi keaslian surat</p>
    </div>

    <div class="footer">
        <p>Desa Pelayanan, {{ \Carbon\Carbon::parse($app->resolved_at ?? now())->translatedFormat('d F Y') }}</p>
        <p>Kepala Desa Pelayanan,</p>
        
        <div class="signature">
            {{ \App\Models\User::whereHas('role', function($q) { $q->whereIn('name', ['kades', 'kepala_desa']); })->first()->name ?? 'Kepala Desa' }}
        </div>
    </div>
</body>
</html>

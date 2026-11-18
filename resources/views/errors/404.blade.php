<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Halaman Tidak Ditemukan | Pelayanan Desa</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-[#F8F9FA] text-slate-700 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-soft border border-slate-100 p-8 text-center animate-fade-in-up">
        <div class="w-20 h-20 rounded-2xl bg-gradient-primary text-white flex items-center justify-center mx-auto mb-6 shadow-soft text-3xl">
            <i class="fas fa-compass"></i>
        </div>
        <span class="text-xs uppercase font-extrabold tracking-widest text-primary-500 bg-primary-50 px-3 py-1 rounded-full mb-3 inline-block">
            Error 404 &bull; Not Found
        </span>
        <h1 class="text-2xl font-extrabold text-slate-800 mb-2">Halaman Tidak Ditemukan</h1>
        <p class="text-xs text-slate-500 font-medium leading-relaxed mb-6">
            Mohon maaf, halaman atau berkas yang Anda cari tidak tersedia, telah dipindahkan, atau tautan yang dimasukkan salah.
        </p>
        <div class="flex flex-col sm:flex-row gap-3 justify-center">
            <a href="/" class="btn-soft-primary justify-center flex items-center">
                <i class="fas fa-house mr-2 text-xs"></i> Ke Halaman Utama
            </a>
            <a href="/faq" class="btn-soft-white justify-center flex items-center">
                <i class="fas fa-circle-question mr-2 text-xs"></i> Pusat Bantuan
            </a>
        </div>
    </div>
</body>
</html>

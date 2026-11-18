@echo off
title Worker Antrean Notifikasi - Pelayanan Desa
echo ==============================================================
echo Menjalankan Queue Worker Pelayanan Desa
echo Antrean: sktm, laporan-prioritas, laporan, default
echo Koneksi: database
echo ==============================================================
php artisan queue:work database --queue=sktm,laporan-prioritas,laporan,default --sleep=3 --tries=3 --backoff=10,30,60
pause


<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $roles = ['masyarakat', 'staf', 'supervisor', 'kades', 'kepala_desa'];
        foreach ($roles as $role) {
            \App\Models\Role::firstOrCreate(['name' => $role]);
        }

        $docTypes = [
            [
                'code' => 'SKTM',
                'name' => 'Surat Keterangan Tidak Mampu',
                'description' => 'Surat bukti prasejahtera untuk beasiswa, bantuan sosial, atau keringanan biaya pengobatan. Syarat: KTP, KK, Surat Pengantar RT/RW. Prosedur: Pengajuan via web/kantor desa -> Verifikasi Staf -> Persetujuan Kepala Desa -> Cetak PDF. Biaya: Gratis (Rp 0). Estimasi waktu: 1-2 hari kerja.'
            ],
            [
                'code' => 'SK_DOMISILI',
                'name' => 'Surat Keterangan Domisili',
                'description' => 'Surat menerangkan tempat tinggal / domisili warga di wilayah desa. Syarat: KTP, KK, Surat Pengantar RT/RW, Surat pernyataan tempat tinggal jika sewa. Biaya: Gratis (Rp 0). Estimasi waktu: 1 hari kerja.'
            ],
            [
                'code' => 'PENGANTAR_SKCK',
                'name' => 'Surat Pengantar SKCK',
                'description' => 'Surat pengantar desa untuk pembuatan SKCK di Polsek/Polres. Syarat: Fotokopi KTP, KK, Surat Pengantar RT/RW, Pasfoto 4x6 (2 lembar). Catatan: SKCK resmi diterbitkan Polsek/Polres. Biaya di desa: Gratis (Rp 0).'
            ],
            [
                'code' => 'SKU',
                'name' => 'Surat Keterangan Usaha',
                'description' => 'Surat keterangan memiliki usaha di lokasi desa. Syarat: KTP, KK, Surat Pengantar RT/RW, Foto tempat usaha. Biaya: Gratis (Rp 0). Estimasi waktu: 1-2 hari kerja.'
            ],
            [
                'code' => 'SK_KELAHIRAN',
                'name' => 'Surat Keterangan Kelahiran',
                'description' => 'Surat pengantar untuk penerbitan Akta Kelahiran di Disdukcapil. Syarat: Surat Lahir Bidan/RS, KTP Orang Tua, KK, Buku Nikah. Biaya: Gratis (Rp 0).'
            ]
        ];

        foreach ($docTypes as $doc) {
            \App\Models\DocumentType::updateOrCreate(['code' => $doc['code']], $doc);
        }

        $defaultUsers = [
            ['name' => 'Warga Desa', 'email' => 'masyarakat@desa.id', 'role' => 'masyarakat'],
            ['name' => 'Staf Desa', 'email' => 'staf@desa.id', 'role' => 'staf'],
            ['name' => 'Supervisor Kasi', 'email' => 'supervisor@desa.id', 'role' => 'supervisor'],
            ['name' => 'Kepala Desa', 'email' => 'kades@desa.id', 'role' => 'kades'],
        ];

        foreach ($defaultUsers as $userData) {
            $role = \App\Models\Role::where('name', $userData['role'])->first();
            if ($role) {
                User::updateOrCreate(
                    ['email' => $userData['email']],
                    [
                        'name' => $userData['name'],
                        'password' => bcrypt('password'),
                        'role_id' => $role->id,
                        'email_verified_at' => now(),
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}

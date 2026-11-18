<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\DocumentType;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class SktmForm extends Component
{
    use WithFileUploads;

    public $currentStep = 1;
    public $isSubmitting = false;

    // Data Pemohon
    public $nik, $no_kk, $name, $place_of_birth, $date_of_birth, $gender;
    public $address, $rt, $rw, $desa, $kecamatan, $kabupaten;
    public $occupation, $penghasilan;

    // Keperluan
    public $purpose, $applicant_notes;

    // AI Form Assistant
    public $aiExplanation = '';
    public $activeAiField = '';

    // Dokumen
    public $ktp, $kk, $pendukung;

    protected function messages(): array
    {
        return [
            'nik.required' => 'NIK wajib diisi.',
            'nik.numeric' => 'NIK harus berupa angka.',
            'nik.digits' => 'NIK harus berjumlah 16 digit.',
            'nik.unique' => 'NIK ini sudah terdaftar dalam sistem atas nama warga lain. Mohon periksa kembali NIK Anda.',
            'no_kk.required' => 'Nomor KK wajib diisi.',
            'no_kk.numeric' => 'Nomor KK harus berupa angka.',
            'no_kk.digits' => 'Nomor KK harus berjumlah 16 digit.',
            'name.required' => 'Nama lengkap wajib diisi.',
            'name.max' => 'Nama lengkap maksimal 255 karakter.',
            'place_of_birth.required' => 'Tempat lahir wajib diisi.',
            'date_of_birth.required' => 'Tanggal lahir wajib diisi.',
            'date_of_birth.date' => 'Format tanggal lahir tidak valid.',
            'gender.required' => 'Jenis kelamin wajib dipilih.',
            'gender.in' => 'Pilihan jenis kelamin tidak valid.',
            'address.required' => 'Alamat jalan/rumah wajib diisi.',
            'rt.required' => 'RT wajib diisi.',
            'rt.numeric' => 'RT harus berupa angka.',
            'rw.required' => 'RW wajib diisi.',
            'rw.numeric' => 'RW harus berupa angka.',
            'desa.required' => 'Desa / Kelurahan wajib diisi.',
            'kecamatan.required' => 'Kecamatan wajib diisi.',
            'kabupaten.required' => 'Kabupaten / Kota wajib diisi.',
            'occupation.required' => 'Pekerjaan saat ini wajib diisi.',
            'penghasilan.required' => 'Penghasilan per bulan wajib diisi.',
            'penghasilan.numeric' => 'Penghasilan harus berupa angka.',
            'penghasilan.min' => 'Penghasilan minimal Rp 0.',
            'purpose.required' => 'Tujuan pembuatan SKTM wajib dipilih.',
            'purpose.max' => 'Tujuan pembuatan SKTM maksimal 255 karakter.',
            'ktp.required' => 'Berkas KTP asli wajib diunggah.',
            'ktp.file' => 'Berkas KTP tidak valid.',
            'ktp.mimes' => 'Format berkas KTP harus JPG, JPEG, PNG, atau PDF.',
            'ktp.max' => 'Ukuran berkas KTP maksimal 2MB.',
            'kk.required' => 'Berkas Kartu Keluarga asli wajib diunggah.',
            'kk.file' => 'Berkas Kartu Keluarga tidak valid.',
            'kk.mimes' => 'Format berkas Kartu Keluarga harus JPG, JPEG, PNG, atau PDF.',
            'kk.max' => 'Ukuran berkas Kartu Keluarga maksimal 2MB.',
            'pendukung.file' => 'Berkas pendukung tidak valid.',
            'pendukung.mimes' => 'Format berkas pendukung harus JPG, JPEG, PNG, atau PDF.',
            'pendukung.max' => 'Ukuran berkas pendukung maksimal 2MB.',
        ];
    }

    public function step1Rules(): array
    {
        $profileId = auth()->user()?->profile?->id;

        return [
            'nik' => [
                'required',
                'numeric',
                'digits:16',
                \Illuminate\Validation\Rule::unique('user_profiles', 'nik')->ignore($profileId),
            ],
            'no_kk' => 'required|numeric|digits:16',
            'name' => 'required|string|max:255',
            'place_of_birth' => 'required|string',
            'date_of_birth' => 'required|date',
            'gender' => 'required|in:L,P',
            'address' => 'required|string',
            'rt' => 'required|numeric',
            'rw' => 'required|numeric',
            'desa' => 'required|string',
            'kecamatan' => 'required|string',
            'kabupaten' => 'required|string',
            'occupation' => 'required|string',
            'penghasilan' => 'required|numeric|min:0',
        ];
    }

    public function step2Rules(): array
    {
        return [
            'purpose' => 'required|string|max:255',
            'applicant_notes' => 'nullable|string',
        ];
    }

    public function step3Rules(): array
    {
        return [
            'ktp' => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048',
            'kk' => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048',
            'pendukung' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ];
    }

    public function mount()
    {
        $user = auth()->user();
        if (!$user) {
            return redirect()->route('login');
        }

        if ($user->role && !in_array($user->role->name, ['masyarakat', 'user', 'warga'])) {
            abort(403, 'Hanya masyarakat yang berwenang membuat pengajuan SKTM.');
        }

        if (!$user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        $this->name = $user->name;
        
        $profile = $user->profile;
        if ($profile) {
            $this->nik = $profile->nik;
            $this->no_kk = $profile->no_kk;
            $this->place_of_birth = $profile->place_of_birth;
            $this->date_of_birth = $profile->date_of_birth ? ($profile->date_of_birth instanceof \Carbon\CarbonInterface ? $profile->date_of_birth->format('Y-m-d') : substr((string)$profile->date_of_birth, 0, 10)) : null;
            $this->gender = $profile->gender ?? $user->gender;
            $this->address = $profile->address;
            $this->rt = $profile->rt;
            $this->rw = $profile->rw;
            $this->desa = $profile->desa;
            $this->kecamatan = $profile->kecamatan;
            $this->kabupaten = $profile->kabupaten;
            $this->occupation = $profile->occupation;
            $this->penghasilan = $profile->penghasilan;
        } else {
            $this->gender = $user->gender;
        }
    }

    public function askAiExplain($field)
    {
        $this->activeAiField = $field;
        
        $fieldMap = [
            'penghasilan' => 'Penghasilan Per Bulan',
            'purpose' => 'Tujuan Pembuatan SKTM',
            'applicant_notes' => 'Keterangan/Keperluan Tambahan',
            'occupation' => 'Pekerjaan',
        ];

        $fieldName = $fieldMap[$field] ?? $field;
        
        $ai = app(\App\Services\AiService::class);
        $prompt = "Jelaskan dengan 1 atau 2 kalimat sederhana kepada masyarakat awam tentang bagaimana cara mengisi kolom '{$fieldName}' pada formulir pembuatan SKTM (Surat Keterangan Tidak Mampu). Contoh: 'Penghasilan per bulan diisi dengan perkiraan rata-rata penghasilan keluarga per bulan.'";
        
        $explanation = $ai->chat([
            ['role' => 'user', 'content' => $prompt]
        ]);

        if ($ai->isProviderFailure($explanation)) {
            $localGuides = [
                'penghasilan' => 'Isi dengan perkiraan rata-rata total penghasilan keluarga per bulan dalam rupiah (contoh: 800000 atau Rp 800.000).',
                'purpose' => 'Jelaskan keperluan pengajuan SKTM secara singkat dan jelas, misalnya untuk pengajuan beasiswa sekolah/kuliah atau keringanan biaya berobat.',
                'applicant_notes' => 'Isi keterangan tambahan bila ada informasi pendukung keluarga yang perlu diketahui staf desa (boleh dikosongkan bila tidak ada).',
                'occupation' => 'Isi sesuai pekerjaan utama pemohon atau kepala keluarga saat ini (misalnya Buruh Harian, Petani, Pedagang Kecil, atau Belum Bekerja).',
            ];
            $explanation = $localGuides[$field] ?? "Silakan isi kolom {$fieldName} sesuai data kondisi keluarga Anda yang sebenarnya.";
        }

        $this->aiExplanation = $explanation;
    }

    public function nextStep()
    {
        if ($this->currentStep == 1) {
            $this->validate($this->step1Rules(), $this->messages());
            $this->currentStep = 2;
        } elseif ($this->currentStep == 2) {
            $this->validate($this->step2Rules(), $this->messages());
            $this->currentStep = 3;
        }
    }

    public function prevStep()
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    public function submit()
    {
        if ($this->currentStep < 3) {
            $this->nextStep();
            return;
        }

        if ($this->isSubmitting) {
            return;
        }

        // Re-validate all steps to maintain server-side integrity
        try {
            $this->validate($this->step1Rules(), $this->messages());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->currentStep = 1;
            throw $e;
        }

        try {
            $this->validate($this->step2Rules(), $this->messages());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->currentStep = 2;
            throw $e;
        }

        $this->validate($this->step3Rules(), $this->messages());

        $this->isSubmitting = true;

        try {
            DB::beginTransaction();

            $user = auth()->user();
            if (!$user) {
                session()->flash('error', 'Sesi login telah berakhir. Silakan login kembali.');
                return redirect()->route('login');
            }

            if ($user->role && !in_array($user->role->name, ['masyarakat', 'user', 'warga'])) {
                session()->flash('error', 'Hanya warga/masyarakat yang berwenang membuat pengajuan SKTM.');
                return redirect()->route('dashboard');
            }
            
            // 1. Update Profile
            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'nik' => $this->nik,
                    'no_kk' => $this->no_kk,
                    'place_of_birth' => $this->place_of_birth,
                    'date_of_birth' => $this->date_of_birth,
                    'gender' => $this->gender,
                    'address' => $this->address,
                    'rt' => $this->rt,
                    'rw' => $this->rw,
                    'desa' => $this->desa,
                    'kecamatan' => $this->kecamatan,
                    'kabupaten' => $this->kabupaten,
                    'occupation' => $this->occupation,
                    'penghasilan' => $this->penghasilan,
                ]
            );

            // 2. Create Application
            $docType = DocumentType::where('code', 'SKTM')->firstOrFail();
            $appNumber = 'SKTM-' . date('Ymd') . '-' . strtoupper(Str::random(4));

            $application = Application::create([
                'application_number' => $appNumber,
                'user_id' => $user->id,
                'document_type_id' => $docType->id,
                'status' => 'SUBMITTED',
                'purpose' => $this->purpose,
                'form_data' => [
                    'nik' => $this->nik,
                    'no_kk' => $this->no_kk,
                    'name' => $this->name,
                    'place_of_birth' => $this->place_of_birth,
                    'date_of_birth' => $this->date_of_birth,
                    'gender' => $this->gender,
                    'address' => $this->address,
                    'rt' => $this->rt,
                    'rw' => $this->rw,
                    'desa' => $this->desa,
                    'kecamatan' => $this->kecamatan,
                    'kabupaten' => $this->kabupaten,
                    'occupation' => $this->occupation,
                    'penghasilan' => $this->penghasilan,
                ],
                'applicant_notes' => $this->applicant_notes,
                'submitted_at' => now(),
            ]);

            // 3. Upload Documents
            $ktpPath = $this->ktp->store('documents/sktm', 'local');
            ApplicationDocument::create([
                'application_id' => $application->id,
                'document_name' => 'KTP',
                'file_path' => $ktpPath,
                'mime_type' => $this->ktp->getMimeType(),
                'status' => 'PENDING',
            ]);

            $kkPath = $this->kk->store('documents/sktm', 'local');
            ApplicationDocument::create([
                'application_id' => $application->id,
                'document_name' => 'Kartu Keluarga',
                'file_path' => $kkPath,
                'mime_type' => $this->kk->getMimeType(),
                'status' => 'PENDING',
            ]);

            if ($this->pendukung) {
                $pendukungPath = $this->pendukung->store('documents/sktm', 'local');
                ApplicationDocument::create([
                    'application_id' => $application->id,
                    'document_name' => 'Dokumen Pendukung',
                    'file_path' => $pendukungPath,
                    'mime_type' => $this->pendukung->getMimeType(),
                    'status' => 'PENDING',
                ]);
            }

            // 4. Record History
            ApplicationHistory::create([
                'application_id' => $application->id,
                'actor_id' => $user->id,
                'status_from' => 'DRAFT',
                'status_to' => 'SUBMITTED',
                'description' => 'Pengajuan SKTM berhasil disubmit oleh masyarakat.',
            ]);

            DB::commit();

            // Trigger notification
            try {
                \App\Services\NotificationService::notifyStatusChange($application);
            } catch (\Throwable $ne) {
                Log::warning('Gagal memicu notifikasi pengajuan baru: ' . $ne->getMessage());
            }

            session()->flash('message', 'Pengajuan SKTM berhasil dibuat dengan nomor ' . $appNumber);
            session()->flash('success', 'Pengajuan SKTM berhasil dikirim dengan nomor ' . $appNumber);
            return $this->redirect('/pengajuan');

        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->isSubmitting = false;

            // Handle potential database unique constraint violation gracefully
            if ($e instanceof \Illuminate\Database\QueryException && ($e->errorInfo[1] ?? null) == 1062) {
                if (str_contains($e->getMessage(), 'user_profiles_nik_unique')) {
                    $this->currentStep = 1;
                    $this->addError('nik', 'NIK ini sudah terdaftar dalam sistem atas nama warga lain.');
                    session()->flash('error', 'NIK yang Anda masukkan sudah terdaftar atas nama warga lain. Silakan periksa kembali pada Langkah 1.');
                    return;
                }
            }

            Log::error('Error submit SKTM: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            session()->flash('error', 'Terjadi kesalahan sistem saat menyimpan pengajuan. Mohon coba beberapa saat lagi.');
        }
    }

    public function render()
    {
        return view('livewire.sktm-form');
    }
}

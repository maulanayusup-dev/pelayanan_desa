<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome', ['title' => 'Sistem Pelayanan Desa']);
});

Route::get('/informasi-sktm', function () {
    return view('informasi', ['title' => 'Informasi SKTM']);
});

Route::get('/persyaratan', function () {
    return view('persyaratan', ['title' => 'Persyaratan SKTM']);
});

Route::get('/faq', function () {
    return view('faq', ['title' => 'FAQ']);
});

Route::get('/verifikasi-sktm/{token}', [\App\Http\Controllers\SktmVerificationController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('sktm.verify');

Route::get('/login', function () {
    if (auth()->check()) {
        $user = auth()->user();
        if ($user->role && $user->role->name !== 'masyarakat') {
            return redirect()->route('admin.dashboard');
        }
        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }
        return redirect()->route('dashboard');
    }
    return view('auth.login', ['title' => 'Login']);
})->name('login');

Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login'])->name('login.post');

Route::get('/register', function () {
    if (auth()->check()) {
        $user = auth()->user();
        if ($user->role && $user->role->name !== 'masyarakat') {
            return redirect()->route('admin.dashboard');
        }
        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }
        return redirect()->route('dashboard');
    }
    return view('auth.register', ['title' => 'Register']);
})->name('register');

Route::post('/register', [\App\Http\Controllers\AuthController::class, 'register'])->name('register.post');

Route::get('/logout', function () {
    return redirect()->route('login');
});
Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout'])->name('logout');

// Email Verification Routes
Route::get('/email/verify', function () {
    if (auth()->user()?->hasVerifiedEmail()) {
        return redirect()->route('dashboard');
    }
    return view('auth.verify-email');
})->middleware('auth')->name('verification.notice');

Route::get('/email/verify/{id}/{hash}', function (\Illuminate\Http\Request $request, $id, $hash) {
    if (! $request->hasValidSignature()) {
        abort(403, 'Tautan verifikasi tidak valid atau telah kedaluwarsa.');
    }

    $user = \App\Models\User::findOrFail($id);

    if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
        abort(403, 'Hash verifikasi email tidak cocok.');
    }

    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new \Illuminate\Auth\Events\Verified($user));
    }

    if (auth()->check()) {
        return redirect()->route('dashboard')->with('success', 'Email Anda berhasil diverifikasi!');
    }

    return redirect()->route('login')->with('success', 'Email Anda berhasil diverifikasi! Silakan masuk ke akun Anda.');
})->name('verification.verify');

Route::post('/email/verification-notification', function (\Illuminate\Http\Request $request) {
    $user = $request->user();

    if ($user->hasVerifiedEmail()) {
        return redirect()->route('dashboard');
    }

    $sent = $user->sendEmailVerificationNotification();

    if (! $sent) {
        return back()->with('error', 'Gagal mengirim email verifikasi karena kendala koneksi server email (SMTP). Silakan periksa konfigurasi mail server pada file .env atau coba beberapa saat lagi.');
    }

    return back()->with('status', 'verification-link-sent');
})->middleware(['auth', 'throttle:6,1'])->name('verification.send');

Route::middleware(['auth', 'active.user'])->group(function () {
    Route::get('/profile', [\App\Http\Controllers\ProfileController::class, 'show'])->name('profile');
    Route::put('/profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.password');

    // Warga yang belum terverifikasi email tidak boleh mengakses dashboard & pengajuan surat
    Route::middleware('verified.citizen')->group(function () {
        Route::get('/dashboard', function () {
            $user = auth()->user();
            if ($user->role?->name !== 'masyarakat') {
                return redirect()->route('admin.dashboard');
            }

            $applications = \App\Models\Application::where('user_id', $user->id)->latest()->take(5)->get();
            $total = \App\Models\Application::where('user_id', $user->id)->count();
            $approved = \App\Models\Application::where('user_id', $user->id)->whereIn('status', ['APPROVED', 'COMPLETED'])->count();
            $processing = \App\Models\Application::where('user_id', $user->id)->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW', 'SUPERVISOR_REVIEW', 'HEAD_APPROVAL'])->count();
            $needs_revision = \App\Models\Application::where('user_id', $user->id)->where('status', 'NEEDS_REVISION')->count();
            
            return view('dashboard', [
                'title' => 'Dashboard Masyarakat', 
                'applications' => $applications,
                'total' => $total,
                'approved' => $approved,
                'processing' => $processing,
                'needs_revision' => $needs_revision
            ]);
        })->name('dashboard');

        Route::get('/pengajuan', function () {
            $applications = \App\Models\Application::where('user_id', auth()->id())->latest()->paginate(5);
            return view('pengajuan.index', ['title' => 'Pengajuan SKTM', 'applications' => $applications]);
        })->name('pengajuan');

        Route::get('/pengajuan/create', function () {
            $userRole = auth()->user()->role?->name;
            if ($userRole && !in_array($userRole, ['masyarakat', 'user', 'warga'])) {
                abort(403, 'Akses ditolak.');
            }
            return view('pengajuan.create', ['title' => 'Buat Pengajuan SKTM']);
        })->name('pengajuan.create');

        Route::get('/pengajuan/{id}', function ($id) {
            $userRole = auth()->user()->role?->name;
            $query = \App\Models\Application::with(['documents', 'histories' => function($q) {
                $q->orderBy('created_at', 'desc');
            }, 'reviews.reviewer']);

            if ($userRole === 'masyarakat') {
                $query->where('user_id', auth()->id());
            }

            $application = $query->where('id', $id)->firstOrFail();
            
            return view('pengajuan.show', ['title' => 'Detail Pengajuan', 'app' => $application]);
        })->name('pengajuan.show');

        Route::get('/pengajuan/{id}/download', function ($id) {
            $application = \App\Models\Application::with(['user', 'documents'])->findOrFail($id);

            $user = auth()->user();
            $normalizedRole = strtolower((string) ($user?->role?->name ?? ''));
            if ($normalizedRole === 'masyarakat') {
                if ($application->user_id !== $user->id) {
                    abort(403, 'Akses ditolak.');
                }
            } elseif (! in_array($normalizedRole, ['staf', 'supervisor', 'kades', 'kepala_desa'], true)) {
                abort(403, 'Akses ditolak.');
            }

            if (! in_array($application->status, ['APPROVED', 'COMPLETED'], true)) {
                return back()->with('error', 'Surat SKTM belum disetujui / diterbitkan.');
            }

            return app(\App\Services\SktmPdfService::class)->downloadForAuthorizedUser($application);
        })->name('pengajuan.download');

        Route::post('/pengajuan/{id}/revisi', function (\Illuminate\Http\Request $request, $id) {
            $application = \App\Models\Application::with('documents')->where('id', $id)->where('user_id', auth()->id())->firstOrFail();

            if ($application->status !== 'NEEDS_REVISION') {
                return back()->with('error', 'Pengajuan ini tidak dalam status perlu revisi.');
            }

            $request->validate([
                'notes' => 'nullable|string|max:500',
                'ktp' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
                'kk' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
                'pendukung' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
            ], [
                'ktp.max' => 'Ukuran berkas KTP maksimal 2MB.',
                'kk.max' => 'Ukuran berkas KK maksimal 2MB.',
                'pendukung.max' => 'Ukuran berkas pendukung maksimal 2MB.',
            ]);

            \Illuminate\Support\Facades\DB::beginTransaction();
            try {
                if ($request->hasFile('ktp')) {
                    $path = $request->file('ktp')->store('documents/sktm', 'local');
                    $doc = $application->documents()->where('document_name', 'KTP')->first();
                    if ($doc) {
                        $doc->update(['file_path' => $path, 'status' => 'PENDING', 'notes' => null]);
                    }
                }

                if ($request->hasFile('kk')) {
                    $path = $request->file('kk')->store('documents/sktm', 'local');
                    $doc = $application->documents()->where('document_name', 'Kartu Keluarga')->first();
                    if ($doc) {
                        $doc->update(['file_path' => $path, 'status' => 'PENDING', 'notes' => null]);
                    }
                }

                if ($request->hasFile('pendukung')) {
                    $path = $request->file('pendukung')->store('documents/sktm', 'local');
                    $doc = $application->documents()->where('document_name', 'Dokumen Pendukung')->first();
                    if ($doc) {
                        $doc->update(['file_path' => $path, 'status' => 'PENDING', 'notes' => null]);
                    } else {
                        $application->documents()->create([
                            'document_name' => 'Dokumen Pendukung',
                            'file_path' => $path,
                            'mime_type' => $request->file('pendukung')->getMimeType(),
                            'status' => 'PENDING',
                        ]);
                    }
                }

                $application->status = 'SUBMITTED';
                $application->submitted_at = now();
                $application->save();

                \App\Models\ApplicationHistory::create([
                    'application_id' => $application->id,
                    'actor_id' => auth()->id(),
                    'status_from' => 'NEEDS_REVISION',
                    'status_to' => 'SUBMITTED',
                    'description' => 'Masyarakat mengunggah perbaikan dokumen. ' . ($request->notes ? 'Catatan: ' . $request->notes : ''),
                ]);

                \Illuminate\Support\Facades\DB::commit();

                \App\Services\NotificationService::notifyStatusChange($application, 'NEEDS_REVISION');

                return back()->with('message', 'Dokumen revisi berhasil dikirim. Pengajuan Anda kembali dalam antrean pemeriksaan staf.');
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\DB::rollBack();
                return back()->with('error', 'Gagal mengirim revisi: ' . $e->getMessage());
            }
        })->name('pengajuan.revisi');

        Route::get('/laporan/lampiran/{attachment}', \App\Http\Controllers\ReportAttachmentController::class)
            ->whereUuid('attachment')
            ->name('reports.attachments.download');

        Route::get('/laporan', \App\Livewire\Reports\MyReports::class)
            ->name('reports.index');
        Route::get('/laporan/buat', \App\Livewire\Reports\CreateReport::class)
            ->name('reports.create');
        Route::get('/laporan/{report}', \App\Livewire\Reports\ReportDetail::class)
            ->whereUuid('report')
            ->name('reports.show');
    });

    Route::get('/pengajuan/dokumen/{id}', function ($id) {
        $doc = \App\Models\ApplicationDocument::with('application')->findOrFail($id);

        $user = auth()->user();
        if (! $user || ! $user->isActiveAccount()) {
            abort(403, 'Akses ditolak. Akun Anda tidak aktif.');
        }

        if (! $doc->application) {
            abort(404, 'Data pengajuan tidak ditemukan.');
        }

        $normalizedRole = strtolower((string) ($user->role?->name ?? ''));
        $allowedOfficerRoles = ['staf', 'supervisor', 'kades', 'kepala_desa'];

        if ($normalizedRole === 'masyarakat') {
            if (! $user->hasVerifiedEmail()) {
                abort(403, 'Anda wajib memverifikasi alamat email sebelum dapat mengakses dokumen pengajuan.');
            }
            if ($doc->application->user_id !== $user->id) {
                abort(403, 'Akses ditolak.');
            }
        } elseif (! in_array($normalizedRole, $allowedOfficerRoles, true)) {
            abort(403, 'Akses ditolak. Peran akun tidak memiliki kewenangan mengakses dokumen.');
        }

        $filePath = (string) $doc->file_path;
        if (
            $filePath === ''
            || str_contains($filePath, "\0")
            || str_contains($filePath, '..')
            || str_starts_with($filePath, '/')
            || str_starts_with($filePath, '\\')
            || preg_match('/^[A-Za-z]:/', $filePath) === 1
        ) {
            abort(403, 'Lokasi berkas dokumen tidak valid.');
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $disk->exists($filePath)) {
            abort(404, 'Berkas dokumen fisik tidak ditemukan di server.');
        }

        return $disk->response($filePath, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    })->name('pengajuan.dokumen');
});

// Download PDF SKTM dari tombol email penerbitan (tanpa login).
// Signed URL relatif (masa berlaku 7 hari) + throttle; validasi dokumen di SktmDownloadController.
Route::get('/sktm/unduh/{application}/{document}', \App\Http\Controllers\SktmDownloadController::class)
    ->middleware(['signed:relative', 'throttle:' . config('sktm.download_throttle_per_minute', 20) . ',1'])
    ->name('sktm.download.signed');

// Admin / Petugas routes
Route::middleware(['auth', 'active.user', 'role'])->prefix('admin')->group(function () {
    Route::get('/dashboard', App\Livewire\AdminDashboard::class)->name('admin.dashboard');

    Route::get('/pengajuan', function () {
        return view('admin.pengajuan.index', ['title' => 'Daftar Pengajuan']);
    })->name('admin.pengajuan');

    Route::get('/pengajuan/{id}', function ($id) {
        return view('admin.pengajuan.show', ['title' => 'Detail Pengajuan - Petugas', 'id' => $id]);
    })->name('admin.pengajuan.show');

    Route::get('/users', App\Livewire\AccountManagement::class)
        ->middleware('role:supervisor')
        ->name('admin.users');

    Route::get('/ai-settings', App\Livewire\Admin\AiSettings::class)
        ->middleware('role:supervisor')
        ->name('admin.ai.settings');

    Route::get('/laporan', App\Livewire\Admin\ReportCenter::class)
        ->middleware('role:supervisor')
        ->name('admin.reports.index');

    Route::get('/laporan/{report}', App\Livewire\Admin\ReportDetail::class)
        ->whereUuid('report')
        ->middleware('role:supervisor')
        ->name('admin.reports.show');
});


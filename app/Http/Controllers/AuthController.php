<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $messages = [
            'name.required' => 'Nama lengkap wajib diisi.',
            'name.string' => 'Nama lengkap harus berupa teks.',
            'name.max' => 'Nama lengkap maksimal 255 karakter.',
            'email.required' => 'Alamat email aktif wajib diisi.',
            'email.email' => 'Format alamat email tidak valid.',
            'email.max' => 'Alamat email maksimal 255 karakter.',
            'email.unique' => 'Alamat email sudah terdaftar dalam sistem.',
            'password.required' => 'Kata sandi wajib diisi.',
            'password.string' => 'Kata sandi harus berupa teks.',
            'password.min' => 'Kata sandi minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
            'gender.required' => 'Jenis kelamin wajib dipilih.',
            'gender.in' => 'Pilihan jenis kelamin tidak valid (pilih Laki-laki atau Perempuan).',
            'phone_number.required' => 'Nomor HP wajib diisi.',
            'phone_number.string' => 'Nomor HP harus berupa teks.',
            'phone_number.min' => 'Nomor HP minimal 10 karakter.',
            'phone_number.max' => 'Nomor HP maksimal 20 karakter.',
            'phone_number.regex' => 'Format nomor HP tidak valid (gunakan awalan 08 atau +62).',
        ];

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'gender' => ['required', 'string', 'in:L,P'],
            'phone_number' => ['required', 'string', 'min:10', 'max:20', 'regex:/^(\+?62|0)[0-9\- ]{8,18}$/'],
        ], $messages);

        if ($validator->fails()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),
                ], 422);
            }

            return back()
                ->withErrors($validator)
                ->withInput($request->only('name', 'email', 'gender', 'phone_number'));
        }

        $validated = $validator->validated();

        $roleMasyarakat = Role::where('name', 'masyarakat')->first();
        if (! $roleMasyarakat) {
            Log::error('Role masyarakat tidak ditemukan saat registrasi user.');
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Konfigurasi role sistem tidak ditemukan.'], 500);
            }
            return back()->withInput($request->only('name', 'email', 'gender', 'phone_number'))
                         ->withErrors(['email' => 'Konfigurasi role sistem tidak ditemukan. Hubungi administrator.']);
        }

        // Sanitize phone number (strip spaces and hyphens)
        $cleanPhone = preg_replace('/[^\d+]/', '', $validated['phone_number']);

        try {
            DB::beginTransaction();

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role_id' => $roleMasyarakat->id, // STRICT: Public registration always creates masyarakat role
                'gender' => $validated['gender'],
                'phone_number' => $cleanPhone,
                'is_active' => true,
            ]);

            $user->profile()->create([
                'gender' => $validated['gender'],
                'phone_number' => $cleanPhone,
            ]);

            try {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'REGISTER',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'details' => 'Citizen self-registration completed (role: masyarakat)',
                ]);
            } catch (\Throwable $e) {
                // Ignore audit log failure
            }

            $token = null;
            if ($request->expectsJson() || $request->is('api/*')) {
                $token = $user->createToken('auth_token')->plainTextToken;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Registrasi user gagal: ' . $e->getMessage(), [
                'exception' => $e,
                'email' => $validated['email'] ?? null,
            ]);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Terjadi kesalahan sistem saat mendaftarkan akun. Silakan coba kembali.',
                ], 500);
            }

            return back()->withInput($request->only('name', 'email', 'gender', 'phone_number'))
                         ->withErrors(['email' => 'Terjadi kesalahan sistem saat mendaftarkan akun. Silakan coba kembali.']);
        }

        // Dispatch standard Laravel email verification event after DB transaction is committed
        event(new Registered($user));

        // Login session web
        auth()->login($user);

        if ($request->expectsJson() || $request->is('api/*')) {
            $isSent = $user->verificationEmailSent !== false;
            return response()->json([
                'message' => $isSent
                    ? 'Registrasi berhasil. Silakan periksa kotak masuk atau spam email Anda untuk verifikasi akun.'
                    : 'Registrasi berhasil, namun email verifikasi gagal terkirim karena kendala koneksi server email (SMTP).',
                'email_sent' => $isSent,
                'access_token' => $token,
                'token_type' => 'Bearer',
                'redirect_url' => route('verification.notice'),
            ], 201);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        if ($user->verificationEmailSent === false) {
            return redirect()->route('verification.notice')->with('warning', 'Pendaftaran akun berhasil, namun email verifikasi gagal terkirim karena kendala koneksi server email (SMTP). Silakan periksa konfigurasi mail server Anda atau klik tombol "Kirim Ulang Tautan Verifikasi".');
        }

        return redirect()->route('verification.notice')->with('status', 'verification-link-sent');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                throw ValidationException::withMessages([
                    'email' => ['Kredensial tidak valid.'],
                ]);
            }
            return back()->withInput($request->only('email', 'remember'))
                         ->withErrors(['email' => 'Kredensial tidak valid.']);
        }

        if (isset($user->is_active) && ! $user->is_active) {
            try {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'LOGIN_FAILED_INACTIVE',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'details' => 'Login attempt on inactive/disabled account'
                ]);
            } catch (\Throwable $e) {
                // Ignore audit log failure
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                throw ValidationException::withMessages([
                    'email' => ['Akun Anda telah dinonaktifkan. Silakan hubungi administrator kantor desa.'],
                ]);
            }
            return back()->withInput($request->only('email', 'remember'))
                         ->withErrors(['email' => 'Akun Anda telah dinonaktifkan. Silakan hubungi administrator kantor desa.']);
        }

        // Login session web with remember option
        auth()->login($user, $request->boolean('remember'));

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        // Log audit
        try {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'LOGIN',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'details' => 'User logged in'
            ]);
        } catch (\Throwable $e) {
            Log::warning('AuditLog LOGIN creation error: ' . $e->getMessage());
        }

        $roleName = $user->role?->name ?? 'masyarakat';
        $redirectUrl = route('admin.dashboard');
        if ($roleName === 'masyarakat') {
            $redirectUrl = $user->hasVerifiedEmail() ? route('dashboard') : route('verification.notice');
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            try {
                $token = $user->createToken('auth_token')->plainTextToken;
                return response()->json([
                    'message' => 'Login berhasil',
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'role' => $roleName,
                    'redirect_url' => $redirectUrl,
                    'email_verified' => $user->hasVerifiedEmail(),
                ]);
            } catch (\Throwable $e) {
                Log::error('Login token creation failed: ' . $e->getMessage(), ['exception' => $e]);
                return response()->json([
                    'message' => 'Gagal membuat sesi token. Silakan coba kembali.',
                ], 500);
            }
        }

        // Web redirect based on role
        if ($roleName === 'masyarakat') {
            if (! $user->hasVerifiedEmail()) {
                return redirect()->route('verification.notice');
            }
            return redirect()->intended(route('dashboard'));
        }

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request)
    {
        $user = $request->user() ?? auth()->user();
        
        if ($user) {
            try {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'LOGOUT',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'details' => 'User logged out'
                ]);

                $user->tokens()->delete();
            } catch (\Throwable $e) {
                Log::warning('Logout token cleanup warning: ' . $e->getMessage());
            }
        }

        auth()->guard('web')->logout();
        
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'Logout berhasil']);
        }

        return redirect()->route('login')->with('success', 'Berhasil keluar dari sistem.');
    }

    public function me(Request $request)
    {
        $user = $request->user() ?? auth()->user();

        if ($user && ! $user->isActiveAccount()) {
            return \App\Http\Middleware\EnsureActiveUser::denyInactiveResponse($request, $user);
        }

        return response()->json($user ? $user->load('role') : null);
    }
}

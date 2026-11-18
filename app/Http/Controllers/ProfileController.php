<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    /**
     * Tampilkan halaman profil pengguna.
     */
    public function show()
    {
        $user = auth()->user()->load(['role', 'profile']);
        return view('profile', [
            'title' => 'Profil Saya',
            'user' => $user,
            'profile' => $user->profile
        ]);
    }

    /**
     * Perbarui data profil pengguna dan data kependudukan.
     */
    public function update(Request $request)
    {
        $user = auth()->user();
        $profileId = $user->profile()->value('id');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'nik' => [
                'nullable',
                'numeric',
                'digits:16',
                Rule::unique('user_profiles', 'nik')->ignore($profileId),
            ],
            'no_kk' => 'nullable|numeric|digits:16',
            'address' => 'nullable|string|max:500',
            'rt' => 'nullable|numeric|max:999',
            'rw' => 'nullable|numeric|max:999',
            'desa' => 'nullable|string|max:100',
            'kecamatan' => 'nullable|string|max:100',
            'kabupaten' => 'nullable|string|max:100',
            'occupation' => 'nullable|string|max:100',
            'penghasilan' => 'nullable|numeric|min:0',
            'place_of_birth' => 'nullable|string|max:100',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:L,P',
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
            'phone_number.max' => 'Nomor telepon maksimal 20 karakter.',
            'nik.numeric' => 'NIK harus berupa angka.',
            'nik.digits' => 'NIK harus berjumlah 16 digit angka.',
            'nik.unique' => 'NIK tersebut sudah terdaftar pada akun pengguna lain.',
            'no_kk.numeric' => 'Nomor KK harus berupa angka.',
            'no_kk.digits' => 'Nomor KK harus berjumlah 16 digit angka.',
            'penghasilan.numeric' => 'Penghasilan harus berupa angka.',
            'penghasilan.min' => 'Penghasilan minimal Rp 0.',
            'date_of_birth.date' => 'Format tanggal lahir tidak valid.',
            'gender.in' => 'Jenis kelamin harus Laki-laki (L) atau Perempuan (P).',
        ]);

        DB::transaction(function () use ($user, $validated, $request): void {
            $userData = [
                'name' => $validated['name'],
            ];

            if (array_key_exists('phone_number', $validated)) {
                $userData['phone_number'] = $validated['phone_number'];
            }

            $user->update($userData);

            $profileData = collect($validated)->except('name')->toArray();

            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                $profileData
            );

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'UPDATE_PROFILE',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'details' => 'User updated profile information'
            ]);
        });

        return back()->with('success', 'Profil dan data kependudukan berhasil diperbarui.');
    }

    /**
     * Ganti kata sandi pengguna.
     */
    public function updatePassword(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'current_password.required' => 'Kata sandi saat ini wajib diisi.',
            'password.required' => 'Kata sandi baru wajib diisi.',
            'password.min' => 'Kata sandi baru minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi baru tidak cocok.',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'Kata sandi saat ini tidak sesuai.']);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'UPDATE_PASSWORD',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => 'User changed account password'
        ]);

        return back()->with('success_password', 'Kata sandi berhasil diubah.');
    }
}

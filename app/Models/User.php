<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasUuids, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'role_id',
        'is_active',
        'gender',
        'phone_number',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    public function systemReports()
    {
        return $this->hasMany(SystemReport::class, 'reporter_id');
    }

    public function assignedSystemReports()
    {
        return $this->hasMany(SystemReport::class, 'assigned_to');
    }

    public function systemReportMessages()
    {
        return $this->hasMany(SystemReportMessage::class, 'sender_id');
    }

    public function systemReportAttachments()
    {
        return $this->hasMany(SystemReportAttachment::class, 'uploaded_by');
    }

    /**
     * Determine whether the account is active and not soft-deleted.
     */
    public function isActiveAccount(): bool
    {
        if (method_exists($this, 'trashed') && $this->trashed()) {
            return false;
        }

        return ! (isset($this->is_active) && ! $this->is_active);
    }

    /**
     * Revoke all active Sanctum access tokens belonging to this account.
     */
    public function revokeAccessTokens(): void
    {
        $this->tokens()->delete();
    }

    /**
     * In-memory status tracking for verification email sending.
     */
    public ?bool $verificationEmailSent = null;
    public ?string $verificationEmailError = null;

    /**
     * Send email verification notification safely without breaking execution on transport failure.
     * Returns true if sent successfully, false otherwise.
     */
    public function sendEmailVerificationNotification(): bool
    {
        try {
            $verificationUrl = app(\App\Services\PublicUrlService::class)->emailVerificationUrl($this);

            \Illuminate\Support\Facades\Mail::to($this->getEmailForVerification())
                ->send(new \App\Mail\VerifyEmailMailable($this, $verificationUrl));

            $this->verificationEmailSent = true;
            $this->verificationEmailError = null;

            return true;
        } catch (\Throwable $e) {
            $this->verificationEmailSent = false;
            $this->verificationEmailError = $e->getMessage();

            \Illuminate\Support\Facades\Log::warning('Gagal mengirim email verifikasi ke ' . $this->email . ': ' . $e->getMessage(), [
                'user_id' => $this->id,
                'email' => $this->email,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

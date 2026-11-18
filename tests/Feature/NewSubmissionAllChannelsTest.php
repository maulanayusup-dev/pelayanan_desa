<?php

namespace Tests\Feature;

use App\Mail\ApplicationConfirmationMailable;
use App\Mail\OfficerNotificationMailable;
use App\Models\Application;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ApplicationStatusChanged;
use App\Services\EmailNotificationService;
use App\Services\NewSubmissionRecipients;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aturan lintas kanal: pengajuan SKTM baru (SUBMITTED) HANYA diterima role STAF
 * pada WhatsApp Fonnte, notifikasi in-app (lonceng / tabel notifications), dan email petugas (Gmail SMTP).
 */
class NewSubmissionAllChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentType $docType;
    protected User $warga;
    protected User $staf;
    protected User $stafKedua;
    protected User $stafNonaktif;
    protected User $supervisor;
    protected User $kades;
    protected User $kepalaDesa;
    protected User $wargaLain;

    // Nomor ternormalisasi (62xxx)
    private const P_WARGA = '6281211112222';
    private const P_STAF = '6281233334444';
    private const P_STAF2 = '6281244445555';
    private const P_STAF_NONAKTIF = '6281200001111';
    private const P_SUPERVISOR = '6281255556666';
    private const P_KADES = '6281277778888';
    private const P_KEPALA_DESA = '6281299990000';
    private const P_WARGA_LAIN = '6281266667777';

    protected function setUp(): void
    {
        parent::setUp();

        $roles = [];
        foreach (['masyarakat', 'staf', 'supervisor', 'kades', 'kepala_desa'] as $name) {
            $roles[$name] = Role::firstOrCreate(['name' => $name]);
        }

        $this->docType = DocumentType::firstOrCreate(['code' => 'SKTM'], ['name' => 'Surat Keterangan Tidak Mampu']);

        $this->warga = $this->user($roles['masyarakat']->id, 'Warga Siti', 'warga@desa.id', '081211112222');
        $this->staf = $this->user($roles['staf']->id, 'Staf Doni', 'staf@desa.id', '081233334444');
        $this->stafKedua = $this->user($roles['staf']->id, 'Staf Rina', 'staf2@desa.id', '+62 812-4444-5555');
        $this->stafNonaktif = $this->user($roles['staf']->id, 'Staf Cuti', 'stafcuti@desa.id', '081200001111', false);
        $this->supervisor = $this->user($roles['supervisor']->id, 'Supervisor Rahmat', 'supervisor@desa.id', '081255556666');
        $this->kades = $this->user($roles['kades']->id, 'Kades Subagyo', 'kades@desa.id', '081277778888');
        $this->kepalaDesa = $this->user($roles['kepala_desa']->id, 'Kepala Desa Lama', 'kepdes@desa.id', '081299990000');
        $this->wargaLain = $this->user($roles['masyarakat']->id, 'Warga Lain', 'wargalain@desa.id', '081266667777');

        // Aktifkan gateway WhatsApp Fonnte dengan kredensial palsu, mode sinkron
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'fake_fonnte_token');
        Config::set('services.fonnte.use_queue', false);

        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => ['fnt_ok']], 200),
            '*' => Http::response([], 200),
        ]);
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        Cache::flush();
        EmailNotificationService::resetDeduplication();
    }

    private function user(string $roleId, string $name, string $email, string $phone, bool $active = true): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'role_id' => $roleId,
            'phone_number' => $phone,
            'is_active' => $active,
            'email_verified_at' => now(),
        ]);
    }

    private function application(string $status = 'SUBMITTED'): Application
    {
        return Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-ALL-' . strtoupper(Str::random(6)),
            'user_id' => $this->warga->id,
            'document_type_id' => $this->docType->id,
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }

    private function fonnteTargets(): array
    {
        return $this->targets('api.fonnte.com', 'target');
    }

    private function targets(string $host, string $field): array
    {
        $t = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), $host))
            ->map(fn ($pair) => $pair[0][$field])
            ->values()
            ->all();
        sort($t);

        return $t;
    }

    /** User internal yang menerima notifikasi petugas (in-app) untuk aplikasi ini. */
    private function internalInAppRecipients(Application $app): array
    {
        $all = [$this->staf, $this->stafKedua, $this->stafNonaktif, $this->supervisor, $this->kades, $this->kepalaDesa, $this->wargaLain, $this->warga];
        $ids = [];
        foreach ($all as $u) {
            $sent = Notification::sent($u, ApplicationStatusChanged::class)
                ->filter(fn ($n) => $n->application->id === $app->id && $n->actionUrl === '/admin/pengajuan/' . $app->id);
            if ($sent->isNotEmpty()) {
                $ids[] = $u->email;
            }
        }
        sort($ids);

        return $ids;
    }

    private function officerEmails(): array
    {
        $to = Mail::sent(OfficerNotificationMailable::class)
            ->flatMap(fn ($m) => collect($m->to)->pluck('address'))
            ->all();
        sort($to);

        return $to;
    }

    private function assertOnlyActiveStaffOnAllChannels(Application $app): void
    {
        $staffEmails = ['staf2@desa.id', 'staf@desa.id'];
        $staffPhones = [self::P_STAF, self::P_STAF2];

        // 1. WhatsApp Fonnte
        $this->assertSame($staffPhones, $this->fonnteTargets(), 'Fonnte');

        // 3. Notifikasi internal aplikasi (channel database)
        $this->assertSame($staffEmails, $this->internalInAppRecipients($app), 'Notifikasi internal');

        // 3b. Notifikasi in-app hanya lewat channel database (lonceng); non-staf tidak menerima notifikasi internal sama sekali
        foreach ([$this->staf, $this->stafKedua] as $s) {
            Notification::assertSentTo($s, ApplicationStatusChanged::class, function ($n, $channels) use ($app) {
                return $n->application->id === $app->id
                    && $channels === ['database'];
            });
        }
        foreach ([$this->supervisor, $this->kades, $this->kepalaDesa, $this->stafNonaktif, $this->wargaLain] as $u) {
            Notification::assertNotSentTo($u, ApplicationStatusChanged::class);
        }

        // 5. Email petugas
        $this->assertSame($staffEmails, $this->officerEmails(), 'Email petugas');
    }

    public function test_livewire_submission_reaches_only_staff_on_every_channel(): void
    {
        $this->actingAs($this->warga);

        Livewire::test(\App\Livewire\SktmForm::class)
            ->set('nik', '3201123456789012')->set('no_kk', '3201123456789013')
            ->set('name', 'Warga Siti')->set('place_of_birth', 'Cirebon')->set('date_of_birth', '1995-05-15')
            ->set('gender', 'P')->set('address', 'Jl. Melati')->set('rt', '01')->set('rw', '02')
            ->set('desa', 'Sukamaju')->set('kecamatan', 'Sukarame')->set('kabupaten', 'Cirebon')
            ->set('occupation', 'Buruh')->set('penghasilan', '1500000')
            ->call('nextStep')
            ->set('purpose', 'Beasiswa')
            ->call('nextStep')
            ->set('ktp', UploadedFile::fake()->create('ktp.jpg', 100, 'image/jpeg'))
            ->set('kk', UploadedFile::fake()->create('kk.jpg', 100, 'image/jpeg'))
            ->call('submit')
            ->assertRedirect('/pengajuan');

        $app = Application::where('user_id', $this->warga->id)->firstOrFail();
        $this->assertSame('SUBMITTED', $app->status);

        $this->assertOnlyActiveStaffOnAllChannels($app);
    }

    public function test_applicant_gets_only_citizen_notifications_never_internal_admin_ones(): void
    {
        $app = $this->application();
        NotificationService::notifyStatusChange($app);

        // Warga: tepat 1 notifikasi in-app versi warga (URL portal warga), bukan versi petugas
        Notification::assertSentToTimes($this->warga, ApplicationStatusChanged::class, 1);
        Notification::assertSentTo($this->warga, ApplicationStatusChanged::class, fn ($n) => $n->actionUrl === '/pengajuan/' . $app->id);

        // Warga: email konfirmasi warga, bukan email petugas; nomor warga tidak di WhatsApp internal
        Mail::assertSent(ApplicationConfirmationMailable::class, fn ($m) => $m->hasTo('warga@desa.id'));
        Mail::assertNotSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo('warga@desa.id'));
        $this->assertNotContains(self::P_WARGA, $this->fonnteTargets());

        $this->assertOnlyActiveStaffOnAllChannels($app);
    }

    public function test_repeated_trigger_does_not_duplicate_any_channel(): void
    {
        $app = $this->application();

        NotificationService::notifyStatusChange($app);
        EmailNotificationService::resetDeduplication(); // simulasi request/proses berbeda
        NotificationService::notifyStatusChange($app);

        $this->assertOnlyActiveStaffOnAllChannels($app);
        Notification::assertSentToTimes($this->staf, ApplicationStatusChanged::class, 1);
        Notification::assertSentToTimes($this->stafKedua, ApplicationStatusChanged::class, 1);
        Mail::assertSent(OfficerNotificationMailable::class, 2); // 1 per staf
    }

    public function test_resubmission_after_revision_notifies_staff_only_again(): void
    {
        $app = $this->application();
        NotificationService::notifyStatusChange($app);

        // Warga mengirim perbaikan: NEEDS_REVISION -> SUBMITTED dengan submitted_at baru
        $app->update(['status' => 'SUBMITTED', 'submitted_at' => now()->addHour()]);
        EmailNotificationService::resetDeduplication();
        NotificationService::notifyStatusChange($app->fresh());

        Notification::assertSentToTimes($this->staf, ApplicationStatusChanged::class, 2);
        Notification::assertNotSentTo($this->supervisor, ApplicationStatusChanged::class);
        Notification::assertNotSentTo($this->kades, ApplicationStatusChanged::class);
        Mail::assertNotSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo('supervisor@desa.id') || $m->hasTo('kades@desa.id'));
        $this->assertEmpty(array_intersect([self::P_SUPERVISOR, self::P_KADES, self::P_KEPALA_DESA], $this->fonnteTargets()));
    }

    public function test_staff_number_shared_with_supervisor_or_kades_is_skipped_on_whatsapp(): void
    {
        $this->stafKedua->update(['phone_number' => '081255556666']); // sama dengan nomor Supervisor

        NotificationService::notifyStatusChange($this->application());

        $this->assertSame([self::P_STAF], $this->fonnteTargets());
    }

    public function test_shared_rule_source_matches_requirement(): void
    {
        $this->assertSame(['staf'], NewSubmissionRecipients::ROLES);
        $this->assertSame(['supervisor', 'kades', 'kepala_desa'], NewSubmissionRecipients::EXCLUDED_ROLES);

        $ids = NewSubmissionRecipients::staff($this->application())->pluck('id')->sort()->values()->all();
        $expected = collect([$this->staf->id, $this->stafKedua->id])->sort()->values()->all();
        $this->assertSame($expected, $ids);
    }

    public function test_other_workflow_events_keep_their_existing_recipients(): void
    {
        // SUPERVISOR_REVIEW (status historis) tidak lagi mengirim notifikasi aktif ke Supervisor (in-app / email / Fonnte)
        $review = $this->application('SUPERVISOR_REVIEW');
        NotificationService::notifyStatusChange($review);

        Notification::assertNotSentTo($this->supervisor, ApplicationStatusChanged::class);
        Mail::assertNotSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo('supervisor@desa.id'));
        $this->assertNotContains(self::P_SUPERVISOR, $this->fonnteTargets());

        // HEAD_APPROVAL tetap ke Kades / Kepala Desa
        $head = $this->application('HEAD_APPROVAL');
        NotificationService::notifyStatusChange($head);
        Notification::assertSentTo($this->kades, ApplicationStatusChanged::class, fn ($n) => $n->application->id === $head->id);
        Notification::assertSentTo($this->kepalaDesa, ApplicationStatusChanged::class, fn ($n) => $n->application->id === $head->id);
        Mail::assertSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo('kades@desa.id'));

        // APPROVED tidak mengirim Fonnte COMPLETED sebelum PDF terbit; COMPLETED & REJECTED mengirim Fonnte ke staf
        $approved = $this->application('APPROVED');
        NotificationService::notifyStatusChange($approved);
        Notification::assertSentTo($this->warga, ApplicationStatusChanged::class, fn ($n) => $n->application->id === $approved->id);
        $this->assertNotContains(self::P_STAF, $this->fonnteTargets());

        foreach (['COMPLETED', 'REJECTED'] as $status) {
            $app = $this->application($status);
            NotificationService::notifyStatusChange($app);
            Notification::assertSentTo($this->warga, ApplicationStatusChanged::class, fn ($n) => $n->application->id === $app->id);
            Notification::assertNotSentTo($this->supervisor, ApplicationStatusChanged::class, fn ($n) => $n->application->id === $app->id);
        }
        $this->assertContains(self::P_STAF, $this->fonnteTargets());
    }
}

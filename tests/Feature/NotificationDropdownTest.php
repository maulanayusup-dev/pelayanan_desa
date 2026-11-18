<?php

namespace Tests\Feature;

use App\Livewire\NotificationDropdown;
use App\Models\Application;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ApplicationStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationDropdownTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Application $application;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'masyarakat']);
        $this->user = User::factory()->create([
            'role_id' => $role->id,
            'name' => 'Maulana Yusup',
            'email' => 'maulana@desa.id',
        ]);

        $docType = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            ['name' => 'Surat Keterangan Tidak Mampu', 'description' => 'SKTM']
        );

        $this->application = Application::create([
            'application_number' => 'SKTM-2026-TEST',
            'user_id' => $this->user->id,
            'document_type_id' => $docType->id,
            'status' => 'SUBMITTED',
            'purpose' => 'Keperluan Pendidikan',
        ]);
    }

    /** Test component renders and counts unread notifications */
    public function test_notification_dropdown_renders_and_counts_notifications(): void
    {
        $this->actingAs($this->user);

        // Send two notifications
        $this->user->notify(new ApplicationStatusChanged($this->application, 'Pemberitahuan pertama', 'info'));
        $this->user->notify(new ApplicationStatusChanged($this->application, 'Pemberitahuan kedua', 'success'));

        $this->assertEquals(2, $this->user->unreadNotifications()->count());

        Livewire::test(NotificationDropdown::class)
            ->assertSet('unreadCount', 2)
            ->assertSee('2 Baru')
            ->assertSee('Pemberitahuan pertama')
            ->assertSee('Pemberitahuan kedua')
            ->assertDontSee('setTimeout');
    }

    /** Test markAsRead marks single notification as read */
    public function test_mark_as_read_marks_notification_as_read(): void
    {
        $this->actingAs($this->user);

        $this->user->notify(new ApplicationStatusChanged($this->application, 'Pemberitahuan test', 'info'));
        $notification = $this->user->unreadNotifications()->first();

        $this->assertNotNull($notification);

        Livewire::test(NotificationDropdown::class)
            ->call('markAsRead', $notification->id)
            ->assertSet('unreadCount', 0);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    /** Test markAsReadAndRedirect marks notification as read and redirects */
    public function test_mark_as_read_and_redirect_redirects_to_action_url(): void
    {
        $this->actingAs($this->user);

        $this->user->notify(new ApplicationStatusChanged($this->application, 'Pengajuan disetujui', 'success'));
        $notification = $this->user->unreadNotifications()->first();

        $expectedUrl = '/pengajuan/' . $this->application->id;

        Livewire::test(NotificationDropdown::class)
            ->call('markAsReadAndRedirect', $notification->id)
            ->assertRedirect($expectedUrl);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    /** Test markAllAsRead marks all as read */
    public function test_mark_all_as_read_marks_all_notifications_as_read(): void
    {
        $this->actingAs($this->user);

        $this->user->notify(new ApplicationStatusChanged($this->application, 'Pesan 1', 'info'));
        $this->user->notify(new ApplicationStatusChanged($this->application, 'Pesan 2', 'info'));

        $this->assertEquals(2, $this->user->unreadNotifications()->count());

        Livewire::test(NotificationDropdown::class)
            ->call('markAllAsRead')
            ->assertSet('unreadCount', 0);

        $this->assertEquals(0, $this->user->unreadNotifications()->count());
    }

    /** Test that clicking notification in blade does not call setTimeout */
    public function test_dropdown_rendered_html_does_not_contain_wire_click_set_timeout(): void
    {
        $this->actingAs($this->user);
        $this->user->notify(new ApplicationStatusChanged($this->application, 'Pesan uji', 'info'));

        $component = Livewire::test(NotificationDropdown::class);
        $html = $component->html();

        // Must not contain setTimeout
        $this->assertStringNotContainsString('setTimeout', $html);
        $this->assertStringContainsString('markAsReadAndRedirect', $html);
    }
}

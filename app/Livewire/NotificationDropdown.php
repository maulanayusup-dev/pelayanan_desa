<?php

namespace App\Livewire;

use Livewire\Component;

class NotificationDropdown extends Component
{
    public $unreadCount = 0;
    public $notifications = [];

    public function mount()
    {
        $this->loadNotifications();
    }

    public function loadNotifications()
    {
        $user = auth()->user();
        if ($user) {
            $this->unreadCount = $user->unreadNotifications()->count();
            $this->notifications = $user->notifications()->take(8)->get();
        }
    }

    public function markAsRead($id)
    {
        $user = auth()->user();
        if (!$user) {
            return;
        }

        $notification = $user->notifications()->where('id', $id)->first();
        if ($notification) {
            $notification->markAsRead();
            $this->loadNotifications();
        }
    }

    public function markAsReadAndRedirect($id)
    {
        $user = auth()->user();
        if (!$user) {
            return;
        }

        $notification = $user->notifications()->where('id', $id)->first();
        $targetUrl = null;

        if ($notification) {
            $notification->markAsRead();
            $targetUrl = $notification->data['action_url'] ?? null;
            if (!$targetUrl && !empty($notification->data['application_id'])) {
                $roleName = $user->role->name ?? 'masyarakat';
                if (in_array($roleName, ['staf', 'supervisor', 'kades', 'kepala_desa'])) {
                    $targetUrl = '/admin/pengajuan/' . $notification->data['application_id'];
                } else {
                    $targetUrl = '/pengajuan/' . $notification->data['application_id'];
                }
            }
        }

        $this->loadNotifications();

        if ($targetUrl && $targetUrl !== '#') {
            return redirect()->to($targetUrl);
        }
    }

    public function markAllAsRead()
    {
        $user = auth()->user();
        if ($user) {
            $user->unreadNotifications()->update(['read_at' => now()]);
            $this->loadNotifications();
        }
    }

    public function render()
    {
        return view('livewire.notification-dropdown');
    }
}

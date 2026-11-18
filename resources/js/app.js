import './bootstrap';

// Browser push / desktop notification sudah dihapus dari aplikasi.
// Cabut service worker lama (pernah didaftarkan untuk Web Push) tanpa meminta izin apa pun.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.getRegistrations()
            .then((registrations) => registrations.forEach((registration) => registration.unregister()))
            .catch(() => {});
    });
}

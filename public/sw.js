/*
 * Service worker pembersih.
 * Browser push / desktop notification Pelayanan Desa sudah dihentikan. Browser yang dulu
 * berlangganan masih menyimpan service worker lama; versi ini mencabut langganan push
 * lalu menghapus dirinya sendiri. Tidak ada listener 'push' sehingga tidak ada notifikasi desktop.
 */
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        try {
            const subscription = await self.registration.pushManager.getSubscription();
            if (subscription) {
                await subscription.unsubscribe();
            }
        } catch (e) {
            // abaikan
        }
        await self.registration.unregister();
    })());
});

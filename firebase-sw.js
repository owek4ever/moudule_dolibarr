// firebase-sw.js
importScripts('https://www.gstatic.com/firebasejs/9.23.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/9.23.0/firebase-messaging-compat.js');

firebase.initializeApp({
    apiKey:            "AIzaSyCxUJHdA0_jMxlut9lQxE69Nit91lwwJDw",
    authDomain:        "dolibarr-flotte.firebaseapp.com",
    projectId:         "dolibarr-flotte",
    storageBucket:     "dolibarr-flotte.firebasestorage.app",
    messagingSenderId: "262203283893",
    appId:             "1:262203283893:web:11766cd9f79e099edca1fc"
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage(function(payload) {
    const notificationTitle = payload.notification?.title || 'Flotte Alert';
    const notificationOptions = {
        body:  payload.notification?.body || '',
        icon:  '/flotte/img/flotte_icon.png',
        badge: '/flotte/img/flotte_badge.png',
        data:  payload.data || {},
        requireInteraction: (payload.data?.priority >= 3),
    };
    return self.registration.showNotification(notificationTitle, notificationOptions);
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    if (event.action === 'dismiss') return;

    const data = event.notification.data || {};
    let url = '/flotte/notification_center.php';
    if (data.vehicle_id) url = '/flotte/vehicle_card.php?id=' + data.vehicle_id;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
            for (const client of clientList) {
                if ('focus' in client) { client.navigate(url); return client.focus(); }
            }
            if (clients.openWindow) return clients.openWindow(url);
        })
    );
});

self.addEventListener('install',  () => self.skipWaiting());
self.addEventListener('activate', () => self.clients.claim());
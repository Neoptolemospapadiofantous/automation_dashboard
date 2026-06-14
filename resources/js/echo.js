import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/**
 * Laravel Echo bootstrap — self-hosted Laravel Reverb (Pusher-protocol, so
 * pusher-js is still the client transport).
 *
 * Echo is only initialised when a key is present, so the app boots cleanly
 * where real-time isn't configured (CI, or before the Reverb server is
 * provisioned): useEcho() reports connected:false and the UI shows "Offline".
 */
const key = import.meta.env.VITE_REVERB_APP_KEY;

let echo = null;

if (key) {
    echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

window.Echo = echo;

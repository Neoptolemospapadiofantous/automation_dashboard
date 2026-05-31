import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/**
 * Laravel Echo bootstrap.
 *
 * We use the Pusher protocol, which is spoken by BOTH the managed Pusher
 * cloud and the self-hosted Laravel Reverb server. That means switching from
 * Pusher to Reverb later is purely an env change here — no frontend rewrite.
 *
 * Echo is only initialised when a key is actually present, so the app boots
 * cleanly in environments without real-time credentials (e.g. local/CI).
 */
const key = import.meta.env.VITE_PUSHER_APP_KEY;

let echo = null;

if (key) {
    echo = new Echo({
        broadcaster: 'pusher',
        key,
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
        wsHost: import.meta.env.VITE_PUSHER_HOST || undefined,
        wsPort: import.meta.env.VITE_PUSHER_PORT ?? 80,
        wssPort: import.meta.env.VITE_PUSHER_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_PUSHER_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

window.Echo = echo;

export default echo;

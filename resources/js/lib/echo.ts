// Laravel Echo singleton wired to the self-hosted Reverb server.
// Config comes from the Vite-exposed REVERB env (see .env / quickstart.md).
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo?: Echo<'reverb'>;
    }
}

let echo: Echo<'reverb'> | null = null;

/** Lazily create (once) and return the Echo client. */
export function getEcho(): Echo<'reverb'> {
    if (echo) {
        return echo;
    }

    window.Pusher = Pusher;

    echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    window.Echo = echo;

    return echo;
}

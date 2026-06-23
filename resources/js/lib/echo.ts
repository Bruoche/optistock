// Laravel Echo singleton wired to the self-hosted Reverb server.
// Only the public app key is build-time config (VITE_REVERB_APP_KEY). The
// websocket endpoint prefers explicit VITE_REVERB_* (local dev, where Reverb
// runs on its own port) and otherwise falls back to same-origin derived from
// window.location — so the container image carries no per-environment host and
// nginx proxies the websocket on the same origin (see plan D3 / quickstart.md).
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

    const scheme =
        import.meta.env.VITE_REVERB_SCHEME ??
        (window.location.protocol === 'https:' ? 'https' : 'http');
    const host = import.meta.env.VITE_REVERB_HOST ?? window.location.hostname;
    const port = import.meta.env.VITE_REVERB_PORT
        ? Number(import.meta.env.VITE_REVERB_PORT)
        : window.location.port
          ? Number(window.location.port)
          : scheme === 'https'
            ? 443
            : 80;

    echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    window.Echo = echo;

    return echo;
}

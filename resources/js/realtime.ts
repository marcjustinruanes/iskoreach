import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

export function startRealtime() {
    const key = import.meta.env.VITE_REVERB_APP_KEY;
    if (!key) throw new Error('Set VITE_REVERB_APP_KEY before enabling realtime.');

    return new Echo({
        broadcaster: 'reverb',
        client: new Pusher(key, {
            channelAuthorization: {
                endpoint: '/broadcasting/auth',
                transport: 'ajax',
                headersProvider: () => {
                    const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
                    return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {};
                },
            },
            wsHost: import.meta.env.VITE_REVERB_HOST || 'localhost',
            wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8080),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT || 8080),
            forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
            enabledTransports: ['ws', 'wss'],
            cluster: 'mt1',
        }),
    });
}

import { onBeforeUnmount } from 'vue';

/**
 * Subscribe to a Laravel Echo channel for the lifetime of a component.
 *
 * Usage:
 *   const { connected } = useEcho('some-channel', '.event.name', (payload) => { ... });
 *
 * Returns whether a live connection is actually available (Echo is only
 * initialised when real-time credentials are present — see echo.js), so the
 * UI can show an "offline / demo" state instead of silently doing nothing.
 */
export function useEcho(channelName, eventName, callback, { presence = false } = {}) {
    const echo = window.Echo ?? null;

    if (!echo) {
        return { connected: false, channel: null };
    }

    const channel = presence
        ? echo.join(channelName)
        : echo.channel(channelName);

    channel.listen(eventName, callback);

    onBeforeUnmount(() => {
        channel.stopListening(eventName, callback);
        echo.leave(channelName);
    });

    return { connected: true, channel };
}

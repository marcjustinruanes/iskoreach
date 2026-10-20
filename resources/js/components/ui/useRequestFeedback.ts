import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export function useRequestFeedback(cancel: () => void) {
    const pending = useRef(false);
    const [error, setError] = useState('');
    useEffect(() => {
        const exception = router.on('exception', event => {
            if (!pending.current) return;
            event.preventDefault(); pending.current = false; cancel();
            setError('The result could not be confirmed. Check your connection and reload this page before trying again.');
        });
        const invalid = router.on('invalid', event => {
            if (!pending.current) return;
            event.preventDefault(); pending.current = false; cancel();
            setError(event.detail.response.status === 419 ? 'Your session expired. Reload the page before continuing.' : 'The result could not be confirmed. Reload this page before trying again.');
        });
        return () => { exception(); invalid(); };
    }, [cancel]);
    return {
        error,
        begin: () => {
            if (pending.current) return false;
            setError('');
            if (!navigator.onLine) { setError('You are offline. Reconnect before submitting.'); return false; }
            pending.current = true;
            return true;
        },
        finish: () => { pending.current = false; },
    };
}

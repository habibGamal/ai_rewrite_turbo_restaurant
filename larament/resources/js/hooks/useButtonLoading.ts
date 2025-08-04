import { useState, useCallback } from 'react';
import { router } from '@inertiajs/react';

/**
 * Custom hook for managing button loading states
 * Provides utilities for handling loading states for buttons that make backend requests
 */
export const useButtonLoading = () => {
    const [loadingStates, setLoadingStates] = useState<Record<string, boolean>>({});

    const setLoading = useCallback((buttonId: string, loading: boolean) => {
        setLoadingStates(prev => ({
            ...prev,
            [buttonId]: loading
        }));
    }, []);

    const isLoading = useCallback((buttonId: string) => {
        return loadingStates[buttonId] || false;
    }, [loadingStates]);

    const withLoading = useCallback(<T extends any[]>(
        buttonId: string,
        fn: (...args: T) => void | Promise<void>
    ) => {
        return async (...args: T) => {
            setLoading(buttonId, true);
            try {
                await fn(...args);
            } finally {
                setLoading(buttonId, false);
            }
        };
    }, [setLoading]);

    const makeInertiaRequest = useCallback((
        buttonId: string,
        method: 'get' | 'post' | 'put' | 'patch' | 'delete',
        url: string,
        data?: any,
        options?: any
    ) => {
        setLoading(buttonId, true);
        
        const requestOptions = {
            ...options,
            onFinish: () => {
                setLoading(buttonId, false);
                options?.onFinish?.();
            }
        };

        return router[method](url, data, requestOptions);
    }, [setLoading]);

    return {
        setLoading,
        isLoading,
        withLoading,
        makeInertiaRequest
    };
};

export default useButtonLoading;

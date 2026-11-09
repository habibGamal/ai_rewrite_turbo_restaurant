import { useCallback, useEffect, useRef, type MutableRefObject } from 'react';
import { router } from '@inertiajs/react';

const UNSAVED_CONFIRM_MESSAGE =
    'لديك تغييرات غير محفوظة. هل تريد المغادرة دون حفظ التغييرات؟';

export interface UseUnsavedGuardResult {
    isSavingRef: MutableRefObject<boolean>;
    confirmLeaveIfDirty: () => boolean;
}

export function useUnsavedGuard(isDirty: boolean): UseUnsavedGuardResult {
    const isSavingRef = useRef<boolean>(false);
    const isDirtyRef = useRef<boolean>(isDirty);
    isDirtyRef.current = isDirty;

    useEffect(() => {
        if (!isDirty) {
            return;
        }

        const handleBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', handleBeforeUnload);
        return () => {
            window.removeEventListener('beforeunload', handleBeforeUnload);
        };
    }, [isDirty]);

    useEffect(() => {
        const removeListener = router.on('before', () => {
            if (isDirtyRef.current && !isSavingRef.current) {
                return window.confirm(UNSAVED_CONFIRM_MESSAGE);
            }
            return true;
        });

        return () => {
            removeListener();
        };
    }, []);

    const confirmLeaveIfDirty = useCallback((): boolean => {
        if (isDirtyRef.current && !window.confirm(UNSAVED_CONFIRM_MESSAGE)) {
            return false;
        }
        return true;
    }, []);

    return {
        isSavingRef,
        confirmLeaveIfDirty,
    };
}

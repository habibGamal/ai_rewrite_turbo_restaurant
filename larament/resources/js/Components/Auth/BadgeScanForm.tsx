import React, { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Alert, Button, Input, InputRef } from 'antd';

interface BadgeScanFormProps {
    /** Route the scanned badge is posted to. */
    action: string;
    submitLabel: string;
    onDone?: () => void;
}

/**
 * Scan field for a badge. A barcode scanner types the code and presses
 * Enter. Managers are then asked for their 4-digit PIN.
 */
export default function BadgeScanForm({ action, submitLabel, onDone }: BadgeScanFormProps) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [badge, setBadge] = useState('');
    const [pin, setPin] = useState('');
    const [needsPin, setNeedsPin] = useState(false);
    const [processing, setProcessing] = useState(false);
    const badgeRef = useRef<InputRef>(null);
    const pinRef = useRef<InputRef>(null);

    useEffect(() => {
        badgeRef.current?.focus();
    }, []);

    useEffect(() => {
        if (needsPin) pinRef.current?.focus();
    }, [needsPin]);

    const submit = () => {
        if (badge.trim() === '') return;
        setProcessing(true);
        router.post(
            action,
            { badge: badge.trim(), pin: needsPin ? pin : null },
            {
                preserveState: true,
                onError: (errs) => {
                    if (errs.pin) {
                        setNeedsPin(true);
                        setPin('');
                    } else {
                        setBadge('');
                        setNeedsPin(false);
                        badgeRef.current?.focus();
                    }
                },
                onSuccess: () => onDone?.(),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                submit();
            }}
            className="flex flex-col gap-3"
        >
            <Input.Password
                ref={badgeRef}
                size="large"
                placeholder="اعمل سكان للبادج"
                value={badge}
                visibilityToggle={false}
                autoComplete="off"
                disabled={needsPin}
                onChange={(e) => setBadge(e.target.value)}
            />
            {needsPin && (
                <Input.Password
                    ref={pinRef}
                    size="large"
                    placeholder="الرقم السري (٤ أرقام)"
                    maxLength={4}
                    inputMode="numeric"
                    value={pin}
                    autoComplete="off"
                    onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))}
                />
            )}
            {(errors.badge || errors.pin) && <Alert type="error" showIcon message={errors.badge ?? errors.pin} />}
            <Button type="primary" htmlType="submit" size="large" loading={processing}>
                {submitLabel}
            </Button>
            {needsPin && (
                <Button
                    type="link"
                    onClick={() => {
                        setNeedsPin(false);
                        setBadge('');
                        setPin('');
                        setTimeout(() => badgeRef.current?.focus());
                    }}
                >
                    بادج تاني
                </Button>
            )}
        </form>
    );
}

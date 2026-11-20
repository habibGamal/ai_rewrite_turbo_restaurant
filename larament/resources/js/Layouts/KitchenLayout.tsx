import { kdsTokens } from '@/Components/Kitchen/kdsTokens';
import { App as AntApp, ConfigProvider, theme } from 'antd';
import arEG from 'antd/locale/ar_EG';
import { usePage } from '@inertiajs/react';
import { type ReactNode, useLayoutEffect, useRef } from 'react';

interface KitchenLayoutProps {
    children: ReactNode;
}

export default function KitchenLayout({ children }: KitchenLayoutProps) {
    return (
        <ConfigProvider
            locale={arEG}
            direction="rtl"
            theme={{
                algorithm: theme.darkAlgorithm,
                token: {
                    colorPrimary: kdsTokens.done,
                    colorError: kdsTokens.danger,
                    colorBgElevated: kdsTokens.card,
                    fontSize: 16,
                    borderRadius: 12,
                    colorBgBase: kdsTokens.page,
                },
            }}
        >
            <AntApp>
                <div
                    dir="rtl"
                    className="flex h-dvh flex-col overflow-hidden"
                    style={{ background: kdsTokens.page, color: kdsTokens.text }}
                >
                    <KitchenErrorToaster />
                    {children}
                </div>
            </AntApp>
        </ConfigProvider>
    );
}

function KitchenErrorToaster() {
    const page = usePage();
    const { message } = AntApp.useApp();
    const messageRef = useRef(message);
    messageRef.current = message;
    const errorText = Object.entries(page.props.errors)
        .filter(([key]) => key !== 'kitchen')
        .map(([, value]) => String(value))
        .join('\n');

    useLayoutEffect(() => {
        if (!errorText) {
            return;
        }
        messageRef.current.destroy();
        errorText.split('\n').forEach((error) => {
            if (error) {
                messageRef.current.error(error);
            }
        });
    }, [errorText]);

    return null;
}

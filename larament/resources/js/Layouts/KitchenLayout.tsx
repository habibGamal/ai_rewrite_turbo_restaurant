import { router, usePage } from '@inertiajs/react';
import { LogoutOutlined, UserOutlined } from '@ant-design/icons';
import { App as AntApp, Button, ConfigProvider, theme } from 'antd';
import arEG from 'antd/locale/ar_EG';
import { ReactNode, useLayoutEffect, useRef } from 'react';

interface KitchenLayoutProps {
    children: ReactNode;
}

export default function KitchenLayout({ children }: KitchenLayoutProps) {
    const { auth } = usePage().props;
    const user = auth.user;

    const logout = () => {
        router.post('/logout', undefined, {
            onFinish: () => {
                window.location.href = '/login';
            },
        });
    };

    return (
        <ConfigProvider
            locale={arEG}
            direction="rtl"
            theme={{
                algorithm: theme.darkAlgorithm,
                token: {
                    colorPrimary: '#7E57C2',
                    colorError: '#ff4d4f',
                    fontSize: 18,
                    borderRadius: 10,
                    colorBgBase: '#0b0e14',
                },
            }}
        >
            <AntApp>
            <div dir="rtl" className="flex h-dvh flex-col overflow-hidden bg-[#0b0e14] text-white">
                <header className="flex h-14 shrink-0 items-center justify-between border-b border-white/10 px-4">
                    <span className="text-base font-bold text-zinc-300">شاشة المطبخ</span>
                    <div className="flex items-center gap-3">
                        <span className="flex items-center gap-1 text-base font-bold">
                            <UserOutlined />
                            {user?.name}
                        </span>
                        <Button danger icon={<LogoutOutlined />} onClick={logout} style={{ height: 48 }}>
                            خروج
                        </Button>
                    </div>
                </header>
                <div className="flex min-h-0 flex-1 flex-col">
                    <KitchenErrorToaster />
                    {children}
                </div>
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
    const errorText = Object.values(page.props.errors).join('\n');

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

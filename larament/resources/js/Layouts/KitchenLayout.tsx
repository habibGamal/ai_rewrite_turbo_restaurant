import { User } from '@/types';
import { LogoutOutlined, ReloadOutlined, UserOutlined } from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import { Button, ConfigProvider, Descriptions, FloatButton, Layout, Popconfirm, Typography, message } from 'antd';
import { ReactNode, useCallback, useEffect, useState, useLayoutEffect } from 'react';

const { Content } = Layout;
const { Title, Text } = Typography;

interface KitchenLayoutProps {
    children: ReactNode;
}

const POLL_INTERVAL = 10_000; // 10 seconds

export default function KitchenLayout({ children }: KitchenLayoutProps) {
    const { auth } = usePage().props;
    const user = auth.user as User;
    const [lastUpdated, setLastUpdated] = useState<Date>(new Date());
    const [isRefreshing, setIsRefreshing] = useState(false);

    const logout = () => {
        router.post('/logout', undefined, {
            onFinish: () => {
                window.location.href = '/login';
            },
        });
    };

    const refreshData = useCallback(() => {
        setIsRefreshing(true);
        router.reload({
            onFinish: () => {
                setLastUpdated(new Date());
                setIsRefreshing(false);
            },
        });
    }, []);

    // Auto-polling every 10 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            refreshData();
        }, POLL_INTERVAL);

        return () => clearInterval(interval);
    }, [refreshData]);

    // Handle errors from Inertia
    const page = usePage();
    useLayoutEffect(() => {
        if (Object.values(page.props.errors).length > 0) {
            message.destroy();
            Object.values(page.props.errors).forEach((error) => {
                message.error(error);
            });
        }
    }, [page]);

    return (
        <Layout className="min-h-screen" dir="rtl">
            <FloatButton.Group shape="circle" style={{ left: 24 }}>
                <FloatButton
                    tooltip="تحديث"
                    icon={<ReloadOutlined spin={isRefreshing} />}
                    onClick={refreshData}
                />
                <FloatButton
                    tooltip="تسجيل الخروج"
                    icon={<LogoutOutlined />}
                    onClick={logout}
                />
            </FloatButton.Group>

            <Content className="bg-gray-50">
                <ConfigProvider
                    direction="rtl"
                    theme={{
                        token: {
                            colorPrimary: "#7E57C2",
                            colorError: "#cf6679",
                            fontSize: 18,
                        },
                    }}
                >
                    <div className="p-4">
                        <div className="flex justify-between items-center mb-4">
                            <Descriptions className='w-fit'>
                                <Descriptions.Item label="شاشة المطبخ">
                                    <Title level={4} className="!mb-0">
                                         طلبات المطبخ
                                    </Title>
                                </Descriptions.Item>
                            </Descriptions>

                            <div className="flex  items-center gap-4">
                                <Text className="text-gray-500 text-sm">
                                    آخر تحديث: {lastUpdated.toLocaleTimeString('ar-EG')}
                                </Text>
                                <Text className="flex items-center gap-1">
                                    <UserOutlined />
                                    {user?.name}
                                </Text>
                            </div>
                        </div>

                        {children}
                    </div>
                </ConfigProvider>
            </Content>
        </Layout>
    );
}

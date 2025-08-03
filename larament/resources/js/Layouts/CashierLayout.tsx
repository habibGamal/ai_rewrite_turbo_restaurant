import React, { ReactNode } from 'react';
import { Layout, Menu, Button, Typography, Space } from 'antd';
import { router, usePage } from '@inertiajs/react';
import {
    LogoutOutlined,
    ShoppingCartOutlined,
    UserOutlined,
} from '@ant-design/icons';
import { User } from '@/types';

const { Header, Content } = Layout;
const { Title } = Typography;

interface CashierLayoutProps {
    children: ReactNode;
    title?: string;
}

export default function CashierLayout({ children, title }: CashierLayoutProps) {
    const { auth } = usePage().props;
    const user = auth.user as User;

    const logout = () => {
        router.post('/logout');
    };

    const menuItems = [
        {
            key: 'orders',
            icon: <ShoppingCartOutlined />,
            label: 'الطلبات',
            onClick: () => router.get('/orders'),
        },
    ];

    return (
        <Layout className="min-h-screen" dir="rtl">
            <Header className="flex justify-between items-center bg-white shadow-sm">
                <div className="flex items-center">
                    <Title level={3} className="m-0 text-primary">
                        {title || 'نظام إدارة المطعم'}
                    </Title>
                </div>

                <Space size="middle">
                    <Menu
                        mode="horizontal"
                        items={menuItems}
                        className="border-0 bg-transparent"
                    />

                    <Space>
                        <UserOutlined />
                        <span>{user.name}</span>
                        <Button
                            type="text"
                            icon={<LogoutOutlined />}
                            onClick={logout}
                        >
                            تسجيل الخروج
                        </Button>
                    </Space>
                </Space>
            </Header>

            <Content className="bg-gray-50">
                {children}
            </Content>
        </Layout>
    );
}

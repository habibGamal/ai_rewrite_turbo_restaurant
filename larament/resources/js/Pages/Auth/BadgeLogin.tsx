import React from 'react';
import { Head } from '@inertiajs/react';
import { Card, ConfigProvider } from 'antd';
import BadgeScanForm from '@/Components/Auth/BadgeScanForm';

export default function BadgeLogin() {
    return (
        <ConfigProvider
            direction="rtl"
            theme={{
                token: {
                    colorPrimary: '#7E57C2',
                    colorError: '#cf6679',
                    fontSize: 18,
                },
            }}
        >
            <div className="min-h-screen grid place-items-center bg-gray-50 p-4">
                <Head title="الدخول بالبادج" />
                <Card title="الدخول لنقطة البيع بالبادج" className="w-full max-w-[420px]">
                    <BadgeScanForm action={route('badge.login.store')} submitLabel="دخول" />
                    <div className="mt-4 text-center">
                        <a href="/admin/login" className="text-sm text-gray-500 hover:text-[#7E57C2] underline">
                            الدخول بالإيميل وكلمة السر
                        </a>
                    </div>
                </Card>
            </div>
        </ConfigProvider>
    );
}

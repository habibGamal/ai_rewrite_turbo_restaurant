import React from 'react';
import { Head } from '@inertiajs/react';
import { Card } from 'antd';
import BadgeScanForm from '@/Components/Auth/BadgeScanForm';

export default function BadgeLogin() {
    return (
        <div className="min-h-screen grid place-items-center bg-gray-100 p-4">
            <Head title="الدخول بالبادج" />
            <Card title="الدخول لنقطة البيع بالبادج" className="w-full max-w-[420px]">
                <BadgeScanForm action={route('badge.login.store')} submitLabel="دخول" />
                <div className="mt-4 text-center">
                    <a href="/admin/login" className="text-sm text-gray-500 underline">
                        الدخول بالإيميل وكلمة السر
                    </a>
                </div>
            </Card>
        </div>
    );
}

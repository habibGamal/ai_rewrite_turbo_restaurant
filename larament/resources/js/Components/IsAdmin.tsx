import React from 'react';
import { usePage } from '@inertiajs/react';
import { User } from '@/types';

interface IsAdminProps {
    children: React.ReactNode;
}

export default function IsAdmin({ children }: IsAdminProps) {
    const { auth } = usePage().props as unknown as { auth: { user: User } };
    const user = auth?.user;

    if (!user || (!user.isAdmin && !user.roles?.includes('admin') && !user.permissions?.includes('access_admin_panel'))) {
        return null;
    }

    return <>{children}</>;
}

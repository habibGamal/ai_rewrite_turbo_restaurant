import React from 'react';
import { usePage } from '@inertiajs/react';
import { User } from '@/types';

interface CanAccessProps {
    children: React.ReactNode;
    permission?: string;
    role?: string;
    fallback?: React.ReactNode;
}

export default function CanAccess({ children, permission, role, fallback = null }: CanAccessProps) {
    const { auth } = usePage().props as unknown as { auth: { user: User } };
    const user = auth?.user;

    if (!user) {
        return <>{fallback}</>;
    }

    // Admin always has access
    if (user.isAdmin || user.roles?.includes('admin')) {
        return <>{children}</>;
    }

    if (role && user.roles?.includes(role)) {
        return <>{children}</>;
    }

    if (permission && user.permissions?.includes(permission)) {
        return <>{children}</>;
    }

    return <>{fallback}</>;
}

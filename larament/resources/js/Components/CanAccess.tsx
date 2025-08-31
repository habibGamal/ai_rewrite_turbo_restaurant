import React from 'react';
import { usePage } from '@inertiajs/react';
import { User } from '@/types';

interface CanAccessProps {
    children: React.ReactNode;
    permission: 'discounts' | 'cancelOrders' | 'changeOrderItems';
}

export default function CanAccess({ children, permission }: CanAccessProps) {
    const { auth } = usePage().props;
    const user = auth.user as User;

    // Admin always has access
    if (user.role === 'admin') {
        return <>{children}</>;
    }

    // Check specific permission
    let hasPermission = false;

    switch (permission) {
        case 'discounts':
            hasPermission = user.canApplyDiscounts ?? false;
            break;
        case 'cancelOrders':
            hasPermission = user.canCancelOrders ?? false;
            break;
        case 'changeOrderItems':
            hasPermission = user.canChangeOrderItems ?? false;
            break;
    }

    if (!hasPermission) {
        return null;
    }

    return <>{children}</>;
}

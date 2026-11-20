import { usePage } from '@inertiajs/react';
import { User } from '@/types';

export function usePermission() {
    const { auth } = usePage().props as unknown as { auth: { user: User } };
    const user = auth?.user;

    const can = (permission: string): boolean => {
        if (!user) {
            return false;
        }

        if (user.isAdmin || user.roles?.includes('admin')) {
            return true;
        }

        return user.permissions?.includes(permission) ?? false;
    };

    const hasRole = (role: string): boolean => {
        if (!user) {
            return false;
        }

        return user.roles?.includes(role) ?? false;
    };

    return { can, hasRole, user };
}

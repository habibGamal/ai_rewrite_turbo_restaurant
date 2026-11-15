import { ReactNode } from 'react';
import { ModuleKey } from '@/types';
import { useModule } from '@/hooks/useModule';

interface IfModuleProps {
    name: ModuleKey;
    children: ReactNode;
    fallback?: ReactNode;
}

/**
 * Renders its children only when the module is switched on for this install.
 * Cosmetic only: routes and permissions of a disabled module are refused on the server.
 */
export default function IfModule({ name, children, fallback = null }: IfModuleProps) {
    return <>{useModule(name) ? children : fallback}</>;
}

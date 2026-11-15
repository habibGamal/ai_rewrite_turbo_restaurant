import { usePage } from '@inertiajs/react';
import { BusinessType, ModuleKey, PageProps } from '@/types';

/**
 * Modules a restaurant install had before the module switches existed. Used
 * when the server did not share `modules`, so old pages keep their behaviour.
 */
export const DEFAULT_MODULES: ModuleKey[] = ['restaurant', 'delivery', 'manufacturing'];

/**
 * The modules the vendor switched on for this install. Hiding UI with these is
 * cosmetic: the server answers 404 / 403 for a disabled module's routes.
 */
export function useModules(): ModuleKey[] {
    const { modules } = usePage<PageProps>().props;

    return Array.isArray(modules) ? modules : DEFAULT_MODULES;
}

export function useModule(name: ModuleKey): boolean {
    return useModules().includes(name);
}

export function useBusinessType(): BusinessType {
    const { businessType } = usePage<PageProps>().props;

    return businessType ?? 'restaurant';
}

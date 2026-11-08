import KdsScreen from '@/Components/Kitchen/KdsScreen';
import KitchenLayout from '@/Layouts/KitchenLayout';
import type { KitchenPageProps } from '@/types/kitchen';
import { Head } from '@inertiajs/react';

export default function KitchenIndex(props: KitchenPageProps) {
    return (
        <KitchenLayout>
            <Head title="شاشة المطبخ" />
            <KdsScreen {...props} />
        </KitchenLayout>
    );
}

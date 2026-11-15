import { useState, useEffect } from 'react';
import axios from 'axios';
import { useModule } from '@/hooks/useModule';
import { useTerms } from '@/lib/wording';

export interface TableType {
    id: number | null;
    name: string;
    created_at: string | null;
    updated_at: string | null;
}

/**
 * Table types come from the Restaurant module; without it there is nothing
 * to fetch (the /table-types route is not served).
 */
export default function useTableTypes() {
    const restaurantEnabled = useModule('restaurant');
    const terms = useTerms();
    const [tableTypes, setTableTypes] = useState<TableType[]>([]);
    const [loading, setLoading] = useState(restaurantEnabled);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (restaurantEnabled) {
            fetchTableTypes();
        }
    }, [restaurantEnabled]);

    const fetchTableTypes = async () => {
        try {
            setLoading(true);
            setError(null);

            const response = await axios.get('/table-types');
            setTableTypes(response.data);
        } catch (err: any) {
            console.error('Error fetching table types:', err);
            setError(err.response?.data?.error || terms.fetchTableTypesFailed);

            // Set default option if fetch fails
            setTableTypes([
                {
                    id: null,
                    name: terms.defaultTableType,
                    created_at: null,
                    updated_at: null,
                }
            ]);
        } finally {
            setLoading(false);
        }
    };

    return {
        tableTypes,
        loading,
        error,
        refetch: fetchTableTypes,
    };
}

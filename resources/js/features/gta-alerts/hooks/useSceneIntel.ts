import { useState, useEffect, useCallback, useRef } from 'react';
import { z } from 'zod/v4';
import { SceneIntelItemSchema } from '../domain/alerts/fire/scene-intel';
import type { SceneIntelItem } from '../domain/alerts/fire/scene-intel';

const ResponseSchema = z.object({
    data: z.array(SceneIntelItemSchema),
    meta: z.object({
        event_num: z.string(),
        count: z.number(),
    }),
});

interface UseSceneIntelReturn {
    items: SceneIntelItem[];
    loading: boolean;
    error: Error | null;
    refresh: () => Promise<void>;
}

export function useSceneIntel(
    eventNum: string,
    initialItems: SceneIntelItem[] = [],
): UseSceneIntelReturn {
    const [items, setItems] = useState<SceneIntelItem[]>(initialItems);
    const [loading, setLoading] = useState<boolean>(false);
    const [error, setError] = useState<Error | null>(null);
    const hasDataRef = useRef<boolean>(initialItems.length > 0);

    const fetchData = useCallback(async (signal?: AbortSignal) => {
        if (!eventNum) return;

        try {
            if (!hasDataRef.current) {
                setLoading(true);
            }
            
            const response = await fetch(`/api/incidents/${eventNum}/intel`, {
                signal,
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error(
                    `Failed to fetch scene intel: ${response.statusText}`,
                );
            }

            const json = await response.json();
            const result = ResponseSchema.safeParse(json);

            if (!result.success) {
                console.error(
                    'Scene intel schema validation failed:',
                    result.error,
                );
                setError(new Error('Invalid data received from server'));
                return;
            }

            setItems(result.data.data);
            hasDataRef.current = result.data.data.length > 0;
            setError(null);
        } catch (err) {
            if (err instanceof Error && err.name === 'AbortError') {
                return;
            }
            console.error('Error fetching scene intel:', err);
            setError(err instanceof Error ? err : new Error('Unknown error'));
        } finally {
            setLoading(false);
        }
    }, [eventNum]);

    useEffect(() => {
        const controller = new AbortController();

        // Initial fetch to ensure we have the full timeline
        void fetchData(controller.signal);

        const intervalId = setInterval(() => {
            void fetchData(controller.signal);
        }, 30000); // 30 seconds

        return () => {
            controller.abort();
            clearInterval(intervalId);
        };
    }, [fetchData]);

    return {
        items,
        loading,
        error,
        refresh: () => fetchData(),
    };
}

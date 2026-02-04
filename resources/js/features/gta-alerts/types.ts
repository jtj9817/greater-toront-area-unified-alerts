export interface AlertItem {
    id: string;
    title: string;
    location: string; // Sometimes part of title in design, but good to separate conceptually
    timeAgo: string;
    timestamp: string; // Raw ISO 8601 string from backend
    description: string;
    type: 'fire' | 'police' | 'transit' | 'hazard' | 'medical';
    severity: 'high' | 'medium' | 'low';
    iconName: string;
    accentColor: string; // Tailwind bg-* class, used for the left border
    iconColor: string; // Tailwind text-* class, used for the category icon

    // Incident Metadata
    metadata?: {
        eventNum: string;
        alarmLevel: number;
        unitsDispatched: string | null;
        beat: string | null;
        source?: string; // e.g., "Toronto Fire Services", "TTC Control", "Toronto Police"
        estimatedDelay?: string; // Transit-specific delay info
        shuttleInfo?: string; // Transit-specific shuttle bus instructions
    };
}

export interface UnifiedAlertResource {
    id: string;
    source: 'fire' | 'police' | 'transit';
    external_id: string;
    is_active: boolean;
    timestamp: string;
    title: string;
    location: {
        name: string | null;
        lat: number | null;
        lng: number | null;
    } | null;
    meta: Record<string, unknown>;
}

export interface AlertSectionData {
    id: string;
    title: string;
    iconName: string;
    activeCount: number;
    items: AlertItem[];
}

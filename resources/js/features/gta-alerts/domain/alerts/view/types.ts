import type { SceneIntelItem } from '../fire/scene-intel';

export type AlertPresentationType =
    | 'fire'
    | 'police'
    | 'transit'
    | 'go_transit'
    | 'hazard'
    | 'medical';

export type AlertPresentationSeverity = 'high' | 'medium' | 'low';

export interface AlertPresentationMetadata {
    eventNum: string;
    alarmLevel: number;
    unitsDispatched: string | null;
    beat: string | null;
    source?: string;
    estimatedDelay?: string;
    shuttleInfo?: string;
    routeType?: string;
    route?: string;
    effect?: string;
    direction?: string;
    sourceFeed?: string;
    intelSummary?: SceneIntelItem[];
    intelLastUpdated?: string | null;
}

export interface AlertPresentation {
    id: string;
    title: string;
    location: string;
    timeAgo: string;
    timestamp: string;
    description: string;
    type: AlertPresentationType;
    severity: AlertPresentationSeverity;
    iconName: string;
    accentColor: string;
    iconColor: string;
    metadata?: AlertPresentationMetadata;
}

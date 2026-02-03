export interface AlertItem {
  id: string;
  title: string;
  location: string; // Sometimes part of title in design, but good to separate conceptually
  timeAgo: string;
  description: string;
  type: 'fire' | 'police' | 'transit' | 'hazard' | 'medical';
  severity: 'high' | 'medium' | 'low';
  iconName: string;
  accentColor: string; // Tailwind color class or hex, used for the left border
  
  // Incident Metadata
  metadata?: {
    eventNum: string;
    alarmLevel: number;
    unitsDispatched: string | null;
    beat: string | null;
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

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

export interface IncidentResource {
  id: number;
  event_num: string;
  event_type: string;
  prime_street: string;
  cross_streets: string | null;
  dispatch_time: string;
  alarm_level: number;
  beat: string | null;
  units_dispatched: string | null;
  is_active: boolean;
  feed_updated_at: string;
}

export interface AlertSectionData {
  id: string;
  title: string;
  iconName: string;
  activeCount: number;
  items: AlertItem[];
}
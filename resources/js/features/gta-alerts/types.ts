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
}

export interface AlertSectionData {
  id: string;
  title: string;
  iconName: string;
  activeCount: number;
  items: AlertItem[];
}
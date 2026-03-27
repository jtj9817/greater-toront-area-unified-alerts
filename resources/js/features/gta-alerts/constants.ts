import type { AlertPresentation } from './domain/alerts/view/types';

type AlertSectionData = {
    id: string;
    title: string;
    iconName: string;
    activeCount: number;
    items: AlertPresentation[];
};

export const ALERT_DATA: AlertSectionData[] = [
    {
        id: 'fire',
        title: 'Active Fire',
        iconName: 'local_fire_department',
        activeCount: 3,
        items: [
            {
                id: 'f1',
                title: '2-Alarm Fire - Scarborough',
                location: 'Kennedy & Eglinton',
                locationCoords: null,
                timeAgo: '4m ago',
                timestamp: new Date(Date.now() - 4 * 60 * 1000).toISOString(),
                description:
                    'Crews responding to residential structure fire near Kennedy & Eglinton. Smoke visible from highway.',
                type: 'fire',
                severity: 'high',
                iconName: 'local_fire_department',
                accentColor: 'bg-red-500',
                iconColor: 'text-[#e05560]',
            },
            {
                id: 'f2',
                title: 'Gas Leak - Downtown',
                location: 'King St W',
                locationCoords: null,
                timeAgo: '12m ago',
                timestamp: new Date(Date.now() - 12 * 60 * 1000).toISOString(),
                description:
                    'Hazmat requested at King St W for reported gas line rupture. Building evacuation in progress.',
                type: 'fire',
                severity: 'high',
                iconName: 'local_fire_department',
                accentColor: 'bg-red-500',
                iconColor: 'text-[#e05560]',
            },
            {
                id: 'f3',
                title: 'Vehicle Fire - 401 Westbound',
                location: 'Near Yonge St exit',
                locationCoords: null,
                timeAgo: '32m ago',
                timestamp: new Date(Date.now() - 32 * 60 * 1000).toISOString(),
                description:
                    'Fully engulfed vehicle on shoulder near Yonge St exit. 2 lanes blocked.',
                type: 'fire',
                severity: 'medium',
                iconName: 'local_fire_department',
                accentColor: 'bg-orange-400',
                iconColor: 'text-[#e07830]',
            },
        ],
    },
    {
        id: 'police',
        title: 'Police Operations',
        iconName: 'local_police',
        activeCount: 2,
        items: [
            {
                id: 'p1',
                title: 'Person with a Weapon',
                location: 'High Park',
                locationCoords: null,
                timeAgo: '25m ago',
                timestamp: new Date(Date.now() - 25 * 60 * 1000).toISOString(),
                description:
                    'High Park area. Police on scene. Avoid the area. K9 Unit deployed.',
                type: 'police',
                severity: 'high',
                iconName: 'shield',
                accentColor: 'bg-blue-500',
                iconColor: 'text-[#e05560]',
            },
            {
                id: 'p2',
                title: 'Collision - Highway 400',
                location: 'Near Finch Ave',
                locationCoords: null,
                timeAgo: '45m ago',
                timestamp: new Date(Date.now() - 45 * 60 * 1000).toISOString(),
                description:
                    'Multi-vehicle pileup near Finch Ave. All southbound lanes closed. OPP investigating.',
                type: 'police',
                severity: 'medium',
                iconName: 'car_crash',
                accentColor: 'bg-blue-500',
                iconColor: 'text-[#6890ff]',
            },
        ],
    },
    {
        id: 'transit',
        title: 'Transit (TTC/GO)',
        iconName: 'train',
        activeCount: 1,
        items: [
            {
                id: 't1',
                title: 'Line 1 Yonge-University Delay',
                location: 'St Clair to Lawrence',
                locationCoords: null,
                timeAgo: '1h ago',
                timestamp: new Date(Date.now() - 60 * 60 * 1000).toISOString(),
                description:
                    'Service suspended between St Clair and Lawrence due to signal issues. Shuttle buses operating.',
                type: 'transit',
                severity: 'low',
                iconName: 'directions_subway',
                accentColor: 'bg-green-500',
                iconColor: 'text-[#a78bfa]',
            },
        ],
    },
];

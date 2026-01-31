import { AlertSectionData } from './types';

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
        timeAgo: '4m ago',
        description: 'Crews responding to residential structure fire near Kennedy & Eglinton. Smoke visible from highway.',
        type: 'fire',
        severity: 'high',
        iconName: 'local_fire_department',
        accentColor: 'bg-red-500'
      },
      {
        id: 'f2',
        title: 'Gas Leak - Downtown',
        location: 'King St W',
        timeAgo: '12m ago',
        description: 'Hazmat requested at King St W for reported gas line rupture. Building evacuation in progress.',
        type: 'hazard',
        severity: 'high',
        iconName: 'warning',
        accentColor: 'bg-yellow-500'
      },
      {
        id: 'f3',
        title: 'Vehicle Fire - 401 Westbound',
        location: 'Near Yonge St exit',
        timeAgo: '32m ago',
        description: 'Fully engulfed vehicle on shoulder near Yonge St exit. 2 lanes blocked.',
        type: 'fire',
        severity: 'medium',
        iconName: 'local_fire_department',
        accentColor: 'bg-orange-400'
      }
    ]
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
        timeAgo: '25m ago',
        description: 'High Park area. Police on scene. Avoid the area. K9 Unit deployed.',
        type: 'police',
        severity: 'high',
        iconName: 'shield',
        accentColor: 'bg-blue-500'
      },
      {
        id: 'p2',
        title: 'Collision - Highway 400',
        location: 'Near Finch Ave',
        timeAgo: '45m ago',
        description: 'Multi-vehicle pileup near Finch Ave. All southbound lanes closed. OPP investigating.',
        type: 'police',
        severity: 'medium',
        iconName: 'car_crash',
        accentColor: 'bg-blue-500'
      }
    ]
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
        timeAgo: '1h ago',
        description: 'Service suspended between St Clair and Lawrence due to signal issues. Shuttle buses operating.',
        type: 'transit',
        severity: 'low',
        iconName: 'directions_subway',
        accentColor: 'bg-green-500'
      }
    ]
  }
];
import React from 'react';
import { Icon } from './Icon';

const ZONES = [
    {
        id: 1,
        name: 'Downtown Core',
        status: 'High Activity',
        color: 'text-coral',
        bg: 'bg-coral/10',
        dotColor: 'bg-coral',
        count: 12,
    },
    {
        id: 2,
        name: 'Scarborough',
        status: 'Moderate',
        color: 'text-burnt-orange',
        bg: 'bg-burnt-orange/10',
        dotColor: 'bg-burnt-orange',
        count: 5,
    },
    {
        id: 3,
        name: 'North York',
        status: 'Low Activity',
        color: 'text-forest',
        bg: 'bg-forest/10',
        dotColor: 'bg-forest',
        count: 2,
    },
    {
        id: 4,
        name: 'Etobicoke',
        status: 'Normal',
        color: 'text-amber',
        bg: 'bg-amber/10',
        dotColor: 'bg-amber',
        count: 1,
    },
    {
        id: 5,
        name: 'Peel Region',
        status: 'Monitoring',
        color: 'text-gray-400',
        bg: 'bg-white/5',
        dotColor: 'bg-gray-500',
        count: 0,
    },
    {
        id: 6,
        name: 'York Region',
        status: 'Monitoring',
        color: 'text-gray-400',
        bg: 'bg-white/5',
        dotColor: 'bg-gray-500',
        count: 0,
    },
];

export const ZonesView: React.FC = () => {
    return (
        <section id="gta-alerts-zones-view" className="p-4 md:p-6">
            <div
                id="gta-alerts-zones-header"
                className="mb-6 flex items-end justify-between"
            >
                <div id="gta-alerts-zones-header-content">
                    <h2
                        id="gta-alerts-zones-title"
                        className="mb-2 flex items-center gap-3 text-2xl font-bold text-white"
                    >
                        <Icon name="map" className="text-primary" />
                        Active Zones
                    </h2>
                    <p
                        id="gta-alerts-zones-description"
                        className="text-sm text-text-secondary"
                    >
                        Real-time status by geographic region.
                    </p>
                </div>
                <button
                    id="gta-alerts-zones-manage-btn"
                    className="hidden items-center gap-2 text-sm font-medium text-primary transition-colors hover:text-white md:flex"
                >
                    <Icon name="add_location_alt" />
                    Manage Zones
                </button>
            </div>

            <div
                id="gta-alerts-zones-grid"
                className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
            >
                {ZONES.map((zone) => (
                    <div
                        id={`gta-alerts-zones-card-${zone.id}`}
                        key={zone.id}
                        className="group cursor-pointer rounded-xl border border-white/5 bg-surface-dark p-5 transition-all hover:border-white/20"
                    >
                        <div className="mb-4 flex items-start justify-between">
                            <div
                                className={`flex h-10 w-10 items-center justify-center rounded-lg ${zone.bg} ${zone.color}`}
                            >
                                <Icon name="location_on" />
                            </div>
                            <span className="text-2xl font-bold text-white">
                                {zone.count}
                            </span>
                        </div>

                        <h3
                            id={`gta-alerts-zones-card-${zone.id}-name`}
                            className="mb-1 text-lg font-bold text-white transition-colors group-hover:text-primary"
                        >
                            {zone.name}
                        </h3>

                        <div className="flex items-center gap-2">
                            <span
                                className={`h-2 w-2 rounded-full ${zone.dotColor}`}
                            ></span>
                            <span
                                className={`text-xs font-medium ${zone.color}`}
                            >
                                {zone.status}
                            </span>
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
};

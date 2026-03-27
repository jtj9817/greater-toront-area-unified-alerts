import React from 'react';
import { MapContainer } from 'react-leaflet';

import 'leaflet/dist/leaflet.css';

import { configureLeafletDefaultIcons } from '../lib/leaflet';

configureLeafletDefaultIcons();

interface AlertLocationMapClientProps {
    idBase: string;
    lat: number;
    lng: number;
    locationName: string;
}

export function AlertLocationMapClient({
    idBase,
    lat,
    lng,
}: AlertLocationMapClientProps): React.ReactElement {
    return (
        <div
            id={`${idBase}-map-wrapper`}
            className="relative overflow-hidden rounded-lg border border-white/10"
        >
            <MapContainer
                center={[lat, lng]}
                zoom={15}
                scrollWheelZoom={false}
                style={{ height: '100%', width: '100%' }}
                attributionControl={true}
            >
                {/* TileLayer and Marker will be added in Phase 3 */}
            </MapContainer>
        </div>
    );
}

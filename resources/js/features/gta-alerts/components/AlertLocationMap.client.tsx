import React from 'react';
import { MapContainer, Marker, Popup, TileLayer } from 'react-leaflet';

import 'leaflet/dist/leaflet.css';

import { useIsMobile } from '@/hooks/use-mobile';

import {
    configureLeafletDefaultIcons,
    OPEN_STREET_MAP_ATTRIBUTION,
    OPEN_STREET_MAP_TILE_URL,
} from '../lib/leaflet';

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
    locationName,
}: AlertLocationMapClientProps): React.ReactElement {
    const isMobile = useIsMobile();

    return (
        <div
            id={`${idBase}-map-wrapper`}
            className="relative isolate z-0 aspect-video min-h-64 overflow-hidden rounded-lg border border-white/10"
        >
            <MapContainer
                id={`${idBase}-map`}
                center={[lat, lng]}
                zoom={15}
                scrollWheelZoom={false}
                dragging={!isMobile}
                touchZoom={!isMobile}
                doubleClickZoom={!isMobile}
                boxZoom={!isMobile}
                keyboard={!isMobile}
                style={{ height: '100%', width: '100%' }}
                attributionControl={true}
            >
                <TileLayer
                    attribution={OPEN_STREET_MAP_ATTRIBUTION}
                    url={OPEN_STREET_MAP_TILE_URL}
                />
                <Marker position={[lat, lng]}>
                    <Popup>{locationName}</Popup>
                </Marker>
            </MapContainer>
        </div>
    );
}

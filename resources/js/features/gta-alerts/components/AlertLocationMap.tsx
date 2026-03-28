import React, { lazy, Suspense } from 'react';

const AlertLocationMapClient = lazy(() =>
    import(
        /* @vite-ignore */
        './AlertLocationMap.client'
    ).then((module) => ({
        default: module.AlertLocationMapClient,
    })),
);

interface AlertLocationMapProps {
    idBase: string;
    lat: number;
    lng: number;
    locationName: string;
}

export { AlertLocationUnavailable } from './AlertLocationUnavailable';

export function AlertLocationMap({
    idBase,
    lat,
    lng,
    locationName,
}: AlertLocationMapProps): React.ReactElement {
    return (
        <Suspense
            fallback={
                <div
                    id={`${idBase}-map-loading`}
                    className="relative flex aspect-video items-center justify-center overflow-hidden rounded-lg border border-dashed border-white/10 bg-white/5"
                >
                    <div className="absolute inset-0 bg-[radial-gradient(#e0556033_1px,transparent_1px)] [background-size:16px_16px] opacity-20" />
                    <span className="text-xs text-text-secondary">
                        Loading map...
                    </span>
                </div>
            }
        >
            <AlertLocationMapClient
                idBase={idBase}
                lat={lat}
                lng={lng}
                locationName={locationName}
            />
        </Suspense>
    );
}

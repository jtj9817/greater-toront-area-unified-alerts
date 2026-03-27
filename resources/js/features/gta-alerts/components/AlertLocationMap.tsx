import React, { lazy, Suspense } from 'react';

const AlertLocationMapClient = lazy(
    () =>
        import(
            /* @vite-ignore */
            './AlertLocationMap.client'
        ) as unknown as Promise<{
            default: React.ComponentType<AlertLocationMapProps>;
        }>,
);

interface AlertLocationMapProps {
    idBase: string;
    lat: number;
    lng: number;
    locationName: string;
}

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

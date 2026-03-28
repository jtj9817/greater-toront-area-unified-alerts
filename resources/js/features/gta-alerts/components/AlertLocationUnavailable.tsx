import { Icon } from './Icon';

interface AlertLocationUnavailableProps {
    idBase: string;
    locationName: string;
}

export function AlertLocationUnavailable({
    idBase,
    locationName,
}: AlertLocationUnavailableProps): React.ReactElement {
    return (
        <div
            id={`${idBase}-location-unavailable`}
            className="relative flex aspect-video items-center justify-center overflow-hidden rounded-lg border border-dashed border-white/10 bg-white/5"
        >
            <div className="absolute inset-0 bg-[radial-gradient(#e0556033_1px,transparent_1px)] [background-size:16px_16px] opacity-20" />
            <div className="flex flex-col items-center gap-2 text-center">
                <Icon
                    name="location_off"
                    className="text-2xl text-text-secondary"
                />
                <span className="text-xs text-text-secondary">
                    Map unavailable
                </span>
                <span className="text-xs text-text-secondary/80">
                    Exact coordinates are not available for this alert.
                </span>
                <span className="text-xs text-text-secondary/70">
                    {locationName}
                </span>
            </div>
        </div>
    );
}

import React, { useMemo } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatTimestampEST } from '@/lib/utils';
import {
    mapDomainAlertToPresentation,
    type DomainAlert,
} from '../domain/alerts';
import { AlertLocationMap, AlertLocationUnavailable } from './AlertLocationMap';
import { Icon } from './Icon';
import { SceneIntelTimeline } from './SceneIntelTimeline';

interface DetailsProps {
    alert: DomainAlert;
    onBack: () => void;
    isSaved: boolean;
    isPending: boolean;
    onToggleSave: () => void;
    onShare: () => void;
}

type PresentationAlert = ReturnType<typeof mapDomainAlertToPresentation>;

type DetailSections = {
    header: React.ReactNode;
    metadata: React.ReactNode;
    specializedContent: React.ReactNode;
};

interface DetailLayoutProps {
    alert: PresentationAlert;
    onBack: () => void;
    sections: DetailSections;
    isSaved: boolean;
    isPending: boolean;
    onToggleSave: () => void;
    onShare: () => void;
}

const AlertDetailsLayout: React.FC<DetailLayoutProps> = ({
    alert,
    onBack,
    sections,
    isSaved,
    isPending,
    onToggleSave,
    onShare,
}) => {
    const idBase = `gta-alerts-alert-details-${alert.id}`;

    return (
        <section
            id={idBase}
            className="flex h-full animate-in flex-col bg-background-dark duration-500 fade-in slide-in-from-bottom-4"
        >
            <header
                id={`${idBase}-header`}
                className="sticky top-0 z-10 flex items-center gap-4 border-b border-white/5 bg-background-dark/50 p-4 backdrop-blur-md"
            >
                <button
                    id={`${idBase}-back-btn`}
                    onClick={onBack}
                    className="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-white transition-all hover:bg-[#FF7F00] hover:text-white"
                >
                    <Icon name="arrow_back" />
                </button>
                <div>
                    <h2
                        id={`${idBase}-title`}
                        className="leading-none font-bold text-white"
                    >
                        Incident Details
                    </h2>
                    <p className="mt-1 text-xs text-text-secondary">
                        {alert.id.toUpperCase()} • {alert.location}
                    </p>
                </div>
            </header>

            <div
                id={`${idBase}-content`}
                className="flex-1 overflow-y-auto p-4 md:p-8"
            >
                <div
                    id={`${idBase}-sections`}
                    className="mx-auto max-w-4xl space-y-8"
                >
                    {sections.header}

                    <section
                        id={`${idBase}-briefing-section`}
                        className="rounded-2xl border border-white/5 bg-surface-dark p-6 shadow-xl md:p-8"
                    >
                        <div className="flex flex-col gap-8 md:flex-row">
                            <div className="flex-1">
                                <h3 className="mb-4 text-xs font-bold tracking-widest text-primary uppercase">
                                    Official Briefing
                                </h3>
                                <p className="text-lg leading-relaxed font-light text-white">
                                    {alert.description}
                                </p>

                                <div className="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
                                    <div className="rounded-xl bg-white/5 p-4">
                                        <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                                            Time Reported
                                        </p>
                                        <p className="text-sm text-white">
                                            {formatTimestampEST(
                                                alert.timestamp,
                                            )}
                                        </p>
                                        <p className="mt-0.5 text-xs text-text-secondary">
                                            {alert.timeAgo}
                                        </p>
                                    </div>
                                    <div className="rounded-xl bg-white/5 p-4">
                                        <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                                            Category
                                        </p>
                                        <p className="text-sm text-white capitalize">
                                            {alert.type}
                                        </p>
                                    </div>
                                    {sections.metadata}
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        id={`${idBase}-location-section`}
                        className="rounded-2xl border border-white/5 bg-surface-dark p-6 shadow-xl md:p-8"
                    >
                        <h3 className="mb-4 flex items-center gap-2 text-xs font-bold tracking-widest text-primary uppercase">
                            <Icon name="map" className="text-sm" />
                            Location Map
                        </h3>
                        {alert.locationCoords ? (
                            <AlertLocationMap
                                idBase={idBase}
                                lat={alert.locationCoords.lat}
                                lng={alert.locationCoords.lng}
                                locationName={alert.location}
                            />
                        ) : (
                            <AlertLocationUnavailable
                                idBase={idBase}
                                locationName={alert.location}
                            />
                        )}
                    </section>

                    {sections.specializedContent}

                    <div id={`${idBase}-actions`} className="flex gap-4 pt-4">
                        <button
                            id={`${idBase}-share-btn`}
                            type="button"
                            onClick={onShare}
                            className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-white/10 py-4 font-bold text-white shadow-lg transition-all hover:bg-white/20"
                        >
                            <Icon name="share" />
                            Share Alert
                        </button>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <button
                                    id={`${idBase}-save-btn`}
                                    onClick={onToggleSave}
                                    disabled={isPending}
                                    className={`flex items-center justify-center gap-2 rounded-xl border px-6 py-4 font-bold transition-all ${
                                        isSaved
                                            ? 'border-primary bg-primary text-black shadow-lg hover:border-red-500 hover:bg-red-500/20 hover:text-red-400'
                                            : 'border-white/10 text-white hover:border-white/20 hover:bg-white/10'
                                    } ${isPending ? 'cursor-wait opacity-70' : ''}`}
                                >
                                    <Icon
                                        name={
                                            isPending
                                                ? 'sync'
                                                : isSaved
                                                  ? 'bookmark'
                                                  : 'bookmark_border'
                                        }
                                        className={
                                            isPending ? 'animate-spin' : ''
                                        }
                                        fill={isSaved}
                                    />
                                    {isSaved ? (
                                        <span className="hidden text-sm font-bold sm:inline">
                                            Saved
                                        </span>
                                    ) : (
                                        <span className="hidden text-sm font-bold sm:inline">
                                            Save Alert
                                        </span>
                                    )}
                                </button>
                            </TooltipTrigger>
                            <TooltipContent>
                                {isPending
                                    ? 'Processing…'
                                    : isSaved
                                      ? 'Remove from saved alerts'
                                      : 'Save this alert for later'}
                            </TooltipContent>
                        </Tooltip>
                    </div>
                </div>
            </div>
        </section>
    );
};

function buildFireSections(alert: PresentationAlert): DetailSections {
    const isMedical = alert.type === 'medical';

    if (isMedical) {
        return {
            header: (
                <div className="relative flex flex-col items-center gap-6 overflow-hidden rounded-3xl border border-pink-500/20 bg-pink-950/20 p-8 md:flex-row">
                    <div className="flex h-20 w-20 items-center justify-center rounded-2xl bg-pink-500 text-white shadow-2xl shadow-pink-500/40">
                        <Icon name="medical_services" className="text-4xl" />
                    </div>
                    <div>
                        <span className="mb-2 inline-block rounded-md bg-pink-600 px-2 py-1 text-[10px] font-bold text-white uppercase">
                            Medical Emergency
                        </span>
                        <h1 className="text-3xl font-bold tracking-tight text-white md:text-4xl">
                            {alert.title}
                        </h1>
                    </div>
                </div>
            ),
            metadata: (
                <>
                    <div className="rounded-xl bg-white/5 p-4">
                        <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                            Alert Source
                        </p>
                        <p className="text-sm text-white">
                            {alert.metadata?.source || 'Toronto Fire Services'}
                        </p>
                    </div>
                    <div className="rounded-xl bg-white/5 p-4">
                        <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                            Units Dispatched
                        </p>
                        <p className="text-sm text-white">
                            {alert.metadata?.unitsDispatched || 'None reported'}
                        </p>
                    </div>
                </>
            ),
            specializedContent: (
                <div className="rounded-2xl border border-pink-500/20 bg-pink-500/5 p-6">
                    <h4 className="mb-4 flex items-center gap-2 text-xs font-bold text-pink-400 uppercase">
                        <Icon name="medical_services" className="text-sm" />
                        Medical Advisory
                    </h4>
                    <div className="flex items-start gap-4 rounded-xl border border-pink-500/20 bg-pink-600/10 p-4">
                        <Icon name="info" className="text-pink-400" />
                        <p className="text-sm font-medium text-pink-100">
                            Emergency medical services are responding to this
                            incident. Please clear the area to allow access for
                            first responders.
                        </p>
                    </div>
                </div>
            ),
        };
    }

    return {
        header: (
            <div className="relative flex flex-col items-center gap-6 overflow-hidden rounded-3xl border border-coral/20 bg-crimson/20 p-8 md:flex-row">
                <div className="flex h-20 w-20 items-center justify-center rounded-2xl bg-coral text-white shadow-2xl shadow-coral/40">
                    <Icon name="local_fire_department" className="text-4xl" />
                </div>
                <div>
                    <span className="mb-2 inline-block rounded-md bg-crimson px-2 py-1 text-[10px] font-bold text-white uppercase">
                        {alert.type === 'hazard'
                            ? 'Hazard Response'
                            : 'High Severity Response'}
                    </span>
                    <h1 className="text-3xl font-bold tracking-tight text-white md:text-4xl">
                        {alert.title}
                    </h1>
                </div>
            </div>
        ),
        metadata: (
            <>
                <div className="rounded-xl bg-white/5 p-4">
                    <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                        Alert Source
                    </p>
                    <p className="text-sm text-white">
                        {alert.metadata?.source || 'Toronto Fire Services'}
                    </p>
                </div>
                <div className="rounded-xl bg-white/5 p-4">
                    <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                        Response Tier
                    </p>
                    <p className="text-sm text-white">
                        {alert.metadata?.alarmLevel &&
                        alert.metadata.alarmLevel > 0
                            ? `Level ${alert.metadata.alarmLevel} (Alarm)`
                            : 'Standard Response'}
                    </p>
                </div>
                <div className="rounded-xl bg-white/5 p-4">
                    <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                        Units Dispatched
                    </p>
                    <p className="text-sm text-white">
                        {alert.metadata?.unitsDispatched || 'None reported'}
                    </p>
                </div>
            </>
        ),
        specializedContent: (
            <SceneIntelTimeline
                eventNum={alert.metadata?.eventNum || ''}
                initialItems={alert.metadata?.intelSummary}
            />
        ),
    };
}

function buildPoliceSections(alert: PresentationAlert): DetailSections {
    return {
        header: (
            <div className="relative flex flex-col items-center gap-6 overflow-hidden rounded-3xl border border-blue-500/20 bg-blue-950/20 p-8 md:flex-row">
                <div className="flex h-20 w-20 items-center justify-center rounded-2xl bg-blue-600 text-white shadow-2xl shadow-blue-500/40">
                    <Icon name="local_police" className="text-4xl" />
                </div>
                <div>
                    <span className="mb-2 inline-block rounded-md bg-blue-600 px-2 py-1 text-[10px] font-bold text-white uppercase">
                        Tactical Operation
                    </span>
                    <h1 className="text-3xl font-bold tracking-tight text-white md:text-4xl">
                        {alert.title}
                    </h1>
                </div>
            </div>
        ),
        metadata: (
            <>
                <div className="rounded-xl bg-white/5 p-4">
                    <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                        Alert Source
                    </p>
                    <p className="text-sm text-white">
                        {alert.metadata?.source || 'Toronto Police'}
                    </p>
                </div>
                <div className="rounded-xl bg-white/5 p-4">
                    <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                        Divisional Unit
                    </p>
                    <p className="text-sm text-white">
                        {alert.metadata?.beat || 'Not specified'}
                    </p>
                </div>
            </>
        ),
        specializedContent: (
            <div className="rounded-2xl border border-blue-500/20 bg-blue-500/5 p-6">
                <h4 className="mb-4 flex items-center gap-2 text-xs font-bold text-blue-400 uppercase">
                    <Icon name="visibility" className="text-sm" /> Public Safety
                    Advisory
                </h4>
                <div className="flex items-start gap-4 rounded-xl border border-blue-500/20 bg-blue-600/10 p-4">
                    <Icon name="info" className="text-blue-400" />
                    <p className="text-sm font-medium text-blue-100">
                        Police are currently conducting an investigation at this
                        location. Perimeter is established. Traffic is being
                        rerouted. Avoid the area until further notice.
                    </p>
                </div>
            </div>
        ),
    };
}

function buildTransitSections(alert: PresentationAlert): DetailSections {
    return {
        header: (
            <div className="relative flex flex-col items-center gap-6 overflow-hidden rounded-3xl border border-purple-500/20 bg-purple-950/20 p-8 md:flex-row">
                <div className="flex h-20 w-20 items-center justify-center rounded-2xl bg-purple-500 text-white shadow-2xl shadow-purple-500/40">
                    <Icon name="train" className="text-4xl" />
                </div>
                <div>
                    <span className="mb-2 inline-block rounded-md bg-purple-600 px-2 py-1 text-[10px] font-bold text-white uppercase">
                        Service Notice
                    </span>
                    <h1 className="text-3xl font-bold tracking-tight text-white md:text-4xl">
                        {alert.title}
                    </h1>
                </div>
            </div>
        ),
        metadata: (
            <>
                <div className="rounded-xl bg-white/5 p-4">
                    <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                        Alert Source
                    </p>
                    <p className="text-sm text-white">
                        {alert.metadata?.source || 'TTC Control'}
                    </p>
                </div>
                {alert.metadata?.estimatedDelay && (
                    <div className="rounded-xl bg-white/5 p-4">
                        <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                            Estimated Delay
                        </p>
                        <p className="text-sm text-white">
                            {alert.metadata.estimatedDelay}
                        </p>
                    </div>
                )}
            </>
        ),
        specializedContent: alert.metadata?.shuttleInfo ? (
            <div className="rounded-2xl border border-purple-500/20 bg-purple-500/5 p-6">
                <h4 className="mb-4 text-xs font-bold text-purple-400 uppercase">
                    Shuttle Bus Info
                </h4>
                <p className="text-sm text-white/80">
                    {alert.metadata.shuttleInfo}
                </p>
            </div>
        ) : null,
    };
}

function buildGoTransitSections(alert: PresentationAlert): DetailSections {
    return {
        header: (
            <div className="relative flex flex-col items-center gap-6 overflow-hidden rounded-3xl border border-forest/20 bg-forest/10 p-8 md:flex-row">
                <div className="flex h-20 w-20 items-center justify-center rounded-2xl bg-forest text-white shadow-2xl shadow-forest/40">
                    <Icon name="directions_transit" className="text-4xl" />
                </div>
                <div>
                    <span className="mb-2 inline-block rounded-md bg-forest px-2 py-1 text-[10px] font-bold text-white uppercase">
                        GO Service Notice
                    </span>
                    <h1 className="text-3xl font-bold tracking-tight text-white md:text-4xl">
                        {alert.title}
                    </h1>
                </div>
            </div>
        ),
        metadata: (
            <>
                <div className="rounded-xl bg-white/5 p-4">
                    <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                        Alert Source
                    </p>
                    <p className="text-sm text-white">
                        {alert.metadata?.source || 'GO Transit'}
                    </p>
                </div>
                <div className="rounded-xl bg-white/5 p-4">
                    <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                        Corridor
                    </p>
                    <p className="text-sm text-white">
                        {alert.metadata?.route || 'Not specified'}
                    </p>
                </div>
                {alert.metadata?.estimatedDelay && (
                    <div className="rounded-xl bg-white/5 p-4">
                        <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                            Estimated Delay
                        </p>
                        <p className="text-sm text-white">
                            {alert.metadata.estimatedDelay}
                        </p>
                    </div>
                )}
            </>
        ),
        specializedContent: (
            <div className="rounded-2xl border border-forest/20 bg-forest/5 p-6">
                <h4 className="mb-4 flex items-center gap-2 text-xs font-bold text-forest uppercase">
                    <Icon name="info" className="text-sm" /> Operations Note
                </h4>
                <p className="text-sm text-white/90">
                    Monitor station displays and platform announcements for the
                    latest GO service adjustments.
                </p>
            </div>
        ),
    };
}

export const AlertDetailsView: React.FC<DetailsProps> = ({
    alert,
    onBack,
    isSaved,
    isPending,
    onToggleSave,
    onShare,
}) => {
    const presentation = useMemo(
        () => mapDomainAlertToPresentation(alert),
        [alert],
    );

    const sections = useMemo<DetailSections>(() => {
        switch (alert.kind) {
            case 'fire':
                return buildFireSections(presentation);
            case 'police':
                return buildPoliceSections(presentation);
            case 'transit':
                return buildTransitSections(presentation);
            case 'go_transit':
                return buildGoTransitSections(presentation);
        }
    }, [alert.kind, presentation]);

    return (
        <AlertDetailsLayout
            alert={presentation}
            onBack={onBack}
            sections={sections}
            isSaved={isSaved}
            isPending={isPending}
            onToggleSave={onToggleSave}
            onShare={onShare}
        />
    );
};

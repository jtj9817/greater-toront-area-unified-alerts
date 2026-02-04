import React, { Component } from 'react';
import { formatTimestampEST } from '@/lib/utils';
import type { AlertItem } from '../types';
import { Icon } from './Icon';

interface DetailsProps {
    alert: AlertItem;
    onBack: () => void;
}

/**
 * OOP Pattern: Template Method
 * Abstract Base Class defining the structure of the Details View
 */
abstract class AlertDetailTemplate extends Component<DetailsProps> {
    // Fix: Explicitly declare props to resolve TS error about missing property in abstract class
    public readonly props: Readonly<DetailsProps>;

    constructor(props: DetailsProps) {
        super(props);
        this.props = props;
    }

    // Explicitly typing the return value of the main render method
    render(): React.ReactNode {
        const { alert, onBack } = this.props;
        const isSaved = false;

        return (
            <div className="flex h-full animate-in flex-col bg-background-dark duration-500 fade-in slide-in-from-bottom-4">
                {/* Persistent Navigation */}
                <div className="sticky top-0 z-10 flex items-center gap-4 border-b border-white/5 bg-background-dark/50 p-4 backdrop-blur-md">
                    <button
                        onClick={onBack}
                        className="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-white transition-all hover:bg-primary hover:text-white"
                    >
                        <Icon name="arrow_back" />
                    </button>
                    <div>
                        <h2 className="leading-none font-bold text-white">
                            Incident Details
                        </h2>
                        <p className="mt-1 text-xs text-text-secondary">
                            {alert.id.toUpperCase()} • {alert.location}
                        </p>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto p-4 md:p-8">
                    <div className="mx-auto max-w-4xl space-y-8">
                        {/* 1. Header Hook */}
                        {this.renderHeader()}

                        {/* 2. Primary Content Hook */}
                        <section className="rounded-2xl border border-white/5 bg-surface-dark p-6 shadow-xl md:p-8">
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
                                        {this.renderMetadata()}
                                    </div>
                                </div>
                            </div>
                        </section>

                        {/* 3. Specialized Content Hook */}
                        {this.renderSpecializedContent()}

                        {/* 4. Action Hook */}
                        <div className="flex gap-4 pt-4">
                            <button className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-white/10 py-4 font-bold text-white shadow-lg transition-all hover:bg-white/20">
                                <Icon name="share" />
                                Broadcast Alert
                            </button>
                            <button
                                className={`flex items-center justify-center gap-2 rounded-xl border px-6 transition-all ${
                                    isSaved
                                        ? 'border-white/20 bg-white/10 text-white shadow-lg'
                                        : 'border-white/10 text-white hover:border-white/20'
                                }`}
                            >
                                <Icon
                                    name={
                                        isSaved ? 'bookmark' : 'bookmark_border'
                                    }
                                    fill={isSaved}
                                />
                                {isSaved && (
                                    <span className="hidden text-sm font-bold sm:inline">
                                        Saved
                                    </span>
                                )}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    // Abstract Hooks to be implemented by subclasses
    abstract renderHeader(): React.ReactNode;
    abstract renderMetadata(): React.ReactNode;
    abstract renderSpecializedContent(): React.ReactNode;
}

/**
 * Inheritance: Specialized Renderer for Fire Alerts
 */
class FireAlertDetail extends AlertDetailTemplate {
    renderHeader(): React.ReactNode {
        return (
            <div className="relative flex flex-col items-center gap-6 overflow-hidden rounded-3xl border border-coral/20 bg-crimson/20 p-8 md:flex-row">
                <div className="flex h-20 w-20 items-center justify-center rounded-2xl bg-coral text-white shadow-2xl shadow-coral/40">
                    <Icon name="local_fire_department" className="text-4xl" />
                </div>
                <div>
                    <span className="mb-2 inline-block rounded-md bg-crimson px-2 py-1 text-[10px] font-bold text-white uppercase">
                        High Severity Response
                    </span>
                    <h1 className="text-3xl font-bold tracking-tight text-white md:text-4xl">
                        {this.props.alert.title}
                    </h1>
                </div>
            </div>
        );
    }

    renderMetadata(): React.ReactNode {
        const { alert } = this.props;
        return (
            <React.Fragment>
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
            </React.Fragment>
        );
    }

    renderSpecializedContent(): React.ReactNode {
        return (
            <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div className="rounded-2xl border border-white/5 bg-surface-dark p-6">
                    <h4 className="mb-4 flex items-center gap-2 text-xs font-bold text-primary uppercase">
                        <Icon name="map" className="text-sm" /> Location Map
                    </h4>
                    <div className="relative flex aspect-video items-center justify-center overflow-hidden rounded-lg border border-dashed border-white/10 bg-white/5">
                        <div className="absolute inset-0 bg-[radial-gradient(#e0556033_1px,transparent_1px)] [background-size:16px_16px] opacity-20"></div>
                        <Icon
                            name="location_on"
                            className="animate-bounce text-4xl text-primary"
                        />
                        <span className="absolute bottom-4 text-xs text-text-secondary">
                            Interactive Map Loading...
                        </span>
                    </div>
                </div>
                <div className="rounded-2xl border border-white/5 bg-surface-dark p-6">
                    <h4 className="mb-4 flex items-center gap-2 text-xs font-bold text-primary uppercase">
                        <Icon name="list_alt" className="text-sm" /> Scene Intel
                    </h4>
                    <ul className="space-y-3">
                        {[
                            'Hydrant confirmed operational',
                            'Search of Floor 1 complete',
                            'Command established - Pumper 12',
                        ].map((intel, i) => (
                            <li
                                key={i}
                                className="flex gap-3 text-sm text-gray-400"
                            >
                                <span className="font-bold text-coral">•</span>
                                {intel}
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        );
    }
}

/**
 * Inheritance: Specialized Renderer for Police Alerts
 */
class PoliceAlertDetail extends AlertDetailTemplate {
    renderHeader(): React.ReactNode {
        return (
            <div className="relative flex flex-col items-center gap-6 overflow-hidden rounded-3xl border border-blue-500/20 bg-blue-950/20 p-8 md:flex-row">
                <div className="flex h-20 w-20 items-center justify-center rounded-2xl bg-blue-600 text-white shadow-2xl shadow-blue-500/40">
                    <Icon name="local_police" className="text-4xl" />
                </div>
                <div>
                    <span className="mb-2 inline-block rounded-md bg-blue-600 px-2 py-1 text-[10px] font-bold text-white uppercase">
                        Tactical Operation
                    </span>
                    <h1 className="text-3xl font-bold tracking-tight text-white md:text-4xl">
                        {this.props.alert.title}
                    </h1>
                </div>
            </div>
        );
    }

    renderMetadata(): React.ReactNode {
        return (
            <React.Fragment>
                <div className="rounded-xl bg-white/5 p-4">
                    <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                        Divisional Unit
                    </p>
                    <p className="text-sm text-white">31 Division</p>
                </div>
                <div className="rounded-xl bg-white/5 p-4">
                    <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                        Status
                    </p>
                    <p className="text-sm text-white">On-Scene</p>
                </div>
            </React.Fragment>
        );
    }

    renderSpecializedContent(): React.ReactNode {
        return (
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
        );
    }
}

/**
 * Inheritance: Specialized Renderer for Transit/Default Alerts
 */
class DefaultAlertDetail extends AlertDetailTemplate {
    renderHeader(): React.ReactNode {
        return (
            <div className="relative flex flex-col items-center gap-6 overflow-hidden rounded-3xl border border-white/5 bg-surface-dark p-8 md:flex-row">
                <div
                    className={`h-20 w-20 rounded-2xl ${this.props.alert.accentColor} flex items-center justify-center text-white shadow-2xl`}
                >
                    <Icon
                        name={this.props.alert.iconName}
                        className="text-4xl"
                    />
                </div>
                <div>
                    <span className="mb-2 inline-block rounded-md bg-white/10 px-2 py-1 text-[10px] font-bold text-white/60 uppercase">
                        Service Notice
                    </span>
                    <h1 className="text-3xl font-bold tracking-tight text-white md:text-4xl">
                        {this.props.alert.title}
                    </h1>
                </div>
            </div>
        );
    }

    renderMetadata(): React.ReactNode {
        return (
            <React.Fragment>
                <div className="rounded-xl bg-white/5 p-4">
                    <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                        Alert Source
                    </p>
                    <p className="text-sm text-white">TTC Control</p>
                </div>
                <div className="rounded-xl bg-white/5 p-4">
                    <p className="mb-1 text-[10px] font-bold text-text-secondary uppercase">
                        Estimated Delay
                    </p>
                    <p className="text-sm text-white">20-30 mins</p>
                </div>
            </React.Fragment>
        );
    }

    renderSpecializedContent(): React.ReactNode {
        return (
            <div className="rounded-2xl border border-white/10 bg-white/5 p-6">
                <h4 className="mb-4 text-xs font-bold text-text-secondary uppercase">
                    Shuttle Bus Info
                </h4>
                <p className="text-sm text-white/60">
                    Board shuttle buses at street level. Follow staff
                    instructions. Extra travel time is required.
                </p>
            </div>
        );
    }
}

/**
 * Functional Component that acts as a Factory/Wrapper for the OOP Detail views
 */
export const AlertDetailsView: React.FC<DetailsProps> = (props) => {
    const { alert } = props;

    // Choose the appropriate renderer subclass based on alert type
    if (alert.type === 'fire' || alert.type === 'hazard') {
        return <FireAlertDetail {...props} />;
    }
    if (alert.type === 'police') {
        return <PoliceAlertDetail {...props} />;
    }

    return <DefaultAlertDetail {...props} />;
};

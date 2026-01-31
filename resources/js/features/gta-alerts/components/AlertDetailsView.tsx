import React, { Component } from 'react';
import { AlertItem } from '../types';
import { Icon } from './Icon';
import { AlertService } from '../services/AlertService';

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
    const isSaved = AlertService.getSavedItems().some(i => i.id === alert.id);
    
    return (
      <div className="flex flex-col h-full bg-background-dark animate-in fade-in slide-in-from-bottom-4 duration-500">
        {/* Persistent Navigation */}
        <div className="p-4 border-b border-white/5 flex items-center gap-4 bg-background-dark/50 sticky top-0 z-10 backdrop-blur-md">
          <button 
            onClick={onBack}
            className="w-10 h-10 rounded-full bg-white/5 flex items-center justify-center text-white hover:bg-primary hover:text-white transition-all"
          >
            <Icon name="arrow_back" />
          </button>
          <div>
            <h2 className="text-white font-bold leading-none">Incident Details</h2>
            <p className="text-text-secondary text-xs mt-1">{alert.id.toUpperCase()} • {alert.location}</p>
          </div>
        </div>

        <div className="flex-1 overflow-y-auto p-4 md:p-8">
          <div className="max-w-4xl mx-auto space-y-8">
            {/* 1. Header Hook */}
            {this.renderHeader()}

            {/* 2. Primary Content Hook */}
            <section className="bg-surface-dark rounded-2xl border border-white/5 p-6 md:p-8 shadow-xl">
              <div className="flex flex-col md:flex-row gap-8">
                <div className="flex-1">
                  <h3 className="text-primary text-xs font-bold uppercase tracking-widest mb-4">Official Briefing</h3>
                  <p className="text-white text-lg leading-relaxed font-light">
                    {alert.description}
                  </p>
                  
                  <div className="mt-8 grid grid-cols-2 sm:grid-cols-4 gap-4">
                     <div className="bg-white/5 p-4 rounded-xl">
                        <p className="text-text-secondary text-[10px] uppercase font-bold mb-1">Time Reported</p>
                        <p className="text-white text-sm">{alert.timeAgo}</p>
                     </div>
                     <div className="bg-white/5 p-4 rounded-xl">
                        <p className="text-text-secondary text-[10px] uppercase font-bold mb-1">Category</p>
                        <p className="text-white text-sm capitalize">{alert.type}</p>
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
              <button className="flex-1 bg-primary hover:bg-primary/80 text-white font-bold py-4 rounded-xl transition-all shadow-lg shadow-primary/20 flex items-center justify-center gap-2">
                <Icon name="share" />
                Broadcast Alert
              </button>
              <button 
                className={`px-6 border rounded-xl transition-all flex items-center justify-center gap-2 ${
                  isSaved 
                    ? 'bg-primary border-primary text-white shadow-lg shadow-primary/20' 
                    : 'border-white/10 hover:border-white/20 text-white'
                }`}
              >
                <Icon name={isSaved ? "bookmark" : "bookmark_border"} fill={isSaved} />
                {isSaved && <span className="font-bold text-sm hidden sm:inline">Saved</span>}
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
      <div className="relative overflow-hidden rounded-3xl bg-red-950/20 border border-red-500/20 p-8 flex flex-col md:flex-row items-center gap-6">
        <div className="w-20 h-20 rounded-2xl bg-red-500 flex items-center justify-center text-white shadow-2xl shadow-red-500/40">
           <Icon name="local_fire_department" className="text-4xl" />
        </div>
        <div>
          <span className="bg-red-500 text-white text-[10px] font-bold px-2 py-1 rounded-md uppercase mb-2 inline-block">High Severity Response</span>
          <h1 className="text-3xl md:text-4xl font-bold text-white tracking-tight">{this.props.alert.title}</h1>
        </div>
      </div>
    );
  }

  renderMetadata(): React.ReactNode {
    return (
      <React.Fragment>
        <div className="bg-white/5 p-4 rounded-xl">
          <p className="text-text-secondary text-[10px] uppercase font-bold mb-1">Response Tier</p>
          <p className="text-white text-sm">Level 2 (Alarm)</p>
        </div>
        <div className="bg-white/5 p-4 rounded-xl">
          <p className="text-text-secondary text-[10px] uppercase font-bold mb-1">Units Dispatched</p>
          <p className="text-white text-sm">4 Pumpers, 1 Aerial</p>
        </div>
      </React.Fragment>
    );
  }

  renderSpecializedContent(): React.ReactNode {
    return (
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div className="bg-surface-dark border border-white/5 rounded-2xl p-6">
          <h4 className="text-primary text-xs font-bold uppercase mb-4 flex items-center gap-2">
            <Icon name="map" className="text-sm" /> Location Map
          </h4>
          <div className="aspect-video bg-white/5 rounded-lg flex items-center justify-center border border-dashed border-white/10 overflow-hidden relative">
             <div className="absolute inset-0 bg-[radial-gradient(#ee711133_1px,transparent_1px)] [background-size:16px_16px] opacity-20"></div>
             <Icon name="location_on" className="text-primary text-4xl animate-bounce" />
             <span className="text-text-secondary text-xs absolute bottom-4">Interactive Map Loading...</span>
          </div>
        </div>
        <div className="bg-surface-dark border border-white/5 rounded-2xl p-6">
          <h4 className="text-primary text-xs font-bold uppercase mb-4 flex items-center gap-2">
            <Icon name="list_alt" className="text-sm" /> Scene Intel
          </h4>
          <ul className="space-y-3">
             {['Hydrant confirmed operational', 'Search of Floor 1 complete', 'Command established - Pumper 12'].map((intel, i) => (
               <li key={i} className="flex gap-3 text-sm text-gray-400">
                  <span className="text-red-500 font-bold">•</span>
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
      <div className="relative overflow-hidden rounded-3xl bg-blue-950/20 border border-blue-500/20 p-8 flex flex-col md:flex-row items-center gap-6">
        <div className="w-20 h-20 rounded-2xl bg-blue-600 flex items-center justify-center text-white shadow-2xl shadow-blue-500/40">
           <Icon name="local_police" className="text-4xl" />
        </div>
        <div>
          <span className="bg-blue-600 text-white text-[10px] font-bold px-2 py-1 rounded-md uppercase mb-2 inline-block">Tactical Operation</span>
          <h1 className="text-3xl md:text-4xl font-bold text-white tracking-tight">{this.props.alert.title}</h1>
        </div>
      </div>
    );
  }

  renderMetadata(): React.ReactNode {
    return (
      <React.Fragment>
        <div className="bg-white/5 p-4 rounded-xl">
          <p className="text-text-secondary text-[10px] uppercase font-bold mb-1">Divisional Unit</p>
          <p className="text-white text-sm">31 Division</p>
        </div>
        <div className="bg-white/5 p-4 rounded-xl">
          <p className="text-text-secondary text-[10px] uppercase font-bold mb-1">Status</p>
          <p className="text-white text-sm">On-Scene</p>
        </div>
      </React.Fragment>
    );
  }

  renderSpecializedContent(): React.ReactNode {
    return (
      <div className="bg-blue-500/5 border border-blue-500/20 rounded-2xl p-6">
        <h4 className="text-blue-400 text-xs font-bold uppercase mb-4 flex items-center gap-2">
          <Icon name="visibility" className="text-sm" /> Public Safety Advisory
        </h4>
        <div className="flex items-start gap-4 p-4 bg-blue-600/10 rounded-xl border border-blue-500/20">
           <Icon name="info" className="text-blue-400" />
           <p className="text-sm text-blue-100 font-medium">Police are currently conducting an investigation at this location. Perimeter is established. Traffic is being rerouted. Avoid the area until further notice.</p>
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
      <div className="relative overflow-hidden rounded-3xl bg-surface-dark border border-white/5 p-8 flex flex-col md:flex-row items-center gap-6">
        <div className={`w-20 h-20 rounded-2xl ${this.props.alert.accentColor} flex items-center justify-center text-white shadow-2xl`}>
           <Icon name={this.props.alert.iconName} className="text-4xl" />
        </div>
        <div>
          <span className="bg-white/10 text-white/60 text-[10px] font-bold px-2 py-1 rounded-md uppercase mb-2 inline-block">Service Notice</span>
          <h1 className="text-3xl md:text-4xl font-bold text-white tracking-tight">{this.props.alert.title}</h1>
        </div>
      </div>
    );
  }

  renderMetadata(): React.ReactNode {
    return (
      <React.Fragment>
        <div className="bg-white/5 p-4 rounded-xl">
          <p className="text-text-secondary text-[10px] uppercase font-bold mb-1">Alert Source</p>
          <p className="text-white text-sm">TTC Control</p>
        </div>
        <div className="bg-white/5 p-4 rounded-xl">
          <p className="text-text-secondary text-[10px] uppercase font-bold mb-1">Estimated Delay</p>
          <p className="text-white text-sm">20-30 mins</p>
        </div>
      </React.Fragment>
    );
  }

  renderSpecializedContent(): React.ReactNode {
    return (
      <div className="bg-white/5 border border-white/10 rounded-2xl p-6">
        <h4 className="text-text-secondary text-xs font-bold uppercase mb-4">Shuttle Bus Info</h4>
        <p className="text-white/60 text-sm">Board shuttle buses at street level. Follow staff instructions. Extra travel time is required.</p>
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
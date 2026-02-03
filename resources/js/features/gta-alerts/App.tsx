import React, { useState, useMemo } from 'react';
import { AlertDetailsView } from './components/AlertDetailsView';
import { BottomNav } from './components/BottomNav';
import { FeedView } from './components/FeedView';
import { Icon } from './components/Icon';
import { SavedView } from './components/SavedView';
import { SettingsView } from './components/SettingsView';
import { Sidebar } from './components/Sidebar';
import { ZonesView } from './components/ZonesView';
import { AlertService } from './services/AlertService';
import type { UnifiedAlertResource } from './types';

interface AppProps {
  alerts: {
    data: UnifiedAlertResource[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
  };
  filters: {
    status: 'all' | 'active' | 'cleared';
  };
  latestFeedUpdatedAt: string | null;
}

const App: React.FC<AppProps> = ({ alerts, filters, latestFeedUpdatedAt }) => {
  const [currentView, setCurrentView] = useState('feed');
  const [activeAlertId, setActiveAlertId] = useState<string | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  
  // Sidebar states
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false);
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);

  // Map backend unified alerts to frontend AlertItems
  const allAlerts = useMemo(() => {
    return alerts.data.map((a) => AlertService.mapUnifiedAlertToAlertItem(a));
  }, [alerts.data]);

  const pagination = useMemo(() => {
    const meta = alerts.meta as Record<string, unknown>;
    return {
      prevUrl: alerts.links?.prev ?? null,
      nextUrl: alerts.links?.next ?? null,
      currentPage: typeof meta.current_page === 'number' ? meta.current_page : null,
      lastPage: typeof meta.last_page === 'number' ? meta.last_page : null,
      total: typeof meta.total === 'number' ? meta.total : null,
    };
  }, [alerts.links, alerts.meta]);

  // Use the local alerts to find the active alert
  const activeAlert = useMemo(() => {
    return activeAlertId ? allAlerts.find(item => item.id === activeAlertId) : null;
  }, [activeAlertId, allAlerts]);

  const handleNavigate = (view: string) => {
    setCurrentView(view);
    setActiveAlertId(null); 
    setIsMobileMenuOpen(false); // Close mobile drawer on navigation
  };

  const renderView = () => {
    if (activeAlert) {
      return (
        <AlertDetailsView 
          alert={activeAlert} 
          onBack={() => setActiveAlertId(null)} 
        />
      );
    }

    switch (currentView) {
      case 'saved':
        return <SavedView onSelectAlert={setActiveAlertId} allAlerts={allAlerts} />;
      case 'zones':
        return <ZonesView />;
      case 'settings':
        return <SettingsView />;
      case 'feed':
      default:
        return (
          <FeedView 
            searchQuery={searchQuery} 
            onSelectAlert={setActiveAlertId} 
            allAlerts={allAlerts} 
            latestFeedUpdatedAt={latestFeedUpdatedAt}
            status={filters.status}
            pagination={pagination}
          />
        );
    }
  };

  const getBreadcrumbTitle = () => {
    if (activeAlert) return 'Alert Details';
    switch (currentView) {
      case 'saved': return 'Saved Alerts';
      case 'zones': return 'Active Zones';
      case 'settings': return 'Settings';
      default: return 'Live Feed';
    }
  };

  const getBreadcrumbIcon = () => {
    if (activeAlert) return 'info';
    switch (currentView) {
      case 'saved': return 'bookmark';
      case 'zones': return 'map';
      case 'settings': return 'settings';
      default: return 'dashboard';
    }
  };

  return (
    <div className="gta-alerts-theme bg-background-dark font-display text-white h-screen flex w-full overflow-hidden relative">
      
      {/* Mobile Sidebar Overlay/Backdrop */}
      {isMobileMenuOpen && (
        <div 
          className="fixed inset-0 bg-black/60 backdrop-blur-sm z-[90] md:hidden transition-opacity duration-300"
          onClick={() => setIsMobileMenuOpen(false)}
        />
      )}

      {/* Unified Sidebar (handles mobile drawer and desktop collapse) */}
      <Sidebar 
        currentView={activeAlert ? '' : currentView} 
        onNavigate={handleNavigate} 
        isCollapsed={isSidebarCollapsed}
        onToggleCollapse={() => setIsSidebarCollapsed(!isSidebarCollapsed)}
        isMobileOpen={isMobileMenuOpen}
        onCloseMobile={() => setIsMobileMenuOpen(false)}
      />

      <div className="flex-1 flex flex-col h-full relative min-w-0">
        <header className="flex-none z-50 glass-header border-b border-white/5 md:bg-background-dark/95 md:backdrop-blur-md">
            <div className="md:max-w-7xl md:mx-auto w-full">
                <div className="md:hidden px-4 pt-6 pb-2 flex justify-between items-center">
                  <div className="flex items-center gap-3">
                    <button 
                      onClick={() => setIsMobileMenuOpen(true)}
                      className="w-10 h-10 flex items-center justify-center text-white/80 hover:text-primary transition-colors bg-white/5 rounded-lg"
                    >
                      <Icon name="menu" style={{ fontSize: '24px' }} />
                    </button>
                    <h2 className="text-white text-xl font-bold leading-tight tracking-tight">GTA Alerts</h2>
                  </div>
                  <div className="flex gap-3">
                    <button onClick={() => handleNavigate('settings')} className="text-white/80 hover:text-primary transition-colors">
                      <Icon name="notifications" style={{ fontSize: '24px' }} />
                    </button>
                    <button onClick={() => handleNavigate('settings')} className="text-white/80 hover:text-primary transition-colors">
                       <Icon name="settings" style={{ fontSize: '24px' }} />
                    </button>
                  </div>
                </div>

                <div className="px-4 pb-4 pt-1 md:py-4 flex gap-4 items-center">
                    <div className="hidden md:flex items-center text-text-secondary text-sm mr-4 min-w-max">
                        <Icon name={getBreadcrumbIcon()} className="mr-2 text-primary" />
                        <span className="font-medium text-white">{getBreadcrumbTitle()}</span>
                        <Icon name="chevron_right" className="mx-2 text-white/20 text-lg" />
                        <span className="opacity-60">Greater Toronto Area</span>
                    </div>

                    {!activeAlert && (
                      <label className="flex flex-col w-full max-w-2xl">
                          <div className="flex w-full flex-1 items-stretch rounded-lg h-10 md:h-11 relative group transition-all">
                            <div className="absolute left-0 top-0 h-full flex items-center pl-4 pointer-events-none z-10">
                              <span className="text-text-secondary group-focus-within:text-primary transition-colors">
                                 <Icon name="search" />
                              </span>
                            </div>
                            <input 
                              className="flex w-full min-w-0 flex-1 resize-none overflow-hidden rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-primary/50 border border-transparent focus:border-primary/50 bg-input-dark h-full placeholder:text-text-secondary px-4 pl-12 text-sm md:text-base font-normal leading-normal transition-all shadow-inner"
                              placeholder="Search alerts, streets, or categories..." 
                              value={searchQuery}
                              onChange={(e) => setSearchQuery(e.target.value)}
                            />
                          </div>
                      </label>
                    )}

                    <div className="hidden md:flex ml-auto gap-3 items-center">
                        <div className="h-6 w-px bg-white/10 mx-1"></div>
                        <button className="w-10 h-10 rounded-full bg-surface-dark border border-white/5 flex items-center justify-center text-text-secondary hover:text-white hover:border-primary/30 hover:bg-white/5 transition-all relative">
                            <Icon name="notifications" />
                            <span className="absolute top-2 right-2.5 w-2 h-2 bg-amber rounded-full border-2 border-surface-dark"></span>
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <main className="flex-1 overflow-y-auto no-scrollbar pb-6 relative p-0 scroll-smooth">
          <div className="md:max-w-7xl md:mx-auto w-full h-full">
            {renderView()}
          </div>
        </main>

        <BottomNav currentView={activeAlert ? '' : currentView} onNavigate={handleNavigate} />
      </div>
    </div>
  );
};

export default App;

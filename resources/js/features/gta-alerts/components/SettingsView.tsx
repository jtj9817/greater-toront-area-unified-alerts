import React from 'react';
import { Icon } from './Icon';

const SettingItem: React.FC<{ icon: string; title: string; desc: string; toggled?: boolean }> = ({ icon, title, desc, toggled = false }) => (
  <div className="flex items-center justify-between p-4 bg-surface-dark rounded-lg border border-white/5 hover:border-white/10 transition-colors">
    <div className="flex items-start gap-4">
      <div className="w-10 h-10 rounded-full bg-white/5 flex items-center justify-center text-text-secondary mt-1">
        <Icon name={icon} />
      </div>
      <div>
        <h4 className="text-white font-medium mb-1">{title}</h4>
        <p className="text-text-secondary text-xs max-w-xs">{desc}</p>
      </div>
    </div>
    <div className={`w-12 h-6 rounded-full relative transition-colors cursor-pointer ${toggled ? 'bg-primary' : 'bg-white/10'}`}>
      <div className={`absolute top-1 w-4 h-4 rounded-full bg-white transition-all shadow-md ${toggled ? 'left-7' : 'left-1'}`}></div>
    </div>
  </div>
);

export const SettingsView: React.FC = () => {
  return (
    <div className="p-4 md:p-6 max-w-4xl mx-auto w-full">
      <div className="mb-8 border-b border-white/10 pb-6">
        <h2 className="text-2xl font-bold text-white mb-2 flex items-center gap-3">
          <Icon name="settings" className="text-primary" />
          System Settings
        </h2>
        <p className="text-text-secondary text-sm">Manage your notifications and dashboard preferences.</p>
      </div>

      <div className="space-y-8">
        <section>
          <h3 className="text-primary text-xs font-bold uppercase tracking-wider mb-4 px-1">Notifications</h3>
          <div className="space-y-3">
            <SettingItem 
              icon="notifications_active" 
              title="Push Notifications" 
              desc="Receive real-time alerts for high-severity incidents in your zones."
              toggled={true}
            />
            <SettingItem 
              icon="mail" 
              title="Email Digests" 
              desc="Daily summary of major incidents delivered to your inbox."
              toggled={false}
            />
          </div>
        </section>

        <section>
          <h3 className="text-primary text-xs font-bold uppercase tracking-wider mb-4 px-1">Display</h3>
          <div className="space-y-3">
            <SettingItem 
              icon="dark_mode" 
              title="Dark Mode" 
              desc="Use high-contrast dark theme for night visibility."
              toggled={true}
            />
            <SettingItem 
              icon="map" 
              title="Live Map Overlay" 
              desc="Show incident locations on the background map."
              toggled={true}
            />
          </div>
        </section>
        
        <div className="pt-6">
            <button className="w-full md:w-auto px-6 py-3 bg-white/5 hover:bg-white/10 text-white rounded-lg border border-white/10 text-sm font-medium transition-colors flex items-center justify-center gap-2">
                <Icon name="logout" className="text-coral" />
                Sign Out
            </button>
        </div>
      </div>
    </div>
  );
};
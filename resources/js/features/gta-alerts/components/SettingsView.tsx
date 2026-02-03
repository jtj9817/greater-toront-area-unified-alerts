import React from 'react';
import { Icon } from './Icon';

const SettingItem: React.FC<{
    icon: string;
    title: string;
    desc: string;
    toggled?: boolean;
}> = ({ icon, title, desc, toggled = false }) => (
    <div className="flex items-center justify-between rounded-lg border border-white/5 bg-surface-dark p-4 transition-colors hover:border-white/10">
        <div className="flex items-start gap-4">
            <div className="mt-1 flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-text-secondary">
                <Icon name={icon} />
            </div>
            <div>
                <h4 className="mb-1 font-medium text-white">{title}</h4>
                <p className="max-w-xs text-xs text-text-secondary">{desc}</p>
            </div>
        </div>
        <div
            className={`relative h-6 w-12 cursor-pointer rounded-full transition-colors ${toggled ? 'bg-primary' : 'bg-white/10'}`}
        >
            <div
                className={`absolute top-1 h-4 w-4 rounded-full bg-white shadow-md transition-all ${toggled ? 'left-7' : 'left-1'}`}
            ></div>
        </div>
    </div>
);

export const SettingsView: React.FC = () => {
    return (
        <div className="mx-auto w-full max-w-4xl p-4 md:p-6">
            <div className="mb-8 border-b border-white/10 pb-6">
                <h2 className="mb-2 flex items-center gap-3 text-2xl font-bold text-white">
                    <Icon name="settings" className="text-primary" />
                    System Settings
                </h2>
                <p className="text-sm text-text-secondary">
                    Manage your notifications and dashboard preferences.
                </p>
            </div>

            <div className="space-y-8">
                <section>
                    <h3 className="mb-4 px-1 text-xs font-bold tracking-wider text-primary uppercase">
                        Notifications
                    </h3>
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
                    <h3 className="mb-4 px-1 text-xs font-bold tracking-wider text-primary uppercase">
                        Display
                    </h3>
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
                    <button className="flex w-full items-center justify-center gap-2 rounded-lg border border-white/10 bg-white/5 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-white/10 md:w-auto">
                        <Icon name="logout" className="text-coral" />
                        Sign Out
                    </button>
                </div>
            </div>
        </div>
    );
};

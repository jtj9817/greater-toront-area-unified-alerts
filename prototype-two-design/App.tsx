/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import { useState } from 'react';

export default function App() {
  const [viewMode, setViewMode] = useState<'feed' | 'table'>('table');

  return (
    <div className="relative flex h-screen w-full overflow-hidden bg-background-dark text-white font-sans">
      {/* Sidebar */}
      <aside className="w-64 flex flex-col border-r border-[#333333] bg-black shrink-0">
        <div className="p-6 border-b border-[#333333] flex items-center gap-3">
          <div className="p-2 bg-primary rounded-none text-black">
            <span className="material-symbols-outlined block text-2xl font-bold">local_fire_department</span>
          </div>
          <h1 className="text-xl font-black text-white tracking-tighter">GTA ALERTS</h1>
        </div>
        <nav className="flex-1 p-4 flex flex-col gap-2">
          <a className="flex items-center gap-3 px-3 py-3 bg-primary text-black font-black uppercase text-xs" href="#">
            <span className="material-symbols-outlined text-[22px] fill-1">feed</span>
            <span>Feed</span>
          </a>
          <a className="flex items-center gap-3 px-3 py-3 text-white hover:bg-[#333333] transition-colors font-bold uppercase text-xs" href="#">
            <span className="material-symbols-outlined text-[22px]">inbox</span>
            <span>Inbox</span>
          </a>
          <a className="flex items-center gap-3 px-3 py-3 text-white hover:bg-[#333333] transition-colors font-bold uppercase text-xs" href="#">
            <span className="material-symbols-outlined text-[22px]">bookmark</span>
            <span>Saved</span>
          </a>
          <a className="flex items-center gap-3 px-3 py-3 text-white hover:bg-[#333333] transition-colors font-bold uppercase text-xs" href="#">
            <span className="material-symbols-outlined text-[22px]">map</span>
            <span>Zones</span>
          </a>
          <a className="flex items-center gap-3 px-3 py-3 text-white hover:bg-[#333333] transition-colors font-bold uppercase text-xs" href="#">
            <span className="material-symbols-outlined text-[22px]">settings</span>
            <span>Settings</span>
          </a>
        </nav>
      </aside>

      {/* Main Content */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Header */}
        <header className="h-16 bg-black border-b border-[#333333] flex items-center justify-between px-8 shrink-0">
          <div className="flex items-center gap-6 flex-1">
            <div className="relative max-w-xl w-full">
              <span className="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-primary text-xl">search</span>
              <input className="w-full pl-10 pr-4 py-2 bg-[#1A1A1A] border border-[#333333] focus:border-primary rounded-none focus:outline-none text-sm text-white placeholder-gray-500 uppercase font-bold" placeholder="Search incidents..." type="text" />
            </div>
          </div>
          <div className="flex items-center gap-4 pl-4 ml-4">
            <button className="relative p-2 text-white hover:bg-primary hover:text-black">
              <span className="material-symbols-outlined">notifications</span>
              <span className="absolute top-2 right-2 w-2 h-2 bg-critical border-2 border-black"></span>
            </button>
            <button className="relative p-2 text-white hover:bg-primary hover:text-black ml-2">
              <span className="material-symbols-outlined">person</span>
            </button>
          </div>
        </header>

        {/* Main Area */}
        <main className="flex-1 overflow-y-auto bg-background-dark p-8 custom-scrollbar">
          <div className="max-w-6xl mx-auto">
            <div className="flex items-end justify-between mb-8 border-b-4 border-white pb-6">
              <div>
                <div className="flex items-center gap-2 text-warning font-black text-xs uppercase tracking-widest mb-2">
                  <span className="material-symbols-outlined text-sm">public</span>
                  Public Alert Feed • High Visibility Text Mode
                </div>
                <h2 className="text-5xl font-black text-white tracking-tighter leading-none uppercase italic">Fire Alerts Feed</h2>
                <p className="text-gray-400 mt-2 font-bold uppercase text-sm">Real-time incident stream // Greater Toronto Area</p>
              </div>
              <div className="flex gap-3">
                <div className="flex bg-[#1A1A1A] border-2 border-black p-1">
                  <button 
                    onClick={() => setViewMode('feed')}
                    className={`flex items-center gap-2 px-4 py-2 text-xs font-black uppercase transition-colors ${viewMode === 'feed' ? 'bg-primary text-black' : 'text-white hover:text-primary'}`}
                  >
                    <span className="material-symbols-outlined text-lg">view_agenda</span>
                    Feed
                  </button>
                  <button 
                    onClick={() => setViewMode('table')}
                    className={`flex items-center gap-2 px-4 py-2 text-xs font-black uppercase transition-colors ${viewMode === 'table' ? 'bg-primary text-black' : 'text-white hover:text-primary'}`}
                  >
                    <span className="material-symbols-outlined text-lg">table_rows</span>
                    Table
                  </button>
                </div>
                <button className="flex items-center gap-2 bg-white text-black px-6 py-3 text-xs font-black uppercase brutalist-border panel-shadow active:translate-x-1 active:translate-y-1 active:shadow-none transition-all cursor-pointer">
                  <span className="material-symbols-outlined text-lg">filter_list</span>
                  Filter Feed
                </button>
              </div>
            </div>

            {viewMode === 'table' ? (
              <div className="overflow-hidden border-4 border-black panel-shadow">
                <table className="w-full incident-table border-collapse">
                  <thead>
                    <tr>
                      <th>Timestamp</th>
                      <th>Incident Type</th>
                      <th>Location</th>
                      <th>Status</th>
                      <th>Severity</th>
                      <th>Dispatched Units</th>
                      <th className="w-10"></th>
                    </tr>
                  </thead>
                  <tbody className="bg-black">
                    <tr className="expandable-row bg-panel-light text-black">
                      <td className="font-black">12:45 PM</td>
                      <td className="uppercase tracking-tighter text-base">3-Alarm Commercial Structure Fire</td>
                      <td className="underline decoration-2 decoration-primary">456 King Street West, Toronto</td>
                      <td>
                        <span className="bg-black text-primary px-2 py-1 text-[10px] font-black uppercase">Active</span>
                      </td>
                      <td>
                        <span className="bg-critical text-white px-3 py-1 text-[10px] font-black uppercase border border-black">Critical Severity</span>
                      </td>
                      <td>
                        <span className="flex items-center gap-1"><span className="material-symbols-outlined text-sm">fire_truck</span> 12 Units</span>
                      </td>
                      <td><span className="material-symbols-outlined">expand_less</span></td>
                    </tr>
                    <tr className="bg-panel-light text-black">
                      <td className="p-0 border-b-4 border-black" colSpan={7}>
                        <div className="bg-[#F0F0F0] p-6 m-4 border-l-[12px] border-critical">
                          <p className="mb-3 font-black text-critical tracking-widest uppercase text-xs">INCIDENT SUMMARY:</p>
                          <p className="text-base font-bold leading-relaxed">Heavy black smoke visible from second-floor windows of a mixed-use commercial building. Primary search currently in progress. Toronto Fire Services incident #TF-2023-0892. Commander reports defensive operations on side Charlie. Hydro and Enbridge notified for emergency shutoffs. Avoid area due to complete road closures on King St between Spadina and Portland. Emergency perimeter established within 2 blocks.</p>
                          <div className="mt-4 flex gap-4">
                            <span className="flex items-center gap-2 bg-warning px-3 py-1 border-2 border-black text-[10px] font-black uppercase"><span className="material-symbols-outlined text-base">person</span> Rescue In Progress</span>
                          </div>
                        </div>
                      </td>
                    </tr>
                    <tr className="expandable-row group">
                      <td className="text-gray-400 group-hover:text-black">11:15 AM</td>
                      <td className="uppercase tracking-tighter">Vehicle Fire - Highway 401 Eastbound</td>
                      <td>Hwy 401 EB at Mavis Rd, Mississauga</td>
                      <td>
                        <span className="bg-gray-700 text-gray-400 group-hover:bg-gray-200 group-hover:text-black px-2 py-1 text-[10px] font-black uppercase">Cleared</span>
                      </td>
                      <td>
                        <span className="bg-warning text-black px-3 py-1 text-[10px] font-black uppercase border border-black">Medium Priority</span>
                      </td>
                      <td>
                        <span className="flex items-center gap-1"><span className="material-symbols-outlined text-sm">fire_truck</span> 3 Units</span>
                      </td>
                      <td><span className="material-symbols-outlined">expand_more</span></td>
                    </tr>
                    <tr className="expandable-row group">
                      <td>10:40 AM</td>
                      <td className="uppercase tracking-tighter">Residential Structure Fire</td>
                      <td>22 Heritage Way, Markham, ON</td>
                      <td>
                        <span className="bg-black text-primary px-2 py-1 text-[10px] font-black uppercase">Active</span>
                      </td>
                      <td>
                        <span className="bg-critical text-white px-3 py-1 text-[10px] font-black uppercase border border-black">High Severity</span>
                      </td>
                      <td>
                        <span className="flex items-center gap-1"><span className="material-symbols-outlined text-sm">fire_truck</span> 8 Units</span>
                      </td>
                      <td><span className="material-symbols-outlined">expand_more</span></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="space-y-6">
                {/* Alert 1 */}
                <div className="bg-panel-light p-8 flex gap-8 items-start border-4 border-black panel-shadow">
                  <div className="flex flex-col items-center shrink-0 w-24 border-r-2 border-black pr-4">
                    <span className="text-lg font-black text-black">12:45 PM</span>
                    <div className="h-1 w-full bg-black my-2"></div>
                    <span className="text-xs font-black text-primary bg-black px-2 py-1 uppercase tracking-tighter">Active</span>
                  </div>
                  <div className="flex-1">
                    <div className="flex flex-wrap items-center justify-between gap-4 mb-4">
                      <div className="flex items-center gap-4">
                        <h3 className="text-3xl font-black text-black uppercase tracking-tighter">3-Alarm Commercial Structure Fire</h3>
                        <span className="px-4 py-2 bg-critical text-white text-[11px] font-black uppercase tracking-[0.2em] border-2 border-black">Critical Severity</span>
                      </div>
                      <div className="flex items-center gap-4 text-[10px] font-black text-black uppercase">
                        <span className="flex items-center gap-2 bg-gray-100 px-3 py-2 border-2 border-black"><span className="material-symbols-outlined text-base">fire_truck</span> 12 Units Dispatched</span>
                        <span className="flex items-center gap-2 bg-warning px-3 py-2 border-2 border-black"><span className="material-symbols-outlined text-base">person</span> Rescue In Progress</span>
                      </div>
                    </div>
                    <div className="flex items-center gap-2 text-lg font-black text-black mb-6">
                      <span className="material-symbols-outlined text-primary font-bold">location_on</span>
                      <span className="underline decoration-4 decoration-primary">456 King Street West, Toronto, ON M5V 1K4 (King &amp; Spadina)</span>
                    </div>
                    <div className="text-base text-black font-medium leading-relaxed bg-[#F0F0F0] p-6 border-l-[12px] border-critical">
                      <p className="mb-3 font-black text-critical tracking-widest uppercase">INCIDENT SUMMARY:</p>
                      <p className="text-lg font-bold">Heavy black smoke visible from second-floor windows of a mixed-use commercial building. Primary search currently in progress. Toronto Fire Services incident #TF-2023-0892. Commander reports defensive operations on side Charlie. Hydro and Enbridge notified for emergency shutoffs. Avoid area due to complete road closures on King St between Spadina and Portland. Emergency perimeter established within 2 blocks.</p>
                    </div>
                  </div>
                </div>

                {/* Alert 2 */}
                <div className="bg-panel-light p-8 flex gap-8 items-start border-4 border-black panel-shadow opacity-90">
                  <div className="flex flex-col items-center shrink-0 w-24 border-r-2 border-black pr-4 grayscale">
                    <span className="text-lg font-black text-gray-500">11:15 AM</span>
                    <div className="h-1 w-full bg-gray-300 my-2"></div>
                    <span className="text-xs font-black text-white bg-gray-500 px-2 py-1 uppercase tracking-tighter">Cleared</span>
                  </div>
                  <div className="flex-1">
                    <div className="flex flex-wrap items-center justify-between gap-4 mb-4">
                      <div className="flex items-center gap-4">
                        <h3 className="text-3xl font-black text-black uppercase tracking-tighter">Vehicle Fire - Highway 401 Eastbound</h3>
                        <span className="px-4 py-2 bg-warning text-black text-[11px] font-black uppercase tracking-[0.2em] border-2 border-black">Medium Priority</span>
                      </div>
                      <div className="flex items-center gap-4 text-[10px] font-black text-black uppercase">
                        <span className="flex items-center gap-2 bg-gray-100 px-3 py-2 border-2 border-black"><span className="material-symbols-outlined text-base">fire_truck</span> 3 Units</span>
                      </div>
                    </div>
                    <div className="flex items-center gap-2 text-lg font-black text-black mb-6">
                      <span className="material-symbols-outlined text-gray-400">location_on</span>
                      <span>Hwy 401 EB at Mavis Rd, Mississauga (Express Lanes)</span>
                    </div>
                    <div className="text-base text-black font-medium leading-relaxed bg-[#F0F0F0] p-6 border-l-[12px] border-warning">
                      Peel Fire crews responded to a single passenger vehicle fire on the right shoulder. Fire fully extinguished at 11:32 AM. No injuries reported to occupants or responders. MOP overseeing cleanup of fluids. Right two lanes currently blocked for towing operations. Expect delays from Winston Churchill Blvd.
                    </div>
                  </div>
                </div>

                {/* Alert 3 */}
                <div className="bg-panel-light p-8 flex gap-8 items-start border-4 border-black panel-shadow">
                  <div className="flex flex-col items-center shrink-0 w-24 border-r-2 border-black pr-4">
                    <span className="text-lg font-black text-black">10:40 AM</span>
                    <div className="h-1 w-full bg-black my-2"></div>
                    <span className="text-xs font-black text-primary bg-black px-2 py-1 uppercase tracking-tighter">Active</span>
                  </div>
                  <div className="flex-1">
                    <div className="flex flex-wrap items-center justify-between gap-4 mb-4">
                      <div className="flex items-center gap-4">
                        <h3 className="text-3xl font-black text-black uppercase tracking-tighter">Residential Structure Fire</h3>
                        <span className="px-4 py-2 bg-critical text-white text-[11px] font-black uppercase tracking-[0.2em] border-2 border-black">High Severity</span>
                      </div>
                      <div className="flex items-center gap-4 text-[10px] font-black text-black uppercase">
                        <span className="flex items-center gap-2 bg-gray-100 px-3 py-2 border-2 border-black"><span className="material-symbols-outlined text-base">fire_truck</span> 8 Units On Scene</span>
                      </div>
                    </div>
                    <div className="flex items-center gap-2 text-lg font-black text-black mb-6">
                      <span className="material-symbols-outlined text-primary">location_on</span>
                      <span>22 Heritage Way, Markham, ON (York Region)</span>
                    </div>
                    <div className="text-base text-black font-medium leading-relaxed bg-[#F0F0F0] p-6 border-l-[12px] border-critical">
                      <p className="mb-3 font-black uppercase text-black">TACTICAL REPORT:</p>
                      York Regional Fire on scene of a detached single family dwelling. Working fire confirmed in the basement with extension into the first floor. Aerial ladder 12 deployed for exposure protection on side Delta. Interior crews conducting aggressive fire attack. Power disconnected by Alectra utility crew. All occupants accounted for at this time. Incident commander requesting secondary hydrant hookup.
                    </div>
                  </div>
                </div>
              </div>
            )}
          </div>
        </main>

        {/* Footer */}
        <footer className="h-12 bg-black border-t-4 border-primary px-8 flex items-center justify-between text-[11px] font-black text-white uppercase tracking-widest shrink-0">
          <div className="flex gap-8">
            <span className="flex items-center gap-2">
              <span className="material-symbols-outlined text-xs">thermostat</span>
              Temp: 24°C | Humidity: 65% | Wind: 15km/h W
            </span>
          </div>
          <div className="flex gap-6">
            <a className="hover:text-primary transition-colors border-b border-transparent hover:border-primary" href="#">Incident Archives</a>
            <a className="hover:text-primary transition-colors border-b border-transparent hover:border-primary" href="#">Privacy Policy</a>
            <a className="hover:text-primary transition-colors border-b border-transparent hover:border-primary" href="#">System Status</a>
          </div>
        </footer>
      </div>

      {/* Floating Action Button */}
      <button className="fixed bottom-16 right-8 size-16 bg-primary text-black border-4 border-black panel-shadow flex items-center justify-center transition-all hover:-translate-y-1 hover:-translate-x-1 active:translate-x-0 active:translate-y-0 active:shadow-none z-50 cursor-pointer">
        <span className="material-symbols-outlined text-3xl font-black">refresh</span>
      </button>
    </div>
  );
}

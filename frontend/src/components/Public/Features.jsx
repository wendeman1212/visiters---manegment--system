import React from 'react';
import Layout from '../Shared/Layout';
import AppIcon from '../../config/iconMap';
import Reveal from '../Shared/Reveal';

export default function Features() {
    return (
        <Layout title="System Features">
            <section className="ai-page">
                <div className="ai-glow ai-glow-orange" style={{ width: '460px', height: '460px', top: '-120px', left: '-100px' }} />
                <div className="ai-glow ai-glow-blue" style={{ width: '520px', height: '520px', bottom: '-160px', right: '-120px' }} />

                <div className="relative z-10 w-full max-w-7xl">
                    <Reveal className="text-center">
                        <div>
                            <span className="ai-pill">Capabilities</span>
                            <h2 className="ai-title" style={{ color: '#ff9933' }}>VMS Feature Portfolio</h2>
                        </div>
                    </Reveal>

                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2 md:gap-8">
                        <Reveal delay={0} className="h-full">
                            <div className="ai-card h-full">
                                <div className="inline-flex rounded-2xl border border-cyan-400/30 bg-cyan-500/10 text-cyan-400 shadow-[0_0_30px_-8px_rgba(6,182,212,0.6)]">
                                    <AppIcon name="shield" size={24} />
                                </div>
                                <h3 className="text-lg font-bold" style={{ color: '#22d3ee' }}>Validation Gate</h3>
                                <p className="text-sm text-slate-400">
                                    Runs automatic checks on visitor document format, overlapping slots, weekend block policies, and host availability before requests route to employees.
                                </p>
                            </div>
                        </Reveal>

                        <Reveal delay={100} className="h-full">
                            <div className="ai-card h-full">
                                <div className="inline-flex rounded-2xl border border-blue-400/30 bg-blue-500/10 text-blue-400 shadow-[0_0_30px_-8px_rgba(59,130,246,0.6)]">
                                    <AppIcon name="check" size={24} />
                                </div>
                                <h3 className="text-lg font-bold" style={{ color: '#60a5fa' }}>Single-Use Cryptographic QR</h3>
                                <p className="text-sm text-slate-400">
                                    QR tokens are generated instantly on host approval, automatically expired at checkout or end-of-visit windows, and prevented from replaying or copying.
                                </p>
                            </div>
                        </Reveal>

                        <Reveal delay={200} className="h-full">
                            <div className="ai-card h-full">
                                <div className="inline-flex rounded-2xl border border-purple-400/30 bg-purple-500/10 text-purple-400 shadow-[0_0_30px_-8px_rgba(139,92,246,0.6)]">
                                    <AppIcon name="zap" size={24} />
                                </div>
                                <h3 className="text-lg font-bold" style={{ color: '#a78bfa' }}>Department Load Limiting</h3>
                                <p className="text-sm text-slate-400">
                                    Protects corporate campuses from overcrowding. Restricts simultaneous visit checks when a department exceeds its customized occupancy limit.
                                </p>
                            </div>
                        </Reveal>

                        <Reveal delay={300} className="h-full">
                            <div className="ai-card h-full">
                                <div className="inline-flex rounded-2xl border border-emerald-400/30 bg-emerald-500/10 text-emerald-400 shadow-[0_0_30px_-8px_rgba(16,185,129,0.6)]">
                                    <AppIcon name="lock" size={24} />
                                </div>
                                <h3 className="text-lg font-bold" style={{ color: '#34d399' }}>Audit Log</h3>
                                <p className="text-sm text-slate-400">
                                    Maintains an immutable, timestamped record of logins, logouts, blacklist edits, checks, escalations, and approvals for compliance reviews.
                                </p>
                            </div>
                        </Reveal>
                    </div>
                </div>
            </section>
        </Layout>
    );
}

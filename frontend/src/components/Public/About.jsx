import React from 'react';
import Layout from '../Shared/Layout';
import Reveal from '../Shared/Reveal';

export default function About() {
    return (
        <Layout title="About Us">
            <section className="ai-page">
                <div className="ai-glow ai-glow-blue" style={{ width: '460px', height: '460px', top: '-140px', right: '-100px' }} />
                <div className="ai-glow ai-glow-orange" style={{ width: '400px', height: '400px', bottom: '-120px', left: '-80px' }} />

                <div className="relative z-10 w-full max-w-7xl">
                    <Reveal direction="left">
                        <span className="ai-pill ai-pill-blue">About the Platform</span>
                        <h2 className="ai-title ai-special-title-4">System Overview & Vision</h2>
                        <p className="ai-sub text-base leading-relaxed">
                            The Advanced Visitor Management System (VMS) is an enterprise solution designed to secure organization entryways, audit visitor logs, and eliminate manual front desk inefficiencies.
                        </p>
                    </Reveal>

                    <Reveal delay={120}>
                        <div className="ai-card">
                            <h3 className="text-xl font-bold text-cyan-400">Solving Modern Workspace Challenges</h3>
                            <p className="text-sm leading-relaxed text-slate-400">
                                Corporate campuses, hospitals, government agencies, and industrial facilities all share a common challenge: let legitimate guests in quickly while keeping unauthorized people out, and maintain an auditable footprint of who was on-site. The VMS replaces outdated paper logs and ad-hoc call lists with a unified, role-based workflow platform.
                            </p>
                        </div>
                    </Reveal>

                    <Reveal delay={100}>
                        <div>
                            <span className="ai-pill">Roles</span>
                            <h3 className="ai-title text-2xl text-amber-400">Role Governance Matrix</h3>
                            <p className="ai-sub text-sm">
                                Our system enforces clear access rights across six defined stakeholder groups:
                            </p>
                        </div>
                    </Reveal>

                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2 md:gap-8">
                        <Reveal delay={0} className="h-full">
                            <div className="ai-card h-full">
                                <div className="text-sm font-bold text-cyan-400">CEO</div>
                                <p className="text-sm leading-relaxed text-slate-400">Enterprise-wide reporting, department load statistics, emergency lockdowns.</p>
                            </div>
                        </Reveal>
                        <Reveal delay={80} className="h-full">
                            <div className="ai-card h-full">
                                <div className="text-sm font-bold text-blue-400">Admin</div>
                                <p className="text-sm leading-relaxed text-slate-400">System configuration, departments setup, employee approvals, blacklist records management.</p>
                            </div>
                        </Reveal>
                        <Reveal delay={160} className="h-full">
                            <div className="ai-card h-full">
                                <div className="text-sm font-bold text-purple-400">System Admin</div>
                                <p className="text-sm leading-relaxed text-slate-400">Platform-wide oversight, resolves escalations when hosts are unresponsive.</p>
                            </div>
                        </Reveal>
                        <Reveal delay={240} className="h-full">
                            <div className="ai-card h-full">
                                <div className="text-sm font-bold text-emerald-400">Host (Employee)</div>
                                <p className="text-sm leading-relaxed text-slate-400">Submits invites, reviews incoming visitor requests, meets guests.</p>
                            </div>
                        </Reveal>
                        <Reveal delay={320} className="h-full">
                            <div className="ai-card h-full">
                                <div className="text-sm font-bold text-amber-400">Security Guard</div>
                                <p className="text-sm leading-relaxed text-slate-400">Scan-time validation of QR tokens, checks visitor IDs, triggers emergency alerts.</p>
                            </div>
                        </Reveal>
                        <Reveal delay={400} className="h-full">
                            <div className="ai-card h-full">
                                <div className="text-sm font-bold text-rose-400">Visitor</div>
                                <p className="text-sm leading-relaxed text-slate-400">Registers profile, schedules appointments, downloads QR tokens, logs out and leaves feedback.</p>
                            </div>
                        </Reveal>
                    </div>
                </div>
            </section>
        </Layout>
    );
}

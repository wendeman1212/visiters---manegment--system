import React from 'react';
import Layout from '../Shared/Layout';
import Reveal from '../Shared/Reveal';

export default function VisitorGuidelines() {
    return (
        <Layout title="Visitor Guidelines">
            <section className="ai-page">
                <div className="ai-glow ai-glow-blue" style={{ width: '440px', height: '440px', top: '-120px', right: '-90px' }} />
                <div className="ai-glow ai-glow-orange" style={{ width: '420px', height: '420px', bottom: '-140px', left: '-100px' }} />

                <div className="relative z-10 w-full max-w-4xl">
                    <Reveal direction="left">
                        <span className="ai-pill">Entry Policy</span>
                        <h2 className="ai-title">Visitor Rules &amp; Entry Guidelines</h2>
                    </Reveal>

                    <div className="flex flex-col gap-6">
                        <Reveal>
                            <p className="ai-sub text-base">
                                To ensure organizational safety, security, and auditable governance, all visitors must review and adhere to the guidelines below before arriving on-site.
                            </p>
                        </Reveal>

                        <Reveal delay={80}>
                            <div className="ai-card">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-cyan-400/40 bg-cyan-500/10 text-sm font-extrabold text-cyan-400">1</span>
                                    <h3 className="text-lg font-bold text-cyan-400">Pre-Registration is Mandatory</h3>
                                </div>
                                <p className="text-sm text-slate-400">
                                    Walk-in entries are not permitted under Phase 1 guidelines. All visitors must submit an entry request via our public request portal and receive a validated QR token before arrival.
                                </p>
                            </div>
                        </Reveal>

                        <Reveal delay={160}>
                            <div className="ai-card">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-blue-400/40 bg-blue-500/10 text-sm font-extrabold text-blue-400">2</span>
                                    <h3 className="text-lg font-bold text-blue-400">Bring Valid Physical ID</h3>
                                </div>
                                <p className="text-sm text-slate-400">
                                    When arriving at the front desk, Security Guards will scan your QR code and manually cross-reference your name and physical ID document (national ID or passport) against the registered VMS record.
                                </p>
                            </div>
                        </Reveal>

                        <Reveal delay={240}>
                            <div className="ai-card">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-purple-400/40 bg-purple-500/10 text-sm font-extrabold text-purple-400">3</span>
                                    <h3 className="text-lg font-bold text-purple-400">Keep Your QR Secure</h3>
                                </div>
                                <p className="text-sm text-slate-400">
                                    QR tokens are single-use, time-bound, and cryptographically signed. Do not share, copy, or screenshot your QR token. Re-scanning a token that has already been validated will immediately trigger a security alert.
                                </p>
                            </div>
                        </Reveal>

                        <Reveal delay={320}>
                            <div className="ai-card">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-emerald-400/40 bg-emerald-500/10 text-sm font-extrabold text-emerald-400">4</span>
                                    <h3 className="text-lg font-bold text-emerald-400">Check-Out on Departure</h3>
                                </div>
                                <p className="text-sm text-slate-400">
                                    All visitors must checkout at the security gate before leaving the premises. This updates active logs, ensures building evacuation roll-calls are accurate in an emergency, and releases department capacity slots.
                                </p>
                            </div>
                        </Reveal>
                    </div>
                </div>
            </section>
        </Layout>
    );
}

import React from 'react';
import Layout from '../Shared/Layout';
import { useLang } from '../../LangContext';
import { t } from '../../i18n';
import AppIcon from '../../config/iconMap';
import Reveal from '../Shared/Reveal';
import { FaFacebookF, FaXTwitter, FaLinkedinIn, FaYoutube, FaTiktok, FaTelegram } from 'react-icons/fa6';
import topBanner from '../../../resources/images/background.png';

const SOCIALS = [
    { name: 'Facebook', href: 'https://web.facebook.com/profile.php?id=61554955861892', Icon: FaFacebookF, color: '#1877F2' },
    { name: 'X', href: 'https://x.com/EthiopianP74117', Icon: FaXTwitter, color: '#ffffff' },
    { name: 'LinkedIn', href: 'https://www.linkedin.com/company/ethiopianitpark/', Icon: FaLinkedinIn, color: '#0A66C2' },
    { name: 'YouTube', href: 'https://www.youtube.com/@EthiopianITParkOfficial', Icon: FaYoutube, color: '#FF0000' },
    { name: 'TikTok', href: 'https://www.tiktok.com/@ethiopianitpark1', Icon: FaTiktok, color: '#ffffff' },
    { name: 'Telegram', href: 'https://t.me/EthiopianItPark', Icon: FaTelegram, color: '#229ED9' },
];

export default function Home() {
    const { lang } = useLang();
    return (
        <Layout title="Home">
            <img src={topBanner} alt="" className="home-top-banner" />
            <section className="ai-page">
                <div className="ai-glow ai-glow-orange" style={{ width: '420px', height: '420px', top: '-120px', left: '-100px' }} />
                <div className="ai-glow ai-glow-blue" style={{ width: '520px', height: '520px', top: '20%', right: '-140px' }} />

                <div className="relative z-10 grid w-full max-w-7xl items-center gap-16 lg:grid-cols-2">
                    <Reveal direction="left" className="h-full ai-hover-card">
                        <div className="flex h-full flex-col justify-center">
                            <h1 className="ai-title ai-special-title-alt text-5xl md:text-7xl">{t(lang,'heroTitle')}</h1>
                            <p className="ai-sub ai-sub-motion max-w-2xl text-lg md:text-xl">{t(lang,'heroSubtitle')}</p>
                        </div>
                    </Reveal>

                    <Reveal direction="right" delay={150} className="hidden h-full lg:block ai-hover-card">
                        <div className="relative h-full">
                            <div className="h-full overflow-hidden rounded-2xl" style={{ background: 'linear-gradient(135deg, #1e40af, #2563eb)', border: '1px solid rgba(96,165,250,0.45)', boxShadow: '0 12px 40px -12px rgba(37,99,235,0.5)' }}>
                                <div className="flex flex-col items-center justify-center gap-6 p-8">
                                    <div className="animate-float rounded-2xl border border-white/30 bg-white/15 p-4 text-white shadow-[0_0_40px_-10px_rgba(255,255,255,0.6)]">
                                        <AppIcon name="shield" size={48} />
                                    </div>
                                    <div className="text-lg font-bold text-white">Smart Visitor Security &amp; Access Platform</div>
                                    <div className="max-w-md text-center text-base md:text-lg text-blue-100">
                                        Streamlining visitor registration, appointment management, host coordination, QR-based access, real-time notifications, and secure entry from request to check-out.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Reveal>
                </div>
            </section>

            <section className="ai-page">
                <div className="ai-glow ai-glow-blue" style={{ width: '480px', height: '480px', top: '-160px', left: '35%' }} />
                <div className="relative z-10 w-full max-w-7xl">
                    <Reveal className="text-center">
                        <div>
                            <span className="ai-pill ai-pill-blue">
                                <AppIcon name="grid" size={13} /> Platform
                            </span>
                            <h2 className="ai-title ai-special-title">{t(lang,'onePlatform')}</h2>
                        </div>
                    </Reveal>
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-3 md:gap-8">
                        <Reveal delay={0} className="h-full ai-hover-card">
                            <div className="animate-float h-full rounded-2xl p-6 md:p-8" style={{ background: 'linear-gradient(135deg, #1e40af, #2563eb)', boxShadow: '0 12px 40px -12px rgba(37,99,235,0.5)' }}>
                                <div className="mb-5 inline-flex rounded-2xl bg-white/15 p-3 text-white">
                                    <AppIcon name="shield" size={24} />
                                </div>
                                <h3 className="text-lg font-bold text-white">{t(lang,'identityGuard')}</h3>
                                <p className="text-sm text-blue-100">{t(lang,'identityGuardDesc')}</p>
                            </div>
                        </Reveal>
                        <Reveal delay={120} className="h-full ai-hover-card">
                            <div className="animate-float h-full rounded-2xl p-6 md:p-8" style={{ animationDelay: '0.6s', background: 'linear-gradient(135deg, #4338ca, #6366f1)', boxShadow: '0 12px 40px -12px rgba(99,102,241,0.5)' }}>
                                <div className="mb-5 inline-flex rounded-2xl bg-white/15 p-3 text-white">
                                    <AppIcon name="check" size={24} />
                                </div>
                                <h3 className="text-lg font-bold text-white">{t(lang,'singleUseQR')}</h3>
                                <p className="text-sm text-indigo-100">{t(lang,'singleUseQRDesc')}</p>
                            </div>
                        </Reveal>
                        <Reveal delay={240} className="h-full ai-hover-card">
                            <div className="animate-float h-full rounded-2xl p-6 md:p-8" style={{ animationDelay: '1.2s', background: 'linear-gradient(135deg, #155e75, #0e7490)', boxShadow: '0 12px 40px -12px rgba(14,116,144,0.5)' }}>
                                <div className="mb-5 inline-flex rounded-2xl bg-white/15 p-3 text-white">
                                    <AppIcon name="bell" size={24} />
                                </div>
                                <h3 className="text-lg font-bold text-white">{t(lang,'instantAlerts')}</h3>
                                <p className="text-sm text-cyan-100">{t(lang,'instantAlertsDesc')}</p>
                            </div>
                        </Reveal>
                    </div>
                </div>
            </section>

            <section className="ai-page">
                <div className="ai-glow ai-glow-orange" style={{ width: '440px', height: '440px', bottom: '-160px', right: '-120px' }} />
                <div className="relative z-10 w-full max-w-7xl border-t border-white/5">
                    <Reveal className="text-center">
                        <div>
                            <h2 className="ai-title ai-special-title-3">{t(lang,'visitorLifecycle')}</h2>
                        </div>
                    </Reveal>
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4 md:gap-8">
                        {[
                            { n:'1', key:'step1', descKey:'step1Desc', gradient:'linear-gradient(135deg, #1e40af, #2563eb)', glow:'rgba(37,99,235,0.5)', text:'text-blue-100' },
                            { n:'2', key:'step2', descKey:'step2Desc', gradient:'linear-gradient(135deg, #4338ca, #6366f1)', glow:'rgba(99,102,241,0.5)', text:'text-indigo-100' },
                            { n:'3', key:'step3', descKey:'step3Desc', gradient:'linear-gradient(135deg, #155e75, #0e7490)', glow:'rgba(14,116,144,0.5)', text:'text-cyan-100' },
                            { n:'4', key:'step4', descKey:'step4Desc', gradient:'linear-gradient(135deg, #065f46, #059669)', glow:'rgba(5,150,105,0.5)', text:'text-emerald-100' },
                        ].map((s, idx) => (
                            <Reveal key={s.n} delay={idx * 100} className="h-full ai-hover-card">
                                <div className="animate-float flex h-full flex-col items-center justify-center gap-4 rounded-2xl p-6 text-center" style={{ animationDelay: `${idx * 0.6}s`, background: s.gradient, boxShadow: `0 12px 40px -12px ${s.glow}`, border: '1px solid rgba(255,255,255,0.2)' }}>
                                    <div className="flex h-14 w-14 items-center justify-center rounded-full border border-white/30 bg-white/15 text-xl font-extrabold text-white">
                                        {s.n}
                                    </div>
                                    <h4 className="text-lg font-bold text-white">{t(lang,s.key)}</h4>
                                    <p className={`text-sm ${s.text}`}>{t(lang,s.descKey)}</p>
                                </div>
                            </Reveal>
                        ))}
                    </div>
                </div>
            </section>

            <section className="ai-page social-footer">
                <div className="ai-glow ai-glow-blue" style={{ width: '420px', height: '420px', bottom: '-160px', left: '-120px' }} />
                <div className="relative z-10 w-full max-w-7xl border-t border-white/5 pt-10">
                    <Reveal className="text-center">
                        <div>
                            <h2 className="ai-title ai-special-title-3">{t(lang,'connectWithUs')}</h2>
                        </div>
                    </Reveal>
                    <div className="mt-8 flex flex-wrap items-center justify-center gap-4">
                        {SOCIALS.map(({ name, href, Icon, color }) => (
                            <a
                                key={name}
                                href={href}
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label={name}
                                title={name}
                                className="social-icon-btn"
                                style={{ '--social-color': color }}
                            >
                                <Icon size={22} />
                            </a>
                        ))}
                    </div>
                </div>
            </section>
        </Layout>
    );
}

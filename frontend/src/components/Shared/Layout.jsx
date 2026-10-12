import React, { useState, useEffect, useRef } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { useLang } from '../../LangContext';
import { useTheme } from '../../ThemeContext';
import { t } from '../../i18n';
import AppIcon from '../../config/iconMap';
import NotificationPanel from './NotificationPanel';

export default function Layout({ children, title }) {
    const { auth, flash, ceo_broadcasts } = usePage().props;
    const { lang } = useLang();
    const { theme, setTheme } = useTheme();

    // Live broadcasts — start with server-provided, refresh every 30s via Inertia reload
    const [broadcasts, setBroadcasts] = useState(ceo_broadcasts || []);
    const [dismissed, setDismissed] = useState(() => {
        try { return JSON.parse(sessionStorage.getItem('dismissed_broadcasts') || '[]'); }
        catch { return []; }
    });

    // Auto-dismiss flash messages after 4 seconds
    const [showSuccess, setShowSuccess] = useState(!!flash.success);
    const [showError, setShowError]     = useState(!!flash.error);
    useEffect(() => { if (flash.success) { setShowSuccess(true); const t = setTimeout(() => setShowSuccess(false), 4000); return () => clearTimeout(t); } }, [flash.success]);
    useEffect(() => { if (flash.error)   { setShowError(true);   const t = setTimeout(() => setShowError(false),   4000); return () => clearTimeout(t); } }, [flash.error]);

    // Keep broadcasts in sync when Inertia props update (on navigation / reload)
    useEffect(() => {
        setBroadcasts(ceo_broadcasts || []);
    }, [ceo_broadcasts]);

    // Auto-poll every 30 seconds via a lightweight JSON endpoint so the
    // announcement bar refreshes without re-executing the whole dashboard
    // controller (broadcasts only reach company employees, never visitors).
    const pollRef = useRef(null);
    const isWebUser = !!auth.user;
    const isCeo = auth.user?.role === 'ceo';

    useEffect(() => {
        if (!isWebUser || isCeo) return;

        pollRef.current = setInterval(() => {
            fetch('/api/broadcasts', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            })
                .then(r => r.json())
                .then(data => {
                    if (data && data.broadcasts) setBroadcasts(data.broadcasts);
                })
                .catch(() => { });
        }, 30000);

        return () => clearInterval(pollRef.current);
    }, [isWebUser, isCeo]);

    const dismissBroadcast = (id) => {
        const updated = [...dismissed, id];
        setDismissed(updated);
        sessionStorage.setItem('dismissed_broadcasts', JSON.stringify(updated));
    };

    const [sidebarOpen, setSidebarOpen] = useState(false);
    const toggleSidebar = () => {
        setSidebarOpen(prev => {
            const next = !prev;
            document.body.classList.toggle('sidebar-open', next);
            return next;
        });
    };

    useEffect(() => {
        return () => document.body.classList.remove('sidebar-open');
    }, []);

    const activeBroadcasts = broadcasts.filter(b => !dismissed.includes(b.id));

    const handleLogout = (e) => { e.preventDefault(); router.post('/logout'); };

    const user = auth.user;
    const visitor = auth.visitor;
    const currentUser = user || visitor;
    const isLoggedIn = !!currentUser;

    const getSidebarLinks = () => {
        if (!user && !visitor) return [];
        if (visitor) return [{ name: t(lang, 'home'), url: '/visitor/dashboard', icon: 'clipboard' }];
        switch (user.role) {
            case 'system_admin': return [
                { name: 'Platform Overview', url: '/system-admin/dashboard#overview', icon: 'chart' },
                { name: 'Companies',         url: '/system-admin/dashboard#companies', icon: 'building' },
                { name: 'Activity',          url: '/system-admin/dashboard#activity', icon: 'clipboard' },
                { name: 'Security',         url: '/system-admin/dashboard#security', icon: 'shield' },
                { name: 'Manage Companies',  url: '/admin/companies', icon: 'settings' },
            ];
            case 'ceo':   return [
                { name: 'Executive Overview',  url: '/ceo/dashboard#overview',    icon: 'chart' },
                { name: 'Security Risk',        url: '/ceo/dashboard#security',    icon: 'shield' },
                { name: 'Analytics',            url: '/ceo/dashboard#analytics',   icon: 'trending' },
                { name: 'Emergency Command',    url: '/ceo/dashboard#emergency',   icon: 'emergency' },
                { name: 'Policy & Settings',    url: '/ceo/dashboard#policy',      icon: 'settings' },
                { name: 'Export Reports',       url: '/ceo/dashboard#export',      icon: 'upload' },
            ];
            case 'admin': return [{ name: 'Admin Control', url: '/admin/dashboard', icon: 'settings' }];
            case 'host':  return [{ name: t(lang, 'hostEmployee'), url: '/host/dashboard', icon: 'group' }];
            case 'security_guard': return [
                { name: 'Dashboard',       url: '/security/dashboard',    icon: 'home' },
                { name: 'Entry Gate',      url: '/security/dashboard#entry_gate', icon: 'exit' },
                { name: 'Live Visitors',   url: '/security/dashboard#live', icon: 'mappin' },
                { name: 'Alerts',          url: '/security/dashboard#incidents', icon: 'warning' },
                { name: 'Reports',         url: '/security/dashboard#reports', icon: 'chart' },
            ];
            default:      return [];
        }
    };

    const dashboardUrl = user
        ? (user.role === 'system_admin' ? '/system-admin/dashboard'
            : user.role === 'ceo' ? '/ceo/dashboard'
            : user.role === 'admin' ? '/admin/dashboard'
            : user.role === 'security_guard' ? '/security/dashboard'
            : '/host/dashboard')
        : '/visitor/dashboard';

    return (
        <div style={{ minHeight: '100vh', display: 'flex', flexDirection: 'column' }}>
            {/* Top Navigation */}
            <nav className="navbar">
                <div className="container nav-container">
                    <Link href={isLoggedIn ? dashboardUrl : '/'} className="logo-link">
                        <div className="logo-icon">V</div>
                        <div className="logo-text">VMS<span>Enterprise</span></div>
                    </Link>

                    {/* Public nav only when NOT logged in */}
                    {!isLoggedIn ? (
                        <ul className="nav-menu">
                            <li><Link href="/"           className="nav-link" style={{ background: 'none' }}>{t(lang, 'home')}</Link></li>
                            <li><Link href="/about"      className="nav-link" style={{ background: 'none' }}>{t(lang, 'about')}</Link></li>
                            <li><Link href="/features"   className="nav-link" style={{ background: 'none' }}>{t(lang, 'features')}</Link></li>
                            <li><Link href="/guidelines" className="nav-link" style={{ background: 'none' }}>{t(lang, 'guidelines')}</Link></li>
                            <li><Link href="/faq"        className="nav-link" style={{ background: 'none' }}>{t(lang, 'faq')}</Link></li>
                            <li><Link href="/contact"    className="nav-link" style={{ background: 'none' }}>{t(lang, 'contact')}</Link></li>
                        </ul>
                    ) : null}

                    <div className="nav-actions">
                        {/* Mobile hamburger — toggles internal dashboard sidebars */}
                        <button
                            className="hamburger-btn"
                            onClick={toggleSidebar}
                            aria-label="Toggle sidebar"
                            style={{ background: 'var(--nav-tint)', borderRadius: '6px', padding: '6px 8px', color: 'var(--text-primary)', cursor: 'pointer', fontSize: '18px', lineHeight: 1 }}
                        >
                            <AppIcon name={sidebarOpen ? 'close' : 'menu'} size={18} />
                        </button>
                        {/* Dark/Light Mode Toggle */}
                        <button
                            onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
                            style={{ background: theme === 'dark' ? 'rgba(37,99,235,0.15)' : 'var(--nav-tint)', color: 'var(--accent-primary)', borderRadius: '6px', padding: '6px 12px', fontSize: '12px', fontWeight: '700', cursor: 'pointer', letterSpacing: '0.5px' }}
                            title="Toggle Dark/Light Mode"
                        >
                            {theme === 'dark' ? <><AppIcon name="sun" size={14} /> Light</> : <><AppIcon name="moon" size={14} /> Dark</>}
                        </button>

                        {/* Broadcast notification bell — only for logged-in non-CEO */}
                        {isLoggedIn && !isCeo && activeBroadcasts.length > 0 && (
                            <div style={{ position: 'relative' }}>
                                <div style={{
                                    background: 'rgba(239,68,68,0.15)',
                                    border: '1px solid rgba(239,68,68,0.4)',
                                    borderRadius: '8px',
                                    padding: '6px 12px',
                                    fontSize: '13px',
                                    color: 'var(--status-danger-light, #fca5a5)',
                                    fontWeight: 700,
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: '6px',
                                    animation: 'glowPulse 2s infinite',
                                }}>
                                    <AppIcon name="bell" size={16} /> <span>{activeBroadcasts.length} CEO Alert{activeBroadcasts.length > 1 ? 's' : ''}</span>
                                </div>
                            </div>
                        )}

                        {!currentUser ? (
                            <>
                                <Link href="/register" className="btn btn-primary">{t(lang, 'registerHere')}</Link>
                                <Link href="/login" className="btn btn-primary">{t(lang, 'login')}</Link>
                            </>
                        ) : (
                            <div style={{ display:'flex', alignItems:'center', gap:10 }}>
                                <NotificationPanel isVisitor={!!visitor} />
                                {user && !visitor ? (
                                    <Link href="/profile" style={{ fontSize:13, color:'var(--text-primary)', fontWeight:600, textDecoration:'none' }}>{currentUser?.name || ''}</Link>
                                ) : (
                                    <span style={{ fontSize:13, color:'var(--text-primary)', fontWeight:600 }}>{currentUser?.name || ''}</span>
                                )}
                                <button onClick={handleLogout} className="btn btn-danger" style={{ padding: '8px 16px', fontSize: '13px' }}>
                                    {t(lang, 'logout')}
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            </nav>

            {/* ── Mobile nav overlay (public pages) ── */}
            {!isLoggedIn && sidebarOpen && (
                <div className="mobile-nav-overlay" style={{
                    position: 'fixed', top: 0, left: 0, right: 0, bottom: 0,
                    background: 'var(--bg-card)', zIndex: 150,
                    display: 'flex', flexDirection: 'column',
                    padding: '80px var(--space-6) var(--space-6)',
                }}>
                    <button onClick={toggleSidebar} style={{
                        position: 'absolute', top: 16, right: 16,
                        background: 'rgba(255,255,255,0.1)', border: 'none', borderRadius: 8,
                        width: 36, height: 36, display: 'flex', alignItems: 'center', justifyContent: 'center',
                        cursor: 'pointer', color: 'var(--text-primary)', fontSize: 18,
                    }}>
                        <AppIcon name="close" size={18} />
                    </button>
                    <ul style={{ listStyle: 'none', display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', padding: 0 }}>
                        <li><Link href="/" onClick={toggleSidebar} className="nav-link" style={{ fontSize: 18, padding: '12px 0', display: 'block', borderBottom: '1px solid var(--border-color)', background: 'none' }}>{t(lang, 'home')}</Link></li>
                        <li><Link href="/about" onClick={toggleSidebar} className="nav-link" style={{ fontSize: 18, padding: '12px 0', display: 'block', borderBottom: '1px solid var(--border-color)', background: 'none' }}>{t(lang, 'about')}</Link></li>
                        <li><Link href="/features" onClick={toggleSidebar} className="nav-link" style={{ fontSize: 18, padding: '12px 0', display: 'block', borderBottom: '1px solid var(--border-color)', background: 'none' }}>{t(lang, 'features')}</Link></li>
                        <li><Link href="/guidelines" onClick={toggleSidebar} className="nav-link" style={{ fontSize: 18, padding: '12px 0', display: 'block', borderBottom: '1px solid var(--border-color)', background: 'none' }}>{t(lang, 'guidelines')}</Link></li>
                        <li><Link href="/faq" onClick={toggleSidebar} className="nav-link" style={{ fontSize: 18, padding: '12px 0', display: 'block', borderBottom: '1px solid var(--border-color)', background: 'none' }}>{t(lang, 'faq')}</Link></li>
                        <li><Link href="/contact" onClick={toggleSidebar} className="nav-link" style={{ fontSize: 18, padding: '12px 0', display: 'block', borderBottom: '1px solid var(--border-color)', background: 'none' }}>{t(lang, 'contact')}</Link></li>
                    </ul>
                    <div style={{ marginTop: 'auto', display: 'flex', flexDirection: 'column', gap: 12, paddingBottom: 40 }}>
                        <Link href="/register" className="btn btn-primary" onClick={toggleSidebar} style={{ width: '100%', justifyContent: 'center', padding: '14px' }}>{t(lang, 'registerHere')}</Link>
                    </div>
                </div>
            )}

            {/* Flash notifications */}
            <div className="container" style={{ marginTop: '24px' }}>
                {flash.success && showSuccess && (
                    <div className="alert-banner alert-success animate-fade-in">
                        <div><AppIcon name="check" size={16} color="#10b981" /> <strong>{t(lang, 'success')}:</strong> {flash.success}</div>
                        <button style={{ background: 'none', border: 'none', color: 'inherit', cursor: 'pointer', fontSize: '16px' }} onClick={() => setShowSuccess(false)}>×</button>
                    </div>
                )}
                {flash.error && showError && (
                    <div className="alert-banner alert-error animate-fade-in">
                        <div><AppIcon name="warning" size={16} color="#f59e0b" /> <strong>{t(lang, 'alert')}:</strong> {flash.error}</div>
                        <button style={{ background: 'none', border: 'none', color: 'inherit', cursor: 'pointer', fontSize: '16px' }} onClick={() => setShowError(false)}>×</button>
                    </div>
                )}
            </div>

            {/* ── CEO Broadcast Banners — full-width, above everything ── */}
            {activeBroadcasts.length > 0 && (
                <div style={{ width: '100%' }}>
                    {activeBroadcasts.map(b => {
                        const styles = {
                            info: {
                                bg: 'linear-gradient(135deg, rgba(37,99,235,0.18), rgba(37,99,235,0.12))',
                                border: 'rgba(37,99,235,0.45)',
                                color: '#93c5fd',
                                icon: 'info',
                                label: 'CEO Notice',
                                glow: 'rgba(37,99,235,0.2)',
                            },
                            warning: {
                                bg: 'linear-gradient(135deg, rgba(245,158,11,0.18), rgba(234,179,8,0.12))',
                                border: 'rgba(245,158,11,0.5)',
                                color: '#fde68a',
                                icon: 'warning',
                                label: 'CEO Warning',
                                glow: 'rgba(245,158,11,0.2)',
                            },
                            critical: {
                                bg: 'linear-gradient(135deg, rgba(239,68,68,0.2), rgba(220,38,38,0.14))',
                                border: 'rgba(239,68,68,0.55)',
                                color: '#fca5a5',
                                icon: 'emergency',
                                label: 'CRITICAL — CEO Emergency Alert',
                                glow: 'rgba(239,68,68,0.25)',
                            },
                        };
                        const s = styles[b.priority] || styles.info;
                        const isCritical = b.priority === 'critical';
                        return (
                            <div key={b.id} className="animate-fade-in" style={{
                                width: '100%',
                                background: s.bg,
                                borderTop: `1px solid ${s.border}`,
                                borderBottom: `1px solid ${s.border}`,
                                color: s.color,
                                padding: '14px 32px',
                                display: 'flex',
                                justifyContent: 'space-between',
                                alignItems: 'center',
                                gap: '20px',
                                backdropFilter: 'blur(12px)',
                                boxShadow: `0 4px 24px ${s.glow}`,
                                animation: isCritical ? 'glowPulse 1.5s infinite' : undefined,
                            }}>
                                <div style={{ display: 'flex', gap: '14px', alignItems: 'center', flex: 1 }}>
                                    <span style={{ fontSize: '22px', flexShrink: 0 }}><AppIcon name={s.icon} size={22} /></span>
                                    <div>
                                        <span style={{
                                            fontWeight: 800,
                                            fontSize: '11px',
                                            textTransform: 'uppercase',
                                            letterSpacing: '0.8px',
                                            opacity: 0.75,
                                            marginRight: '12px',
                                        }}>
                                            {s.label}
                                        </span>
                                        <span style={{ fontWeight: 600, fontSize: '14px' }}>{b.message}</span>
                                    </div>
                                </div>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '16px', flexShrink: 0 }}>
                                    <span style={{ fontSize: '11px', opacity: 0.5, whiteSpace: 'nowrap' }}>
                                        {new Date(b.time).toLocaleString()}
                                    </span>
                                    <button
                                        onClick={() => dismissBroadcast(b.id)}
                                        style={{
                                            background: 'rgba(255,255,255,0.1)',
                                            border: `1px solid ${s.border}`,
                                            borderRadius: '6px',
                                            color: s.color,
                                            cursor: 'pointer',
                                            fontSize: '12px',
                                            fontWeight: 700,
                                            padding: '4px 10px',
                                            whiteSpace: 'nowrap',
                                        }}
                                    >
                                         {t(lang, 'dismiss')}
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {/* Dashboard layout when logged in, public layout otherwise */}
            {isLoggedIn ? (
                (user?.role === 'system_admin' || user?.role === 'host' || user?.role === 'ceo' || user?.role === 'admin' || user?.role === 'security_guard' || visitor) ? (
                    /* System Admin, Host, CEO, Admin, Security Guard & Visitor have their own internal sidebar nav — render full-width */
                    <main className="dashboard-main animate-fade-in" style={{ maxHeight: 'none', padding: 'var(--section-padding-y, 28px) var(--section-padding-x, 32px)' }}>{children}</main>
                ) : (
                    <div className="dashboard-layout">
                        <aside className="sidebar">
                            <ul className="sidebar-menu">
                                {getSidebarLinks().map((link, idx) => (
                                    <li key={idx}>
                                        <Link href={link.url} className="sidebar-link">
                                            <AppIcon name={link.icon} size={16} /> {link.name}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                            <div style={{ marginTop: 'auto', borderTop: '1px solid var(--glass-border)', paddingTop: '20px' }}>
                                <Link href="/" className="sidebar-link" style={{ fontSize: '13px', color: 'var(--text-muted)' }}>
                                    <AppIcon name="globe" size={16} /> {t(lang, 'publicSite')}
                                </Link>
                            </div>
                        </aside>
                        <main className="dashboard-main animate-fade-in">{children}</main>
                    </div>
                )
            ) : (
                <main style={{ flex: 1 }} className="animate-fade-in">{children}</main>
            )}

            <footer className="footer">
                <div className="container">

                </div>
            </footer>
        </div>
    );
}

import React, { useState, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import Layout from '../Shared/Layout';
import { useForm, usePage, router, Link } from '@inertiajs/react';
import axios from 'axios';
import AppIcon from '../../config/iconMap';
import Messages from './Messages';
import VisitorTransferModal from './VisitorTransferModal';
import { fmtDateTime, toLocalDatetimeInput, localToServerDatetime } from '../../utils/timezone';

function todayStr() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// ── Reusable mini components ──────────────────────────────────────────────────

function StatCard({ label, value, color }) {
    const colorMap = {
        cyan: 'var(--accent-cyan)',
        emerald: 'var(--status-success)',
        warning: 'var(--status-warning)',
        danger: 'var(--status-danger)',
        blue: 'var(--accent-blue)',
        default: 'var(--text-primary)',
    };
    return (
        <div className="stat-card">
            <span className="stat-label">{label}</span>
            <span className="stat-value" style={{ color: colorMap[color] || colorMap.default }}>{value}</span>
        </div>
    );
}

function ProgressBar({ value, max = 100, color }) {
    const pct = Math.min(100, Math.round((value / max) * 100));
    const bg = color === 'danger' ? 'var(--status-danger)'
        : color === 'warning' ? 'var(--status-warning)'
            : 'var(--accent-cyan)';
    return (
        <div style={{ background: 'rgba(255,255,255,0.07)', borderRadius: '4px', height: '8px', overflow: 'hidden' }}>
            <div style={{ width: `${pct}%`, height: '100%', background: bg, borderRadius: '4px', transition: 'width 0.5s' }} />
        </div>
    );
}

function SectionTitle({ children }) {
    return (
        <h3 style={{ fontFamily: 'var(--font-heading)', marginBottom: '16px', fontSize: '16px', fontWeight: 700 }}>
            {children}
        </h3>
    );
}

const visitMenuItemStyle = {
    display: 'flex',
    alignItems: 'center',
    gap: 10,
    width: '100%',
    border: 0,
    background: 'transparent',
    color: 'var(--text-primary)',
    fontSize: 13,
    fontWeight: 500,
    padding: '9px 12px',
    borderRadius: 8,
    cursor: 'pointer',
    textAlign: 'left',
    whiteSpace: 'nowrap',
};

const visitMenuPanelStyle = {
    position: 'fixed',
    zIndex: 2000,
    minWidth: 190,
    background: 'var(--bg-card)',
    border: '1px solid var(--glass-border)',
    borderRadius: 12,
    boxShadow: '0 12px 32px rgba(15,23,42,0.18), 0 2px 8px rgba(15,23,42,0.08)',
    padding: 6,
    maxHeight: '70vh',
    overflowY: 'auto',
};

function VisitMenuBtn({ danger, tone, onClick, children }) {
    const color = danger ? 'var(--status-danger)' : (tone || 'var(--text-primary)');
    return (
        <button
            onClick={onClick}
            style={{ ...visitMenuItemStyle, color }}
            onMouseEnter={e => { e.currentTarget.style.background = danger ? 'rgba(239,68,68,0.08)' : 'rgba(37,99,235,0.08)'; }}
            onMouseLeave={e => { e.currentTarget.style.background = 'transparent'; }}
        >
            {children}
        </button>
    );
}

const TABS = [
    { key: 'overview', label: 'Overview', icon: 'chart' },
    { key: 'visits', label: 'Visits', icon: 'calendar' },
    { key: 'security', label: 'Security Risk', icon: 'shield' },
    { key: 'compliance', label: 'Compliance', icon: 'check' },
    { key: 'departments', label: 'Departments', icon: 'building' },
    { key: 'analytics', label: 'Analytics', icon: 'trending' },
    { key: 'approvals', label: 'Approval Performance', icon: 'clock' },
    { key: 'admins', label: 'Admin Approval', icon: 'users' },
    { key: 'emergency', label: 'Emergency', icon: 'emergency' },
    { key: 'audit', label: 'Audit Review', icon: 'file' },
    { key: 'policy', label: 'Policy', icon: 'settings' },
    { key: 'export', label: 'Export', icon: 'download' },
    { key: 'messages', label: 'Messages', icon: 'mail' },
];

export default function CEODashboard({
    stats, security, compliance, department_statistics,
    analytics, approval_performance, pending_admins,
    policies, recent_broadcasts, audit_logs, system_alerts,
    active_incidents, incident_stats, notifications, unread_count,
    escalations,
    all_visits = [],
    escalation_targets = [],
    section,
    messages_inbox, messages_sent, messages_recipients, messages_unread_count,
    branches = [], active_lockdowns = [],
}) {
    const { auth, app_timezone } = usePage().props;
    const user = auth.user;
    const tz = app_timezone || 'Africa/Addis_Ababa';
    // Read tab from URL hash, default to 'overview'
    const [activeTab, setActiveTab] = useState(() => {
        if (section) return section;
        const hash = window.location.hash.replace('#', '');
        return hash || 'overview';
    });
    const [auditFilter, setAuditFilter] = useState('');
    const [sidebarOpen, setSidebarOpen] = useState(true);

    useEffect(() => {
        if (section) {
            setActiveTab(section);
        } else {
            const hash = window.location.hash.replace('#', '');
            setActiveTab(hash || 'overview');
        }
    }, [section]);


    // ── Emergency module (instant, no full-page reload) ──
    const [emergency, setEmergency] = useState(() => ({
        lockdowns: active_lockdowns || [],
        lockdownStatus: !!(stats && stats.lockdown_status),
        broadcasts: recent_broadcasts || [],
    }));
    const [emergencyBusy, setEmergencyBusy] = useState(null);
    const [emergencyMsg, setEmergencyMsg] = useState(null);
    const [showBroadcasts, setShowBroadcasts] = useState(false);
    const emergencyBusyRef = useRef(false);

    const postEmergency = async (url, payload, { key, onSuccess } = {}) => {
        if (emergencyBusyRef.current) return; // prevent duplicate submissions
        emergencyBusyRef.current = true;
        setEmergencyBusy(key || url);
        setEmergencyMsg(null);
        try {
            const csrf = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1] || '';
            const { data } = await axios.post(url, payload, {
                headers: {
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(csrf),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (data && data.success) {
                setEmergency(prev => ({
                    lockdowns: data.active_lockdowns !== undefined ? data.active_lockdowns : prev.lockdowns,
                    lockdownStatus: data.lockdown_status !== undefined ? data.lockdown_status : prev.lockdownStatus,
                    broadcasts: data.recent_broadcasts !== undefined ? data.recent_broadcasts : prev.broadcasts,
                }));
                setEmergencyMsg({ type: 'success', text: data.message || 'Done.' });
                if (onSuccess) onSuccess(data);
            } else {
                setEmergencyMsg({ type: 'error', text: (data && data.message) || 'Request failed. Please try again.' });
            }
        } catch (err) {
            const errors = err?.response?.data?.errors;
            const msg = err?.response?.data?.message
                || (errors ? Object.values(errors).flat().join(' ') : null)
                || 'Request failed. Please try again.';
            setEmergencyMsg({ type: 'error', text: msg });
        } finally {
            emergencyBusyRef.current = false;
            setEmergencyBusy(null);
        }
    };

    // ── Escalation (responsible person) action state ──
    const [escTransferVisit, setEscTransferVisit] = useState(null);
    const [escTransferOpts, setEscTransferOpts] = useState(null);
    const [escTransferSel, setEscTransferSel] = useState({ company: '', branch: '', dept: '', host: '' });
    const [escTransferReason, setEscTransferReason] = useState('');
    const [escTransferLoading, setEscTransferLoading] = useState(false);
    const [escOutcome, setEscOutcome] = useState({});

    // ── Visitor Transfer (CEO — company-level) ──
    const [transferVisit, setTransferVisit] = useState(null);

    const fmt = (dt) => dt ? fmtDateTime(dt) : '—';

    // Company -> Branch -> Department -> Host cascade for the transfer modal.
    const buildEscUrl = (v, sel) => {
        const qs = [];
        if (sel.company) qs.push(`company_id=${encodeURIComponent(sel.company)}`);
        if (sel.branch) qs.push(`branch_id=${encodeURIComponent(sel.branch)}`);
        if (sel.dept) qs.push(`department_id=${encodeURIComponent(sel.dept)}`);
        const q = qs.length ? `?${qs.join('&')}` : '';
        return `/escalation/visits/${v.id}/transfer-options${q}`;
    };

    const readEscError = async (res) => {
        try {
            const data = await res.json();
            if (data && data.message) return data.message;
        } catch (_) { /* ignore */ }
        return 'Failed to load transfer options.';
    };

    const loadEscOpts = async (v, sel) => {
        setEscTransferLoading(true);
        try {
            const res = await fetch(buildEscUrl(v, sel), { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(await readEscError(res));
            setEscTransferOpts(await res.json());
        } catch (err) {
            setEscTransferOpts({ error: true, message: err.message || 'Could not load transfer options.' });
        } finally {
            setEscTransferLoading(false);
        }
    };

    const openEscTransfer = async (v) => {
        setEscTransferVisit(v);
        setEscTransferOpts(null);
        setEscTransferSel({ company: v.company_id || '', branch: '', dept: '', host: '' });
        setEscTransferReason('');
        await loadEscOpts(v, { company: v.company_id || '', branch: '', dept: '' });
    };

    const changeEscSel = async (field, value) => {
        if (!escTransferVisit) return;
        const next = { ...escTransferSel, [field]: value };
        if (field === 'company') { next.branch = ''; next.dept = ''; next.host = ''; }
        if (field === 'branch') { next.dept = ''; next.host = ''; }
        if (field === 'dept') { next.host = ''; }
        setEscTransferSel(next);
        await loadEscOpts(escTransferVisit, next);
    };

    const submitEscTransfer = (v) => {
        if (!escTransferSel.host) { alert('Please select a host, Admin or CEO.'); return; }
        router.post(`/escalation/visits/${v.id}/transfer`, {
            new_host_id: escTransferSel.host,
            company_id: escTransferSel.company,
            reason: escTransferReason,
        }, { onSuccess: () => setEscTransferVisit(null) });
    };

    const escActs = (e) => (
        <div style={{ display: 'flex', gap: 5, flexWrap: 'wrap', justifyContent: 'flex-end', maxWidth: 260 }}>
            {e.status === 'escalated' && (
                <button onClick={() => router.post(`/escalation/visits/${e.id}/receive`)} className="btn btn-primary" style={{ fontSize: 11, padding: '3px 9px' }}><AppIcon name="check" size={12} /> Receive</button>
            )}
            {e.status === 'approved' && (
                <button onClick={() => router.post(`/host/visits/${e.id}/confirm-arrival`)} className="btn btn-primary" style={{ fontSize: 11, padding: '3px 9px' }}><AppIcon name="check" size={12} /> Check-in</button>
            )}
            {e.status === 'checked_in' && (
                <>
                    <button onClick={() => router.post(`/host/visits/${e.id}/meeting-start`)} className="btn btn-primary" style={{ fontSize: 11, padding: '3px 9px' }}><AppIcon name="handshake" size={12} /> Start</button>
                    <input type="text" className="form-input" style={{ width: '100%', fontSize: 11, padding: '3px 8px' }} placeholder="Outcome..." value={escOutcome[e.id] || ''} onChange={ev => setEscOutcome(p => ({ ...p, [e.id]: ev.target.value }))} />
                    <button onClick={() => { if (!escOutcome[e.id]) { alert('Enter a meeting outcome.'); return; } router.post(`/host/visits/${e.id}/meeting-end`, { meeting_outcome: escOutcome[e.id] }); }} className="btn btn-danger" style={{ fontSize: 11, padding: '3px 9px' }}><AppIcon name="check" size={12} /> End</button>
                </>
            )}
            <button onClick={() => openEscTransfer(e)} className="btn btn-secondary" style={{ fontSize: 11, padding: '3px 9px' }}><AppIcon name="users" size={12} /> Transfer</button>
            <button onClick={() => setTransferVisit(e)} className="btn btn-secondary" style={{ fontSize: 11, padding: '3px 9px' }}><AppIcon name="check" size={12} /> Reassign Host</button>
            {e.status !== 'checked_in' && (
                <button onClick={() => router.post(`/escalation/visits/${e.id}/no-show`)} className="btn btn-secondary" style={{ fontSize: 11, padding: '3px 9px' }}><AppIcon name="clock" size={12} /> No-Show</button>
            )}
            <button onClick={() => router.post(`/escalation/visits/${e.id}/complete`)} className="btn btn-primary" style={{ fontSize: 11, padding: '3px 9px' }}><AppIcon name="check" size={12} /> Complete</button>
        </div>
    );

    // ── Escalations tab: per-visit actions (Transfer / Approve / Edit / Reject / Delete) ──
    const [escMenuOpen, setEscMenuOpen] = useState(null);
    const [escAction, setEscAction] = useState(null); // { visit, mode: 'edit' | 'reject' }
    const [escEditTime, setEscEditTime] = useState('');
    const [escEditDuration, setEscEditDuration] = useState(60);
    const [escRejectReason, setEscRejectReason] = useState('');

    const openEscAction = (v, mode) => {
        setEscAction({ visit: v, mode });
        setEscEditTime(v.requested_time ? toLocalDatetimeInput(v.requested_time) : '');
        setEscEditDuration(v.duration || 60);
        setEscRejectReason('');
        setEscMenuOpen(null);
    };

    const escApprove = (v) => router.post(`/visit-requests/${v.id}/decision`, { decision: 'approve' });

    const escDelete = (v) => {
        setEscMenuOpen(null);
        if (confirm(`Delete visit request #${v.id}? This action cannot be undone.`)) {
            router.delete(`/admin/visit-requests/${v.id}`);
        }
    };

    const submitEscAction = () => {
        if (!escAction) return;
        const v = escAction.visit;
        if (escAction.mode === 'edit') {
            router.post(`/admin/visit-requests/${v.id}/update-time`, {
                requested_time: localToServerDatetime(escEditTime, tz),
                duration: escEditDuration,
            }, { onSuccess: () => setEscAction(null) });
        } else if (escAction.mode === 'reject') {
            if (!escRejectReason.trim()) { alert('Please provide a reason for rejection.'); return; }
            router.post(`/visit-requests/${v.id}/decision`, {
                decision: 'reject',
                comments: escRejectReason,
            }, { onSuccess: () => setEscAction(null) });
        }
    };

    // ── Escalate a company visit to an Admin only ──
    const [escModal, setEscModal] = useState(null);
    const [escTarget, setEscTarget] = useState('');
    const [escReason, setEscReason] = useState('');

    const eligibleEscalationTargets = (visit) => {
        const list = escalation_targets || [];
        const companyId = visit?.company_id;
        return list.filter(u => u.role === 'admin' && u.status !== 'suspended' && (companyId ? u.company_id === companyId : true));
    };

    const openEscalate = (v) => {
        setEscModal(v);
        setEscTarget('');
        setEscReason('');
    };

    const submitEscalate = (v) => {
        if (!escTarget) { alert('Please select an Admin to escalate to.'); return; }
        router.post(`/visit-requests/${v.id}/host-decision`, {
            decision: 'escalate',
            escalated_to: escTarget,
            reason: escReason,
        }, { onSuccess: () => setEscModal(null) });
    };

    // ── Company Visits tab: per-visit actions (Approve / End / Reject / Escalate) ──
    const [visitMenuOpen, setVisitMenuOpen] = useState(null);
    const [visitAnchor, setVisitAnchor] = useState({ top: 0, right: 0, height: 0 });
    const [visitConfirm, setVisitConfirm] = useState(null); // { visit, action: 'approve' | 'end' | 'reject' }
    const [visitRejectReason, setVisitRejectReason] = useState('');

    useEffect(() => {
        if (visitMenuOpen === null) return;
        const close = (e) => { if (e.target.closest && e.target.closest('[data-visit-menu]')) return; setVisitMenuOpen(null); };
        const reposition = () => setVisitMenuOpen(null);
        document.addEventListener('mousedown', close);
        window.addEventListener('scroll', reposition, true);
        window.addEventListener('resize', reposition);
        return () => {
            document.removeEventListener('mousedown', close);
            window.removeEventListener('scroll', reposition, true);
            window.removeEventListener('resize', reposition);
        };
    }, [visitMenuOpen]);

    const openVisitConfirm = (v, action) => {
        setVisitMenuOpen(null);
        setVisitRejectReason('');
        setVisitConfirm({ visit: v, action });
    };

    const submitVisitConfirm = () => {
        if (!visitConfirm) return;
        const v = visitConfirm.visit;
        if (visitConfirm.action === 'approve') {
            router.post(`/visit-requests/${v.id}/decision`, { decision: 'approve' }, { onSuccess: () => setVisitConfirm(null) });
        } else if (visitConfirm.action === 'end') {
            router.post(`/admin/visit-requests/${v.id}/end`, {}, { onSuccess: () => setVisitConfirm(null) });
        } else if (visitConfirm.action === 'reject') {
            if (!visitRejectReason.trim()) { alert('A reason is required to reject a visit.'); return; }
            router.post(`/visit-requests/${v.id}/decision`, { decision: 'reject', comments: visitRejectReason }, { onSuccess: () => setVisitConfirm(null) });
        }
    };

    // Update URL hash when tab changes
    const changeTab = (tab) => {
        setActiveTab(tab);
        window.location.hash = tab;
    };

    // ── Forms ──
    const broadcastForm = useForm({ message: '', priority: 'info', scope_type: 'company', branch_id: '', company_id: user?.company_id || '' });
    const policyForm = useForm({
        allow_weekends: policies?.allow_weekends ?? false,
        enforce_biz_hours: policies?.enforce_biz_hours ?? true,
        sla_minutes: policies?.sla_minutes ?? 5,
    });
    const lockdownForm = useForm({ scope_type: 'company', branch_id: '', reason: '' });

    const handleLockdown = () => router.post('/ceo/lockdown');
    const handleLiftLockdown = (id) => {
        if (confirm('Lift this lockdown? Visitors in the affected scope will regain access immediately.')) {
            postEmergency(`/ceo/incidents/${id}/lift`, {}, { key: `lift-${id}` });
        }
    };
    const handleAdminApproval = (id, action) => router.post(`/ceo/admins/${id}`, { action });

    const handleBroadcast = (e) => {
        e.preventDefault();
        postEmergency('/ceo/broadcast', broadcastForm.data, {
            key: 'broadcast',
            onSuccess: () => broadcastForm.reset(),
        });
    };

    const handleLockdownToggle = (e) => {
        e.preventDefault();
        postEmergency('/ceo/lockdown', lockdownForm.data, {
            key: 'lockdown',
            onSuccess: () => lockdownForm.reset('reason'),
        });
    };

    const handlePolicyUpdate = (e) => {
        e.preventDefault();
        policyForm.post('/ceo/policies');
    };

    const filteredAudit = auditFilter
        ? audit_logs.filter(l => l.action.includes(auditFilter.toLowerCase()))
        : audit_logs;

    return (
        <Layout title="CEO Executive Dashboard">
            <div className="admin-layout" style={section ? { minHeight: 'calc(100vh - 80px)' } : { display: 'flex', minHeight: 'calc(100vh - 80px)' }}>
                {!section && (
                    <aside className="admin-sidebar" style={{
                        width: sidebarOpen ? '220px' : '0px',
                        background: '#2563eb',
                        borderRight: sidebarOpen ? '1px solid rgba(255,255,255,0.15)' : 'none',
                        padding: '0',
                        position: 'sticky',
                        top: '68px',
                        alignSelf: 'flex-start',
                        maxHeight: 'calc(100vh - 68px)',
                        overflow: sidebarOpen ? 'auto' : 'hidden',
                        flexShrink: 0,
                        borderRadius: '0 0 0 14px',
                        transition: 'width 0.25s ease',
                    }}>
                        {/* Hamburger toggle */}
                        <div style={{ padding: '10px 12px', borderBottom: '1px solid rgba(255,255,255,0.15)', display: 'flex', alignItems: 'center', justifyContent: sidebarOpen ? 'flex-end' : 'center' }}>
                            <button onClick={() => setSidebarOpen(p => !p)} style={{
                                background: 'rgba(255,255,255,0.2)',
                                border: 'none',
                                borderRadius: '8px',
                                width: 34,
                                height: 34,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                cursor: 'pointer',
                                color: '#fff',
                                fontSize: 16,
                                fontWeight: 700,
                                lineHeight: 1,
                            }}><AppIcon name="chevron" size={16} /></button>
                        </div>

                        {/* Profile mini-card */}
                        {sidebarOpen && <div style={{ padding: '16px', borderBottom: '1px solid rgba(255,255,255,0.15)' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                <label style={{ width: 38, height: 38, borderRadius: '50%', background: user?.profile_photo ? 'none' : 'rgba(255,255,255,0.2)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 900, fontSize: 17, color: 'white', flexShrink: 0, overflow: 'hidden', cursor: 'pointer' }} title="Click to change photo">
                                    {user?.profile_photo ? <img src={`/storage/${user.profile_photo}`} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : ''}
                                    <input type="file" accept="image/*" style={{ display: 'none' }} onChange={e => { const f = e.target.files[0]; if (!f) return; const fd = new FormData(); fd.append('profile_photo', f); fd.append('name', user?.name || ''); router.post('/profile/update', fd, { forceFormData: true, preserveScroll: true }); }} />
                                </label>
                                <div>
                                    <div style={{ fontWeight: 700, fontSize: 13, color: '#ffffff' }}>CEO</div>
                                    <div style={{ fontSize: 11, color: 'rgba(255,255,255,0.7)' }}>Executive Office</div>
                                </div>
                            </div>
                        </div>}

                        <ul style={{ listStyle: 'none', padding: '6px 8px', margin: 0, display: 'flex', flexDirection: 'column', gap: '2px' }}>
                            {TABS.map(t => {
                                const isActive = activeTab === t.key;
                                return (
                                    <li key={t.key}>
                                        <button
                                            onClick={() => router.visit(`/ceo/section/${t.key}`)}
                                            title={sidebarOpen ? '' : t.label}
                                            style={{
                                                width: '100%',
                                                textAlign: sidebarOpen ? 'left' : 'center',
                                                background: isActive ? 'rgba(255,255,255,0.2)' : 'transparent',
                                                border: 'none',
                                                color: '#ffffff',
                                                padding: sidebarOpen ? '10px 14px' : '10px 0',
                                                fontSize: '13px',
                                                fontWeight: isActive ? 700 : 500,
                                                cursor: 'pointer',
                                                transition: 'all 0.2s',
                                                display: 'flex',
                                                alignItems: 'center',
                                                justifyContent: sidebarOpen ? 'space-between' : 'center',
                                                lineHeight: 1.4,
                                                borderRadius: '8px',
                                            }}
                                            onMouseEnter={(e) => {
                                                if (!isActive) e.currentTarget.style.background = 'rgba(255,255,255,0.12)';
                                            }}
                                            onMouseLeave={(e) => {
                                                if (!isActive) e.currentTarget.style.background = 'transparent';
                                            }}
                                        >
                                            <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}><AppIcon name={t.icon} size={14} /> {sidebarOpen ? t.label : t.label.split(' ')[0]}</span>
                                            {t.key === 'admins' && pending_admins.length > 0 && sidebarOpen && (
                                                <span style={{ background: '#f59e0b', color: '#fff', borderRadius: '10px', padding: '2px 7px', fontSize: '11px', fontWeight: 700 }}>
                                                    {pending_admins.length}
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>

                        {/* Bottom links */}
                        <div style={{ borderTop: '1px solid rgba(255,255,255,0.15)', padding: '12px 16px', display: 'flex', flexDirection: 'column', gap: 6 }}>
                            <Link href="/profile" style={{ fontSize: 12, color: 'rgba(255,255,255,0.8)', textDecoration: 'none', display: 'flex', alignItems: 'center', gap: 8, padding: '6px 0', fontWeight: 600 }}><AppIcon name="user" size={14} /> My Profile</Link>
                        </div>
                    </aside>
                )}
                {!section && !sidebarOpen && (
                    <button
                        className="sidebar-expand-btn"
                        onClick={() => setSidebarOpen(true)}
                        title="Expand sidebar"
                        style={{ position: 'fixed', top: 80, left: 12, zIndex: 200, background: '#2563eb', border: '1px solid rgba(255,255,255,0.25)', borderRadius: 8, width: 34, height: 34, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', color: '#fff', fontSize: 16, fontWeight: 700, boxShadow: '0 2px 10px rgba(0,0,0,0.3)' }}>
                        <AppIcon name="grid" size={16} />
                    </button>
                )}
                <div className="dashboard-content" style={{ flex: 1, padding: section ? '28px 32px' : '0 32px', minWidth: 0, overflow: section ? 'visible' : 'hidden' }}>
                    {section && (
                        <div style={{ marginBottom: 24, paddingBottom: 16, borderBottom: '1px solid rgba(99,102,241,0.12)' }}>
                            <Link href="/ceo/dashboard" style={{ color: 'var(--accent-primary)', fontSize: 14, fontWeight: 600, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 6 }}>← Back to CEO Dashboard</Link>
                        </div>
                    )}
                    {/* ── Header ── */}
                    <div className="dashboard-header">
                        <div className="dashboard-title">
                            <h2>Executive Governance Console</h2>
                            <p>Enterprise health, KPIs, emergency command, analytics, and compliance</p>
                        </div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexShrink: 0 }}>
                        </div>
                    </div>

                    {/* ── Lockdown Banner ── */}
                    {emergency.lockdownStatus && (
                        <div className="alert-banner alert-error" style={{ marginBottom: '24px', animation: 'glowPulse 1.5s infinite' }}>
                            <AppIcon name="lock" size={16} /> System Lockdown Active — All visits are suspended until the CEO lifts the lockdown.
                        </div>
                    )}

                    {activeTab === 'overview' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '32px' }}>
                            {/* Enterprise KPIs */}
                            <div className="stats-grid">
                                <StatCard label="Total Registered Guests" value={stats.total_visitors} color="cyan" />
                                <StatCard label="Currently On Premises" value={stats.active_visitors} color="emerald" />
                                <StatCard label="Total Visit Requests" value={stats.total_requests} color="default" />
                                <StatCard label="Pending Appointments" value={stats.pending_requests} color="warning" />
                                <StatCard label="Approval Rate" value={`${stats.approval_rate}%`} color="emerald" />
                                <StatCard label="Rejection Rate" value={`${stats.rejection_rate}%`} color="danger" />
                                <StatCard label="Avg Visit Duration" value={`${stats.avg_duration} min`} color="blue" />
                                <StatCard label="Gate Status" value={emergency.lockdownStatus ? 'LOCKDOWN' : 'NORMAL'} color={emergency.lockdownStatus ? 'danger' : 'emerald'} />
                            </div>

                            {/* Today's Activity */}
                            <div className="glass-card">
                                <SectionTitle>Today's Activity</SectionTitle>
                                <div className="stats-grid">
                                    <StatCard label="Check-Ins Today" value={stats.today_checkins} color="emerald" />
                                    <StatCard label="Check-Outs Today" value={stats.today_checkouts} color="blue" />
                                    <StatCard label="New Requests Today" value={stats.today_pending} color="warning" />
                                    <StatCard label="Rejections Today" value={stats.today_rejections} color="danger" />
                                </div>
                            </div>

                            {/* Approval Performance Breakdown */}
                            <div className="glass-card">
                                <SectionTitle><AppIcon name="chart" size={16} /> Approval Performance Breakdown</SectionTitle>
                                <div className="grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20 }}>
                                    <div>
                                        <div style={{ fontSize: 13, fontWeight: 700, marginBottom: 8, color: 'var(--text-secondary)' }}>Approval vs Rejection</div>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                            <div style={{ flex: approval_performance.approval_rate, height: 24, background: 'rgba(16,185,129,0.25)', borderRadius: '6px 0 0 6px', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 700, color: '#10b981' }}>
                                                {approval_performance.approval_rate}%
                                            </div>
                                            <div style={{ flex: approval_performance.rejection_rate, height: 24, background: 'rgba(239,68,68,0.25)', borderRadius: '0 6px 6px 0', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 700, color: '#f87171' }}>
                                                {approval_performance.rejection_rate}%
                                            </div>
                                        </div>
                                        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: 'var(--text-muted)', marginTop: 4 }}>
                                            <span>Approved</span>
                                            <span>Rejected</span>
                                        </div>
                                    </div>
                                    <div>
                                        <div style={{ fontSize: 13, fontWeight: 700, marginBottom: 8, color: 'var(--text-secondary)' }}>Top Approvers</div>
                                        {approval_performance.top_approvers?.length > 0 ? (
                                            <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                                                {approval_performance.top_approvers.map((a, i) => (
                                                    <div key={i} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '4px 8px', borderRadius: 6, background: 'rgba(255,255,255,0.02)' }}>
                                                        <span style={{ fontSize: 12, fontWeight: 600 }}>{a.name}</span>
                                                        <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 8, background: 'rgba(16,185,129,0.15)', color: '#10b981', fontWeight: 700 }}>{a.count} approved</span>
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <p style={{ fontSize: 12, color: 'var(--text-muted)' }}>No approval data yet.</p>
                                        )}
                                    </div>
                                </div>
                            </div>


                        </div>
                    )}

                    {/* ══════════════════════════════════════════════════════
                TAB: VISITS
            ══════════════════════════════════════════════════════ */}
                    {activeTab === 'visits' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '32px' }}>
                            <div className="glass-card">
                                <SectionTitle><AppIcon name="calendar" size={16} /> Company Visits</SectionTitle>
                                <p style={{ fontSize: '12px', color: 'var(--text-muted)', marginBottom: 12 }}>
                                    Visits currently assigned to you (pending, approved, checked-in or escalated). Escalate a visit to an Admin or CEO to hand over responsibility for it.
                                </p>
                                {all_visits.length === 0 ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: '14px' }}>No visits to display.</p>
                                ) : (
                                    <div className="table-container" style={{ marginBottom: 0 }}>
                                        <table className="data-table">
                                            <thead>
                                                <tr>
                                                    <th>Visitor</th>
                                                    <th>Assigned Host</th>
                                                    <th>Status</th>
                                                    <th>Scheduled</th>
                                                    <th>Duration</th>
                                                    <th>Purpose</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {all_visits.map(v => (
                                                    <tr key={v.id}>
                                                        <td>
                                                            <div style={{ fontWeight: 600 }}>{v.visitor?.name || v.company_name || '—'}</div>
                                                            {v.company_name && <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.company_name}</div>}
                                                        </td>
                                                        <td>{v.host?.name || '—'}</td>
                                                        <td><span className={`badge badge-${v.status}`}>{String(v.status).replace(/_/g, ' ')}</span></td>
                                                        <td style={{ whiteSpace: 'nowrap', fontSize: '12px' }}>{fmtDateTime(v.requested_time)}</td>
                                                        <td style={{ fontSize: '12px' }}>{v.duration} min</td>
                                                        <td style={{ maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{v.purpose || '—'}</td>
                                                        <td>
                                                            <div style={{ position: 'relative' }}>
                                                                <button onClick={(e) => {
                                                                    if (visitMenuOpen === v.id) { setVisitMenuOpen(null); return; }
                                                                    const r = e.currentTarget.getBoundingClientRect();
                                                                    setVisitAnchor({ top: r.top, right: window.innerWidth - r.right, height: r.height });
                                                                    setVisitMenuOpen(v.id);
                                                                }} className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 10px' }}>
                                                                    <AppIcon name="settings" size={12} /> Actions <span style={{ marginLeft: 2, fontSize: 9 }}>▾</span>
                                                                </button>
                                                                {visitMenuOpen === v.id && createPortal(
                                                                    <div data-visit-menu style={{ ...visitMenuPanelStyle, top: visitAnchor.top + visitAnchor.height + 6, right: visitAnchor.right }}>
                                                                        {['pending', 'escalated'].includes(v.status) && (
                                                                            <VisitMenuBtn tone="var(--status-success)" onClick={() => openVisitConfirm(v, 'approve')}>
                                                                                <AppIcon name="check" size={13} /> Approve
                                                                            </VisitMenuBtn>
                                                                        )}
                                                                        {v.status === 'checked_in' && (
                                                                            <VisitMenuBtn danger onClick={() => openVisitConfirm(v, 'end')}>
                                                                                <AppIcon name="exit" size={13} /> End Visit
                                                                            </VisitMenuBtn>
                                                                        )}
                                                                        {['pending', 'escalated'].includes(v.status) && (
                                                                            <VisitMenuBtn danger onClick={() => openVisitConfirm(v, 'reject')}>
                                                                                <AppIcon name="close" size={13} /> Reject
                                                                            </VisitMenuBtn>
                                                                        )}
                                                                        {['pending', 'approved'].includes(v.status) && (
                                                                            <VisitMenuBtn onClick={() => { setVisitMenuOpen(null); openEscalate(v); }}>
                                                                                <AppIcon name="warning" size={13} /> Escalate
                                                                            </VisitMenuBtn>
                                                                        )}
                                                                        <VisitMenuBtn onClick={() => { setVisitMenuOpen(null); setTransferVisit(v); }}>
                                                                            <AppIcon name="users" size={13} /> Transfer
                                                                        </VisitMenuBtn>
                                                                    </div>,
                                                                    document.body
                                                                )}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ══════════════════════════════════════════════════════
                TAB: SECURITY RISK
            ══════════════════════════════════════════════════════ */}
                    {activeTab === 'security' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '32px' }}>
                            <div className="stats-grid">
                                <StatCard label="Blacklist Hits (All-Time)" value={security.blacklist_hits} color="danger" />
                                <StatCard label="Failed Validations Today" value={security.failed_validations} color="warning" />
                                <StatCard label="SLA-Overdue Escalations" value={security.sla_overdue} color="warning" />
                                <StatCard label="System Status" value={emergency.lockdownStatus ? 'LOCKDOWN' : 'NORMAL'} color={emergency.lockdownStatus ? 'danger' : 'emerald'} />
                            </div>

                            <div className="glass-card">
                                <SectionTitle>Recent Security Events</SectionTitle>
                                {security.recent_events.length === 0 ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: '14px' }}>No recent security events.</p>
                                ) : (
                                    <div className="table-container" style={{ marginBottom: 0 }}>
                                        <table className="data-table">
                                            <thead><tr><th>Timestamp</th><th>Action</th><th>Actor</th><th>IP</th></tr></thead>
                                            <tbody>
                                                {security.recent_events.map(log => (
                                                    <tr key={log.id}>
                                                        <td style={{ whiteSpace: 'nowrap' }}>{fmtDateTime(log.created_at)}</td>
                                                        <td>
                                                            <span className="badge badge-rejected" style={{ background: 'rgba(239,68,68,0.1)', color: 'var(--status-danger)', border: '1px solid rgba(239,68,68,0.3)' }}>
                                                                {log.action.replace(/_/g, ' ')}
                                                            </span>
                                                        </td>
                                                        <td>{(log.actor_type || 'system').toUpperCase()} #{log.actor_id || '—'}</td>
                                                        <td>{log.ip_address || '—'}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>

                            {incident_stats && (
                                <div style={{ display: 'flex', flexDirection: 'column', gap: '32px' }}>
                                    <div className="stats-grid">
                                        <StatCard label="Total Incidents" value={incident_stats.total || 0} color="danger" />
                                        <StatCard label="Open" value={incident_stats.open || 0} color="warning" />
                                        <StatCard label="Investigating" value={incident_stats.investigating || 0} color="cyan" />
                                        <StatCard label="Resolved" value={incident_stats.resolved || 0} color="emerald" />
                                        <StatCard label="Critical" value={incident_stats.critical || 0} color="danger" />
                                        <StatCard label="High" value={incident_stats.high || 0} color="warning" />
                                    </div>

                                    <div className="glass-card">
                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px', flexWrap: 'wrap', gap: '12px' }}>
                                            <SectionTitle>Active Incidents</SectionTitle>
                                            {active_incidents && active_incidents.length > 0 && (
                                                <button className="btn btn-danger" style={{ fontSize: '11px', padding: '5px 10px' }} onClick={() => { if (confirm('Delete ALL active incidents? This cannot be undone.')) router.delete('/ceo/incidents'); }}>
                                                    <AppIcon name="trash" size={12} /> Delete All
                                                </button>
                                            )}
                                        </div>
                                        {!active_incidents || active_incidents.length === 0 ? (
                                            <p style={{ color: 'var(--text-muted)', fontSize: '14px' }}>No active incidents at this time.</p>
                                        ) : (
                                            <div className="table-container" style={{ marginBottom: 0 }}>
                                                <table className="data-table">
                                                    <thead><tr><th>Title</th><th>Type</th><th>Severity</th><th>Status</th><th>Reported By</th><th>Created At</th><th>Action Taken</th><th>Actions</th></tr></thead>
                                                    <tbody>
                                                        {active_incidents.map(inc => {
                                                            const sevColors = {
                                                                critical: 'var(--status-danger)',
                                                                high: 'var(--status-warning)',
                                                                medium: 'var(--accent-cyan)',
                                                                low: 'var(--text-secondary)',
                                                            };
                                                            const sevBg = {
                                                                critical: 'rgba(239,68,68,0.1)',
                                                                high: 'rgba(245,158,11,0.1)',
                                                                medium: 'rgba(6,182,212,0.1)',
                                                                low: 'rgba(255,255,255,0.05)',
                                                            };
                                                            const sev = (inc.severity || 'medium').toLowerCase();
                                                            return (
                                                                <tr key={inc.id}>
                                                                    <td style={{ fontWeight: 600 }}>{inc.title}</td>
                                                                    <td>{inc.type ? inc.type.replace(/_/g, ' ') : '—'}</td>
                                                                    <td>
                                                                        <span className="badge" style={{ background: sevBg[sev] || sevBg.medium, color: sevColors[sev] || sevColors.medium, border: `1px solid ${sevColors[sev] || sevColors.medium}` }}>
                                                                            {(inc.severity || 'medium').toUpperCase()}
                                                                        </span>
                                                                    </td>
                                                                    <td>
                                                                        <span className={`badge badge-${inc.status === 'resolved' ? 'approved' : inc.status === 'open' ? 'pending' : 'rejected'}`}>
                                                                            {inc.status ? inc.status.replace(/_/g, ' ') : '—'}
                                                                        </span>
                                                                    </td>
                                                                    <td>{inc.reported_by ? (typeof inc.reported_by === 'object' ? inc.reported_by.name || '—' : inc.reported_by) : '—'}</td>
                                                                    <td style={{ whiteSpace: 'nowrap', fontSize: '12px' }}>{inc.created_at ? fmtDateTime(inc.created_at) : '—'}</td>
                                                                    <td style={{ fontSize: '12px', color: 'var(--text-secondary)', maxWidth: '200px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{inc.action_taken || '—'}</td>
                                                                    <td>
                                                                        <button className="btn btn-danger" style={{ fontSize: '10px', padding: '2px 8px' }} onClick={() => { if (confirm(`Delete incident #${inc.id}? This cannot be undone.`)) router.delete(`/ceo/incidents/${inc.id}`); }}>
                                                                            <AppIcon name="trash" size={11} /> Del
                                                                        </button>
                                                                    </td>
                                                                </tr>
                                                            );
                                                        })}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* ══════════════════════════════════════════════════════
                TAB: COMPLIANCE
            ══════════════════════════════════════════════════════ */}
                    {activeTab === 'compliance' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '32px' }}>
                            <div className="stats-grid">
                                <StatCard label="Within Business Hours %" value={`${compliance.compliance_rate}%`} color="emerald" />
                                <StatCard label="Processed Within SLA %" value={`${compliance.sla_rate}%`} color="cyan" />
                                <StatCard label="Weekend Visit Attempts" value={compliance.weekend_attempts} color="warning" />
                                <StatCard label="Active Admin Accounts" value={compliance.active_admins} color="blue" />
                                <StatCard label="Pending Admin Approvals" value={compliance.pending_admins_count} color="warning" />
                                <StatCard label="Suspended Accounts" value={compliance.suspended_count} color="danger" />
                            </div>

                            <div className="glass-card">
                                <SectionTitle>Compliance Metrics</SectionTitle>
                                <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
                                    {[
                                        { label: 'Business Hours Compliance', value: compliance.compliance_rate },
                                        { label: 'SLA Processing Rate', value: compliance.sla_rate },
                                        { label: 'Overall Approval Rate', value: stats.approval_rate },
                                    ].map(m => (
                                        <div key={m.label}>
                                            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '6px' }}>
                                                <span style={{ fontSize: '14px' }}>{m.label}</span>
                                                <span style={{ fontWeight: 700, color: m.value >= 80 ? 'var(--status-success)' : m.value >= 60 ? 'var(--status-warning)' : 'var(--status-danger)' }}>
                                                    {m.value}%
                                                </span>
                                            </div>
                                            <ProgressBar value={m.value} color={m.value >= 80 ? 'cyan' : m.value >= 60 ? 'warning' : 'danger'} />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ══════════════════════════════════════════════════════
                TAB: DEPARTMENTS
            ══════════════════════════════════════════════════════ */}
                    {activeTab === 'departments' && (
                        <div className="glass-card">
                            <SectionTitle>Department Performance</SectionTitle>
                            <div className="table-container" style={{ marginBottom: 0 }}>
                                <table className="data-table">
                                    <thead>
                                        <tr>
                                            <th>Department</th>
                                            <th>Capacity</th>
                                            <th>Active Hosts</th>
                                            <th>Total Visits</th>
                                            <th>Total Approved</th>
                                            <th>Active Now</th>
                                            <th>Utilization</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {department_statistics.map((d, i) => (
                                            <tr key={i} style={{ background: d.utilization >= 100 ? 'rgba(239,68,68,0.04)' : d.utilization >= 80 ? 'rgba(245,158,11,0.04)' : '' }}>
                                                <td style={{ fontWeight: 700 }}>{d.name}</td>
                                                <td>{d.capacity_limit}</td>
                                                <td>{d.hosts_count}</td>
                                                <td>{d.total_visits}</td>
                                                <td><span className="badge badge-approved" style={{ background: 'rgba(16,185,129,0.1)', color: 'var(--status-success)', border: '1px solid rgba(16,185,129,0.3)' }}>{d.total_approved || 0}</span></td>
                                                <td>
                                                    <span className={`badge ${d.active_now >= d.capacity_limit ? 'badge-rejected' : d.active_now >= d.capacity_limit * 0.8 ? 'badge-pending' : 'badge-approved'}`}>
                                                        {d.active_now}
                                                    </span>
                                                </td>
                                                <td style={{ minWidth: 160 }}>
                                                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                                                        <div style={{ flex: 1 }}>
                                                            <ProgressBar
                                                                value={d.utilization}
                                                                color={d.utilization >= 100 ? 'danger' : d.utilization >= 80 ? 'warning' : 'cyan'}
                                                            />
                                                        </div>
                                                        <span style={{
                                                            fontSize: '12px', fontWeight: 700, minWidth: 38, textAlign: 'right',
                                                            color: d.utilization >= 100 ? 'var(--status-danger)' : d.utilization >= 80 ? 'var(--status-warning)' : 'var(--accent-cyan)'
                                                        }}>
                                                            {d.utilization}%
                                                        </span>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* ══════════════════════════════════════════════════════
                TAB: ANALYTICS
            ══════════════════════════════════════════════════════ */}
                    {activeTab === 'analytics' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '32px' }}>
                            {/* Status Breakdown */}
                            <div className="glass-card">
                                <SectionTitle>Visit Status Breakdown</SectionTitle>
                                <div className="stats-grid">
                                    {Object.entries(analytics.status_breakdown).map(([status, count]) => (
                                        <StatCard
                                            key={status}
                                            label={status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                            value={count}
                                            color={status === 'approved' || status === 'checked_out' ? 'emerald'
                                                : status === 'pending' ? 'warning'
                                                    : status === 'rejected' ? 'danger'
                                                        : status === 'checked_in' ? 'cyan'
                                                            : 'default'}
                                        />
                                    ))}
                                </div>
                            </div>

                            {/* Monthly Trend */}
                            <div className="glass-card">
                                <SectionTitle>Monthly Visit Trends (Current Year)</SectionTitle>
                                <div style={{ display: 'flex', alignItems: 'flex-end', gap: '8px', height: '140px', padding: '0 8px' }}>
                                    {analytics.monthly_visits.map((m, i) => {
                                        const maxCount = Math.max(...analytics.monthly_visits.map(x => x.count), 1);
                                        const h = Math.max(8, Math.round((m.count / maxCount) * 120));
                                        return (
                                            <div key={i} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '4px' }}>
                                                <span style={{ fontSize: '10px', color: 'var(--text-secondary)' }}>{m.count}</span>
                                                <div style={{ width: '100%', height: `${h}px`, background: 'var(--accent-cyan)', opacity: 0.7, borderRadius: '3px 3px 0 0', transition: 'height 0.4s' }} />
                                                <span style={{ fontSize: '10px', color: 'var(--text-muted)' }}>{m.month}</span>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Top Hosts */}
                            <div className="glass-card">
                                <SectionTitle>Top 5 Most Visited Hosts</SectionTitle>
                                <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                                    {analytics.top_hosts.map((h, i) => {
                                        const maxCount = Math.max(...analytics.top_hosts.map(x => x.visit_count), 1);
                                        return (
                                            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                                                <span style={{ fontSize: '13px', minWidth: '20px', color: 'var(--text-secondary)' }}>#{i + 1}</span>
                                                <span style={{ fontSize: '14px', minWidth: '160px' }}>{h.name}</span>
                                                <div style={{ flex: 1 }}>
                                                    <ProgressBar value={h.visit_count} max={maxCount} />
                                                </div>
                                                <span style={{ fontSize: '13px', fontWeight: 700, minWidth: '30px', textAlign: 'right', color: 'var(--accent-cyan)' }}>
                                                    {h.visit_count}
                                                </span>
                                            </div>
                                        );
                                    })}
                                    {analytics.top_hosts.length === 0 && (
                                        <p style={{ color: 'var(--text-muted)', fontSize: '14px' }}>No visit data yet.</p>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ══════════════════════════════════════════════════════
                TAB: APPROVAL PERFORMANCE
            ══════════════════════════════════════════════════════ */}
                    {activeTab === 'approvals' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '32px' }}>
                            <div className="stats-grid">
                                <StatCard label="Total Approved" value={approval_performance.total_approved} color="emerald" />
                                <StatCard label="Total Rejected" value={approval_performance.total_rejected} color="danger" />
                                <StatCard label="Total Escalated" value={approval_performance.total_escalated} color="warning" />
                                <StatCard label="Approval Rate" value={`${approval_performance.approval_rate}%`} color="cyan" />
                                <StatCard label="Rejection Rate" value={`${approval_performance.rejection_rate}%`} color="danger" />
                                <StatCard label="Avg Time to Approve" value={approval_performance.avg_approval_minutes !== null ? `${approval_performance.avg_approval_minutes} min` : 'N/A'} color="blue" />
                            </div>

                            <div className="glass-card">
                                <SectionTitle>Approval vs Rejection Rate</SectionTitle>
                                <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                                    {[
                                        { label: 'Approval Rate', value: approval_performance.approval_rate, color: 'cyan' },
                                        { label: 'Rejection Rate', value: approval_performance.rejection_rate, color: 'danger' },
                                    ].map(m => (
                                        <div key={m.label}>
                                            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '6px' }}>
                                                <span style={{ fontSize: '14px' }}>{m.label}</span>
                                                <span style={{ fontWeight: 700 }}>{m.value}%</span>
                                            </div>
                                            <ProgressBar value={m.value} color={m.color} />
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div className="glass-card">
                                <SectionTitle>Top 5 Hosts by Approvals</SectionTitle>
                                <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                                    {approval_performance.top_approvers.map((h, i) => {
                                        const maxCount = Math.max(...approval_performance.top_approvers.map(x => x.count), 1);
                                        return (
                                            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                                                <span style={{ fontSize: '13px', minWidth: '20px', color: 'var(--text-secondary)' }}>#{i + 1}</span>
                                                <span style={{ fontSize: '14px', minWidth: '160px' }}>{h.name}</span>
                                                <div style={{ flex: 1 }}>
                                                    <ProgressBar value={h.count} max={maxCount} />
                                                </div>
                                                <span style={{ fontSize: '13px', fontWeight: 700, minWidth: '30px', textAlign: 'right', color: 'var(--accent-cyan)' }}>
                                                    {h.count}
                                                </span>
                                            </div>
                                        );
                                    })}
                                    {approval_performance.top_approvers.length === 0 && (
                                        <p style={{ color: 'var(--text-muted)', fontSize: '14px' }}>No approval data yet.</p>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ══════════════════════════════════════════════════════
                TAB: ESCALATIONS
            ══════════════════════════════════════════════════════ */}
                    {activeTab === 'escalations' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '32px' }}>
                            <div className="glass-card">
                                <SectionTitle><AppIcon name="emergency" size={16} /> Escalations</SectionTitle>
                                <p style={{ fontSize: '12px', color: 'var(--text-muted)', marginBottom: 12 }}>
                                    Visits you are responsible for as the escalation owner. Use the Actions menu to transfer, approve, edit, reject or delete a request.
                                </p>
                                {(escalations || []).length === 0 ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: '14px' }}>No escalations assigned to you right now.</p>
                                ) : (
                                    <div className="table-container" style={{ marginBottom: 0 }}>
                                        <table className="data-table">
                                            <thead>
                                                <tr>
                                                    <th>Visitor</th>
                                                    <th>Assigned Host</th>
                                                    <th>Status</th>
                                                    <th>Scheduled</th>
                                                    <th>Purpose</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {(escalations || []).map(v => (
                                                    <tr key={v.id}>
                                                        <td>
                                                            <div style={{ fontWeight: 600 }}>{v.visitor?.name || '—'}</div>
                                                            {v.company_name && <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.company_name}</div>}
                                                        </td>
                                                        <td>{v.host?.name || v.escalatedTo?.name || '—'}</td>
                                                        <td><span className={`badge badge-${v.status}`}>{String(v.status).replace(/_/g, ' ')}</span></td>
                                                        <td style={{ whiteSpace: 'nowrap', fontSize: '12px' }}>{fmtDateTime(v.requested_time)}</td>
                                                        <td style={{ maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{v.purpose || '—'}</td>
                                                        <td>
                                                            <div style={{ position: 'relative' }}>
                                                                <button onClick={() => setEscMenuOpen(escMenuOpen === v.id ? null : v.id)} className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 10px' }}>
                                                                    <AppIcon name="settings" size={12} /> Actions
                                                                </button>
                                                                {escMenuOpen === v.id && (
                                                                    <div style={{ position: 'absolute', right: 0, top: '100%', marginTop: 4, zIndex: 20, background: 'var(--glass-bg)', border: '1px solid var(--glass-border)', borderRadius: 8, boxShadow: '0 8px 24px rgba(0,0,0,0.35)', minWidth: 170, overflow: 'hidden' }}>
                                                                        <button onClick={() => { openEscTransfer(v); setEscMenuOpen(null); }} style={{ display: 'flex', alignItems: 'center', gap: 8, width: '100%', padding: '9px 12px', fontSize: 13, color: 'var(--text-primary)', background: 'transparent', border: 0, cursor: 'pointer', textAlign: 'left' }}>
                                                                            <AppIcon name="users" size={13} /> Transfer
                                                                        </button>
                                                                        <button onClick={() => { escApprove(v); setEscMenuOpen(null); }} style={{ display: 'flex', alignItems: 'center', gap: 8, width: '100%', padding: '9px 12px', fontSize: 13, color: 'var(--status-success)', background: 'transparent', border: 0, cursor: 'pointer', textAlign: 'left' }}>
                                                                            <AppIcon name="check" size={13} /> Approve
                                                                        </button>
                                                                        <button onClick={() => openEscAction(v, 'edit')} style={{ display: 'flex', alignItems: 'center', gap: 8, width: '100%', padding: '9px 12px', fontSize: 13, color: 'var(--text-primary)', background: 'transparent', border: 0, cursor: 'pointer', textAlign: 'left' }}>
                                                                            <AppIcon name="calendar" size={13} /> Edit
                                                                        </button>
                                                                        <button onClick={() => openEscAction(v, 'reject')} style={{ display: 'flex', alignItems: 'center', gap: 8, width: '100%', padding: '9px 12px', fontSize: 13, color: 'var(--status-danger)', background: 'transparent', border: 0, cursor: 'pointer', textAlign: 'left' }}>
                                                                            <AppIcon name="close" size={13} /> Reject
                                                                        </button>
                                                                        <div style={{ height: 1, background: 'var(--glass-border)' }} />
                                                                        <button onClick={() => escDelete(v)} style={{ display: 'flex', alignItems: 'center', gap: 8, width: '100%', padding: '9px 12px', fontSize: 13, color: 'var(--status-danger)', background: 'transparent', border: 0, cursor: 'pointer', textAlign: 'left' }}>
                                                                            <AppIcon name="trash" size={13} /> Delete
                                                                        </button>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ══════════════════════════════════════════════════════
                TAB: ADMIN APPROVAL
            ══════════════════════════════════════════════════════ */}
                    {activeTab === 'admins' && (
                        <div className="glass-card">
                            <SectionTitle>Pending Administrator Registrations</SectionTitle>
                            {pending_admins.length === 0 ? (
                                <p style={{ color: 'var(--text-muted)', fontSize: '14px' }}>No administrator registrations require review.</p>
                            ) : (
                                <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                                    {pending_admins.map(admin => (
                                        <div key={admin.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', background: 'rgba(255,255,255,0.02)', padding: '14px 16px', borderRadius: '8px', border: '1px solid var(--glass-border)' }}>
                                            <div>
                                                <div style={{ fontWeight: 600, fontSize: '15px' }}>{admin.name}</div>
                                                <div style={{ fontSize: '12px', color: 'var(--text-secondary)', marginTop: '4px' }}>
                                                    {admin.email} &nbsp;·&nbsp; {admin.phone || 'No phone'}
                                                </div>
                                                <span className="badge badge-pending" style={{ marginTop: '6px', display: 'inline-block' }}>Admin</span>
                                            </div>
                                            <div style={{ display: 'flex', gap: '8px' }}>
                                                <button onClick={() => handleAdminApproval(admin.id, 'approve')} className="btn btn-primary" style={{ padding: '8px 16px', fontSize: '13px' }}>
                                                    <AppIcon name="check" size={14} /> Approve
                                                </button>
                                                <button onClick={() => handleAdminApproval(admin.id, 'reject')} className="btn btn-danger" style={{ padding: '8px 16px', fontSize: '13px' }}>
                                                    <AppIcon name="close" size={14} /> Decline
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {/* ══════════════════════════════════════════════════════
                TAB: EMERGENCY COMMAND CENTER
            ══════════════════════════════════════════════════════ */}
                    {activeTab === 'emergency' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '32px' }}>
                            {emergencyMsg && (
                                <div style={{ padding: '12px 16px', borderRadius: '8px', fontSize: '13px', fontWeight: 600, background: emergencyMsg.type === 'success' ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)', border: `1px solid ${emergencyMsg.type === 'success' ? 'rgba(16,185,129,0.4)' : 'rgba(239,68,68,0.4)'}`, color: emergencyMsg.type === 'success' ? 'var(--status-success)' : 'var(--status-danger)' }}>
                                    {emergencyMsg.text}
                                </div>
                            )}
                            {/* Active Lockdowns */}
                            <div className="glass-card" style={{ border: emergency.lockdowns.length ? '1px solid var(--status-danger)' : '1px solid var(--glass-border)' }}>
                                <SectionTitle><AppIcon name="lock" size={16} /> Active Lockdowns</SectionTitle>
                                {emergency.lockdowns.length === 0 ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: '14px' }}>No active lockdowns.</p>
                                ) : (
                                    <div className="table-container" style={{ marginBottom: 0 }}>
                                        <table className="data-table">
                                            <thead><tr><th>Scope</th><th>Branch</th><th>Reason</th><th>Declared By</th><th>Started At</th><th>Actions</th></tr></thead>
                                            <tbody>
                                                {emergency.lockdowns.map(l => (
                                                    <tr key={l.id}>
                                                        <td>
                                                            <span className={`badge ${l.scope_type === 'branch' ? 'badge-pending' : 'badge-rejected'}`}>
                                                                {l.scope_type === 'branch' ? 'BRANCH' : 'COMPANY'}
                                                            </span>
                                                        </td>
                                                        <td>{l.branch_name || 'All Branches'}</td>
                                                        <td style={{ fontSize: '12px' }}>{l.reason || '—'}</td>
                                                        <td style={{ fontSize: '12px' }}>{l.started_by || '—'}</td>
                                                        <td style={{ whiteSpace: 'nowrap', fontSize: '12px' }}>{l.started_at ? fmtDateTime(l.started_at) : '—'}</td>
                                                        <td>
                                                            <button className="btn btn-secondary" style={{ fontSize: '11px', padding: '4px 10px' }} onClick={() => handleLiftLockdown(l.id)} disabled={emergencyBusy === `lift-${l.id}`}>
                                                                {emergencyBusy === `lift-${l.id}` ? 'Lifting...' : <><AppIcon name="unlock" size={12} /> Lift Lockdown</>}
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>

                            {/* Lockdown Controls */}
                            <div className="glass-card" style={{ border: emergency.lockdownStatus ? '1px solid var(--status-danger)' : '1px solid var(--glass-border)' }}>
                                <SectionTitle><AppIcon name="lock" size={16} /> Quick Lockdown Control</SectionTitle>
                                <p style={{ fontSize: '14px', color: 'var(--text-secondary)', marginBottom: '20px' }}>
                                    Activating a lockdown immediately rejects pending visits in scope, blocks new check-ins/check-outs, and notifies all active employees in scope.
                                </p>
                                <form onSubmit={handleLockdownToggle} style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' }}>
                                        <div className="form-group" style={{ marginBottom: 0 }}>
                                            <label className="form-label">Scope</label>
                                            <select className="form-select" value={lockdownForm.data.scope_type} onChange={e => lockdownForm.setData('scope_type', e.target.value)}>
                                                <option value="company">Entire Company (all branches)</option>
                                                <option value="branch">One Specific Branch</option>
                                            </select>
                                        </div>
                                        {lockdownForm.data.scope_type === 'branch' && (
                                            <div className="form-group" style={{ marginBottom: 0 }}>
                                                <label className="form-label">Branch</label>
                                                <select className="form-select" value={lockdownForm.data.branch_id} onChange={e => lockdownForm.setData('branch_id', e.target.value)}>
                                                    <option value="">— Select Branch —</option>
                                                    {branches.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                                </select>
                                            </div>
                                        )}
                                    </div>
                                    <div className="form-group" style={{ marginBottom: 0 }}>
                                        <label className="form-label">Reason</label>
                                        <input type="text" className="form-input" placeholder="Reason for this lockdown" value={lockdownForm.data.reason} onChange={e => lockdownForm.setData('reason', e.target.value)} />
                                    </div>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '20px', flexWrap: 'wrap' }}>
                                        <div style={{ padding: '12px 20px', borderRadius: '8px', background: emergency.lockdownStatus ? 'rgba(239,68,68,0.1)' : 'rgba(16,185,129,0.1)', border: `1px solid ${emergency.lockdownStatus ? 'rgba(239,68,68,0.4)' : 'rgba(16,185,129,0.4)'}` }}>
                                            <span style={{ fontWeight: 700, fontSize: '15px', color: emergency.lockdownStatus ? 'var(--status-danger)' : 'var(--status-success)' }}>
                                                {emergency.lockdownStatus ? <><AppIcon name="lock" size={14} /> LOCKDOWN ACTIVE</> : <><AppIcon name="unlock" size={14} /> SYSTEM NORMAL</>}
                                            </span>
                                        </div>
                                        <button type="submit" className={`btn ${emergency.lockdownStatus ? 'btn-secondary' : 'btn-danger'}`} style={{ padding: '12px 28px', fontSize: '14px', fontWeight: 800 }} disabled={emergencyBusy === 'lockdown'}>
                                            {emergencyBusy === 'lockdown' ? 'Working...' : <><AppIcon name={emergency.lockdownStatus ? 'unlock' : 'lock'} size={14} /> {emergency.lockdownStatus ? 'LIFT LOCKDOWN' : 'ACTIVATE LOCKDOWN'}</>}
                                        </button>
                                    </div>
                                </form>
                            </div>

                            {/* Broadcast Announcements */}
                            <div className="glass-card">
                                <SectionTitle><AppIcon name="broadcast" size={16} /> Broadcast Announcement</SectionTitle>
                                <p style={{ fontSize: '13px', color: 'var(--text-secondary)', marginBottom: '20px' }}>
                                    Send an immediate notification to employees in the selected scope. Branch-scoped announcements never reach other branches.
                                </p>
                                <form onSubmit={handleBroadcast} style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '16px' }}>
                                        <div className="form-group" style={{ marginBottom: 0 }}>
                                            <label className="form-label">Scope</label>
                                            <select className="form-select" value={broadcastForm.data.scope_type} onChange={e => broadcastForm.setData('scope_type', e.target.value)}>
                                                <option value="company">Entire Company (all branches)</option>
                                                <option value="branch">One Specific Branch</option>
                                            </select>
                                        </div>
                                        {broadcastForm.data.scope_type === 'branch' && (
                                            <div className="form-group" style={{ marginBottom: 0 }}>
                                                <label className="form-label">Branch</label>
                                                <select className="form-select" value={broadcastForm.data.branch_id} onChange={e => broadcastForm.setData('branch_id', e.target.value)}>
                                                    <option value="">— Select Branch —</option>
                                                    {branches.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                                </select>
                                            </div>
                                        )}
                                        <div className="form-group" style={{ marginBottom: 0 }}>
                                            <label className="form-label">Priority Level</label>
                                            <select className="form-select" value={broadcastForm.data.priority} onChange={e => broadcastForm.setData('priority', e.target.value)}>
                                                <option value="info">Info</option>
                                                <option value="warning">Warning</option>
                                                <option value="critical">Critical</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div className="form-group" style={{ marginBottom: 0 }}>
                                        <label className="form-label">Message <span style={{ fontSize: '12px', color: 'var(--text-muted)', fontWeight: 400 }}>({broadcastForm.data.message.length}/500)</span></label>
                                        <textarea
                                            className="form-textarea"
                                            rows={4}
                                            maxLength={500}
                                            placeholder="Type your announcement here..."
                                            value={broadcastForm.data.message}
                                            onChange={e => broadcastForm.setData('message', e.target.value)}
                                            required
                                        />
                                        {broadcastForm.errors.message && <div style={{ color: 'var(--status-danger)', fontSize: '12px', marginTop: '4px' }}>{broadcastForm.errors.message}</div>}
                                    </div>
                                    <button type="submit" className="btn btn-primary" style={{ alignSelf: 'flex-start', padding: '10px 28px' }} disabled={emergencyBusy === 'broadcast'}>
                                        {emergencyBusy === 'broadcast' ? 'Sending...' : <><AppIcon name="broadcast" size={14} /> Send to Selected Scope</>}
                                    </button>
                                </form>
                            </div>

                            {/* Broadcast History */}
                            <div className="glass-card">
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '12px', flexWrap: 'wrap' }}>
                                    <SectionTitle>Broadcast History</SectionTitle>
                                    <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                                        <button className="btn btn-secondary" style={{ fontSize: '11px', padding: '5px 10px' }} onClick={() => setShowBroadcasts(s => !s)}>
                                            <AppIcon name="eye" size={12} /> {showBroadcasts ? 'Hide' : 'View'}
                                        </button>
                                        <button className="btn btn-danger" style={{ fontSize: '11px', padding: '5px 10px' }} onClick={() => { if (confirm('Delete ALL broadcasts for your company? This cannot be undone.')) router.delete('/ceo/broadcasts'); }}>
                                            <AppIcon name="trash" size={12} /> Delete All
                                        </button>
                                    </div>
                                </div>
                                <p style={{ color: 'var(--text-muted)', fontSize: '13px' }}>{(emergency.broadcasts || []).length} broadcast(s) recorded.</p>
                                {showBroadcasts && (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: '12px', marginTop: '12px' }}>
                                        {emergency.broadcasts.length === 0 ? (
                                            <p style={{ color: 'var(--text-muted)', fontSize: '14px' }}>No broadcasts sent yet.</p>
                                        ) : emergency.broadcasts.map((b, i) => (
                                            <div key={i} style={{ padding: '12px 14px', borderRadius: '6px', background: 'rgba(255,255,255,0.02)', border: `1px solid ${b.priority === 'critical' ? 'rgba(239,68,68,0.3)' : b.priority === 'warning' ? 'rgba(245,158,11,0.3)' : 'var(--glass-border)'}` }}>
                                                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '6px', flexWrap: 'wrap', gap: '8px' }}>
                                                    <span style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                                                        <span className={`badge ${b.priority === 'critical' ? 'badge-rejected' : b.priority === 'warning' ? 'badge-pending' : 'badge-approved'}`}>
                                                            {b.priority.toUpperCase()}
                                                        </span>
                                                        <span className="badge badge-pending" style={{ fontSize: '10px' }}>{b.scope_type === 'branch' ? (b.branch_name ? `BRANCH: ${b.branch_name}` : 'BRANCH') : 'COMPANY'}</span>
                                                    </span>
                                                    <span style={{ fontSize: '11px', color: 'var(--text-muted)' }}>{b.timestamp ? fmtDateTime(b.timestamp) : ''} {b.creator ? `· ${b.creator}` : ''}</span>
                                                </div>
                                                <p style={{ fontSize: '13px', color: 'var(--text-secondary)', margin: 0 }}>{b.message}</p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ══════════════════════════════════════════════════════
                TAB: AUDIT REVIEW
            ══════════════════════════════════════════════════════ */}
                    {activeTab === 'audit' && (
                        <div className="glass-card">
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px', flexWrap: 'wrap', gap: '12px' }}>
                                <SectionTitle>System Audit Log</SectionTitle>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                                    <button className="btn btn-danger" style={{ fontSize: '11px', padding: '5px 10px' }} onClick={() => { if (confirm('Delete ALL audit records for your scope? This cannot be undone.')) router.delete('/ceo/audit-logs'); }}>
                                        <AppIcon name="trash" size={12} /> Delete All
                                    </button>
                                    <input
                                        type="text"
                                        className="form-input"
                                        placeholder="Filter by action (e.g. login, lockdown, approve)..."
                                        value={auditFilter}
                                        onChange={e => setAuditFilter(e.target.value)}
                                        style={{ maxWidth: '320px', padding: '8px 12px', fontSize: '13px' }}
                                    />
                                </div>
                            </div>
                            <div className="table-container">
                                <table className="data-table">
                                    <thead>
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>Actor</th>
                                            <th>Action</th>
                                            <th>Entity</th>
                                            <th>IP Address</th>
                                            <th>Details</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filteredAudit.map(log => (
                                            <tr key={log.id}>
                                                <td style={{ whiteSpace: 'nowrap', fontSize: '12px' }}>{fmtDateTime(log.created_at)}</td>
                                                <td style={{ fontSize: '12px' }}>{(log.actor_type || 'system').toUpperCase()} #{log.actor_id || '—'}</td>
                                                <td>
                                                    <span className="badge badge-approved" style={{
                                                        backgroundColor: log.action.includes('fail') || log.action.includes('lockdown') || log.action.includes('blacklist') ? 'rgba(239,68,68,0.1)' : 'rgba(16,185,129,0.1)',
                                                        color: log.action.includes('fail') || log.action.includes('lockdown') || log.action.includes('blacklist') ? 'var(--status-danger)' : 'var(--status-success)',
                                                        border: '1px solid currentColor',
                                                    }}>
                                                        {log.action.replace(/_/g, ' ')}
                                                    </span>
                                                </td>
                                                <td style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>{log.entity || '—'} {log.entity_id ? `#${log.entity_id}` : ''}</td>
                                                <td style={{ fontSize: '12px' }}>{log.ip_address || '—'}</td>
                                                <td style={{ fontSize: '11px', color: 'var(--text-muted)', maxWidth: '200px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                    {log.after_value ? JSON.stringify(log.after_value).substring(0, 70) : '—'}
                                                </td>
                                                <td>
                                                    <button className="btn btn-danger" style={{ fontSize: '10px', padding: '2px 8px' }} onClick={() => { if (confirm(`Delete audit record #${log.id}? This cannot be undone.`)) router.delete(`/ceo/audit-logs/${log.id}`); }}>
                                                        <AppIcon name="trash" size={11} /> Del
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                        {filteredAudit.length === 0 && (
                                            <tr><td colSpan={7} style={{ textAlign: 'center', color: 'var(--text-muted)', padding: '24px' }}>No matching audit records.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            <p style={{ fontSize: '12px', color: 'var(--text-muted)', marginTop: '8px' }}>Showing latest 50 records. Use Export tab for full audit CSV.</p>
                        </div>
                    )}

                    {/* ══════════════════════════════════════════════════════
                TAB: POLICY APPROVAL
            ══════════════════════════════════════════════════════ */}
                    {activeTab === 'policy' && (
                        <div className="glass-card" style={{ maxWidth: '640px' }}>
                            <SectionTitle><AppIcon name="settings" size={16} /> System Policy Configuration</SectionTitle>
                            <p style={{ fontSize: '13px', color: 'var(--text-secondary)', marginBottom: '24px' }}>
                                These settings take effect immediately across the validation engine and approval service.
                            </p>
                            <form onSubmit={handlePolicyUpdate} style={{ display: 'flex', flexDirection: 'column', gap: '24px' }}>
                                {/* Weekend Visits */}
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '16px', background: 'rgba(255,255,255,0.02)', borderRadius: '8px', border: '1px solid var(--glass-border)' }}>
                                    <div>
                                        <div style={{ fontWeight: 600, fontSize: '14px' }}>Allow Weekend Visits</div>
                                        <div style={{ fontSize: '12px', color: 'var(--text-secondary)', marginTop: '4px' }}>
                                            When disabled, visitors cannot request appointments on Saturday or Sunday.
                                        </div>
                                    </div>
                                    <label style={{ position: 'relative', display: 'inline-block', width: '48px', height: '26px', cursor: 'pointer' }}>
                                        <input
                                            type="checkbox"
                                            style={{ opacity: 0, width: 0, height: 0 }}
                                            checked={!!policyForm.data.allow_weekends}
                                            onChange={e => policyForm.setData('allow_weekends', e.target.checked)}
                                        />
                                        <span style={{
                                            position: 'absolute', top: 0, left: 0, right: 0, bottom: 0,
                                            background: policyForm.data.allow_weekends ? 'var(--accent-cyan)' : 'rgba(255,255,255,0.15)',
                                            borderRadius: '13px', transition: '0.3s',
                                        }}>
                                            <span style={{
                                                position: 'absolute', top: '3px',
                                                left: policyForm.data.allow_weekends ? '26px' : '3px',
                                                width: '20px', height: '20px',
                                                background: '#fff', borderRadius: '50%', transition: '0.3s',
                                            }} />
                                        </span>
                                    </label>
                                </div>

                                {/* Business Hours */}
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '16px', background: 'rgba(255,255,255,0.02)', borderRadius: '8px', border: '1px solid var(--glass-border)' }}>
                                    <div>
                                        <div style={{ fontWeight: 600, fontSize: '14px' }}>Enforce Business Hours (08:00–18:00)</div>
                                        <div style={{ fontSize: '12px', color: 'var(--text-secondary)', marginTop: '4px' }}>
                                            When enabled, visit requests outside standard operating hours are rejected.
                                        </div>
                                    </div>
                                    <label style={{ position: 'relative', display: 'inline-block', width: '48px', height: '26px', cursor: 'pointer' }}>
                                        <input
                                            type="checkbox"
                                            style={{ opacity: 0, width: 0, height: 0 }}
                                            checked={!!policyForm.data.enforce_biz_hours}
                                            onChange={e => policyForm.setData('enforce_biz_hours', e.target.checked)}
                                        />
                                        <span style={{
                                            position: 'absolute', top: 0, left: 0, right: 0, bottom: 0,
                                            background: policyForm.data.enforce_biz_hours ? 'var(--accent-cyan)' : 'rgba(255,255,255,0.15)',
                                            borderRadius: '13px', transition: '0.3s',
                                        }}>
                                            <span style={{
                                                position: 'absolute', top: '3px',
                                                left: policyForm.data.enforce_biz_hours ? '26px' : '3px',
                                                width: '20px', height: '20px',
                                                background: '#fff', borderRadius: '50%', transition: '0.3s',
                                            }} />
                                        </span>
                                    </label>
                                </div>

                                {/* SLA Minutes */}
                                <div style={{ padding: '16px', background: 'rgba(255,255,255,0.02)', borderRadius: '8px', border: '1px solid var(--glass-border)' }}>
                                    <div style={{ fontWeight: 600, fontSize: '14px', marginBottom: '4px' }}>Auto-Escalation SLA Timeout</div>
                                    <div style={{ fontSize: '12px', color: 'var(--text-secondary)', marginBottom: '12px' }}>
                                        Pending requests idle longer than this duration (minutes) are auto-escalated to Admin.
                                    </div>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                                        <input
                                            type="number"
                                            className="form-input"
                                            min={1} max={60}
                                            style={{ width: '100px' }}
                                            value={policyForm.data.sla_minutes}
                                            onChange={e => policyForm.setData('sla_minutes', Number(e.target.value))}
                                        />
                                        <span style={{ fontSize: '13px', color: 'var(--text-secondary)' }}>minutes</span>
                                    </div>
                                    {policyForm.errors.sla_minutes && <div style={{ color: 'var(--status-danger)', fontSize: '12px', marginTop: '4px' }}>{policyForm.errors.sla_minutes}</div>}
                                </div>

                                <button type="submit" className="btn btn-primary" style={{ alignSelf: 'flex-start', padding: '10px 28px' }} disabled={policyForm.processing}>
                                    {policyForm.processing ? 'Saving...' : <><AppIcon name="save" size={14} /> Save Policies</>}
                                </button>
                            </form>
                        </div>
                    )}

                    {/* ══════════════════════════════════════════════════════
                TAB: EXPORT REPORTS
            ══════════════════════════════════════════════════════ */}
                    {activeTab === 'export' && (
                        <div className="grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '32px' }}>
                            {/* Visit Requests Export */}
                            <div className="glass-card" style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                                <SectionTitle><AppIcon name="download" size={16} /> Visit Requests Report</SectionTitle>
                                <p style={{ fontSize: '13px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                                    Exports all visit requests as a CSV file including visitor name, email, host, department, purpose, scheduled time, duration, status, approval/rejection timestamps, and comments.
                                </p>
                                <ul style={{ fontSize: '12px', color: 'var(--text-muted)', paddingLeft: '16px', lineHeight: '2' }}>
                                    <li>ID, Visitor Name, Visitor Email</li>
                                    <li>Host Name, Department</li>
                                    <li>Purpose, Requested Time, Duration</li>
                                    <li>Status, Approved At, Rejected At</li>
                                    <li>Comments</li>
                                </ul>
                                <a
                                    href="/ceo/export/visits"
                                    className="btn btn-primary"
                                    style={{ alignSelf: 'flex-start', padding: '10px 24px', textDecoration: 'none' }}
                                >
                                    <AppIcon name="download" size={14} /> Download Visits CSV
                                </a>
                                <p style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
                                    File: <code>vms-visits-{todayStr()}.csv</code>
                                </p>
                            </div>

                            {/* Audit Log Export */}
                            <div className="glass-card" style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                                <SectionTitle><AppIcon name="file" size={16} /> Audit Log Report</SectionTitle>
                                <p style={{ fontSize: '13px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                                    Exports the complete system audit trail as a CSV file including all actor actions, timestamps, and IP addresses for compliance and governance review.
                                </p>
                                <ul style={{ fontSize: '12px', color: 'var(--text-muted)', paddingLeft: '16px', lineHeight: '2' }}>
                                    <li>Timestamp, Actor Type, Actor ID</li>
                                    <li>Action, Entity, Entity ID</li>
                                    <li>IP Address</li>
                                </ul>
                                <a
                                    href="/ceo/export/audit"
                                    className="btn btn-primary"
                                    style={{ alignSelf: 'flex-start', padding: '10px 24px', textDecoration: 'none' }}
                                >
                                    <AppIcon name="download" size={14} /> Download Audit CSV
                                </a>
                                <p style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
                                    File: <code>vms-audit-{todayStr()}.csv</code>
                                </p>
                            </div>
                        </div>
                    )}

                    {activeTab === 'messages' && (
                        <Messages
                            inbox={messages_inbox || []}
                            sent={messages_sent || []}
                            recipients={messages_recipients || []}
                            unread_count={messages_unread_count || 0}
                            sender_type="user"
                            sender_id={user.id}
                            embedded
                        />
                    )}

                </div> {/* End main content */}
            </div>

            {/* Escalate Visit Modal */}
            {escModal && (
                <div className="modal-overlay" style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 }} onClick={() => setEscModal(null)}>
                    <div className="glass-card modal-content" style={{ width: 480, maxWidth: '90vw', padding: 24, border: '1px solid rgba(245,158,11,0.4)' }} onClick={e => e.stopPropagation()}>
                        <h3 style={{ fontWeight: 700, fontSize: 16, marginBottom: 16, display: 'flex', alignItems: 'center', gap: 8 }}>
                            <AppIcon name="warning" size={18} /> Escalate Visit #{escModal.id}
                        </h3>
                        <div style={{ padding: '12px', borderRadius: 8, background: 'rgba(245,158,11,0.06)', border: '1px solid rgba(245,158,11,0.2)', marginBottom: 16, fontSize: 12, color: 'var(--text-secondary)' }}>
                            <strong>Visitor:</strong> {escModal.visitor?.name || escModal.company_name}<br />
                            <strong>Purpose:</strong> {escModal.purpose}<br />
                            <strong>Status:</strong> <span className={`badge badge-${escModal.status}`}>{escModal.status.replace(/_/g, ' ')}</span>
                        </div>
                        <form onSubmit={e => { e.preventDefault(); submitEscalate(escModal); }}>
                            <div style={{ marginBottom: 12 }}>
                                <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Escalate to (Admin) *</label>
                                <select className="form-input" value={escTarget} onChange={e => setEscTarget(e.target.value)} required style={{ width: '100%' }}>
                                    <option value="">Select Admin...</option>
                                    {eligibleEscalationTargets(escModal).map(u => (
                                        <option key={u.id} value={u.id}>{u.name} (Admin){u.department?.name ? ` — ${u.department.name}` : ''}</option>
                                    ))}
                                </select>
                                {eligibleEscalationTargets(escModal).length === 0 && (
                                    <span style={{ fontSize: 12, color: 'var(--status-danger)' }}>No active Admin in this company.</span>
                                )}
                            </div>
                            <div style={{ marginBottom: 12 }}>
                                <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Reason for Escalation</label>
                                <textarea className="form-input" value={escReason} onChange={e => setEscReason(e.target.value)} rows={3} placeholder="Why are you escalating this visit?" style={{ width: '100%' }} />
                            </div>
                            <div style={{ padding: '10px 12px', borderRadius: 8, background: 'rgba(99,102,241,0.06)', border: '1px solid rgba(99,102,241,0.15)', marginBottom: 16, fontSize: 11, color: 'var(--text-secondary)' }}>
                                The selected Admin becomes the responsible person for this visit. Once escalated, the visitor can be received and checked in by them. This can be transferred back to a host at any time.
                            </div>
                            <div style={{ display: 'flex', gap: 8 }}>
                                <button type="button" onClick={() => setEscModal(null)} className="btn btn-secondary">Cancel</button>
                                <button type="submit" className="btn btn-primary" disabled={!escTarget}>Confirm Escalation</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Company Visit Action Confirm Modal */}
            {visitConfirm && (
                <div className="modal-overlay" style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 }} onClick={() => setVisitConfirm(null)}>
                    <div className="glass-card modal-content" style={{ width: 440, maxWidth: '90vw', padding: 24, border: '1px solid rgba(99,102,241,0.3)' }} onClick={e => e.stopPropagation()}>
                        <h3 style={{ fontWeight: 700, fontSize: 16, marginBottom: 16, display: 'flex', alignItems: 'center', gap: 8 }}>
                            <AppIcon name={visitConfirm.action === 'end' ? 'exit' : visitConfirm.action === 'approve' ? 'check' : 'close'} size={18} />
                            {visitConfirm.action === 'approve' ? 'Approve' : visitConfirm.action === 'end' ? 'End Visit' : 'Reject Visit'} #{visitConfirm.visit.id}
                        </h3>
                        <p style={{ fontSize: 13, marginBottom: 12 }}>
                            {visitConfirm.action === 'approve' && <>Approve the visit for <strong>{visitConfirm.visit.visitor?.name || visitConfirm.visit.company_name || 'this visitor'}</strong>? The visitor will receive their QR pass.</>}
                            {visitConfirm.action === 'end' && <>End the active visit for <strong>{visitConfirm.visit.visitor?.name || visitConfirm.visit.company_name || 'this visitor'}</strong>? The visitor will be checked out, the visit closed and a valid <strong>Exit QR code</strong> generated and sent to the visitor for the security exit gate. The record is kept for history and reporting.</>}
                            {visitConfirm.action === 'reject' && <>Reject the visit for <strong>{visitConfirm.visit.visitor?.name || visitConfirm.visit.company_name || 'this visitor'}</strong>? A reason is required.</>}
                        </p>
                        {visitConfirm.action === 'reject' && (
                            <div style={{ marginBottom: 16 }}>
                                <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Reason for rejection</label>
                                <textarea className="form-input" value={visitRejectReason} onChange={e => setVisitRejectReason(e.target.value)} rows={3} placeholder="Required..." style={{ width: '100%' }} />
                            </div>
                        )}
                        <div style={{ display: 'flex', gap: 8 }}>
                            <button type="button" onClick={() => setVisitConfirm(null)} className="btn btn-secondary">Cancel</button>
                            <button type="button" onClick={submitVisitConfirm} className={visitConfirm.action === 'approve' ? 'btn btn-primary' : 'btn btn-danger'}>
                                {visitConfirm.action === 'approve' ? 'Confirm Approval' : visitConfirm.action === 'end' ? 'Confirm End Visit' : 'Confirm Rejection'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Escalation Edit / Reject Modal */}
            {escAction && (
                <div className="modal-overlay" style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 }} onClick={() => setEscAction(null)}>
                    <div className="glass-card modal-content" style={{ width: 460, maxWidth: '90vw', padding: 24, border: '1px solid rgba(99,102,241,0.3)' }} onClick={e => e.stopPropagation()}>
                        <h3 style={{ fontWeight: 700, fontSize: 16, marginBottom: 16, display: 'flex', alignItems: 'center', gap: 8 }}>
                            <AppIcon name={escAction.mode === 'edit' ? 'calendar' : 'close'} size={18} />
                            {escAction.mode === 'edit' ? `Edit Visit #${escAction.visit.id}` : `Reject Visit #${escAction.visit.id}`}
                        </h3>
                        {escAction.mode === 'edit' ? (
                            <form onSubmit={e => { e.preventDefault(); submitEscAction(); }}>
                                <div style={{ marginBottom: 12 }}>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Date &amp; Time</label>
                                    <input type="datetime-local" className="form-input" value={escEditTime} onChange={e => setEscEditTime(e.target.value)} required style={{ width: '100%' }} />
                                </div>
                                <div style={{ marginBottom: 16 }}>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Duration</label>
                                    <select className="form-input" value={escEditDuration} onChange={e => setEscEditDuration(Number(e.target.value))} required style={{ width: '100%' }}>
                                        {[15, 30, 60, 120, 240, 480].map(d => <option key={d} value={d}>{d} min</option>)}
                                    </select>
                                </div>
                                <div style={{ display: 'flex', gap: 8 }}>
                                    <button type="button" onClick={() => setEscAction(null)} className="btn btn-secondary">Cancel</button>
                                    <button type="submit" className="btn btn-primary">Save Changes</button>
                                </div>
                            </form>
                        ) : (
                            <form onSubmit={e => { e.preventDefault(); submitEscAction(); }}>
                                <div style={{ marginBottom: 16 }}>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Reason for rejection</label>
                                    <textarea className="form-input" value={escRejectReason} onChange={e => setEscRejectReason(e.target.value)} rows={4} required placeholder="Required..." style={{ width: '100%' }} />
                                </div>
                                <div style={{ display: 'flex', gap: 8 }}>
                                    <button type="button" onClick={() => setEscAction(null)} className="btn btn-secondary">Cancel</button>
                                    <button type="submit" className="btn btn-danger">Reject Visit</button>
                                </div>
                            </form>
                        )}
                    </div>
                </div>
            )}

            {/* Escalation Transfer Back Modal */}
            {escTransferVisit && (
                <div className="modal-overlay" style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 }} onClick={() => setEscTransferVisit(null)}>
                    <div className="glass-card modal-content" style={{ width: 520, maxWidth: '90vw', padding: 24, border: '1px solid rgba(99,102,241,0.3)' }} onClick={e => e.stopPropagation()}>
                        <h3 style={{ fontWeight: 700, fontSize: 16, marginBottom: 16, display: 'flex', alignItems: 'center', gap: 8 }}>
                            <AppIcon name="users" size={18} /> Transfer Escalated Visit #{escTransferVisit.id}
                        </h3>
                        <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginBottom: 16 }}>
                            Visitor: <strong>{escTransferVisit.visitor?.name || escTransferVisit.company_name}</strong> · Status: <span className={`badge badge-${escTransferVisit.status}`}>{escTransferVisit.status.replace(/_/g, ' ')}</span>
                        </div>

                        {escTransferLoading && <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Loading transfer options...</p>}

                        {escTransferOpts?.error && (
                            <p style={{ color: 'var(--status-danger)', fontSize: 13 }}>{escTransferOpts.message || 'Could not load transfer options.'}</p>
                        )}

                        {escTransferOpts && !escTransferOpts.error && (
                            <form onSubmit={e => { e.preventDefault(); submitEscTransfer(escTransferVisit); }}>
                                <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: 12 }}>
                                    <select className="form-input" value={escTransferSel.company} onChange={e => changeEscSel('company', e.target.value)} style={{ width: '100%' }}>
                                        <option value="">Select company...</option>
                                        {(escTransferOpts.companies || []).map(c => <option key={c.company_id} value={c.company_id}>{c.company_name}</option>)}
                                    </select>
                                    <select className="form-input" value={escTransferSel.branch} onChange={e => changeEscSel('branch', e.target.value)} style={{ width: '100%' }} disabled={!escTransferSel.company}>
                                        <option value="">All branches</option>
                                        {(escTransferOpts.branches || []).map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                        {(escTransferOpts.branches || []).length === 0 && escTransferSel.company && <option value="" disabled>No branches available</option>}
                                    </select>
                                    <select className="form-input" value={escTransferSel.dept} onChange={e => changeEscSel('dept', e.target.value)} style={{ width: '100%' }} disabled={!escTransferSel.company}>
                                        <option value="">All departments</option>
                                        {(escTransferOpts.departments || []).map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                                        {(escTransferOpts.departments || []).length === 0 && escTransferSel.company && <option value="" disabled>No departments available</option>}
                                    </select>
                                    <select className="form-input" value={escTransferSel.host} onChange={e => setEscTransferSel(p => ({ ...p, host: e.target.value }))} style={{ width: '100%' }} disabled={!escTransferSel.company}>
                                        <option value="">Select recipient...</option>
                                        {(escTransferOpts.managers || []).length > 0 && (
                                            <optgroup label="Admins / CEOs">
                                                {(escTransferOpts.managers || []).map(m => <option key={m.id} value={m.id}>{m.name} ({m.role === 'ceo' ? 'CEO' : 'Admin'})</option>)}
                                            </optgroup>
                                        )}
                                        {(escTransferOpts.hosts || []).length > 0 && (
                                            <optgroup label="Active Hosts">
                                                {(escTransferOpts.hosts || []).map(h => <option key={h.id} value={h.id}>{h.name}{h.department ? ` — ${h.department}` : ''}</option>)}
                                            </optgroup>
                                        )}
                                        {(escTransferOpts.hosts || []).length === 0 && (escTransferOpts.managers || []).length === 0 && escTransferSel.company && (
                                            <option value="" disabled>{escTransferSel.dept ? 'No eligible hosts found' : 'Select a branch and department to find hosts'}</option>
                                        )}
                                    </select>
                                </div>
                                <input type="text" className="form-input" style={{ width: '100%', fontSize: 12, marginBottom: 12 }} placeholder="Reason for transfer (optional)" value={escTransferReason} onChange={e => setEscTransferReason(e.target.value)} />
                                <div style={{ padding: '10px 12px', borderRadius: 8, background: 'rgba(99,102,241,0.06)', border: '1px solid rgba(99,102,241,0.15)', marginBottom: 16, fontSize: 11, color: 'var(--text-secondary)' }}>
                                    On transfer, the selected host, Admin or CEO becomes responsible for the visit. If the visitor has not yet checked in, the visit returns to <strong>pending</strong> for the new recipient to approve; if already checked in, it stays active for the new recipient.
                                </div>
                                <div style={{ display: 'flex', gap: 8 }}>
                                    <button type="button" onClick={() => setEscTransferVisit(null)} className="btn btn-secondary">Cancel</button>
                                    <button type="submit" className="btn btn-primary" disabled={!escTransferSel.host}>Confirm Transfer</button>
                                </div>
                            </form>
                        )}
                    </div>
                </div>
            )}

            {/* Visitor Transfer (CEO — company-level) */}
            {transferVisit && <VisitorTransferModal visit={transferVisit} onClose={() => setTransferVisit(null)} />}
        </Layout>
    );
}

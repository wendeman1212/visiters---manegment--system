import React, { useState, useMemo } from 'react';
import Layout from '../Shared/Layout';
import { useForm, usePage, router, Link } from '@inertiajs/react';
import { getBuildings, getFloors, getRooms, formatLocation } from '../../config/facilityData';
import AppIcon from '../../config/iconMap';
import Messages from './Messages';
import { fmtDateTime, fmtDate as fmtDateTz, fmtTime as fmtTimeTz, toLocalDatetimeInput, toServerDatetimeInput, getMinDatetimeLocal, getMeetingEnd, localToServerDatetime } from '../../utils/timezone';

// ── Shared helpers ──────────────────────────────────────────────
let _appTz = 'Africa/Addis_Ababa';
const fmt = (dt) => dt ? new Date(dt).toLocaleString() : '—';
const fmtDate = (dt) => dt ? new Date(dt).toLocaleDateString() : '—';
const fmtTime = (dt) => dt ? new Date(dt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—';

const priorityColor = { vip: '#ec4899', high: '#ef4444', normal: '#6366f1', low: '#6b7280' };
const priorityIcon = { vip: 'star', high: 'emergency', normal: 'check', low: 'close' };

function appointmentPassed(v) {
    if (!v || !v.requested_time) return false;
    const end = new Date(new Date(v.requested_time).getTime() + (v.duration || 60) * 60000);
    return new Date() > end;
}

function SectionTitle({ children }) {
    return (
        <h3 style={{ fontFamily: 'var(--font-heading)', fontSize: '17px', fontWeight: 700, marginBottom: '20px', display: 'flex', alignItems: 'center', gap: '8px' }}>
            {children}
        </h3>
    );
}

function StatCard({ label, value, color, icon }) {
    const colors = { cyan: 'var(--accent-cyan)', emerald: 'var(--status-success)', warning: 'var(--status-warning)', danger: 'var(--status-danger)', blue: 'var(--status-info)', pink: '#ec4899', default: 'var(--text-primary)' };
    return (
        <div className="stat-card">
            <span className="stat-label">{label}</span>
            <span className="stat-value" style={{ color: colors[color] || colors.default, fontSize: '26px' }}>
                {icon && <span style={{ marginRight: 6 }}><AppIcon name={icon} size={16} /></span>}{value}
            </span>
        </div>
    );
}

function TabBtn({ active, onClick, children, badge }) {
    return (
        <button onClick={onClick} style={{
            background: 'none', border: 'none',
            borderBottom: active ? '2px solid var(--accent-primary)' : '2px solid transparent',
            color: active ? 'var(--accent-primary)' : 'var(--text-secondary)',
            fontWeight: 600, fontSize: '13px', padding: '10px 14px',
            cursor: 'pointer', whiteSpace: 'nowrap', transition: 'all 0.2s',
            display: 'flex', alignItems: 'center', gap: '6px',
        }}>
            {children}
            {badge > 0 && (
                <span style={{ background: 'var(--status-warning)', color: '#000', borderRadius: '10px', padding: '1px 7px', fontSize: '10px', fontWeight: 800 }}>
                    {badge}
                </span>
            )}
        </button>
    );
}

function buildFacilityHierarchy(facilities) {
    const h = {};
    (facilities || []).forEach(f => {
        if (!h[f.building]) h[f.building] = {};
        if (!h[f.building][f.floor]) h[f.building][f.floor] = [];
        if (!h[f.building][f.floor].includes(f.room)) h[f.building][f.floor].push(f.room);
    });
    return h;
}

function FacilitySelect({ building, floor, room, onChange, compact, facilities }) {
    const hierarchy = useMemo(() => buildFacilityHierarchy(facilities), [facilities]);
    const hasDbFacilities = (facilities || []).length > 0;
    const buildings = hasDbFacilities ? Object.keys(hierarchy) : getBuildings();
    const floors = hasDbFacilities && hierarchy[building] ? Object.keys(hierarchy[building]) : getFloors(building);
    const rooms = hasDbFacilities && hierarchy[building]?.[floor] ? hierarchy[building][floor] : getRooms(building, floor);
    const s = compact ? { fontSize: 11, padding: '4px 8px' } : { fontSize: 12, padding: '5px 10px' };
    const w = compact ? { width: 100 } : { width: 130 };
    return (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
            <select className="form-select" style={{ ...s, ...w }} value={building || ''} onChange={e => onChange({ building: e.target.value, floor: '', room: '' })}>
                <option value="">Building</option>
                {buildings.map(b => <option key={b} value={b}>{b}</option>)}
            </select>
            <select className="form-select" style={{ ...s, ...w }} value={floor || ''} onChange={e => onChange({ building: building || '', floor: e.target.value, room: '' })} disabled={!building}>
                <option value="">Floor</option>
                {floors.map(f => <option key={f} value={f}>{f}</option>)}
            </select>
            <select className="form-select" style={{ ...s, ...w }} value={room || ''} onChange={e => onChange({ building: building || '', floor: floor || '', room: e.target.value })} disabled={!floor}>
                <option value="">Room</option>
                {rooms.map(r => <option key={r} value={r}>{r}</option>)}
            </select>
        </div>
    );
}

function LocationDisplay({ building, floor, room, style }) {
    const text = formatLocation(building, floor, room);
    if (!text) return null;
    return <div style={{ fontSize: 12, color: 'var(--status-success)', marginTop: 4, ...style }}> {text}</div>;
}

const MAIN_TABS = [
    { key: 'dashboard', label: 'Dashboard', icon: 'home' },
    { key: 'requests', label: 'Requests', icon: 'clipboard' },
    { key: 'appointments', label: 'Appointments', icon: 'calendar' },
    { key: 'reschedules', label: 'Reschedules', icon: 'calendar' },
    { key: 'host_change', label: 'Host Change', icon: 'users' },
    { key: 'visitors', label: 'Visitors', icon: 'user' },
    { key: 'arrival', label: 'Arrival', icon: 'exit' },
    { key: 'during', label: 'During Visit', icon: 'handshake' },
    { key: 'facility', label: 'Facility', icon: 'building' },
    { key: 'completion', label: 'Completion', icon: 'check' },
    { key: 'analytics', label: 'Analytics', icon: 'chart' },
    { key: 'messages', label: 'Messages', icon: 'mail' },
];

export default function HostDashboard({ visits, all_hosts, transfer_recipients, reschedule_requests, today_stats, analytics, notifications, section, messages_inbox, messages_sent, messages_recipients, messages_unread_count, pending_host_change_requests, my_host_change_requests, facilities }) {
    const { auth, app_timezone } = usePage().props;
    const host = auth.user;
    const tz = app_timezone || 'Africa/Addis_Ababa';

    const [tab, setTab] = useState(section || 'dashboard');
    const [sidebarOpen, setSidebarOpen] = useState(true);
    const [reqTab, setReqTab] = useState('pending');
    const [selectedIds, setSelectedIds] = useState([]);
    const [comments, setComments] = useState('');
    const [editOpen, setEditOpen] = useState({});
    const [editState, setEditState] = useState({});
    const [notesOpen, setNotesOpen] = useState({});
    const [notesState, setNotesState] = useState({});
    const [extendId, setExtendId] = useState(null);
    const [extraMins, setExtraMins] = useState(30);
    const [outcomeOpen, setOutcomeOpen] = useState({});
    const [outcomeText, setOutcomeText] = useState({});
    const [transferOpen, setTransferOpen] = useState({});
    const [transferHost, setTransferHost] = useState(null);
    const [transferReason, setTransferReason] = useState('');
    const [bulkReason, setBulkReason] = useState('');
    const [facForm, setFacForm] = useState({ building: '', floor: '', room: '' });
    const [editingFacId, setEditingFacId] = useState(null);
    const [facEditForm, setFacEditForm] = useState({ building: '', floor: '', room: '' });
    const [groupValidation, setGroupValidation] = useState({});
    const [groupValidating, setGroupValidating] = useState({});
    const [expandedCompany, setExpandedCompany] = useState({});
    const [hostReschedModal, setHostReschedModal] = useState(null);
    const [hostReschedForm, setHostReschedForm] = useState({ new_requested_time: '', new_duration: 60, reason: '' });

    // Visitor-initiated reschedule requests awaiting this host's decision.
    const pendingReschedules = (reschedule_requests || []).filter(r => r.status === 'pending');
    const rescheduleHistory  = (reschedule_requests || []).filter(r => r.status !== 'pending');

    // Escalation state
    const [escModal, setEscModal] = useState(null);
    const [escTarget, setEscTarget] = useState('');
    const [escReason, setEscReason] = useState('');

    // Eligible escalation targets: active Admin/CEO of the visitor's company.
    const eligibleEscalationTargets = (visit) => {
        const list = transfer_recipients || [];
        const companyId = visit?.company_id;
        return list.filter(u => (u.role === 'admin' || u.role === 'ceo') && u.status !== 'suspended' && (companyId ? u.company_id === companyId : true));
    };

    const openEscalate = (v) => {
        setEscModal(v);
        setEscTarget('');
        setEscReason('');
    };

    const submitEscalate = (v) => {
        if (!escTarget) { alert('Please select an Admin or CEO to escalate to.'); return; }
        router.post(`/visit-requests/${v.id}/host-decision`, {
            decision: 'escalate',
            escalated_to: escTarget,
            reason: escReason,
        }, { onSuccess: () => setEscModal(null) });
    };

    // Host Change state
    const [hostChangeVisit, setHostChangeVisit] = useState(null);
    const [hostChangeForm, setHostChangeForm] = useState({ new_host_id: '', new_department_id: '', reason: '', additional_notes: '', meeting_room: '' });
    const [hostChangeReviewNotes, setHostChangeReviewNotes] = useState({});

    // Branch-level visits may only be changed to a host in the same branch;
    // company-level visits may only be changed to a host in the same company.
    const eligibleTransferHosts = (visit) => {
        const list = all_hosts || [];
        if (visit?.branch_id) return list.filter(h => h.branch_id === visit.branch_id);
        if (visit?.company_id) return list.filter(h => h.company_id === visit.company_id);
        return list;
    };

    // Eligible recipients for the Transfer Visitor feature.
    // Admin and CEO are company-level users: they belong to the visitor's
    // selected company (filtered by company_id) but not to any specific
    // branch, so every branch of that company may transfer to them. Hosts are
    // branch-level and must be in the visit's branch (or company).
    const eligibleTransferUsers = (visit) => {
        const list = transfer_recipients || [];
        const companyId = visit?.company_id;
        return list.filter(u => {
            if (u.role === 'admin' || u.role === 'ceo') {
                return companyId ? u.company_id === companyId : true;
            }
            if (visit?.branch_id) return u.branch_id === visit.branch_id;
            return companyId ? u.company_id === companyId : true;
        });
    };

    // Transfer panel (shared by pending / approved / checked-in visit cards).
    const renderTransferPanel = (v) => (
        transferOpen[v.id] && (
            <div style={{ marginBottom: 16, padding: '12px', background: 'rgba(99,102,241,0.06)', borderRadius: 8 }}>
                <div style={{ fontSize: 13, marginBottom: 8, fontWeight: 600 }}>Transfer visitor to:</div>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 8 }}>
                    <select
                        className="form-select"
                        style={{ flex: 1, minWidth: 200, fontSize: 12 }}
                        value={transferHost?.id || ''}
                        onChange={e => {
                            const selected = eligibleTransferUsers(v).find(u => u.id === Number(e.target.value));
                            setTransferHost(selected || null);
                            setTransferReason('');
                        }}
                    >
                        <option value="">Select a host, admin or CEO...</option>
                        {eligibleTransferUsers(v).map(u => (
                            <option key={u.id} value={u.id}>
                                {u.name} ({u.role === 'admin' ? 'Admin' : u.role === 'ceo' ? 'CEO' : 'Host'}{u.department?.name ? ` — ${u.department.name}` : ''})
                            </option>
                        ))}
                    </select>
                    {eligibleTransferUsers(v).length === 0 && (
                        <span style={{ fontSize: 12, color: 'var(--status-danger)' }}>No eligible users available in your company or branch.</span>
                    )}
                </div>
                {transferHost && (
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                        <input type="text" className="form-input" style={{ flex: 1, minWidth: 180, fontSize: 12 }} placeholder="Reason for transfer (optional)" value={transferReason} onChange={e => setTransferReason(e.target.value)} />
                        <button onClick={() => { router.post(`/visit-requests/${v.id}/transfer`, { visit_request_id: v.id, new_host_id: transferHost.id, reason: transferReason }); setTransferOpen(p => ({ ...p, [v.id]: false })); setTransferHost(null); setTransferReason(''); }} className="btn btn-primary" style={{ fontSize: 12 }}>
                            Confirm Transfer
                        </button>
                        <button onClick={() => { setTransferHost(null); setTransferReason(''); }} className="btn btn-secondary" style={{ fontSize: 12 }}>Cancel</button>
                    </div>
                )}
            </div>
        )
    );

    // ── Filters ──
    const filterVisits = (group) => visits.filter(v => {
        if (group === 'pending') return v.status === 'pending';
        if (group === 'approved') return v.status === 'approved';
        if (group === 'checked_in') return v.status === 'checked_in';
        if (group === 'today') return ['approved', 'checked_in'].includes(v.status) && new Date(v.requested_time).toDateString() === new Date().toDateString();
        if (group === 'upcoming') return v.status === 'approved' && new Date(v.requested_time) > new Date();
        if (group === 'missed') return v.is_no_show;
        if (group === 'completed') return ['checked_out', 'archived'].includes(v.status);
        if (group === 'vip') return v.is_vip;
        if (group === 'all') return true;
        return false;
    });

    // ── Handlers ──
    const decide = (id, decision, extra = {}) => {
        router.post(`/visit-requests/${id}/host-decision`, { decision, comments, ...extra },
            { onSuccess: () => { setComments(''); } });
    };

    const toggleEdit = (id, time, dur) => {
        setEditOpen(p => ({ ...p, [id]: !p[id] }));
        if (!editState[id]) {
            setEditState(p => ({ ...p, [id]: { requested_time: toLocalDatetimeInput(time), duration: dur } }));
        }
    };
    const saveTime = (id) => {
        const s = editState[id];
        if (!s?.requested_time || !s?.duration) return;
        const serverTime = localToServerDatetime(s.requested_time, tz);
        router.post(`/visit-requests/${id}/update-time`, { requested_time: serverTime, duration: s.duration },
            { onSuccess: () => setEditOpen(p => ({ ...p, [id]: false })) });
    };

    const toggleSelect = (id) => setSelectedIds(p => p.includes(id) ? p.filter(x => x !== id) : [...p, id]);
    const selectAll = (group) => {
        const ids = filterVisits(group).map(v => v.id);
        setSelectedIds(ids);
    };

    const deleteVisit = (id, name) => {
        if (!confirm(`Delete the visit for ${name}? This permanently removes the visit, QR passes, and all related records. This cannot be undone.`)) return;
        router.delete(`/host/visits/${id}`, { preserveScroll: true });
    };

    const validateGroupMembers = (visitId) => {
        setGroupValidating(p => ({ ...p, [visitId]: true }));
        const csrf = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1];
        fetch(`/host/visits/${visitId}/validate-group`, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? decodeURIComponent(csrf) : '' },
        }).then(r => r.json()).then(d => {
            if (d.success) {
                setGroupValidation(p => ({ ...p, [visitId]: d }));
            }
        }).catch(() => { }).finally(() => {
            setGroupValidating(p => ({ ...p, [visitId]: false }));
        });
    };

    const card = (content, style = {}) => (
        <div className="glass-card" style={{ marginBottom: 24, ...style }}>{content}</div>
    );

    return (
        <Layout title="Host Dashboard">
            {/* ── Header ── */}
            <div className="dashboard-header">
                <div className="dashboard-title">
                    <h2>Host Operations Panel</h2>
                    <p>Welcome back, {host?.name} — manage visitors, meetings, and facility resources</p>
                </div>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                    <button onClick={() => router.get('/visit-requests/process-sla')} className="btn btn-secondary" style={{ fontSize: '11px', padding: '6px 12px' }}> SLA</button>
                    <button onClick={() => router.reload()} className="btn btn-secondary" style={{ fontSize: '11px', padding: '6px 12px' }}></button>
                </div>
            </div>

            {/* ── Sidebar-style LEFT nav + RIGHT content ── */}
            <div className="admin-layout" style={section ? { minHeight: 'calc(100vh - 120px)' } : { display: 'flex', gap: 0, minHeight: 'calc(100vh - 120px)' }}>

                {!section && (
                    <div className="admin-sidebar" style={{
                        width: sidebarOpen ? 210 : 0,
                        flexShrink: 0,
                        borderRight: sidebarOpen ? '1px solid rgba(255,255,255,0.15)' : 'none',
                        position: 'sticky',
                        top: 80,
                        alignSelf: 'flex-start',
                        maxHeight: 'calc(100vh - 80px)',
                        overflow: sidebarOpen ? 'auto' : 'hidden',
                        paddingBottom: 20,
                        background: '#2563eb',
                        borderRadius: '0 0 0 14px',
                        transition: 'width 0.25s ease',
                    }}>
                        <div style={{ padding: '10px 12px', borderBottom: '1px solid rgba(255,255,255,0.15)', display: 'flex', alignItems: 'center', justifyContent: sidebarOpen ? 'flex-end' : 'center' }}>
                            <button onClick={() => setSidebarOpen(p => !p)} style={{ background: 'rgba(255,255,255,0.2)', border: 'none', borderRadius: 8, width: 34, height: 34, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', color: '#fff', fontSize: 16, fontWeight: 700 }}></button>
                        </div>
                        <ul style={{ listStyle: 'none', display: 'flex', flexDirection: 'column', gap: 2, padding: '4px 8px' }}>
                            {sidebarOpen && <li style={{ padding: '12px 4px', borderBottom: '1px solid rgba(255,255,255,0.15)', marginBottom: 4 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                    <label style={{ width: 36, height: 36, borderRadius: '50%', background: host?.profile_photo ? 'none' : 'rgba(255,255,255,0.2)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, fontSize: 16, color: 'white', flexShrink: 0, overflow: 'hidden', cursor: 'pointer' }} title="Click to change photo">
                                        {host?.profile_photo ? <img src={`/storage/${host.profile_photo}`} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : (host?.name?.charAt(0) || 'H')}
                                        <input type="file" accept="image/*" style={{ display: 'none' }} onChange={e => { const f = e.target.files[0]; if (!f) return; const fd = new FormData(); fd.append('profile_photo', f); fd.append('name', host?.name || ''); router.post('/profile/update', fd, { forceFormData: true, preserveScroll: true }); }} />
                                    </label>
                                    <div>
                                        <div style={{ fontWeight: 700, fontSize: 13, color: '#ffffff' }}>{host?.name}</div>
                                        <div style={{ fontSize: 11, color: 'rgba(255,255,255,0.7)' }}>Host Employee</div>
                                    </div>
                                </div>
                            </li>}
                            {MAIN_TABS.map(t => {
                            const badge = t.key === 'requests' ? filterVisits('pending').length
                                : t.key === 'arrival' ? filterVisits('approved').filter(v => new Date(v.requested_time) <= new Date(Date.now() + 3600000)).length
                                    : t.key === 'host_change' ? (pending_host_change_requests || []).length
                                        : t.key === 'reschedules' ? pendingReschedules.length
                                            : 0;
                                return (
                                    <li key={t.key}>
                                        <button onClick={() => router.visit('/host/section/' + t.key)} title={sidebarOpen ? '' : t.label} style={{
                                            width: '100%', textAlign: sidebarOpen ? 'left' : 'center', border: 'none',
                                            background: tab === t.key ? 'rgba(255,255,255,0.2)' : 'transparent',
                                            color: '#ffffff',
                                            padding: sidebarOpen ? '10px 14px' : '10px 0', fontSize: '13px', fontWeight: tab === t.key ? 700 : 500,
                                            cursor: 'pointer', transition: 'all 0.2s',
                                            display: 'flex', justifyContent: sidebarOpen ? 'space-between' : 'center', alignItems: 'center',
                                            borderRadius: '8px',
                                        }} onMouseEnter={e => { if (tab !== t.key) e.currentTarget.style.background = 'rgba(255,255,255,0.12)'; }} onMouseLeave={e => { if (tab !== t.key) e.currentTarget.style.background = 'transparent'; }}>
                                            <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}><AppIcon name={t.icon} size={14} /> {sidebarOpen ? t.label : t.label.split(' ')[0]}</span>
                                            {badge > 0 && sidebarOpen && <span style={{ background: '#f59e0b', color: '#fff', borderRadius: 10, padding: '1px 7px', fontSize: 10, fontWeight: 800 }}>{badge}</span>}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                        {/* Bottom links */}
                        <div style={{ borderTop: '1px solid rgba(255,255,255,0.15)', marginTop: 8, padding: '12px 16px', display: 'flex', flexDirection: 'column', gap: 6 }}>
                            <Link href="/profile" style={{ fontSize: 12, color: '#ffffff', textDecoration: 'none', display: 'flex', alignItems: 'center', gap: 8, padding: '6px 0' }}><AppIcon name="user" size={14} /> My Profile</Link>
                        </div>
                    </div>
                )}
                {!section && !sidebarOpen && (
                    <button
                        className="sidebar-expand-btn"
                        onClick={() => setSidebarOpen(true)}
                        title="Expand sidebar"
                        style={{ position: 'fixed', top: 90, left: 12, zIndex: 200, background: '#2563eb', border: '1px solid rgba(255,255,255,0.25)', borderRadius: 8, width: 34, height: 34, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', color: '#fff', fontSize: 16, fontWeight: 700, boxShadow: '0 2px 10px rgba(0,0,0,0.3)' }}>
                        <AppIcon name="grid" size={16} />
                    </button>
                )}

                {/* RIGHT: content */}
                <div className="dashboard-content" style={{ flex: 1, padding: section ? '28px 32px' : '0 0 0 24px', minWidth: 0, overflow: section ? 'visible' : 'hidden' }}>
                    {section && (
                        <div style={{ marginBottom: 24, paddingBottom: 16, borderBottom: '1px solid #e5e7eb' }}>
                            <Link href="/host/dashboard" style={{ color: 'var(--accent-primary)', fontSize: 14, fontWeight: 600, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 6 }}> Back to Host Dashboard</Link>
                        </div>
                    )}

                    {/* ══════ DASHBOARD TAB ══════ */}
                    {tab === 'dashboard' && (
                        <div>
                            <div className="stats-grid" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))' }}>
                                <StatCard label="Pending Requests" value={today_stats.pending} color="warning" icon="clock" />
                                <StatCard label="Approved Today" value={today_stats.approved} color="emerald" icon="check" />
                                <StatCard label="Checked In" value={today_stats.checked_in} color="cyan" icon="user" />
                                <StatCard label="Completed Today" value={today_stats.completed} color="blue" icon="flag" />
                                <StatCard label="No-Shows" value={today_stats.no_shows} color="danger" icon="close" />
                                <StatCard label="Rejected Today" value={today_stats.rejected} color="danger" icon="close" />
                                <StatCard label="Archived Today" value={today_stats.archived} color="default" icon="clock" />
                                <StatCard label="Active On-Site" value={today_stats.live_count} color="emerald" icon="building" />
                            </div>

                            {/* Upcoming today */}
                            <div className="glass-card" style={{ marginBottom: 24 }}>
                                <SectionTitle> Upcoming Today</SectionTitle>
                                {filterVisits('upcoming').length === 0 ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No upcoming approved visits today.</p>
                                ) : (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                                        {filterVisits('upcoming').map(v => (
                                            <div key={v.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '12px 16px', background: 'rgba(99,102,241,0.05)', borderRadius: 10, border: '1px solid rgba(99,102,241,0.15)' }}>
                                                <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                                                    <span style={{ fontSize: 22 }}>{priorityIcon[v.priority] ? <AppIcon name={priorityIcon[v.priority]} size={22} /> : null}</span>
                                                    <div>
                                                        <div style={{ fontWeight: 700 }}>{v.visitor.name}</div>
                                                        <div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>{v.purpose}</div>
                                                    </div>
                                                </div>
                                                <div style={{ textAlign: 'right' }}>
                                                    <div style={{ fontWeight: 700, color: 'var(--accent-cyan)' }}>{fmtTime(v.requested_time)}</div>
                                                    <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.duration} min · {formatLocation(v.building, v.floor, v.meeting_room_id) || 'No location'}</div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* Arrival Alert — visitors checked in by security */}
                            {filterVisits('checked_in').length > 0 && (
                                <div className="glass-card" style={{ borderColor: 'rgba(16,185,129,0.3)', background: 'rgba(16,185,129,0.03)', marginBottom: 24 }}>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16 }}>
                                        <SectionTitle style={{ margin: 0 }}> Visitor Arrivals — {filterVisits('checked_in').length} on-site</SectionTitle>
                                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, padding: '3px 10px', borderRadius: 20, background: 'rgba(16,185,129,0.15)', color: '#10b981', fontWeight: 700, animation: 'fadeSlideUp 0.3s ease' }}> LIVE</span>
                                    </div>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                        {filterVisits('checked_in').map(v => (
                                            <div key={v.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '14px 16px', borderRadius: 10, background: 'rgba(16,185,129,0.06)', border: '1px solid rgba(16,185,129,0.2)', animation: 'fadeSlideUp 0.3s ease' }}>
                                                <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                                                    <div style={{ width: 40, height: 40, borderRadius: '50%', background: 'linear-gradient(135deg,#10b981,#059669)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 16, color: 'white', flexShrink: 0 }}>
                                                        {(v.visitor.name || 'V').charAt(0).toUpperCase()}
                                                    </div>
                                                    <div>
                                                        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                                                            <span style={{ fontWeight: 700, fontSize: 15 }}>{v.visitor.name}</span>
                                                            <span style={{ fontSize: 10, padding: '2px 8px', borderRadius: 10, background: 'rgba(16,185,129,0.15)', color: '#10b981', fontWeight: 700 }}> CHECKED IN</span>
                                                            {v.is_vip && <span style={{ fontSize: 10, padding: '2px 8px', borderRadius: 10, background: 'rgba(236,72,153,0.15)', color: '#ec4899', fontWeight: 700 }}> VIP</span>}
                                                        </div>
                                                        <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginTop: 2 }}>Purpose: {v.purpose} · {v.duration} min</div>
                                                        <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>Checked in by Security · {v.requested_time ? fmt(v.requested_time) : ''}</div>
                                                    </div>
                                                </div>
                                                <div style={{ display: 'flex', gap: 8, flexShrink: 0 }}>
                                                    <button onClick={() => setTab('arrival')} className="btn btn-primary" style={{ fontSize: 12, padding: '7px 16px', background: 'linear-gradient(135deg,#10b981,#059669)', borderColor: '#10b981' }}> Meet Visitor</button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* ══════ REQUESTS TAB ══════ */}
                    {tab === 'requests' && (
                        <div>
                            <div style={{ display: 'flex', borderBottom: '1px solid rgba(255,255,255,0.07)', marginBottom: 24, gap: 4, flexWrap: 'wrap' }}>
                                {[['pending', 'Pending'], ['approved', 'Approved'], ['checked_in', 'Active'], ['completed', 'Completed'], ['missed', 'No-Show'], ['all', 'All']].map(([k, l]) => (
                                    <TabBtn key={k} active={reqTab === k} onClick={() => setReqTab(k)} badge={k === 'pending' ? filterVisits('pending').length : 0}>{l}</TabBtn>
                                ))}
                            </div>

                            {/* Bulk actions for pending */}
                            {reqTab === 'pending' && filterVisits('pending').length > 0 && (
                                <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap', alignItems: 'center', padding: '10px 14px', background: 'rgba(245,158,11,0.05)', borderRadius: 10, border: '1px solid rgba(245,158,11,0.15)' }}>
                                    <span style={{ fontSize: 12, color: 'var(--text-secondary)', fontWeight: 600 }}>Bulk:</span>
                                    <button onClick={() => selectAll('pending')} className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 10px' }}> All</button>
                                    <button onClick={() => setSelectedIds([])} className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 10px' }}>Clear</button>
                                    {selectedIds.length > 0 && <>
                                        <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>{selectedIds.length} selected</span>
                                        <button onClick={() => router.post('/host/visits/bulk-approve', { ids: selectedIds })} className="btn btn-primary" style={{ fontSize: 11, padding: '4px 12px' }}> Approve</button>
                                        <input type="text" className="form-input" style={{ width: 150, fontSize: 11, padding: '4px 8px' }} placeholder="Rejection reason..." value={bulkReason} onChange={e => setBulkReason(e.target.value)} />
                                        <button onClick={() => router.post('/host/visits/bulk-reject', { ids: selectedIds, reason: bulkReason })} className="btn btn-danger" style={{ fontSize: 11, padding: '4px 12px' }}> Reject</button>
                                    </>}
                                </div>
                            )}

                            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                                {filterVisits(reqTab).length === 0 ? (
                                    <div className="glass-card" style={{ textAlign: 'center', padding: '40px', color: 'var(--text-muted)' }}>No visits in this category.</div>
                                ) : filterVisits(reqTab).map(v => (
                                    <div key={v.id} className="glass-card" style={{ padding: '20px 24px', borderLeft: `3px solid ${priorityColor[v.priority] || '#6366f1'}` }}>
                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16 }}>
                                            <div style={{ display: 'flex', gap: 12, alignItems: 'flex-start' }}>
                                                {reqTab === 'pending' && <input type="checkbox" checked={selectedIds.includes(v.id)} onChange={() => toggleSelect(v.id)} style={{ marginTop: 4 }} />}
                                                <div>
                                                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 4 }}>
                                                        <span style={{ fontWeight: 700, fontSize: 16 }}>{v.visitor.name}</span>
                                                        {v.is_vip && <span className="badge badge-approved" style={{ background: 'rgba(236,72,153,0.12)', color: '#ec4899', border: '1px solid rgba(236,72,153,0.3)' }}> VIP</span>}
                                                        {v.category && (
                                                            <span className="badge" style={{ background: `${v.category.color}22`, color: v.category.color, border: `1px solid ${v.category.color}55`, fontWeight: 700 }}>
                                                                <AppIcon name="folder" size={10} /> {v.category.name}
                                                            </span>
                                                        )}
                                                        <span className={`badge badge-${v.status}`}>{v.status.replace(/_/g, ' ')}</span>
                                                        <span style={{ fontSize: 11, color: priorityColor[v.priority], fontWeight: 700, textTransform: 'uppercase' }}>{priorityIcon[v.priority]} {v.priority}</span>
                                                    </div>
                                                    <div style={{ fontSize: 13, color: 'var(--text-secondary)' }}>
                                                        {v.visitor.email} · {v.visitor.phone} · ID: {v.visitor.id_document_no}
                                                    </div>
                                                    {v.visitor.company_name && (
                                                        <div style={{ fontSize: 12, color: 'var(--accent-cyan)', marginTop: 2 }}> {v.visitor.company_name}{v.visitor.company_position ? ` — ${v.visitor.company_position}` : ''}
                                                        </div>
                                                    )}
                                                    {v.visitor.id_document_url && (
                                                        <div style={{ fontSize: 12, marginTop: 2 }}>
                                                            <a href={v.visitor.id_document_url} target="_blank" rel="noopener noreferrer" style={{ color: 'var(--accent-primary)' }}> View ID Document</a>
                                                        </div>
                                                    )}
                                                    {v.category && (
                                                        <div style={{ marginTop: 10, padding: '10px 12px', borderRadius: 8, background: `${v.category.color}0d`, border: `1px solid ${v.category.color}40`, display: 'flex', flexDirection: 'column', gap: 4, maxWidth: 540 }}>
                                                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                                                <span style={{ fontSize: 18 }}><AppIcon name="folder" size={16} /></span>
                                                                <span style={{ fontWeight: 800, fontSize: 13, color: v.category.color, textTransform: 'uppercase', letterSpacing: 0.5 }}>Visitor Category: {v.category.name}</span>
                                                            </div>
                                                            {v.category.description && <div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>{v.category.description}</div>}
                                                            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 2 }}>
                                                                <span style={{ fontSize: 10, padding: '2px 8px', borderRadius: 10, background: v.category.requires_approval ? 'rgba(245,158,11,0.15)' : 'rgba(16,185,129,0.15)', color: v.category.requires_approval ? '#f59e0b' : '#10b981', fontWeight: 600 }}>{v.category.requires_approval ? 'Approval required' : 'No approval required'}</span>
                                                                {v.category.auto_approve && <span style={{ fontSize: 10, padding: '2px 8px', borderRadius: 10, background: 'rgba(16,185,129,0.15)', color: '#10b981', fontWeight: 600 }}>Auto-approve</span>}
                                                                {v.category.max_duration && <span style={{ fontSize: 10, padding: '2px 8px', borderRadius: 10, background: 'rgba(255,255,255,0.08)', color: 'var(--text-secondary)', fontWeight: 600 }}>Max {v.category.max_duration} min</span>}
                                                            </div>
                                                        </div>
                                                    )}
                                                    {v.visit_type === 'company' && (
                                                        <div style={{ marginTop: 10, borderRadius: 10, border: '1px solid rgba(99,102,241,0.25)', overflow: 'hidden' }}>
                                                            {/* Company Header Banner */}
                                                            <div style={{ padding: '12px 16px', background: 'linear-gradient(135deg, rgba(99,102,241,0.12), rgba(139,92,246,0.08))', borderBottom: '1px solid rgba(99,102,241,0.15)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                                                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                                                    <span style={{ fontSize: 22 }}><AppIcon name="building" size={22} /></span>
                                                                    <div>
                                                                        <div style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-primary)' }}>{v.company_name}</div>
                                                                        <div style={{ fontSize: 11, color: 'var(--accent-primary)', fontWeight: 600 }}>COMPANY / GROUP VISIT</div>
                                                                    </div>
                                                                </div>
                                                                <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                                                                    {v.groupMembers && v.groupMembers.length > 0 && (
                                                                        <span style={{ fontSize: 11, padding: '3px 10px', borderRadius: 20, background: 'rgba(99,102,241,0.15)', color: 'var(--accent-primary)', fontWeight: 700 }}> {v.groupMembers.length + 1} total visitors
                                                                        </span>
                                                                    )}
                                                                    <button onClick={() => setExpandedCompany(p => ({ ...p, [v.id]: !p[v.id] }))}
                                                                        style={{ fontSize: 11, padding: '4px 10px', borderRadius: 6, border: '1px solid rgba(255,255,255,0.12)', background: 'rgba(255,255,255,0.05)', color: 'var(--text-secondary)', cursor: 'pointer' }}>
                                                                        {expandedCompany[v.id] ? <> Collapse</> : (v.status === 'pending' ? <> Hide</> : <> Details</>)}
                                                                    </button>
                                                                </div>
                                                            </div>

                                                            {/* Company Info Grid */}
                                                            <div style={{ padding: '14px 16px', background: 'rgba(99,102,241,0.03)' }}>
                                                                <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--accent-primary)', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 8 }}>Company Information</div>
                                                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 10 }}>
                                                                    {[
                                                                        { label: 'Company Name', value: v.company_name, icon: 'building' },
                                                                        { label: 'Email', value: v.company_email, icon: 'email' },
                                                                        { label: 'Phone', value: v.company_phone, icon: 'phone' },
                                                                        { label: 'Address', value: v.company_address, icon: 'location' },
                                                                        { label: 'Website', value: v.company_website, icon: 'globe', link: true },
                                                                    ].filter(f => f.value).map((f, i) => (
                                                                        <div key={i} style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                            <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 2 }}><AppIcon name={f.icon} /> {f.label}</div>
                                                                            {f.link ? (
                                                                                <a href={f.value} target="_blank" rel="noopener noreferrer" style={{ fontSize: 13, fontWeight: 600, color: 'var(--accent-primary)', textDecoration: 'none' }}>{f.value}</a>
                                                                            ) : (
                                                                                <div style={{ fontSize: 13, fontWeight: 600 }}>{f.value}</div>
                                                                            )}
                                                                        </div>
                                                                    ))}
                                                                </div>
                                                            </div>

                                                            {/* Representative Info */}
                                                            <div style={{ padding: '14px 16px', background: 'rgba(139,92,246,0.03)', borderTop: '1px solid rgba(255,255,255,0.04)' }}>
                                                                <div style={{ fontSize: 11, fontWeight: 700, color: '#a78bfa', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 8 }}> Representative Information (Request Owner)</div>
                                                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: 10 }}>
                                                                    <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                        <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}>Full Name</div>
                                                                        <div style={{ fontSize: 13, fontWeight: 700 }}>{v.visitor.name}</div>
                                                                    </div>
                                                                    <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                        <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}>Email</div>
                                                                        <div style={{ fontSize: 13, fontWeight: 600 }}>{v.visitor.email}</div>
                                                                    </div>
                                                                    <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                        <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}>Phone</div>
                                                                        <div style={{ fontSize: 13, fontWeight: 600 }}>{v.visitor.phone}</div>
                                                                    </div>
                                                                    <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                        <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}>ID Document</div>
                                                                        <div style={{ fontSize: 13, fontWeight: 600, fontFamily: 'monospace' }}>{v.visitor.id_document_no}</div>
                                                                    </div>
                                                                    {v.representative_gender && (
                                                                        <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                            <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}>Gender</div>
                                                                            <div style={{ fontSize: 13, fontWeight: 600, textTransform: 'capitalize' }}>{v.representative_gender}</div>
                                                                        </div>
                                                                    )}
                                                                    {v.representative_nationality && (
                                                                        <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                            <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}>Nationality</div>
                                                                            <div style={{ fontSize: 13, fontWeight: 600 }}>{v.representative_nationality}</div>
                                                                        </div>
                                                                    )}
                                                                    {v.representative_position && (
                                                                        <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                            <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}>Position</div>
                                                                            <div style={{ fontSize: 13, fontWeight: 600 }}>{v.representative_position}</div>
                                                                        </div>
                                                                    )}

                                                                    {/* Reschedule button for approved visits */}
                                                                    {v.status === 'approved' && (
                                                                        <div style={{ marginTop: 10, paddingTop: 10, borderTop: '1px solid rgba(255,255,255,0.06)' }}>
                                                                            <button onClick={() => { setHostReschedModal(v); setHostReschedForm({ new_requested_time: toLocalDatetimeInput(v.requested_time) || '', new_duration: v.duration || 60, reason: '' }); }} className="btn btn-secondary" style={{ fontSize: 11, padding: '5px 12px' }}>
                                                                                <AppIcon name="calendar" size={12} /> Reschedule
                                                                            </button>
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </div>

                                                            {/* Expanded Section: Group Members + Documents (always visible for pending visits) */}
                                                            {(expandedCompany[v.id] || v.status === 'pending') && (
                                                                <>
                                                                    {/* Group Members Table */}
                                                                    {v.groupMembers && v.groupMembers.length > 0 && (
                                                                        <div style={{ padding: '14px 16px', borderTop: '1px solid rgba(255,255,255,0.04)' }}>
                                                                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
                                                                                <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--accent-cyan)', textTransform: 'uppercase', letterSpacing: 1 }}> Visitor Information — All Added Visitors ({v.groupMembers.length})</div>
                                                                                <button onClick={() => validateGroupMembers(v.id)} disabled={groupValidating[v.id]}
                                                                                    style={{ fontSize: 11, padding: '4px 12px', borderRadius: 6, border: '1px solid rgba(6,182,212,0.3)', background: groupValidating[v.id] ? 'rgba(6,182,212,0.05)' : 'rgba(6,182,212,0.1)', color: 'var(--accent-cyan)', cursor: groupValidating[v.id] ? 'wait' : 'pointer', fontWeight: 600, display: 'flex', alignItems: 'center', gap: 4 }}>
                                                                                    {groupValidating[v.id] ? <> Checking...</> : <> Run Security Check</>}
                                                                                </button>
                                                                            </div>

                                                                            {/* Security Summary if validated */}
                                                                            {groupValidation[v.id] && (
                                                                                <div style={{ display: 'flex', gap: 10, marginBottom: 12 }}>
                                                                                    {[
                                                                                        { label: 'Total', value: groupValidation[v.id].summary.total, color: 'var(--text-primary)', bg: 'rgba(255,255,255,0.06)' },
                                                                                        { label: 'Cleared', value: groupValidation[v.id].summary.cleared, color: '#10b981', bg: 'rgba(16,185,129,0.1)' },
                                                                                        { label: 'Watchlist', value: groupValidation[v.id].summary.warning, color: '#f59e0b', bg: 'rgba(245,158,11,0.1)' },
                                                                                        { label: 'Blocked', value: groupValidation[v.id].summary.blocked, color: '#ef4444', bg: 'rgba(239,68,68,0.1)' },
                                                                                    ].map(s => (
                                                                                        <div key={s.label} style={{ padding: '6px 12px', borderRadius: 8, background: s.bg, border: `1px solid ${s.color}22` }}>
                                                                                            <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase' }}>{s.label}</div>
                                                                                            <div style={{ fontSize: 16, fontWeight: 800, color: s.color }}>{s.value}</div>
                                                                                        </div>
                                                                                    ))}
                                                                                </div>
                                                                            )}

                                                                            {/* Members Table */}
                                                                            <div style={{ overflowX: 'auto', border: '1px solid rgba(255,255,255,0.06)', borderRadius: 8 }}>
                                                                                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                                                                                    <thead>
                                                                                        <tr style={{ background: 'rgba(255,255,255,0.04)' }}>
                                                                                            <th style={{ padding: '8px 10px', textAlign: 'left', fontWeight: 700, color: 'var(--text-muted)', fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.5, borderBottom: '1px solid rgba(255,255,255,0.06)' }}>#</th>
                                                                                            <th style={{ padding: '8px 10px', textAlign: 'left', fontWeight: 700, color: 'var(--text-muted)', fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.5, borderBottom: '1px solid rgba(255,255,255,0.06)' }}>Full Name</th>
                                                                                            <th style={{ padding: '8px 10px', textAlign: 'left', fontWeight: 700, color: 'var(--text-muted)', fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.5, borderBottom: '1px solid rgba(255,255,255,0.06)' }}>National ID / Passport</th>
                                                                                            <th style={{ padding: '8px 10px', textAlign: 'left', fontWeight: 700, color: 'var(--text-muted)', fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.5, borderBottom: '1px solid rgba(255,255,255,0.06)' }}>Email</th>
                                                                                            <th style={{ padding: '8px 10px', textAlign: 'left', fontWeight: 700, color: 'var(--text-muted)', fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.5, borderBottom: '1px solid rgba(255,255,255,0.06)' }}>Phone</th>
                                                                                            <th style={{ padding: '8px 10px', textAlign: 'left', fontWeight: 700, color: 'var(--text-muted)', fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.5, borderBottom: '1px solid rgba(255,255,255,0.06)' }}>Gender</th>
                                                                                            <th style={{ padding: '8px 10px', textAlign: 'left', fontWeight: 700, color: 'var(--text-muted)', fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.5, borderBottom: '1px solid rgba(255,255,255,0.06)' }}>Nationality</th>
                                                                                            <th style={{ padding: '8px 10px', textAlign: 'left', fontWeight: 700, color: 'var(--text-muted)', fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.5, borderBottom: '1px solid rgba(255,255,255,0.06)' }}>Position</th>
                                                                                            <th style={{ padding: '8px 10px', textAlign: 'center', fontWeight: 700, color: 'var(--text-muted)', fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.5, borderBottom: '1px solid rgba(255,255,255,0.06)' }}>Security Status</th>
                                                                                        </tr>
                                                                                    </thead>
                                                                                    <tbody>
                                                                                        {/* Representative Row */}
                                                                                        <tr style={{ background: 'rgba(99,102,241,0.06)' }}>
                                                                                            <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)', fontWeight: 700, color: 'var(--accent-primary)' }}>R</td>
                                                                                            <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)', fontWeight: 700 }}>{v.visitor.name} <span style={{ fontSize: 10, padding: '1px 6px', borderRadius: 4, background: 'rgba(99,102,241,0.15)', color: 'var(--accent-primary)', marginLeft: 4 }}>REPRESENTATIVE</span></td>
                                                                                            <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)', fontFamily: 'monospace' }}>{v.visitor.id_document_no}</td>
                                                                                            <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)' }}>{v.visitor.email}</td>
                                                                                            <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)' }}>{v.visitor.phone}</td>
                                                                                            <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)', textTransform: 'capitalize' }}>{v.representative_gender || '—'}</td>
                                                                                            <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)' }}>{v.representative_nationality || '—'}</td>
                                                                                            <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)' }}>{v.representative_position || '—'}</td>
                                                                                            <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)', textAlign: 'center' }}>
                                                                                                {groupValidation[v.id] ? (
                                                                                                    (() => {
                                                                                                        const repVal = groupValidation[v.id].members.find(m => m.id_document_no === v.visitor.id_document_no);
                                                                                                        const status = repVal?.status || 'cleared';
                                                                                                        const colors = { cleared: { bg: 'rgba(16,185,129,0.12)', color: '#10b981', label: 'CLEARED' }, warning: { bg: 'rgba(245,158,11,0.12)', color: '#f59e0b', label: 'WATCHLIST' }, blocked: { bg: 'rgba(239,68,68,0.12)', color: '#ef4444', label: 'BLOCKED' } };
                                                                                                        const s = colors[status];
                                                                                                        return <span style={{ fontSize: 10, padding: '3px 8px', borderRadius: 4, background: s.bg, color: s.color, fontWeight: 700 }}>{s.label}</span>;
                                                                                                    })()
                                                                                                ) : (
                                                                                                    <span style={{ fontSize: 10, color: 'var(--text-muted)' }}>—</span>
                                                                                                )}
                                                                                            </td>
                                                                                        </tr>
                                                                                        {/* Group Members Rows */}
                                                                                        {v.groupMembers.map((m, i) => {
                                                                                            const memberValidation = groupValidation[v.id]?.members.find(vm => vm.id_document_no === m.id_document_no);
                                                                                            const status = memberValidation?.status || null;
                                                                                            const statusColors = { cleared: { bg: 'rgba(16,185,129,0.12)', color: '#10b981', label: 'CLEARED' }, warning: { bg: 'rgba(245,158,11,0.12)', color: '#f59e0b', label: 'WATCHLIST' }, blocked: { bg: 'rgba(239,68,68,0.12)', color: '#ef4444', label: 'BLOCKED' } };
                                                                                            const sc = status ? statusColors[status] : null;
                                                                                            return (
                                                                                                <tr key={i} style={{ background: status === 'blocked' ? 'rgba(239,68,68,0.04)' : status === 'warning' ? 'rgba(245,158,11,0.04)' : 'transparent' }}>
                                                                                                    <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)', color: 'var(--text-muted)' }}>{i + 1}</td>
                                                                                                    <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)', fontWeight: 600 }}>{m.name}</td>
                                                                                                    <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)', fontFamily: 'monospace' }}>{m.id_document_no}</td>
                                                                                                    <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)' }}>{m.email}</td>
                                                                                                    <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)' }}>{m.phone}</td>
                                                                                                    <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)', textTransform: 'capitalize' }}>{m.gender || '—'}</td>
                                                                                                    <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)' }}>{m.nationality || '—'}</td>
                                                                                                    <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)' }}>{m.position || '—'}</td>
                                                                                                    <td style={{ padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.04)', textAlign: 'center' }}>
                                                                                                        {sc ? (
                                                                                                            <span style={{ fontSize: 10, padding: '3px 8px', borderRadius: 4, background: sc.bg, color: sc.color, fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: 3 }}>
                                                                                                                {sc.label}
                                                                                                                {memberValidation?.reason && (
                                                                                                                    <span title={memberValidation.reason} style={{ cursor: 'help', fontSize: 9, opacity: 0.7 }}></span>
                                                                                                                )}
                                                                                                            </span>
                                                                                                        ) : (
                                                                                                            <span style={{ fontSize: 10, color: 'var(--text-muted)' }}>—</span>
                                                                                                        )}
                                                                                                    </td>
                                                                                                </tr>
                                                                                            );
                                                                                        })}
                                                                                    </tbody>
                                                                                </table>
                                                                            </div>

                                                                            {/* Blocked member warning */}
                                                                            {groupValidation[v.id]?.summary.blocked > 0 && (
                                                                                <div style={{ marginTop: 10, padding: '10px 14px', borderRadius: 8, background: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.25)', display: 'flex', alignItems: 'center', gap: 10 }}>
                                                                                    <span style={{ fontSize: 18 }}><AppIcon name="close" size={18} /></span>
                                                                                    <div>
                                                                                        <div style={{ fontSize: 12, fontWeight: 700, color: '#ef4444' }}>Security Alert: {groupValidation[v.id].summary.blocked} member(s) are on the blacklist</div>
                                                                                        <div style={{ fontSize: 11, color: 'var(--text-secondary)', marginTop: 2 }}>These members cannot be approved for entry. Consider removing them from the group or rejecting the request.</div>
                                                                                    </div>
                                                                                </div>
                                                                            )}
                                                                            {groupValidation[v.id]?.summary.warning > 0 && (
                                                                                <div style={{ marginTop: 10, padding: '10px 14px', borderRadius: 8, background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.25)', display: 'flex', alignItems: 'center', gap: 10 }}>
                                                                                    <span style={{ fontSize: 18 }}><AppIcon name="alert" size={18} /></span>
                                                                                    <div>
                                                                                        <div style={{ fontSize: 12, fontWeight: 700, color: '#f59e0b' }}>Watchlist Notice: {groupValidation[v.id].summary.warning} member(s) are on the watchlist</div>
                                                                                        <div style={{ fontSize: 11, color: 'var(--text-secondary)', marginTop: 2 }}>Review the flagged members before approving. Extra scrutiny is recommended.</div>
                                                                                    </div>
                                                                                </div>
                                                                            )}
                                                                        </div>
                                                                    )}

                                                                    {/* Attached Documents */}
                                                                    {v.documents && v.documents.length > 0 && (
                                                                        <div style={{ padding: '14px 16px', borderTop: '1px solid rgba(255,255,255,0.04)' }}>
                                                                            <div style={{ fontSize: 11, fontWeight: 700, color: '#10b981', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 8 }}> Attached Documents</div>
                                                                            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                                                                {v.documents.map((d, i) => (
                                                                                    <a key={i} href={`/storage/${d.file_path}`} target="_blank" rel="noopener noreferrer" style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 6, background: 'rgba(16,185,129,0.06)', border: '1px solid rgba(16,185,129,0.2)', color: '#10b981', textDecoration: 'none', fontSize: 12, fontWeight: 600, transition: 'all 0.2s' }}
                                                                                        onMouseEnter={e => { e.currentTarget.style.background = 'rgba(16,185,129,0.12)'; }}
                                                                                        onMouseLeave={e => { e.currentTarget.style.background = 'rgba(16,185,129,0.06)'; }}
                                                                                    > {(d.doc_type || d.type).replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                                                                        {d.original_name && <span style={{ fontSize: 10, color: 'var(--text-muted)', fontWeight: 400 }}>({d.original_name})</span>}
                                                                                    </a>
                                                                                ))}
                                                                            </div>
                                                                        </div>
                                                                    )}

                                                                    {/* Visit Details */}
                                                                    <div style={{ padding: '14px 16px', borderTop: '1px solid rgba(255,255,255,0.04)', background: 'rgba(245,158,11,0.03)' }}>
                                                                        <div style={{ fontSize: 11, fontWeight: 700, color: '#f59e0b', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 8 }}> Visit Details</div>
                                                                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 10 }}>
                                                                            <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                                <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}> Purpose</div>
                                                                                <div style={{ fontSize: 13, fontWeight: 600 }}>{v.purpose}</div>
                                                                            </div>
                                                                            <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                                <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}> Date & Time</div>
                                                                                <div style={{ fontSize: 13, fontWeight: 600 }}>{fmt(v.requested_time)}</div>
                                                                            </div>
                                                                            <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                                <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}> Duration</div>
                                                                                <div style={{ fontSize: 13, fontWeight: 600 }}>{v.duration} min</div>
                                                                            </div>
                                                                            <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                                <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}> Facility</div>
                                                                                <div style={{ fontSize: 13, fontWeight: 600 }}>{formatLocation(v.building, v.floor, v.meeting_room_id) || 'No location'}</div>
                                                                            </div>
                                                                            <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                                <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}> Host</div>
                                                                                <div style={{ fontSize: 13, fontWeight: 600 }}>{v.host?.name || host.name}</div>
                                                                            </div>
                                                                            <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                                <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}> Department</div>
                                                                                <div style={{ fontSize: 13, fontWeight: 600 }}>{v.department?.name || v.host?.department?.name || '—'}</div>
                                                                            </div>
                                                                            <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                                <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}> Visit Type</div>
                                                                                <div style={{ fontSize: 13, fontWeight: 600, textTransform: 'capitalize' }}>{v.visit_type === 'company' ? 'Company / Group' : 'Individual'}</div>
                                                                            </div>
                                                                            <div style={{ padding: '8px 10px', background: 'rgba(255,255,255,0.04)', borderRadius: 6, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                                                <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 2 }}> Request ID</div>
                                                                                <div style={{ fontSize: 13, fontWeight: 600, fontFamily: 'monospace' }}>#{v.id}</div>
                                                                            </div>
                                                                        </div>
                                                                        {v.comments && (
                                                                            <div style={{ marginTop: 10, padding: '8px 12px', borderRadius: 6, background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.2)', fontSize: 12, color: 'var(--status-warning)' }}> {v.comments}
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                </>
                                                            )}
                                                        </div>
                                                    )}
                                                    {v.visit_type !== 'company' && (
                                                        <>
                                                            <div style={{ fontSize: 13, marginTop: 6 }}><strong>Purpose:</strong> {v.purpose}</div>
                                                            <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}> {fmt(v.requested_time)} · {v.duration} min · {formatLocation(v.building, v.floor, v.meeting_room_id) || 'No location'}
                                                            </div>
                                                            {v.comments && <div style={{ fontSize: 12, color: 'var(--status-warning)', marginTop: 4 }}> {v.comments}</div>}
                                                            {v.documents && v.documents.length > 0 && (
                                                                <div style={{ marginTop: 12, padding: '12px 14px', borderRadius: 8, background: 'rgba(16,185,129,0.04)', border: '1px solid rgba(16,185,129,0.15)' }}>
                                                                    <div style={{ fontSize: 11, fontWeight: 700, color: '#10b981', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 8 }}> Attached Documents</div>
                                                                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                                                        {v.documents.map((d, i) => (
                                                                            <a key={i} href={`/storage/${d.file_path}`} target="_blank" rel="noopener noreferrer" style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 6, background: 'rgba(16,185,129,0.06)', border: '1px solid rgba(16,185,129,0.2)', color: '#10b981', textDecoration: 'none', fontSize: 12, fontWeight: 600, transition: 'all 0.2s' }}
                                                                                onMouseEnter={e => { e.currentTarget.style.background = 'rgba(16,185,129,0.12)'; }}
                                                                                onMouseLeave={e => { e.currentTarget.style.background = 'rgba(16,185,129,0.06)'; }}
                                                                            > {(d.doc_type || d.type).replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                                                                {d.original_name && <span style={{ fontSize: 10, color: 'var(--text-muted)', fontWeight: 400 }}>({d.original_name})</span>}
                                                                            </a>
                                                                        ))}
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                            <div style={{ display: 'flex', flexDirection: 'column', gap: 6, alignItems: 'flex-end', flexShrink: 0 }}>
                                                <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>#{v.id}</span>
                                                {v.qr_token && <span style={{ fontSize: 11, color: 'var(--accent-cyan)', background: 'rgba(6,182,212,0.08)', padding: '3px 8px', borderRadius: 6 }}> {v.qr_token.token_value}</span>}
                                                {v.visit_type === 'company' && v.groupMembers && v.groupMembers.some(m => m.qr_token) && (
                                                    <div style={{ marginTop: 4 }}>
                                                        <div style={{ fontSize: 10, color: 'var(--accent-cyan)', fontWeight: 700, marginBottom: 4, textAlign: 'right' }}>GROUP QR TOKENS:</div>
                                                        {v.groupMembers.filter(m => m.qr_token).map((m, i) => (
                                                            <div key={i} style={{ fontSize: 10, color: 'var(--text-secondary)', fontFamily: 'monospace', textAlign: 'right', marginBottom: 2 }}>
                                                                {m.name}: <span style={{ color: 'var(--accent-cyan)' }}>{m.qr_token}</span>
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        {/* Pending decision actions */}
                                        {v.status === 'pending' && !appointmentPassed(v) && (
                                            <div style={{ marginTop: 14, paddingTop: 12, borderTop: '1px solid rgba(255,255,255,0.06)' }}>
                                                {/* Comment field */}
                                                <input type="text" className="form-input" style={{ width: '100%', fontSize: 12, marginBottom: 10 }} placeholder="Comment / reason (optional)..." value={comments} onChange={e => setComments(e.target.value)} />
                                                {/* Action buttons row */}
                                                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 8 }}>
                                                    <button onClick={() => decide(v.id, 'approve')} className="btn btn-primary" style={{ fontSize: 11, padding: '5px 12px' }}> Approve</button>
                                                    <button onClick={() => decide(v.id, 'reject')} className="btn btn-danger" style={{ fontSize: 11, padding: '5px 12px' }}> Reject</button>
                                                    <button onClick={() => openEscalate(v)} className="btn btn-secondary" style={{ fontSize: 11, padding: '5px 12px' }}> Escalate</button>
                                                    <button onClick={() => setTransferOpen(p => ({ ...p, [v.id]: !p[v.id] }))} className="btn btn-secondary" style={{ fontSize: 11, padding: '5px 12px' }}> Transfer</button>
                                                </div>
                                                {/* Reschedule button for pending visits */}
                                                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
                                                    <button onClick={() => { setHostReschedModal(v); setHostReschedForm({ new_requested_time: toLocalDatetimeInput(v.requested_time) || '', new_duration: v.duration || 60, reason: '' }); }} className="btn btn-secondary" style={{ fontSize: 11, padding: '5px 12px' }}>
                                                        <AppIcon name="calendar" size={12} /> Reschedule
                                                    </button>
                                                </div>
                                                {renderTransferPanel(v)}
                                            </div>
                                        )}

                                        {/* Edit time for pending */}
                                        {v.status === 'pending' && !appointmentPassed(v) && (
                                            <div style={{ marginTop: 8 }}>
                                                <button onClick={() => toggleEdit(v.id, v.requested_time, v.duration)} style={{ background: 'none', border: '1px solid rgba(255,255,255,0.1)', color: 'var(--text-muted)', fontSize: 11, padding: '3px 10px', borderRadius: 6, cursor: 'pointer' }}> {editOpen[v.id] ? 'Cancel' : 'Edit Time'}
                                                </button>
                                                {editOpen[v.id] && (
                                                    <div style={{ display: 'flex', gap: 10, marginTop: 8, flexWrap: 'wrap' }}>
                                                        <input type="datetime-local" className="form-input" style={{ width: 200, fontSize: 12 }} value={editState[v.id]?.requested_time || ''} onChange={e => setEditState(p => ({ ...p, [v.id]: { ...p[v.id], requested_time: e.target.value } }))} />
                                                        <select className="form-select" style={{ width: 120, fontSize: 12 }} value={editState[v.id]?.duration || 60} onChange={e => setEditState(p => ({ ...p, [v.id]: { ...p[v.id], duration: Number(e.target.value) } }))}>
                                                            {[15, 30, 60, 120, 240].map(d => <option key={d} value={d}>{d} min</option>)}
                                                        </select>
                                                        <button onClick={() => saveTime(v.id)} className="btn btn-primary" style={{ fontSize: 12 }}>Save</button>
                                                    </div>
                                                )}
                                            </div>
                                        )}

                                        {/* Approved visit actions */}
                                        {v.status === 'approved' && (
                                            <div style={{ marginTop: 14, paddingTop: 12, borderTop: '1px solid rgba(255,255,255,0.06)', display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                                                <button onClick={() => deleteVisit(v.id, v.visitor.name)} className="btn btn-danger" style={{ fontSize: 11, padding: '5px 12px' }}>
                                                    <AppIcon name="close" size={11} /> Delete Visit
                                                </button>
                                                <button onClick={() => openEscalate(v)} className="btn btn-secondary" style={{ fontSize: 11, padding: '5px 12px' }}>
                                                    <AppIcon name="warning" size={11} /> Escalate
                                                </button>
                                                <button onClick={() => setTransferOpen(p => ({ ...p, [v.id]: !p[v.id] }))} className="btn btn-secondary" style={{ fontSize: 11, padding: '5px 12px' }}>
                                                    <AppIcon name="users" size={11} /> Transfer
                                                </button>
                                            </div>
                                        )}

                                        {v.status === 'approved' && renderTransferPanel(v)}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* ══════ APPOINTMENTS TAB ══════ */}
                    {tab === 'appointments' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
                            <div className="glass-card">
                                <SectionTitle> Meeting Calendar View</SectionTitle>
                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 8, marginBottom: 16 }}>
                                    {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(d => (
                                        <div key={d} style={{ textAlign: 'center', fontSize: 11, color: 'var(--text-muted)', fontWeight: 700, padding: '6px 0', textTransform: 'uppercase', letterSpacing: 1 }}>{d}</div>
                                    ))}
                                </div>
                                {/* Today's schedule timeline */}
                                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                    {filterVisits('today').length === 0 ? (
                                        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No visits scheduled for today.</p>
                                    ) : filterVisits('today').map(v => (
                                        <div key={v.id} style={{ display: 'flex', gap: 16, alignItems: 'center', padding: '12px 16px', borderRadius: 10, background: v.status === 'checked_in' ? 'rgba(99,102,241,0.1)' : 'rgba(255,255,255,0.02)', border: '1px solid rgba(255,255,255,0.06)' }}>
                                            <div style={{ minWidth: 60, textAlign: 'center' }}>
                                                <div style={{ fontWeight: 800, color: 'var(--accent-primary)', fontSize: 16 }}>{fmtTime(v.requested_time)}</div>
                                                <div style={{ fontSize: 10, color: 'var(--text-muted)' }}>{v.duration}min</div>
                                            </div>
                                            <div style={{ width: 3, alignSelf: 'stretch', background: priorityColor[v.priority] || '#6366f1', borderRadius: 3 }} />
                                            <div style={{ flex: 1 }}>
                                                <div style={{ fontWeight: 600 }}>{v.visitor.name}</div>
                                                <div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>{v.purpose} · {formatLocation(v.building, v.floor, v.meeting_room_id) || 'No location'}</div>
                                            </div>
                                            <span className={`badge badge-${v.status}`}>{v.status.replace(/_/g, ' ')}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* Upcoming visits */}
                            <div className="glass-card">
                                <SectionTitle> Upcoming Approved Appointments</SectionTitle>
                                {filterVisits('upcoming').length === 0 ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No upcoming approved appointments.</p>
                                ) : (
                                    <div className="table-container" style={{ marginBottom: 0 }}>
                                        <table className="data-table">
                                            <thead><tr><th>Visitor</th><th>Purpose</th><th>Date & Time</th><th>Duration</th><th>Facility</th><th>Priority</th><th>QR Token</th></tr></thead>
                                            <tbody>
                                                {filterVisits('upcoming').map(v => (
                                                    <tr key={v.id}>
                                                        <td><div style={{ fontWeight: 600 }}>{v.visitor.name}</div><div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.visitor.email}</div></td>
                                                        <td>{v.purpose}</td>
                                                        <td style={{ whiteSpace: 'nowrap' }}>{fmt(v.requested_time)}</td>
                                                        <td>{v.duration} min</td>
                                                        <td>{formatLocation(v.building, v.floor, v.meeting_room_id) || '—'}</td>
                                                        <td><span style={{ color: priorityColor[v.priority], fontWeight: 700, fontSize: 12 }}>{priorityIcon[v.priority]} {v.priority}</span></td>
                                                        <td style={{ fontSize: 11, fontFamily: 'monospace', color: 'var(--accent-cyan)' }}>{v.qr_token?.token_value || '—'}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ══════ HOST CHANGE TAB ══════ */}
                    {tab === 'reschedules' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
                            <div className="glass-card">
                                <SectionTitle>Pending Reschedule Requests ({pendingReschedules.length})</SectionTitle>
                                {!pendingReschedules.length ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No pending reschedule requests.</p>
                                ) : (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                                        {pendingReschedules.map(r => {
                                            const v = r.visit_request || {};
                                            return (
                                                <div key={r.id} style={{ padding: '16px', borderRadius: 10, background: 'rgba(245,158,11,0.05)', border: '1px solid rgba(245,158,11,0.2)' }}>
                                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16 }}>
                                                        <div>
                                                            <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 4 }}>
                                                                <span style={{ fontWeight: 700, fontSize: 14 }}>{v.visitor?.name || `Visit #${r.visit_request_id}`}</span>
                                                                <span className="badge badge-pending" style={{ fontSize: 10 }}>{r.status}</span>
                                                                <span style={{ fontSize: 10, padding: '2px 6px', borderRadius: 8, background: 'rgba(99,102,241,0.1)', color: 'var(--accent-primary)' }}>
                                                                    Requested by {r.requested_by_role}
                                                                </span>
                                                            </div>
                                                            <div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>
                                                                Current: {r.old_requested_time ? new Date(r.old_requested_time).toLocaleString() : 'N/A'} ({r.old_duration} min)
                                                            </div>
                                                            <div style={{ fontSize: 12, color: 'var(--status-success)', marginTop: 2 }}>
                                                                Requested: {r.new_requested_time ? new Date(r.new_requested_time).toLocaleString() : 'N/A'} ({r.new_duration} min)
                                                            </div>
                                                            {r.reason && <div style={{ fontSize: 12, color: 'var(--status-danger)', marginTop: 4 }}>Reason: {r.reason}</div>}
                                                        </div>
                                                        <div style={{ display: 'flex', flexDirection: 'column', gap: 8, flexShrink: 0 }}>
                                                            <button onClick={() => { const notes = prompt('Approval notes (optional):'); router.post(`/reschedule-requests/${r.id}/review`, { action: 'approve', comments: notes || '' }); }} className="btn btn-primary" style={{ fontSize: 11, padding: '6px 16px' }}>Approve</button>
                                                            <button onClick={() => { const notes = prompt('Rejection reason:'); if (notes !== null) router.post(`/reschedule-requests/${r.id}/review`, { action: 'reject', comments: notes }); }} className="btn btn-danger" style={{ fontSize: 11, padding: '6px 16px' }}>Reject</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>

                            <div className="glass-card">
                                <SectionTitle>Reschedule History ({rescheduleHistory.length})</SectionTitle>
                                {!rescheduleHistory.length ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No reschedule history yet.</p>
                                ) : (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                        {rescheduleHistory.map(r => {
                                            const v = r.visit_request || {};
                                            return (
                                                <div key={r.id} style={{ padding: '12px 16px', borderRadius: 8, background: 'rgba(255,255,255,0.02)', border: `1px solid ${r.status === 'approved' ? 'rgba(16,185,129,0.3)' : r.status === 'rejected' ? 'rgba(239,68,68,0.3)' : 'rgba(107,114,128,0.3)'}` }}>
                                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                                        <span style={{ fontWeight: 600, fontSize: 13 }}>Visit #{r.visit_request_id} — {v.visitor?.name}</span>
                                                        <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 10, background: r.status === 'approved' ? 'rgba(16,185,129,0.15)' : r.status === 'rejected' ? 'rgba(239,68,68,0.15)' : 'rgba(107,114,128,0.15)', color: r.status === 'approved' ? '#10b981' : r.status === 'rejected' ? '#ef4444' : '#6b7280', fontWeight: 600 }}>{r.status}</span>
                                                    </div>
                                                    <div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>
                                                        {r.old_requested_time ? new Date(r.old_requested_time).toLocaleString() : 'N/A'} → {r.new_requested_time ? new Date(r.new_requested_time).toLocaleString() : 'N/A'} ({r.new_duration} min)
                                                    </div>
                                                    <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>
                                                        By {r.requested_by_role}{r.reason ? ` · Reason: ${r.reason}` : ''} · {r.created_at ? new Date(r.created_at).toLocaleString() : ''}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {tab === 'host_change' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
                            {/* Pending Incoming Host Change Requests */}
                            <div className="glass-card">
                                <SectionTitle>Incoming Transfer Requests ({(pending_host_change_requests || []).length})</SectionTitle>
                                {!(pending_host_change_requests || []).length ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No pending transfer requests assigned to you.</p>
                                ) : (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                                        {(pending_host_change_requests || []).map(r => (
                                            <div key={r.id} style={{ padding: '16px', borderRadius: 10, background: 'rgba(245,158,11,0.05)', border: '1px solid rgba(245,158,11,0.2)' }}>
                                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16 }}>
                                                    <div>
                                                        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 4 }}>
                                                            <span style={{ fontWeight: 700, fontSize: 14 }}>{r.visit_request?.visitor?.name}</span>
                                                            <span className="badge badge-pending" style={{ fontSize: 10 }}>pending</span>
                                                            <span style={{ fontSize: 10, padding: '2px 6px', borderRadius: 8, background: 'rgba(99,102,241,0.1)', color: 'var(--accent-primary)' }}>
                                                                {r.visit_request?.status === 'pending' ? 'Pre-Approval' : 'Active Visit'}
                                                            </span>
                                                        </div>
                                                        <div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>
                                                            Visitor: {r.visit_request?.visitor?.name} · Purpose: {r.visit_request?.purpose}
                                                        </div>
                                                        <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginTop: 2 }}>
                                                            Visitor Phone: {r.visit_request?.visitor?.phone || 'N/A'} · Visit Time: {r.visit_request?.requested_time ? new Date(r.visit_request.requested_time).toLocaleString() : 'N/A'}
                                                        </div>
                                                        <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginTop: 2 }}>
                                                            Previous Host: {r.previous_host?.name} → <strong style={{ color: 'var(--status-success)' }}>You</strong>
                                                        </div>
                                                        <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginTop: 2 }}>
                                                            New Department: {r.new_department?.name || 'N/A'}{r.meeting_room ? ` · Meeting Room: ${r.meeting_room}` : ''}
                                                        </div>
                                                        <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>
                                                            Requested by: {r.requested_by_user?.name || 'System'} ({r.requested_by_role})
                                                        </div>
                                                        <div style={{ fontSize: 12, color: 'var(--status-danger)', marginTop: 4 }}>Reason: {r.reason}</div>
                                                        {r.additional_notes && <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>Notes: {r.additional_notes}</div>}
                                                    </div>
                                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8, flexShrink: 0 }}>
                                                        <button onClick={() => { const notes = prompt('Accept notes (optional):'); router.post(`/host-change-requests/${r.id}/accept`, { review_notes: notes || '' }); }} className="btn btn-primary" style={{ fontSize: 11, padding: '6px 16px' }}>Accept</button>
                                                        <button onClick={() => { const notes = prompt('Rejection reason:'); if (notes !== null) router.post(`/host-change-requests/${r.id}/reject`, { review_notes: notes }); }} className="btn btn-danger" style={{ fontSize: 11, padding: '6px 16px' }}>Reject</button>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* Request Host Change for a Visit */}
                            <div className="glass-card">
                                <SectionTitle>Request Host Change</SectionTitle>
                                <p style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 16 }}>Select a visit to request a host change for:</p>
                                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                    {filterVisits('pending').concat(filterVisits('approved')).concat(filterVisits('checked_in')).slice(0, 10).map(v => (
                                        <div key={v.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '12px 16px', borderRadius: 8, background: 'rgba(255,255,255,0.02)', border: '1px solid rgba(255,255,255,0.06)' }}>
                                            <div>
                                                <span style={{ fontWeight: 600 }}>{v.visitor.name}</span>
                                                <span style={{ fontSize: 12, color: 'var(--text-secondary)', marginLeft: 8 }}>{v.purpose}</span>
                                                <span className={`badge badge-${v.status}`} style={{ marginLeft: 8, fontSize: 10 }}>{v.status}</span>
                                            </div>
                                            <button onClick={() => { setHostChangeVisit(v); setHostChangeForm({ new_host_id: '', new_department_id: '', reason: '', additional_notes: '', meeting_room: '' }); }} className="btn btn-secondary" style={{ fontSize: 11, padding: '5px 12px' }}>Request Change</button>
                                        </div>
                                    ))}
                                    {filterVisits('pending').length + filterVisits('approved').length + filterVisits('checked_in').length === 0 && (
                                        <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>No eligible visits for host change.</p>
                                    )}
                                </div>
                            </div>

                            {/* My Host Change Requests History */}
                            <div className="glass-card">
                                <SectionTitle>My Transfer Request History ({(my_host_change_requests || []).length})</SectionTitle>
                                {!(my_host_change_requests || []).length ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No host change requests submitted yet.</p>
                                ) : (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                        {(my_host_change_requests || []).map(r => (
                                            <div key={r.id} style={{ padding: '12px 16px', borderRadius: 8, background: 'rgba(255,255,255,0.02)', border: `1px solid ${r.status === 'accepted' ? 'rgba(16,185,129,0.3)' : r.status === 'rejected' ? 'rgba(239,68,68,0.3)' : r.status === 'cancelled' ? 'rgba(107,114,128,0.3)' : 'rgba(245,158,11,0.3)'}` }}>
                                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                                    <span style={{ fontWeight: 600, fontSize: 13 }}>Visit #{r.visit_request_id} — {r.visit_request?.visitor?.name}</span>
                                                    <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 10, background: r.status === 'accepted' ? 'rgba(16,185,129,0.15)' : r.status === 'rejected' ? 'rgba(239,68,68,0.15)' : r.status === 'cancelled' ? 'rgba(107,114,128,0.15)' : 'rgba(245,158,11,0.15)', color: r.status === 'accepted' ? '#10b981' : r.status === 'rejected' ? '#ef4444' : r.status === 'cancelled' ? '#6b7280' : '#f59e0b', fontWeight: 600 }}>{r.status}</span>
                                                </div>
                                                <div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>
                                                    {r.previous_host?.name} → {r.new_host?.name}
                                                </div>
                                                <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>
                                                    Reason: {r.reason} · {r.created_at ? new Date(r.created_at).toLocaleString() : ''}
                                                </div>
                                                {r.status === 'pending' && (
                                                    <button onClick={() => router.post(`/host-change-requests/${r.id}/cancel`)} className="btn btn-danger" style={{ fontSize: 10, padding: '3px 10px', marginTop: 8 }}>Cancel</button>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ══════ VISITORS TAB ══════ */}
                    {tab === 'visitors' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
                            {/* VIP visitors */}
                            {filterVisits('vip').length > 0 && (
                                <div className="glass-card" style={{ borderColor: 'rgba(236,72,153,0.3)' }}>
                                    <SectionTitle> VIP Visitors</SectionTitle>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                        {filterVisits('vip').map(v => (
                                            <div key={v.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 16px', background: 'rgba(236,72,153,0.06)', borderRadius: 10, border: '1px solid rgba(236,72,153,0.2)' }}>
                                                <div><div style={{ fontWeight: 700 }}>{v.visitor.name}</div><div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>{v.visitor.email} · {v.purpose}</div></div>
                                                <div style={{ display: 'flex', gap: 8 }}>
                                                    <span className={`badge badge-${v.status}`}>{v.status.replace(/_/g, ' ')}</span>
                                                    <button onClick={() => router.post(`/host/visits/${v.id}/vip`)} className="btn btn-secondary" style={{ fontSize: 11, padding: '3px 10px' }}>Remove VIP</button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* All visitors history table */}
                            <div className="glass-card">
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                                    <SectionTitle> Visitor History</SectionTitle>
                                    <button
                                        onClick={() => {
                                            if (!confirm('Delete ALL archived and rejected visitors? This permanently removes the visits, QR passes, and all related records. This cannot be undone.')) return;
                                            router.delete('/host/visits/bulk-delete', { preserveScroll: true });
                                        }}
                                        className="btn btn-secondary"
                                        style={{ fontSize: 11, padding: '4px 10px', color: 'var(--status-danger)', borderColor: 'rgba(239,68,68,0.3)', marginBottom: 20 }}
                                    >
                                        <AppIcon name="trash" size={11} /> Delete All Archived/Rejected
                                    </button>
                                </div>
                                <div className="table-container" style={{ marginBottom: 0 }}>
                                    <table className="data-table">
                                        <thead><tr><th>Visitor</th><th>Contact</th><th>ID Doc</th><th>Last Visit</th><th>Status</th><th>Actions</th></tr></thead>
                                        <tbody>
                                            {visits.slice(0, 20).map(v => (
                                                <tr key={v.id}>
                                                    <td>
                                                        <div style={{ fontWeight: 600 }}>{v.visitor.name}</div>
                                                        <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.purpose}</div>
                                                    </td>
                                                    <td style={{ fontSize: 12 }}>{v.visitor.email}</td>
                                                    <td style={{ fontSize: 12, fontFamily: 'monospace' }}>{v.visitor.id_document_no}</td>
                                                    <td style={{ fontSize: 12, whiteSpace: 'nowrap' }}>{fmtDate(v.requested_time)}</td>
                                                    <td><span className={`badge badge-${v.status}`}>{v.status.replace(/_/g, ' ')}</span></td>
                                                    <td>
                                                        <div style={{ display: 'flex', gap: 4 }}>
                                                            {['archived', 'rejected'].includes(v.status) && (
                                                                <button onClick={() => deleteVisit(v.id, v.visitor.name)} className="btn btn-secondary" style={{ fontSize: 10, padding: '3px 7px', color: 'var(--status-danger)', borderColor: 'rgba(239,68,68,0.3)' }}>
                                                                    <AppIcon name="trash" size={10} />
                                                                </button>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ══════ ARRIVAL TAB ══════ */}
                    {tab === 'arrival' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
                            {/* Expected arrivals (approved, not yet arrived) */}
                            <div className="glass-card">
                                <SectionTitle> Expected Arrivals — {filterVisits('approved').length}</SectionTitle>
                                {filterVisits('approved').length === 0 ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No visitors expected. All approved visits will appear here.</p>
                                ) : (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                                        {filterVisits('approved').map(v => (
                                            <div key={v.id} style={{ padding: '16px', background: 'rgba(99,102,241,0.05)', borderRadius: 12, border: '1px solid rgba(99,102,241,0.15)', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
                                                <div style={{ flex: 1 }}>
                                                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 4 }}>
                                                        <span style={{ fontWeight: 700, fontSize: 16 }}>{v.visitor.name}</span>
                                                        {v.is_vip && <span className="badge" style={{ background: 'rgba(236,72,153,0.15)', color: '#ec4899', border: '1px solid rgba(236,72,153,0.3)' }}> VIP</span>}
                                                    </div>
                                                    <div style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{v.purpose} · {v.visitor.phone}</div>
                                                    <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>
                                                        Scheduled: <strong style={{ color: 'var(--accent-cyan)' }}>{fmt(v.requested_time)}</strong> · {v.duration} min
                                                    </div>
                                                </div>
                                                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8 }}>
                                                    <button onClick={() => router.post(`/host/visits/${v.id}/confirm-arrival`)} className="btn btn-primary" style={{ fontSize: 11, padding: '5px 11px' }}> Arrived</button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ══════ DURING VISIT TAB ══════ */}
                    {tab === 'during' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
                            {filterVisits('checked_in').length > 0 && (
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                                    <SectionTitle> Active Meetings ({filterVisits('checked_in').length})</SectionTitle>
                                    <button
                                        onClick={() => {
                                            if (!confirm(`Delete ALL ${filterVisits('checked_in').length} active visitor(s)? This permanently removes the visits, QR passes, and all related records. This cannot be undone.`)) return;
                                            router.delete('/host/visits/bulk-delete?status=checked_in', { preserveScroll: true });
                                        }}
                                        className="btn btn-secondary"
                                        style={{ fontSize: 11, padding: '4px 10px', color: 'var(--status-danger)', borderColor: 'rgba(239,68,68,0.3)' }}
                                    >
                                        <AppIcon name="trash" size={11} /> Delete All
                                    </button>
                                </div>
                            )}
                            {filterVisits('checked_in').length === 0 ? (
                                <div className="glass-card" style={{ textAlign: 'center', padding: 40 }}>
                                    <div style={{ fontSize: 48, marginBottom: 16 }}><AppIcon name="handshake" size={48} /></div>
                                    <p style={{ color: 'var(--text-muted)' }}>No active meetings right now.</p>
                                </div>
                            ) : filterVisits('checked_in').map(v => (
                                <div key={v.id} className="glass-card" style={{ borderColor: 'rgba(99,102,241,0.3)' }}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 20 }}>
                                        <div>
                                            <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 4 }}>
                                                <span style={{ fontWeight: 700, fontSize: 18 }}>{v.visitor.name}</span>
                                                {v.is_vip && <span className="badge" style={{ background: 'rgba(236,72,153,0.15)', color: '#ec4899', border: '1px solid rgba(236,72,153,0.3)' }}> VIP</span>}
                                                <span className="badge badge-checked_in"> In Meeting</span>
                                            </div>
                                            <div style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{v.purpose} · {v.duration} min</div>
                                            {v.meeting_started_at && <div style={{ fontSize: 12, color: 'var(--status-success)', marginTop: 4 }}>Started: {fmt(v.meeting_started_at)}</div>}
                                            {v.visit_type === 'company' && v.groupMembers && v.groupMembers.length > 0 && (
                                                <div style={{ marginTop: 6, padding: '6px 10px', background: 'rgba(6,182,212,0.06)', borderRadius: 6, border: '1px solid rgba(6,182,212,0.15)' }}>
                                                    <div style={{ fontSize: 10, fontWeight: 700, color: 'var(--accent-cyan)', marginBottom: 3 }}>GROUP MEMBERS</div>
                                                    <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                                                        {v.groupMembers.map((m, i) => (
                                                            <span key={i} style={{ fontSize: 9, padding: '1px 6px', borderRadius: 4, background: m.checked_out_at ? 'rgba(139,92,246,0.12)' : m.checked_in_at ? 'rgba(16,185,129,0.12)' : 'rgba(245,158,11,0.12)', color: m.checked_out_at ? '#a78bfa' : m.checked_in_at ? '#10b981' : '#f59e0b', fontWeight: 600 }}>
                                                                {m.name} {m.checked_out_at ? '(Left)' : m.checked_in_at ? '(In)' : '(Wait)'}
                                                            </span>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
                                            <button onClick={() => router.post(`/host/visits/${v.id}/meeting-start`)} className="btn btn-primary" style={{ fontSize: 11, padding: '5px 11px' }}> Start</button>
                                            <button onClick={() => setExtendId(v.id)} className="btn btn-secondary" style={{ fontSize: 11, padding: '5px 11px' }}> Extend</button>
                                            <button onClick={() => openEscalate(v)} className="btn btn-secondary" style={{ fontSize: 11, padding: '5px 11px' }}> Escalate</button>
                                            <button onClick={() => setTransferOpen(p => ({ ...p, [v.id]: !p[v.id] }))} className="btn btn-secondary" style={{ fontSize: 11, padding: '5px 11px' }}> Transfer Visitor</button>
                                            <button onClick={() => deleteVisit(v.id, v.visitor.name)} className="btn btn-secondary" style={{ fontSize: 11, padding: '5px 11px', color: 'var(--status-danger)', borderColor: 'rgba(239,68,68,0.3)' }}>
                                                <AppIcon name="trash" size={13} /> Delete
                                            </button>
                                        </div>
                                    </div>

                                    {/* Extend duration */}
                                    {extendId === v.id && (
                                        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 16, padding: '10px 14px', background: 'rgba(99,102,241,0.06)', borderRadius: 8, flexWrap: 'wrap' }}>
                                            <span style={{ fontSize: 12, color: 'var(--text-secondary)', flexShrink: 0 }}>Extend by:</span>
                                            {[15, 30, 60, 90].map(m => (
                                                <button key={m} onClick={() => { router.post(`/host/visits/${v.id}/extend`, { extra_minutes: m }); setExtendId(null); }} className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 10px' }}>+{m}m</button>
                                            ))}
                                            <button onClick={() => setExtendId(null)} className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 10px' }}></button>
                                        </div>
                                    )}

                                    {/* Transfer visitor — comprehensive transfer */}
                                    {transferOpen[v.id] && (
                                        <div style={{ marginBottom: 16, padding: '12px', background: 'rgba(99,102,241,0.06)', borderRadius: 8 }}>
                                            <div style={{ fontSize: 13, marginBottom: 8, fontWeight: 600 }}>Transfer visitor to:</div>
                                            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 8 }}>
                                                <select
                                                    className="form-select"
                                                    style={{ flex: 1, minWidth: 200, fontSize: 12 }}
                                                    value={transferHost?.id || ''}
                                                    onChange={e => {
                                                        const selected = eligibleTransferUsers(v).find(u => u.id === Number(e.target.value));
                                                        setTransferHost(selected || null);
                                                        setTransferReason('');
                                                    }}
                                                >
                                                    <option value="">Select a host, admin or CEO...</option>
                                                    {eligibleTransferUsers(v).map(u => (
                                                        <option key={u.id} value={u.id}>
                                                            {u.name} ({u.role === 'admin' ? 'Admin' : u.role === 'ceo' ? 'CEO' : 'Host'}{u.department?.name ? ` — ${u.department.name}` : ''})
                                                        </option>
                                                    ))}
                                                </select>
                                                {eligibleTransferUsers(v).length === 0 && (
                                                    <span style={{ fontSize: 12, color: 'var(--status-danger)' }}>No eligible users available in your company or branch.</span>
                                                )}
                                            </div>
                                            {transferHost && (
                                                <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                                                    <input type="text" className="form-input" style={{ flex: 1, minWidth: 180, fontSize: 12 }} placeholder="Reason for transfer (optional)" value={transferReason} onChange={e => setTransferReason(e.target.value)} />
                                                    <button onClick={() => { router.post(`/visit-requests/${v.id}/transfer`, { visit_request_id: v.id, new_host_id: transferHost.id, reason: transferReason }); setTransferOpen(p => ({ ...p, [v.id]: false })); setTransferHost(null); setTransferReason(''); }} className="btn btn-primary" style={{ fontSize: 12 }}>
                                                        Confirm Transfer
                                                    </button>
                                                    <button onClick={() => { setTransferHost(null); setTransferReason(''); }} className="btn btn-secondary" style={{ fontSize: 12 }}>Cancel</button>
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {/* Meeting notes */}
                                    <div style={{ borderTop: '1px solid rgba(255,255,255,0.06)', paddingTop: 16 }}>
                                        <div style={{ fontWeight: 600, fontSize: 13, marginBottom: 8 }}>Meeting Notes & Agenda</div>
                                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                                            <div>
                                                <label className="form-label">Agenda</label>
                                                <textarea className="form-textarea" style={{ minHeight: 80, fontSize: 12 }} placeholder="Meeting agenda..." defaultValue={v.meeting_agenda || ''} onBlur={e => router.post(`/host/visits/${v.id}/notes`, { meeting_agenda: e.target.value, meeting_notes: v.meeting_notes || '', visitor_notes: v.visitor_notes || '' })} />
                                            </div>
                                            <div>
                                                <label className="form-label">Notes</label>
                                                <textarea className="form-textarea" style={{ minHeight: 80, fontSize: 12 }} placeholder="Meeting notes..." defaultValue={v.meeting_notes || ''} onBlur={e => router.post(`/host/visits/${v.id}/notes`, { meeting_notes: e.target.value, meeting_agenda: v.meeting_agenda || '', visitor_notes: v.visitor_notes || '' })} />
                                            </div>
                                        </div>
                                        <div style={{ marginTop: 12, display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                                            <input type="text" className="form-input" style={{ flex: 1, minWidth: 0, fontSize: 12 }} placeholder="Outcome summary..." value={outcomeText[v.id] || ''} onChange={e => setOutcomeText(p => ({ ...p, [v.id]: e.target.value }))} />
                                            <button onClick={() => { if (!outcomeText[v.id]) return; router.post(`/host/visits/${v.id}/meeting-end`, { meeting_outcome: outcomeText[v.id] }); }} className="btn btn-danger" style={{ fontSize: 11, padding: '6px 12px', flexShrink: 0 }}> End</button>
                                        </div>
                                    </div>
                                </div>
                            ))}

                            {/* Completed meetings today */}
                            {filterVisits('completed').length > 0 && (
                                <div className="glass-card">
                                    <SectionTitle> Completed</SectionTitle>
                                    <div className="table-container" style={{ marginBottom: 0 }}>
                                        <table className="data-table">
                                            <thead><tr><th>Visitor</th><th>Purpose</th><th>Duration</th><th>Outcome</th><th>Feedback</th></tr></thead>
                                            <tbody>
                                                {filterVisits('completed').map(v => (
                                                    <tr key={v.id}>
                                                        <td style={{ fontWeight: 600 }}>{v.visitor.name}</td>
                                                        <td>{v.purpose}</td>
                                                        <td>{v.duration} min</td>
                                                        <td style={{ fontSize: 12, color: 'var(--text-secondary)', maxWidth: 200 }}>{v.meeting_outcome || '—'}</td>
                                                        <td>{v.feedback ? <span style={{ color: 'var(--status-warning)', display: 'inline-flex', alignItems: 'center', gap: 2 }}>{Array.from({ length: v.feedback.rating }, (_, i) => <AppIcon key={i} name="star" />)}</span> : <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>No feedback</span>}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* ══════ FACILITY TAB ══════ */}
                    {tab === 'facility' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
                            {/* Manage facilities */}
                            <div className="glass-card">
                                <SectionTitle> Manage Facilities</SectionTitle>
                                <p style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 16 }}>Create, update or delete the buildings, floors and rooms used to assign facilities to visits.</p>
                                <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 18 }}>
                                    <input className="form-input" style={{ flex: 1, minWidth: 150, fontSize: 12 }} placeholder="Building (e.g. Main Building)" value={facForm.building} onChange={e => setFacForm(p => ({ ...p, building: e.target.value }))} />
                                    <input className="form-input" style={{ flex: 1, minWidth: 150, fontSize: 12 }} placeholder="Floor (e.g. Floor 1)" value={facForm.floor} onChange={e => setFacForm(p => ({ ...p, floor: e.target.value }))} />
                                    <input className="form-input" style={{ flex: 1, minWidth: 150, fontSize: 12 }} placeholder="Room (e.g. Conference Room A)" value={facForm.room} onChange={e => setFacForm(p => ({ ...p, room: e.target.value }))} />
                                    <button onClick={() => {
                                        if (!facForm.building || !facForm.floor || !facForm.room) return;
                                        router.post('/host/facilities', facForm);
                                        setFacForm({ building: '', floor: '', room: '' });
                                    }} className="btn btn-primary" style={{ fontSize: 12, padding: '6px 16px', alignSelf: 'flex-start' }}>+ Add Facility</button>
                                </div>
                                {(facilities || []).length === 0 ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>No facilities yet. Add your first building, floor and room above.</p>
                                ) : (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                        {facilities.map(f => (
                                            <div key={f.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, padding: '10px 12px', background: 'rgba(255,255,255,0.02)', borderRadius: 8, border: '1px solid rgba(255,255,255,0.06)' }}>
                                                {editingFacId === f.id ? (
                                                    <div style={{ display: 'flex', gap: 8, flex: 1, flexWrap: 'wrap' }}>
                                                        <input className="form-input" style={{ flex: 1, minWidth: 120, fontSize: 12 }} value={facEditForm.building} onChange={e => setFacEditForm(p => ({ ...p, building: e.target.value }))} />
                                                        <input className="form-input" style={{ flex: 1, minWidth: 120, fontSize: 12 }} value={facEditForm.floor} onChange={e => setFacEditForm(p => ({ ...p, floor: e.target.value }))} />
                                                        <input className="form-input" style={{ flex: 1, minWidth: 120, fontSize: 12 }} value={facEditForm.room} onChange={e => setFacEditForm(p => ({ ...p, room: e.target.value }))} />
                                                        <button onClick={() => router.put(`/host/facilities/${f.id}`, facEditForm)} className="btn btn-primary" style={{ fontSize: 11, padding: '4px 12px' }}>Save</button>
                                                        <button onClick={() => setEditingFacId(null)} className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 12px' }}>Cancel</button>
                                                    </div>
                                                ) : (
                                                    <>
                                                        <div style={{ fontSize: 13, fontWeight: 600 }}>{f.building} → {f.floor} → {f.room}</div>
                                                        <div style={{ display: 'flex', gap: 6 }}>
                                                            <button onClick={() => { setEditingFacId(f.id); setFacEditForm({ building: f.building, floor: f.floor, room: f.room }); }} className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 10px' }}>Edit</button>
                                                            <button onClick={() => { if (confirm(`Delete facility "${f.building} → ${f.floor} → ${f.room}"?`)) { router.delete(`/host/facilities/${f.id}`); } }} className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 10px', color: 'var(--status-danger)', borderColor: 'rgba(239,68,68,0.3)' }}>Delete</button>
                                                        </div>
                                                    </>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* Facility assignments */}
                            <div className="glass-card">
                                <SectionTitle> Facility Assignments</SectionTitle>
                                <div className="table-container" style={{ marginBottom: 0 }}>
                                    <table className="data-table">
                                        <thead><tr><th>Visitor</th><th>Current Location</th><th>Scheduled Time</th><th>Status</th><th>Change Facility</th></tr></thead>
                                        <tbody>
                                            {visits.filter(v => ['pending', 'approved', 'checked_in'].includes(v.status)).map(v => (
                                                <tr key={v.id}>
                                                    <td style={{ fontWeight: 600 }}>{v.visitor.name}</td>
                                                    <td>{formatLocation(v.building, v.floor, v.meeting_room_id) || <span style={{ color: 'var(--text-muted)' }}>Not assigned</span>}</td>
                                                    <td style={{ fontSize: 12, whiteSpace: 'nowrap' }}>{fmt(v.requested_time)}</td>
                                                    <td><span className={`badge badge-${v.status}`}>{v.status.replace(/_/g, ' ')}</span></td>
                                                    <td>
                                                        <FacilitySelect
                                                            building={v.building}
                                                            floor={v.floor}
                                                            room={v.meeting_room_id}
                                                            compact
                                                            facilities={facilities}
                                                            onChange={({ building, floor, room }) => router.post(`/visit-requests/${v.id}/assign-room`, { building, floor, meeting_room_id: room })}
                                                        />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ══════ COMPLETION TAB ══════ */}
                    {tab === 'completion' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
                            <div className="glass-card">
                                <SectionTitle> Complete & Close Visits</SectionTitle>
                                {filterVisits('checked_in').length === 0 ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No active visits to complete right now.</p>
                                ) : filterVisits('checked_in').map(v => (
                                    <div key={v.id} style={{ padding: '16px', background: 'rgba(99,102,241,0.05)', borderRadius: 12, border: '1px solid rgba(99,102,241,0.15)', marginBottom: 16 }}>
                                        <div style={{ fontWeight: 700, fontSize: 15, marginBottom: 8 }}>{v.visitor.name} — {v.purpose}</div>
                                        {v.visit_type === 'company' && v.groupMembers && v.groupMembers.length > 0 && (
                                            <div style={{ marginBottom: 12, padding: '8px 12px', background: 'rgba(6,182,212,0.06)', borderRadius: 8, border: '1px solid rgba(6,182,212,0.15)' }}>
                                                <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--accent-cyan)', marginBottom: 4 }}>Group Member Status</div>
                                                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                                                    {v.groupMembers.map((m, i) => (
                                                        <span key={i} style={{ fontSize: 10, padding: '2px 8px', borderRadius: 6, background: m.checked_out_at ? 'rgba(139,92,246,0.12)' : m.checked_in_at ? 'rgba(16,185,129,0.12)' : 'rgba(245,158,11,0.12)', color: m.checked_out_at ? '#a78bfa' : m.checked_in_at ? '#10b981' : '#f59e0b', fontWeight: 600 }}>
                                                            {m.name}: {m.checked_out_at ? 'Departed' : m.checked_in_at ? 'Inside' : 'Pending'}
                                                        </span>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                        <div style={{ display: 'flex', gap: 12, marginBottom: 12, flexWrap: 'wrap' }}>
                                            <div style={{ flex: 1 }}>
                                                <label className="form-label">Meeting Outcome</label>
                                                <textarea className="form-textarea" style={{ minHeight: 70, fontSize: 12 }} placeholder="Summarize the meeting outcome..." value={outcomeText[v.id] || ''} onChange={e => setOutcomeText(p => ({ ...p, [v.id]: e.target.value }))} />
                                            </div>
                                            <div style={{ flex: 1 }}>
                                                <label className="form-label">Meeting Notes</label>
                                                <textarea className="form-textarea" style={{ minHeight: 70, fontSize: 12 }} placeholder="Final meeting notes..." defaultValue={v.meeting_notes || ''} onBlur={e => router.post(`/host/visits/${v.id}/notes`, { meeting_notes: e.target.value, meeting_agenda: v.meeting_agenda || '', visitor_notes: v.visitor_notes || '' })} />
                                            </div>
                                        </div>
                                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                            <button onClick={() => router.post(`/host/visits/${v.id}/meeting-end`, { meeting_outcome: outcomeText[v.id] || '' })} className="btn btn-primary" style={{ fontSize: 11, padding: '6px 14px' }}> End Meeting</button>
                                            <button onClick={() => { if (confirm('End meeting and send visitor to Security Exit Gate for QR scan and property verification?')) { router.post(`/host/visits/${v.id}/meeting-end`, { meeting_outcome: outcomeText[v.id] || 'Meeting completed. Visitor proceeding to security exit gate.' }); } }} className="btn btn-secondary" style={{ fontSize: 11, padding: '6px 14px' }}> Send to Exit Gate</button>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {/* Completed visits ready for closure */}
                            <div className="glass-card">
                                <SectionTitle> Recent Completed Visits</SectionTitle>
                                <div className="table-container" style={{ marginBottom: 0 }}>
                                    <table className="data-table">
                                        <thead><tr><th>Visitor</th><th>Purpose</th><th>Completed</th><th>Duration</th><th>Outcome</th><th>Rating</th><th>Actions</th></tr></thead>
                                        <tbody>
                                            {filterVisits('completed').slice(0, 15).map(v => (
                                                <tr key={v.id}>
                                                    <td style={{ fontWeight: 600 }}>{v.visitor.name}</td>
                                                    <td>{v.purpose}</td>
                                                    <td style={{ fontSize: 12, whiteSpace: 'nowrap' }}>{fmtDate(v.meeting_ended_at || v.updated_at)}</td>
                                                    <td>{v.duration} min</td>
                                                    <td style={{ fontSize: 12, maxWidth: 180, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: 'var(--text-secondary)' }}>{v.meeting_outcome || '—'}</td>
                                                    <td>{v.feedback ? <span style={{ color: 'var(--status-warning)', display: 'inline-flex', alignItems: 'center', gap: 2 }}>{Array.from({ length: v.feedback.rating }, (_, i) => <AppIcon key={i} name="star" />)} {v.feedback.rating}/5</span> : <span style={{ color: 'var(--text-muted)', fontSize: 11 }}>Awaiting</span>}</td>
                                                    <td>
                                                        <button onClick={() => deleteVisit(v.id, v.visitor.name)} className="btn btn-danger" style={{ fontSize: 10, padding: '3px 10px' }}>
                                                            <AppIcon name="close" size={10} /> Delete
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ══════ ANALYTICS TAB ══════ */}
                    {tab === 'analytics' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
                            {(() => {
                                const totalVisits = visits.length || analytics.total || 1;
                                const pct = (c) => Math.round((c / totalVisits) * 100);
                                const approvedCount = visits.filter(v => v.status === 'approved').length;
                                const rejectedCount = visits.filter(v => v.status === 'rejected').length;
                                const archivedCount = visits.filter(v => v.status === 'archived').length;
                                return (
                                    <div className="stats-grid">
                                        <StatCard label="Total Visits" value={totalVisits} color="cyan" />
                                        <StatCard label="Approved" value={pct(approvedCount) + '%'} color="emerald" />
                                        <StatCard label="Rejected" value={pct(rejectedCount) + '%'} color="danger" />
                                        <StatCard label="Archived" value={pct(archivedCount) + '%'} color="default" />
                                        <StatCard label="No-Shows" value={analytics.missed} color="danger" />
                                        <StatCard label="Repeat Visitors" value={analytics.repeat_count} color="blue" />
                                    </div>
                                );
                            })()}

                            {/* Monthly bar chart */}
                            <div className="glass-card">
                                <SectionTitle> Monthly Visits ({new Date().getFullYear()})</SectionTitle>
                                <div style={{ display: 'flex', alignItems: 'flex-end', gap: 8, height: 140, padding: '0 8px' }}>
                                    {(analytics.monthly || []).map((m, i) => {
                                        const max = Math.max(...(analytics.monthly || []).map(x => x.count), 1);
                                        const h = Math.max(6, Math.round((m.count / max) * 120));
                                        const isCurrent = i === new Date().getMonth();
                                        return (
                                            <div key={i} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
                                                <span style={{ fontSize: 10, color: 'var(--text-secondary)', fontWeight: isCurrent ? 800 : 400 }}>{m.count}</span>
                                                <div style={{ width: '100%', height: h, background: isCurrent ? 'var(--gradient-primary)' : 'rgba(99,102,241,0.4)', borderRadius: '4px 4px 0 0', transition: 'height 0.5s' }} />
                                                <span style={{ fontSize: 9, color: isCurrent ? 'var(--accent-primary)' : 'var(--text-muted)', fontWeight: isCurrent ? 800 : 400 }}>{m.month}</span>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Status breakdown */}
                            <div className="grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24 }}>
                                <div className="glass-card">
                                    <SectionTitle> Visit Status Breakdown</SectionTitle>
                                    {[
                                        ['Pending', visits.filter(v => v.status === 'pending').length, 'warning'],
                                        ['Approved', visits.filter(v => v.status === 'approved').length, 'emerald'],
                                        ['Checked In', visits.filter(v => v.status === 'checked_in').length, 'cyan'],
                                        ['Completed', visits.filter(v => ['checked_out', 'archived'].includes(v.status)).length, 'blue'],
                                        ['Rejected', visits.filter(v => v.status === 'rejected').length, 'danger'],
                                        ['Escalated', visits.filter(v => v.status === 'escalated').length, 'warning'],
                                    ].map(([label, count, color]) => {
                                        const total = visits.length || 1;
                                        const pct = Math.round((count / total) * 100);
                                        const colors = { warning: 'var(--status-warning)', emerald: 'var(--status-success)', cyan: 'var(--accent-cyan)', blue: 'var(--status-info)', danger: 'var(--status-danger)' };
                                        return (
                                            <div key={label} style={{ marginBottom: 12 }}>
                                                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 4 }}>
                                                    <span>{label}</span><span style={{ fontWeight: 700, color: colors[color] }}>{count} ({pct}%)</span>
                                                </div>
                                                <div style={{ background: 'rgba(255,255,255,0.06)', borderRadius: 4, height: 6 }}>
                                                    <div style={{ width: `${pct}%`, height: '100%', background: colors[color], borderRadius: 4, transition: 'width 0.5s' }} />
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>

                                <div className="glass-card">
                                    <SectionTitle> Most Frequent Visitors</SectionTitle>
                                    {Object.entries(
                                        visits.reduce((acc, v) => { acc[v.visitor.name] = (acc[v.visitor.name] || 0) + 1; return acc; }, {})
                                    ).sort((a, b) => b[1] - a[1]).slice(0, 6).map(([name, count], i) => (
                                        <div key={name} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 0', borderBottom: '1px solid rgba(255,255,255,0.04)' }}>
                                            <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                                                <span style={{ fontWeight: 800, color: i < 3 ? 'var(--accent-primary)' : 'var(--text-muted)', minWidth: 20 }}>#{i + 1}</span>
                                                <span style={{ fontSize: 14 }}>{name}</span>
                                            </div>
                                            <span className="badge badge-approved">{count} visits</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    )}

                    {tab === 'messages' && (
                        <Messages
                            inbox={messages_inbox || []}
                            sent={messages_sent || []}
                            recipients={messages_recipients || []}
                            unread_count={messages_unread_count || 0}
                            sender_type="user"
                            sender_id={host.id}
                            embedded
                        />
                    )}

                </div> {/* end right content */}
            </div> {/* end flex layout */}

            {/* Host Reschedule Modal */}
            {hostReschedModal && (
                <div className="modal-overlay" style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 }} onClick={() => setHostReschedModal(null)}>
                    <div className="glass-card modal-content" style={{ width: 420, maxWidth: '90vw', padding: 24, border: '1px solid rgba(99,102,241,0.3)' }} onClick={e => e.stopPropagation()}>
                        <h3 style={{ fontWeight: 700, fontSize: 16, marginBottom: 16, display: 'flex', alignItems: 'center', gap: 8 }}>
                            <AppIcon name="calendar" size={18} /> Reschedule Visit #{hostReschedModal.id}
                        </h3>
                        <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginBottom: 12 }}>
                            Visitor: {hostReschedModal.visitor?.name}<br />
                            Current: {fmt(hostReschedModal.requested_time)} ({hostReschedModal.duration} min)
                        </div>
                        <form onSubmit={e => {
                            e.preventDefault();
                            const serverTime = localToServerDatetime(hostReschedForm.new_requested_time, tz);
                            router.post(`/visit-requests/${hostReschedModal.id}/request-reschedule`, {
                                new_requested_time: serverTime,
                                new_duration: hostReschedForm.new_duration,
                                reason: hostReschedForm.reason,
                            }, { onSuccess: () => setHostReschedModal(null) });
                        }}>
                            <div style={{ marginBottom: 12 }}>
                                <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>New Date & Time</label>
                                <input type="datetime-local" className="form-input" value={hostReschedForm.new_requested_time} onChange={e => setHostReschedForm(p => ({ ...p, new_requested_time: e.target.value }))} required style={{ width: '100%' }} />
                            </div>
                            <div style={{ marginBottom: 12 }}>
                                <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Duration</label>
                                <select className="form-input" value={hostReschedForm.new_duration} onChange={e => setHostReschedForm(p => ({ ...p, new_duration: Number(e.target.value) }))} required>
                                    {[15, 30, 60, 120, 240, 480].map(d => <option key={d} value={d}>{d} min</option>)}
                                </select>
                            </div>
                            <div style={{ marginBottom: 16 }}>
                                <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Reason</label>
                                <textarea className="form-input" value={hostReschedForm.reason} onChange={e => setHostReschedForm(p => ({ ...p, reason: e.target.value }))} rows={3} placeholder="Reason for reschedule..." style={{ width: '100%' }} />
                            </div>
                            <div style={{ display: 'flex', gap: 8 }}>
                                <button type="button" onClick={() => setHostReschedModal(null)} className="btn btn-secondary">Cancel</button>
                                <button type="submit" className="btn btn-primary">Confirm Reschedule</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Host Change Request Modal */}
            {hostChangeVisit && (
                <div className="modal-overlay" style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 }} onClick={() => setHostChangeVisit(null)}>
                    <div className="glass-card modal-content" style={{ width: 520, maxWidth: '90vw', padding: 24, border: '1px solid rgba(99,102,241,0.3)' }} onClick={e => e.stopPropagation()}>
                        <h3 style={{ fontWeight: 700, fontSize: 16, marginBottom: 16, display: 'flex', alignItems: 'center', gap: 8 }}>
                            <AppIcon name="users" size={18} /> Request Host Change — Visit #{hostChangeVisit.id}
                        </h3>
                        <div style={{ padding: '12px', borderRadius: 8, background: 'rgba(99,102,241,0.05)', border: '1px solid rgba(99,102,241,0.15)', marginBottom: 16 }}>
                            <div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>
                                <strong>Visitor:</strong> {hostChangeVisit.visitor?.name}<br />
                                <strong>Current Host:</strong> {hostChangeVisit.host?.name || 'Auto-assigned'}<br />
                                <strong>Department:</strong> {hostChangeVisit.department?.name || 'N/A'}<br />
                                <strong>Purpose:</strong> {hostChangeVisit.purpose}<br />
                                <strong>Visit Status:</strong> <span className={`badge badge-${hostChangeVisit.status}`}>{hostChangeVisit.status}</span>
                            </div>
                        </div>
                        <form onSubmit={e => {
                            e.preventDefault();
                            if (!hostChangeForm.new_host_id || !hostChangeForm.reason) { alert('Please select a new host and provide a reason.'); return; }
                            router.post('/host-change-requests', {
                                visit_request_id: hostChangeVisit.id,
                                new_host_id: hostChangeForm.new_host_id,
                                new_department_id: hostChangeForm.new_department_id || undefined,
                                reason: hostChangeForm.reason,
                                additional_notes: hostChangeForm.additional_notes || undefined,
                                meeting_room: hostChangeForm.meeting_room || undefined,
                            }, { onSuccess: () => setHostChangeVisit(null) });
                        }}>
                            <div style={{ marginBottom: 12 }}>
                                <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>New Host *</label>
                                <select className="form-input" value={hostChangeForm.new_host_id} onChange={e => setHostChangeForm(p => ({ ...p, new_host_id: e.target.value }))} required style={{ width: '100%' }}>
                                    <option value="">Select new host...</option>
                                    {eligibleTransferHosts(hostChangeVisit).map(h => (
                                        <option key={h.id} value={h.id}>{h.name} — {h.department?.name || 'N/A'}</option>
                                    ))}
                                </select>
                            </div>
                            <div style={{ marginBottom: 12 }}>
                                <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>New Department (optional)</label>
                                <select className="form-input" value={hostChangeForm.new_department_id} onChange={e => setHostChangeForm(p => ({ ...p, new_department_id: e.target.value }))} style={{ width: '100%' }}>
                                    <option value="">Same as host's department</option>
                                    {(all_hosts || []).reduce((acc, h) => { if (h.department && !acc.find(d => d.id === h.department_id)) acc.push(h.department); return acc; }, []).map(d => (
                                        <option key={d.id} value={d.id}>{d.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div style={{ marginBottom: 12 }}>
                                <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Reason for Change *</label>
                                <textarea className="form-input" value={hostChangeForm.reason} onChange={e => setHostChangeForm(p => ({ ...p, reason: e.target.value }))} rows={3} placeholder="Why is the host being changed?" required style={{ width: '100%' }} />
                            </div>
                            <div style={{ marginBottom: 12 }}>
                                <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Additional Notes</label>
                                <textarea className="form-input" value={hostChangeForm.additional_notes} onChange={e => setHostChangeForm(p => ({ ...p, additional_notes: e.target.value }))} rows={2} placeholder="Any additional context..." style={{ width: '100%' }} />
                            </div>
                            <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
                                <button type="button" onClick={() => setHostChangeVisit(null)} className="btn btn-secondary">Cancel</button>
                                <button type="submit" className="btn btn-primary">Submit Request</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Escalation Modal */}
            {escModal && (
                <div className="modal-overlay" style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 }} onClick={() => setEscModal(null)}>
                    <div className="glass-card modal-content" style={{ width: 480, maxWidth: '90vw', padding: 24, border: '1px solid rgba(245,158,11,0.4)' }} onClick={e => e.stopPropagation()}>
                        <h3 style={{ fontWeight: 700, fontSize: 16, marginBottom: 16, display: 'flex', alignItems: 'center', gap: 8 }}>
                            <AppIcon name="warning" size={18} /> Escalate Visit #{escModal.id}
                        </h3>
                        <div style={{ padding: '12px', borderRadius: 8, background: 'rgba(245,158,11,0.06)', border: '1px solid rgba(245,158,11,0.2)', marginBottom: 16, fontSize: 12, color: 'var(--text-secondary)' }}>
                            <strong>Visitor:</strong> {escModal.visitor?.name}<br />
                            <strong>Purpose:</strong> {escModal.purpose}<br />
                            <strong>Status:</strong> <span className={`badge badge-${escModal.status}`}>{escModal.status.replace(/_/g, ' ')}</span>
                        </div>
                        <form onSubmit={e => { e.preventDefault(); submitEscalate(escModal); }}>
                            <div style={{ marginBottom: 12 }}>
                                <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Escalate to (Admin / CEO) *</label>
                                <select className="form-input" value={escTarget} onChange={e => setEscTarget(e.target.value)} required style={{ width: '100%' }}>
                                    <option value="">Select Admin or CEO...</option>
                                    {eligibleEscalationTargets(escModal).map(u => (
                                        <option key={u.id} value={u.id}>{u.name} ({u.role === 'ceo' ? 'CEO' : 'Admin'}){u.department?.name ? ` — ${u.department.name}` : ''}</option>
                                    ))}
                                </select>
                                {eligibleEscalationTargets(escModal).length === 0 && (
                                    <span style={{ fontSize: 12, color: 'var(--status-danger)' }}>No active Admin or CEO in this company.</span>
                                )}
                            </div>
                            <div style={{ marginBottom: 12 }}>
                                <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Reason for Escalation</label>
                                <textarea className="form-input" value={escReason} onChange={e => setEscReason(e.target.value)} rows={3} placeholder="Why are you escalating this visit?" style={{ width: '100%' }} />
                            </div>
                            <div style={{ padding: '10px 12px', borderRadius: 8, background: 'rgba(99,102,241,0.06)', border: '1px solid rgba(99,102,241,0.15)', marginBottom: 16, fontSize: 11, color: 'var(--text-secondary)' }}>
                                The selected Admin/CEO becomes the responsible person for this visit. Once escalated, the visitor can be received and checked in by them. This can be transferred back to a host at any time.
                            </div>
                            <div style={{ display: 'flex', gap: 8 }}>
                                <button type="button" onClick={() => setEscModal(null)} className="btn btn-secondary">Cancel</button>
                                <button type="submit" className="btn btn-primary" disabled={!escTarget}>Confirm Escalation</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </Layout>
    );
}

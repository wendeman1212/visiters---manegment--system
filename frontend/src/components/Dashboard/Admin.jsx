import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { createPortal } from 'react-dom';
import Layout from '../Shared/Layout';
import { useForm, usePage, router, Link } from '@inertiajs/react';
import AppIcon from '../../config/iconMap';
import Messages from './Messages';
import VisitorTransferModal from './VisitorTransferModal';
import { fmtDateTime, fmtDate as fmtDateTz, toLocalDatetimeInput, localToServerDatetime } from '../../utils/timezone';

// ── Helpers ─────────────────────────────────────────
const fmt = (dt) => dt ? fmtDateTime(dt) : '—';
const fmtD = (dt) => dt ? fmtDateTz(dt) : '—';
const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

const menuItem = { display: 'flex', alignItems: 'center', gap: 8, width: '100%', border: 'none', background: 'transparent', color: 'var(--text-primary)', fontSize: 13, fontWeight: 500, padding: '8px 10px', borderRadius: 8, cursor: 'pointer', textAlign: 'left' };
const menuPanel = { position: 'fixed', zIndex: 1000, minWidth: 200, background: 'var(--bg-card)', border: '1px solid var(--glass-border)', borderRadius: 10, boxShadow: '0 12px 32px rgba(15,23,42,0.25)', padding: 6, maxHeight: '70vh', overflowY: 'auto' };

function MenuBtn({ danger, onClick, children }) {
    const base = { ...menuItem, color: danger ? 'var(--status-danger)' : 'var(--text-primary)' };
    return (
        <button onClick={onClick} style={base}
            onMouseEnter={e => { e.currentTarget.style.background = 'rgba(99,102,241,0.08)'; }}
            onMouseLeave={e => { e.currentTarget.style.background = 'transparent'; }}>
            {children}
        </button>
    );
}

function Stat({ label, value, color, icon }) {
    const c = { cyan: 'var(--accent-cyan)', emerald: 'var(--status-success)', warning: 'var(--status-warning)', danger: 'var(--status-danger)', blue: 'var(--status-info)', default: 'var(--text-primary)' };
    return (
        <div className="stat-card">
            <span className="stat-label">{label}</span>
            <span className="stat-value" style={{ color: c[color] || c.default, fontSize: 26 }}>
                {icon && <span style={{ marginRight: 6 }}>{icon}</span>}{value}
            </span>
        </div>
    );
}
function H2({ children }) {
    return <h2 style={{ fontFamily: 'var(--font-heading)', fontSize: 22, fontWeight: 700, marginBottom: 20, background: 'var(--gradient-primary)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}>{children}</h2>;
}
function ST({ children }) {
    return <h3 style={{ fontFamily: 'var(--font-heading)', fontSize: 16, fontWeight: 700, marginBottom: 16 }}>{children}</h3>;
}
function TB({ active, onClick, children, badge }) {
    return (
        <button onClick={onClick} style={{
            width: '100%', textAlign: 'left', border: 'none',
            borderLeft: active ? '3px solid #ffffff' : '3px solid transparent',
            background: active ? 'rgba(255,255,255,0.2)' : 'transparent',
            color: '#ffffff',
            fontWeight: active ? 700 : 500, fontSize: 13, padding: '10px 16px',
            cursor: 'pointer', transition: 'all 0.2s',
            display: 'flex', justifyContent: 'space-between', alignItems: 'center',
            borderRadius: active ? '0 8px 8px 0' : '0',
        }} onMouseEnter={e => { if (!active) { e.currentTarget.style.background = 'rgba(255,255,255,0.12)'; } }} onMouseLeave={e => { if (!active) { e.currentTarget.style.background = 'transparent'; } }}>
            <span>{children}</span>
            {badge > 0 && <span style={{ background: '#f59e0b', color: '#fff', borderRadius: 10, padding: '1px 7px', fontSize: 10, fontWeight: 800 }}>{badge}</span>}
        </button>
    );
}

const TABS = [
    { key: 'overview', label: 'Overview', icon: 'chart' },
    { key: 'appointments', label: 'Appointments', icon: 'calendar' },

    { key: 'company', label: 'Company & Hours', icon: 'building' },
    { key: 'branches', label: 'Branches', icon: 'git-branch' },
    { key: 'departments', label: 'Departments', icon: 'grid' },
    { key: 'users', label: 'User Management', icon: 'users' },
    { key: 'categories', label: 'Visitor Categories', icon: 'folder' },
    { key: 'monitor', label: 'Live Monitor', icon: 'monitor' },
    { key: 'security', label: 'Security', icon: 'shield' },
    { key: 'incidents', label: 'Security Incidents', icon: 'warning' },
    { key: 'export', label: 'Export', icon: 'download' },
    { key: 'reports', label: 'Reports', icon: 'chart' },
    { key: 'audit', label: 'Audit Logs', icon: 'file' },
    { key: 'system', label: 'System & Integrations', icon: 'settings' },
    { key: 'messages', label: 'Messages', icon: 'mail' },
];

export default function AdminDashboard({
    pending_users, all_staff, departments, blacklist, audit_logs,
    visit_stats, hosts, escalations, department_statistics, system_stats,
    company_setting, working_hours, visitor_categories, visitor_categories_with_stats,
    badge_templates, notif_templates, integrations,
    active_incidents, incident_stats, locations, branches, companies, sla_minutes,
    allow_weekends, enforce_biz_hours,
    all_visits, live_visitors,

    my_notifications, unread_count,
    section,
    messages_inbox, messages_sent, messages_recipients, messages_unread_count,
}) {
    const { auth, app_timezone } = usePage().props;
    const tz = app_timezone || 'Africa/Addis_Ababa';
    const myCompanies = (companies || []).filter(c => !auth?.user?.company_id || String(c.company_id) === String(auth.user.company_id));
    const [tab, setTab] = useState(section || 'overview');
    const [sidebarOpen, setSidebarOpen] = useState(true);
    const [escComments, setEscComments] = useState('');
    const [escReschedTime, setEscReschedTime] = useState('');
    const [escReschedDuration, setEscReschedDuration] = useState(60);

    // Live Monitor data is refreshed via a lightweight JSON endpoint instead
    // of a full Inertia reload (which re-executed the entire admin controller).
    const [liveVisitorsState, setLiveVisitorsState] = useState(live_visitors || []);
    const [visitStatsState, setVisitStatsState] = useState(visit_stats || {});
    const [escalationsState, setEscalationsState] = useState(escalations || []);

    // Stay in sync when Inertia props change (e.g. after admin actions reload).
    useEffect(() => { setLiveVisitorsState(live_visitors || []); }, [live_visitors]);
    useEffect(() => { setVisitStatsState(visit_stats || {}); }, [visit_stats]);
    useEffect(() => { setEscalationsState(escalations || []); }, [escalations]);

    // Live Monitor shows only visits that are actually checked in and whose
    // meeting is still in progress. Pending, approved, scheduled and escalated
    // appointments belong in Appointments, never here.
    const liveNow = useMemo(
        () => (liveVisitorsState || []).filter(v => v.status === 'checked_in' && !v.meeting_ended_at),
        [liveVisitorsState],
    );

    // ── Escalation (responsible person) action state ──
    const [escTransferVisit, setEscTransferVisit] = useState(null);
    const [escTransferOpts, setEscTransferOpts] = useState(null);
    const [escTransferSel, setEscTransferSel] = useState({ company: '', branch: '', dept: '', host: '' });
    const [escTransferReason, setEscTransferReason] = useState('');
    const [escTransferLoading, setEscTransferLoading] = useState(false);
    const [escOutcome, setEscOutcome] = useState({});

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
            {e.status !== 'checked_in' && (
                <button onClick={() => router.post(`/escalation/visits/${e.id}/no-show`)} className="btn btn-secondary" style={{ fontSize: 11, padding: '3px 9px' }}><AppIcon name="clock" size={12} /> No-Show</button>
            )}
            <button onClick={() => router.post(`/escalation/visits/${e.id}/complete`)} className="btn btn-primary" style={{ fontSize: 11, padding: '3px 9px' }}><AppIcon name="check" size={12} /> Complete</button>
        </div>
    );
    const [auditFilter, setAuditFilter] = useState('');
    const [auditLocationFilter, setAuditLocationFilter] = useState('');
    const [auditBranchFilter, setAuditBranchFilter] = useState('');
    const [staffFilter, setStaffFilter] = useState('all');
    const [staffBranchFilter, setStaffBranchFilter] = useState('');
    const [staffSearch, setStaffSearch] = useState('');
    const [deptSearch, setDeptSearch] = useState('');
    const [broadcastMsg, setBroadcastMsg] = useState('');
    const [broadcastPri, setBroadcastPri] = useState('info');
    const [showUserForm, setShowUserForm] = useState(false);
    const [editUser, setEditUser] = useState(null);
    const [editBranch, setEditBranch] = useState(null);
    const [editDept, setEditDept] = useState(null);
    const [branchCompanyFilter, setBranchCompanyFilter] = useState('');
    const [expandedCatId, setExpandedCatId] = useState(null);
    const [editCatId, setEditCatId] = useState(null);
    const [expandedIncId, setExpandedIncId] = useState(null);
    const [visitFilter, setVisitFilter] = useState('all');
    const [visitBranchFilter, setVisitBranchFilter] = useState('');
    const [visitCompanyFilter, setVisitCompanyFilter] = useState('');

    // ── Appointment actions: dropdown + modal-driven flows ──
    const [actMenu, setActMenu] = useState(null);          // visit id whose menu is open
    const [actAnchor, setActAnchor] = useState({ top: 0, right: 0, height: 0 });
    const [actModal, setActModal] = useState(null);        // { type, visit }
    const [actReason, setActReason] = useState('');
    const [actComment, setActComment] = useState('');
    const [actEdit, setActEdit] = useState({ requested_time: '', duration: 60 });
    const [actBusy, setActBusy] = useState(false);
    const [actError, setActError] = useState('');

    useEffect(() => {
        if (actMenu === null) return;
        const close = (e) => { if (e.target.closest && e.target.closest('[data-act-menu]')) return; setActMenu(null); };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, [actMenu]);

    // ── Visitor Transfer (Admin — company-level) ──
    const [transferVisit, setTransferVisit] = useState(null);

    // ── Department Capacity: Company → Branch → Department scope ──
    const [capCompany, setCapCompany] = useState(auth?.user?.company_id || '');
    const [capBranch, setCapBranch] = useState('');
    const deptCapacity = useMemo(() => {
        const deptMap = new Map((departments || []).map(d => [String(d.id), d]));
        return (department_statistics || []).map(s => {
            const d = deptMap.get(String(s.id)) || {};
            return { ...s, branch_id: d.branch_id ?? null, company_id: d.company_id ?? null };
        });
    }, [department_statistics, departments]);
    const capDepartments = useMemo(() => {
        if (!capCompany) return [];
        const rows = deptCapacity.filter(r => String(r.company_id) === String(capCompany));
        if (capBranch) return rows.filter(r => String(r.branch_id) === String(capBranch));
        return rows.filter(r => !r.branch_id);
    }, [deptCapacity, capCompany, capBranch]);

    // ── Branch-Based & Time-Framed Reports ──
    const [reportPeriod, setReportPeriod] = useState('daily');
    const [reportBranch, setReportBranch] = useState('');
    const [reportData, setReportData] = useState(null);
    const [reportLoading, setReportLoading] = useState(false);
    const [reportError, setReportError] = useState('');

    const loadReports = useCallback(async (period, branchId) => {
        setReportLoading(true);
        setReportError('');
        try {
            const params = new URLSearchParams({ period });
            if (branchId) params.set('branch_id', branchId);
            const res = await fetch(`/admin/reports/data?${params.toString()}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('Failed to load reports.');
            const data = await res.json();
            setReportData(data);
        } catch (err) {
            setReportError(err.message || 'Failed to load reports.');
        } finally {
            setReportLoading(false);
        }
    }, []);

    useEffect(() => {
        if (tab === 'reports' || tab === 'export') {
            loadReports(reportPeriod, reportBranch);
        }
    }, [tab, reportPeriod, reportBranch, loadReports]);

    const reportExportUrl = (type, format) => {
        const p = new URLSearchParams({ period: reportPeriod, format });
        if (reportBranch) p.set('branch_id', reportBranch);
        return `/admin/reports/export/${type}?${p.toString()}`;
    };

    // ── Branch Security Incidents ──
    const [incidentPeriod, setIncidentPeriod] = useState('daily');
    const [incidentBranch, setIncidentBranch] = useState('');
    const [incidentData, setIncidentData] = useState(null);
    const [incidentLoading, setIncidentLoading] = useState(false);
    const [incidentError, setIncidentError] = useState('');

    const loadIncidents = useCallback(async (period, branchId) => {
        setIncidentLoading(true);
        setIncidentError('');
        try {
            const params = new URLSearchParams({ period });
            if (branchId) params.set('branch_id', branchId);
            const res = await fetch(`/admin/incidents/data?${params.toString()}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('Failed to load incidents.');
            const data = await res.json();
            setIncidentData(data);
        } catch (err) {
            setIncidentError(err.message || 'Failed to load incidents.');
        } finally {
            setIncidentLoading(false);
        }
    }, []);

    useEffect(() => {
        if (tab === 'incidents' || tab === 'export') {
            loadIncidents(incidentPeriod, incidentBranch);
        }
    }, [tab, incidentPeriod, incidentBranch, loadIncidents]);

    const incidentExportUrl = (format) => {
        const p = new URLSearchParams({ period: incidentPeriod, format });
        if (incidentBranch) p.set('branch_id', incidentBranch);
        return `/admin/incidents/export?${p.toString()}`;
    };

    const handleIncidentStatus = (id, status) => {
        router.post(`/admin/incidents/${id}/update`, { status }, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => loadIncidents(incidentPeriod, incidentBranch),
        });
    };

    const userForm = useForm({ name: '', email: '', phone: '', role: 'host', department_id: '', branch_id: '', company_id: auth?.user?.company_id || '', status: 'active', password: '' });

    // Departments available for the user form, scoped to the selected Company + Branch:
    //   • a Branch is selected  → only departments created under that Branch
    //   • No Branch (empty)     → only departments created directly under the Company
    // Branch-level and Company-level departments are never mixed. The currently
    // selected department is always kept (defensive, e.g. pre-existing users).
    const userFormDepartments = useMemo(() => {
        const depts = departments || [];
        const { company_id: cid, branch_id: bid, department_id: cur } = userForm.data;
        let scoped = bid
            ? depts.filter(d => String(d.branch_id) === String(bid))
            : depts.filter(d => !d.branch_id);
        if (cid) scoped = scoped.filter(d => String(d.company_id) === String(cid));
        if (cur && !scoped.some(d => String(d.id) === String(cur))) {
            const current = depts.find(d => String(d.id) === String(cur));
            if (current) scoped = [...scoped, current];
        }
        return scoped;
    }, [departments, userForm.data.company_id, userForm.data.branch_id, userForm.data.department_id]);

    const companyForm = useForm({
        company_name: company_setting?.company_name || 'VMS Enterprise',
        branch_name: company_setting?.branch_name || '',
        address: company_setting?.address || '',
        phone: company_setting?.phone || '',
        email: company_setting?.email || '',
        website: company_setting?.website || '',
        timezone: company_setting?.timezone || 'Africa/Addis_Ababa',
    });

    // Working hours state
    const [hoursState, setHoursState] = useState(() =>
        (working_hours || []).map(h => ({ ...h }))
    );

    const catForm = useForm({ name: '', color: '#6366f1', requires_approval: true, auto_approve: false, description: '' });
    const catEditForm = useForm({ name: '', color: '#6366f1', requires_approval: true, auto_approve: false, description: '' });
    const badgeForm = useForm({ name: '', type: 'visitor', color: '#6366f1', show_photo: false, show_qr: true, is_default: false });
    const notifForm = useForm({ event: 'visit_approved', channel: 'in_app', subject: '', body: '', is_active: true });
    const deptForm = useForm({ name: '', capacity_limit: 50, company_id: auth?.user?.company_id || '', branch_id: '' });
    const branchForm = useForm({ company_id: auth?.user?.company_id || '', name: '', description: '', is_active: true });
    const slaForm = useForm({ sla_minutes: sla_minutes || 5, allow_weekends: allow_weekends ?? false, enforce_biz_hours: enforce_biz_hours ?? true });
    const blForm = useForm({ name: '', id_document_no: '', reason: '' });

    // SMS Gateway integration
    const smsInt = (integrations || []).find(i => i.type === 'sms');
    const smsCfg = (() => {
        try { return smsInt?.config ? (typeof smsInt.config === 'string' ? JSON.parse(smsInt.config) : smsInt.config) : {}; } catch { return {}; }
    })();
    const smsForm = useForm({
        provider: smsCfg.provider || 'webhook',
        account_sid: smsCfg.account_sid || '',
        auth_token: '',
        from_number: smsCfg.from_number || '',
        webhook_url: smsCfg.webhook_url || '',
        api_key: '',
        status: smsInt?.status === 'active',
    });
    const [smsTestPhone, setSmsTestPhone] = useState('');

    const handleEscDecision = (id, decision) => {
        if (decision === 'reschedule') {
            if (!escReschedTime) { alert('Select date/time.'); return; }
            const serverTime = localToServerDatetime(escReschedTime, tz);
            router.post(`/visit-requests/${id}/decision`, { decision: 'reschedule', comments: escComments, rescheduled_time: serverTime, duration: Number(escReschedDuration) || 60 }, { onSuccess: () => { setEscComments(''); setEscReschedTime(''); } });
        } else {
            router.post(`/visit-requests/${id}/decision`, { decision, comments: escComments }, { onSuccess: () => setEscComments('') });
        }
    };

    const saveHours = () => {
        router.post('/admin/working-hours', { hours: hoursState }, { onSuccess: () => { } });
    };

    // ── Appointments: modal-driven actions (Approve / Transfer / Edit / Reject / Delete) ──
    const openActModal = (type, v) => {
        setActMenu(null);
        setActError('');
        setActReason('');
        setActComment('');
        setActEdit({ requested_time: v.requested_time ? toLocalDatetimeInput(v.requested_time) : '', duration: v.duration || 60 });
        setActModal({ type, visit: v });
    };

    const closeActModal = () => {
        if (actBusy) return;
        setActModal(null);
        setActError('');
    };

    const flashErrorOn = (page) => (page && page.props && page.props.flash && page.props.flash.error) || '';

    const submitApprove = (v) => {
        setActBusy(true); setActError('');
        router.post(`/visit-requests/${v.id}/decision`, { decision: 'approve', comments: actComment.trim() }, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = flashErrorOn(page);
                if (err) { setActError(err); return; }
                setActModal(null);
            },
            onFinish: () => setActBusy(false),
        });
    };

    const submitReject = (v) => {
        if (!actReason.trim()) { setActError('A rejection reason is required.'); return; }
        setActBusy(true); setActError('');
        router.post(`/visit-requests/${v.id}/decision`, { decision: 'reject', comments: actReason.trim() }, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = flashErrorOn(page);
                if (err) { setActError(err); return; }
                setActModal(null);
            },
            onFinish: () => setActBusy(false),
        });
    };

    const submitEdit = (v) => {
        if (!actEdit.requested_time) { setActError('Please choose a new date and time.'); return; }
        if (!actEdit.duration || actEdit.duration < 15 || actEdit.duration > 480) { setActError('Duration must be between 15 and 480 minutes.'); return; }
        setActBusy(true); setActError('');
        router.post(`/admin/visit-requests/${v.id}/update-time`, {
            requested_time: localToServerDatetime(actEdit.requested_time, tz),
            duration: Number(actEdit.duration),
        }, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = flashErrorOn(page);
                if (err) { setActError(err); return; }
                setActModal(null);
            },
            onError: (errs) => {
                const first = errs && Object.values(errs).find(Boolean);
                setActError(first ? String(first) : 'Failed to update the appointment.');
            },
            onFinish: () => setActBusy(false),
        });
    };

    const submitEndVisit = (v) => {
        setActBusy(true); setActError('');
        router.post(`/admin/visit-requests/${v.id}/end`, {}, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = flashErrorOn(page);
                if (err) { setActError(err); return; }
                setActModal(null);
            },
            onFinish: () => setActBusy(false),
        });
    };

    // ── Live Monitor: refresh visitor data periodically via lightweight API ──
    const fetchMonitorData = useCallback(() => {
        fetch('/admin/monitor-data', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        })
            .then(r => r.json())
            .then(data => {
                if (!data) return;
                if (data.live_visitors) setLiveVisitorsState(data.live_visitors);
                if (data.visit_stats) setVisitStatsState(data.visit_stats);
                if (data.escalations) setEscalationsState(data.escalations);
            })
            .catch(() => { });
    }, []);

    useEffect(() => {
        if (tab !== 'monitor') return;
        const id = setInterval(fetchMonitorData, 30000);
        return () => clearInterval(id);
    }, [tab, fetchMonitorData]);

    const filteredAudit = audit_logs ? audit_logs.filter(l => {
        if (auditFilter && !l.action?.toLowerCase().includes(auditFilter.toLowerCase())) return false;
        if (auditLocationFilter && String(l.location_id) !== String(auditLocationFilter)) return false;
        if (auditBranchFilter && String(l.branch_id) !== String(auditBranchFilter)) return false;
        return true;
    }) : [];
    const filteredStaff = (all_staff || []).filter(s => {
        if (staffFilter !== 'all' && s.status !== staffFilter) return false;
        if (staffBranchFilter && String(s.branch_id) !== String(staffBranchFilter)) return false;
        if (staffSearch && !s.name?.toLowerCase().includes(staffSearch.toLowerCase()) && !s.email?.toLowerCase().includes(staffSearch.toLowerCase()) && !s.role?.toLowerCase().includes(staffSearch.toLowerCase())) return false;
        return true;
    });

    return (
        <Layout title={section ? `Admin - ${section.replace(/_/g, ' ')}` : "Admin Control Center"}>
            <div className="admin-layout" style={section ? { minHeight: 'calc(100vh - 80px)' } : { display: 'flex', minHeight: 'calc(100vh - 80px)' }}>

                {!section && (
                    <div className="admin-sidebar" style={{ width: sidebarOpen ? 220 : 0, flexShrink: 0, background: '#2563eb', borderRight: sidebarOpen ? '1px solid rgba(255,255,255,0.15)' : 'none', position: 'sticky', top: 68, alignSelf: 'flex-start', maxHeight: 'calc(100vh - 68px)', overflow: sidebarOpen ? 'auto' : 'hidden', borderRadius: '0 0 0 14px', transition: 'width 0.25s ease' }}>
                        {/* Hamburger toggle */}
                        <div style={{ padding: '10px 12px', borderBottom: '1px solid rgba(255,255,255,0.15)', display: 'flex', alignItems: 'center', justifyContent: sidebarOpen ? 'flex-end' : 'center' }}>
                            <button onClick={() => setSidebarOpen(p => !p)} style={{ background: 'rgba(255,255,255,0.2)', border: 'none', borderRadius: 8, width: 34, height: 34, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', color: '#fff', fontSize: 16, fontWeight: 700 }}><AppIcon name="chevron" size={16} /></button>
                        </div>
                        {sidebarOpen && <div style={{ padding: '16px', borderBottom: '1px solid rgba(255,255,255,0.15)' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                <label style={{ width: 38, height: 38, borderRadius: '50%', background: auth.user?.profile_photo ? 'none' : 'rgba(255,255,255,0.2)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 17, color: 'white', flexShrink: 0, overflow: 'hidden', cursor: 'pointer' }} title="Click to change photo">
                                    {auth.user?.profile_photo ? <img src={`/storage/${auth.user.profile_photo}`} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : ''}
                                    <input type="file" accept="image/*" style={{ display: 'none' }} onChange={e => { const f = e.target.files[0]; if (!f) return; const fd = new FormData(); fd.append('profile_photo', f); fd.append('name', auth.user?.name || ''); router.post('/profile/update', fd, { forceFormData: true, preserveScroll: true }); }} />
                                </label>
                                <div>
                                    <div style={{ fontWeight: 700, fontSize: 13, color: '#ffffff' }}>Admin</div>
                                    <div style={{ fontSize: 11, color: 'rgba(255,255,255,0.7)' }}>Operations Control</div>
                                </div>
                            </div>
                            <div style={{ display: 'flex', gap: 6, marginTop: 10, flexWrap: 'wrap' }}>
                                {pending_users?.length > 0 && <span style={{ fontSize: 10, padding: '2px 7px', borderRadius: 10, background: '#fef3c7', color: '#d97706', fontWeight: 700 }}>{pending_users.length} pending</span>}
                            </div>
                        </div>}
                        <ul style={{ listStyle: 'none', padding: '6px 8px', margin: 0, display: 'flex', flexDirection: 'column', gap: 2 }}>
                            {TABS.map(t => (
                                <li key={t.key}>
                                    <TB active={tab === t.key} onClick={() => router.visit(`/admin/section/${t.key}`)}
                                        badge={t.key === 'users' ? pending_users?.length : 0}>
                                        {t.label}
                                    </TB>
                                </li>
                            ))}
                        </ul>
                        <div style={{ borderTop: '1px solid rgba(255,255,255,0.15)', padding: '14px 16px', display: 'flex', flexDirection: 'column', gap: 8 }}>
                            <Link href="/profile" style={{ fontSize: 12, color: 'rgba(255,255,255,0.8)', textDecoration: 'none', padding: '6px 0', display: 'flex', alignItems: 'center', gap: 6 }}><AppIcon name="user" size={14} /> My Profile</Link>
                        </div>
                    </div>
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
                <div className="dashboard-content" style={{ flex: 1, padding: section ? '28px 32px' : '28px 32px', minWidth: 0, overflow: section ? 'visible' : 'hidden' }}>
                    {section && (
                        <div style={{ marginBottom: 24, paddingBottom: 16, borderBottom: '1px solid rgba(99,102,241,0.12)' }}>
                            <Link href="/admin/dashboard" style={{ color: 'var(--accent-primary)', fontSize: 14, fontWeight: 600, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 6 }}>← Back to Admin Dashboard</Link>
                        </div>
                    )}

                    {/* ══ 1. OVERVIEW ══ */}
                    {tab === 'overview' && (
                        <div>
                            <H2><AppIcon name="chart" size={18} /> Administration & Operations</H2>
                            <div className="stats-grid" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(155px,1fr))', marginBottom: 28 }}>
                                <Stat label="Total Visits" value={visitStatsState?.total || 0} color="cyan" />
                                <Stat label="Active On-Site" value={system_stats?.active_sessions || 0} color="emerald" icon={<AppIcon name="user" size={16} />} />
                                <Stat label="Pending" value={visitStatsState?.pending || 0} color="warning" />
                                <Stat label="Approval Rate" value={(visitStatsState?.approval_rate || 0) + '%'} color="emerald" icon={<AppIcon name="check" size={16} />} />
                                <Stat label="Rejection Rate" value={(visitStatsState?.rejection_rate || 0) + '%'} color="danger" icon={<AppIcon name="close" size={16} />} />
                                <Stat label="Staff Pending" value={system_stats?.pending_staff || 0} color="warning" />
                                <Stat label="Blacklisted" value={system_stats?.blacklist_count || 0} color="danger" icon={<AppIcon name="prohibited" size={16} />} />
                            </div>
                            {/* Full Approval Performance Breakdown */}
                            <div className="glass-card" style={{ marginBottom: 24 }}>
                                <ST><AppIcon name="check" size={16} /> Approval Performance Breakdown</ST>
                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(155px,1fr))', gap: 12, marginBottom: 16 }}>
                                    <Stat label="Total Approved" value={visitStatsState?.approved || 0} color="emerald" icon={<AppIcon name="check" size={16} />} />
                                    <Stat label="Total Rejected" value={visitStatsState?.rejected || 0} color="danger" icon={<AppIcon name="close" size={16} />} />
                                    <Stat label="Total Escalated" value={visitStatsState?.escalated || 0} color="warning" icon={<AppIcon name="warning" size={16} />} />
                                    <Stat label="Approval Rate" value={(visitStatsState?.approval_rate || 0) + '%'} color="emerald" />
                                    <Stat label="Rejection Rate" value={(visitStatsState?.rejection_rate || 0) + '%'} color="danger" />
                                </div>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '12px 16px', background: 'rgba(255,255,255,0.02)', borderRadius: 8, border: '1px solid rgba(255,255,255,0.06)' }}>
                                    <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-secondary)', whiteSpace: 'nowrap' }}>Overall:</span>
                                    <div style={{ flex: visitStatsState?.approval_rate || 0, height: 28, background: 'rgba(16,185,129,0.25)', borderRadius: '8px 0 0 8px', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 13, fontWeight: 700, color: '#10b981' }}>
                                        {visitStatsState?.approval_rate || 0}% Approved
                                    </div>
                                    <div style={{ flex: visitStatsState?.rejection_rate || 0, height: 28, background: 'rgba(239,68,68,0.25)', borderRadius: '0 8px 8px 0', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 13, fontWeight: 700, color: '#f87171' }}>
                                        {visitStatsState?.rejection_rate || 0}% Rejected
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                    {tab === 'appointments' && (() => {
                        const filteredVisits = (all_visits || []).filter(v => {
                            if (visitFilter !== 'all' && v.status !== visitFilter) return false;
                            if (visitCompanyFilter && String(v.company_id) !== String(visitCompanyFilter)) return false;
                            if (visitBranchFilter && String(v.branch_id) !== String(visitBranchFilter)) return false;
                            return true;
                        });
                        return (
                            <div>
                                <H2><AppIcon name="calendar" size={18} /> Appointments</H2>
                                <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap', alignItems: 'center' }}>
                                    <select className="form-input" style={{ fontSize: 11, width: 150 }} value={visitCompanyFilter} onChange={e => { setVisitCompanyFilter(e.target.value); setVisitBranchFilter(''); }}>
                                        <option value="">All Companies</option>
                                        {companies?.map(c => <option key={c.company_id} value={c.company_id}>{c.company_name}</option>)}
                                    </select>
                                    <select className="form-input" style={{ fontSize: 11, width: 150 }} value={visitBranchFilter} onChange={e => setVisitBranchFilter(e.target.value)}>
                                        <option value="">All Branches</option>
                                        {branches?.filter(b => !visitCompanyFilter || String(b.company_id) === String(visitCompanyFilter)).map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                    </select>
                                    {['all', 'pending', 'approved', 'escalated'].map(s => (
                                        <button key={s} onClick={() => setVisitFilter(s)} className={`btn ${visitFilter === s ? 'btn-primary' : 'btn-secondary'}`} style={{ fontSize: 12, padding: '6px 14px', textTransform: 'capitalize' }}>
                                            {s} ({s === 'all' ? (all_visits || []).length : (all_visits || []).filter(v => v.status === s).length})
                                        </button>
                                    ))}
                                </div>
                                <div className="glass-card">
                                    <div className="table-container" style={{ marginBottom: 0 }}>
                                        <table className="data-table">
                                            <thead><tr><th>Visitor</th><th>Host</th><th>Company</th><th>Branch</th><th>Purpose</th><th>Status</th><th>Time</th><th>Duration</th><th>Actions</th></tr></thead>
                                            <tbody>
                                                {(filteredVisits || []).map(v => (
                                                    <tr key={v.id}>
                                                        <td>
                                                            <div style={{ fontWeight: 600 }}>{v.visitor_name || '—'}</div>
                                                            {v.assigned_host_id && v.assigned_host_id !== v.host_id && <div style={{ fontSize: 11, color: 'var(--status-info)' }}>Transferred from {v.original_host_name || 'original host'}</div>}
                                                        </td>
                                                        <td>
                                                            <div>{v.host_name || '—'}</div>
                                                            {v.host_department && <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.host_department}</div>}
                                                        </td>
                                                        <td style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.company_name || v.visitor_company_name || '—'}</td>
                                                        <td style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.branch_name || '—'}</td>
                                                        <td>{v.purpose}</td>
                                                        <td><span className={`badge badge-${v.status === 'approved' ? 'approved' : v.status === 'pending' ? 'pending' : v.status === 'checked_in' ? 'approved' : 'rejected'}`} style={{ textTransform: 'capitalize' }}>{v.status.replace(/_/g, ' ')}</span></td>
                                                        <td style={{ fontSize: 12 }}>{v.requested_time ? fmt(v.requested_time) : '—'}</td>
                                                        <td style={{ fontSize: 12 }}>{v.duration} min</td>
                                                        <td style={{ position: 'relative' }} data-act-menu>
                                                            <button onClick={(e) => {
                                                                if (actMenu === v.id) { setActMenu(null); return; }
                                                                const r = e.currentTarget.getBoundingClientRect();
                                                                setActAnchor({ top: r.top, right: window.innerWidth - r.right, height: r.height });
                                                                setActMenu(v.id);
                                                            }} className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 10px' }}>
                                                                Actions <span style={{ marginLeft: 2, fontSize: 9 }}>▾</span>
                                                            </button>
                                                            {actMenu === v.id && createPortal(
                                                                <div data-act-menu style={{ ...menuPanel, top: actAnchor.top + actAnchor.height + 6, right: actAnchor.right }}>
                                                                    {['pending', 'escalated'].includes(v.status) && (
                                                                        <MenuBtn onClick={() => openActModal('approve', v)}><AppIcon name="check" size={13} /> Approve</MenuBtn>
                                                                    )}
                                                                    <MenuBtn onClick={() => { setActMenu(null); setTransferVisit(v); }}><AppIcon name="users" size={13} /> Transfer</MenuBtn>
                                                                    <MenuBtn onClick={() => openActModal('edit', v)}><AppIcon name="edit" size={13} /> Edit</MenuBtn>
                                                                    {['pending', 'escalated'].includes(v.status) && (
                                                                        <MenuBtn danger onClick={() => openActModal('reject', v)}><AppIcon name="close" size={13} /> Reject with Reason</MenuBtn>
                                                                    )}
                                                                    {v.status === 'checked_in' && (
                                                                        <>
                                                                            <div style={{ height: 1, background: 'var(--glass-border)', margin: '4px 6px' }} />
                                                                            <MenuBtn danger onClick={() => openActModal('end', v)}><AppIcon name="exit" size={13} /> End Visit</MenuBtn>
                                                                        </>
                                                                    )}
                                                                </div>,
                                                                document.body
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                                {filteredVisits.length === 0 && <tr><td colSpan={9} style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 20 }}>No appointments found.</td></tr>}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        );
                    })()}
                    {tab === 'company' && (
                        <div>
                            <H2><AppIcon name="building" size={18} /> Company & Hours</H2>
                            <div className="grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24 }}>
                                {/* Company form */}
                                <div className="glass-card">
                                    <ST><AppIcon name="building" size={16} /> Company Details</ST>
                                    <form onSubmit={e => { e.preventDefault(); companyForm.post('/admin/company-settings', { onSuccess: () => { companyForm.setData({ company_name: '', branch_name: '', address: '', phone: '', email: '', website: '', timezone: '' }); } }); }}>
                                        {[['company_name', 'Company Name', 'text', true], ['branch_name', 'Branch Name', 'text', false], ['address', 'Address', 'text', false], ['phone', 'Phone', 'tel', false], ['email', 'Email', 'email', false], ['website', 'Website', 'url', false], ['timezone', 'Timezone', 'text', false]].map(([k, l, t, req]) => (
                                            <div key={k} className="form-group">
                                                <label className="form-label">{l}</label>
                                                <input type={t} className="form-input" value={companyForm.data[k]} onChange={e => companyForm.setData(k, e.target.value)} required={req} />
                                            </div>
                                        ))}
                                        <button type="submit" className="btn btn-primary" style={{ width: '100%' }} disabled={companyForm.processing}><AppIcon name="save" size={14} /> Save Company</button>
                                    </form>
                                </div>
                                {/* Working Hours */}
                                <div className="glass-card">
                                    <ST><AppIcon name="clock" size={16} /> Working Hours</ST>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                        {hoursState.map((h, i) => (
                                            <div key={h.day_of_week} style={{ display: 'flex', gap: 10, alignItems: 'center', padding: '10px 14px', borderRadius: 10, background: h.is_working_day ? 'rgba(99,102,241,0.06)' : 'rgba(255,255,255,0.02)', border: '1px solid rgba(255,255,255,0.06)' }}>
                                                <div style={{ width: 90, fontWeight: 600, fontSize: 13, flexShrink: 0 }}>{DAYS[h.day_of_week]}</div>
                                                <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, flexShrink: 0, cursor: 'pointer' }}>
                                                    <input type="checkbox" checked={!!h.is_working_day} onChange={e => { const ns = [...hoursState]; ns[i] = { ...ns[i], is_working_day: e.target.checked }; setHoursState(ns); }} />
                                                    <span style={{ color: h.is_working_day ? 'var(--status-success)' : 'var(--text-muted)' }}>{h.is_working_day ? 'Open' : 'Closed'}</span>
                                                </label>
                                                {h.is_working_day && (<>
                                                    <input type="time" className="form-input" style={{ width: 100, fontSize: 12, padding: '4px 8px' }} value={h.open_time?.slice(0, 5) || '08:00'} onChange={e => { const ns = [...hoursState]; ns[i] = { ...ns[i], open_time: e.target.value }; setHoursState(ns); }} />
                                                    <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>–</span>
                                                    <input type="time" className="form-input" style={{ width: 100, fontSize: 12, padding: '4px 8px' }} value={h.close_time?.slice(0, 5) || '18:00'} onChange={e => { const ns = [...hoursState]; ns[i] = { ...ns[i], close_time: e.target.value }; setHoursState(ns); }} />
                                                </>)}
                                            </div>
                                        ))}
                                        <button onClick={saveHours} className="btn btn-primary" style={{ marginTop: 8 }}><AppIcon name="save" size={14} /> Save Hours</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                    {tab === 'branches' && (
                        <div>
                            <H2><AppIcon name="git-branch" size={18} /> Branches</H2>
                            <div className="grid-2col-asym" style={{ display: 'grid', gridTemplateColumns: '1fr 1.5fr', gap: 24 }}>
                                <div className="glass-card">
                                    <ST>{editBranch ? 'Edit Branch' : 'Create Branch'}</ST>
                                    <form onSubmit={e => {
                                        e.preventDefault();
                                        if (editBranch) {
                                            branchForm.put(`/admin/branches/inline/${editBranch.id}`, {
                                                onSuccess: () => { setEditBranch(null); branchForm.reset(); }
                                            });
                                        } else {
                                            branchForm.post('/admin/branches/inline', { onSuccess: () => branchForm.reset() });
                                        }
                                    }}>
                                        <div className="form-group"><label className="form-label">Company</label>
                                            <select className="form-input" value={branchForm.data.company_id} onChange={e => branchForm.setData('company_id', e.target.value)} required disabled={!!auth?.user?.company_id}>
                                                <option value="">— Select Company —</option>
                                                {companies?.map(c => <option key={c.company_id} value={c.company_id}>{c.company_name}</option>)}
                                            </select>
                                        </div>
                                        <div className="form-group"><label className="form-label">Branch Name</label><input type="text" className="form-input" value={branchForm.data.name} onChange={e => branchForm.setData('name', e.target.value)} required placeholder="e.g. Downtown Office" /></div>
                                        <div className="form-group"><label className="form-label">Description</label><textarea className="form-input" value={branchForm.data.description} onChange={e => branchForm.setData('description', e.target.value)} placeholder="Optional description" rows={3} /></div>
                                        <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, marginBottom: 16, cursor: 'pointer' }}>
                                            <input type="checkbox" checked={branchForm.data.is_active} onChange={e => branchForm.setData('is_active', e.target.checked)} />
                                            <span style={{ color: 'var(--status-success)' }}>Active</span>
                                        </label>
                                        {Object.keys(branchForm.errors || {}).length > 0 && (
                                            <div style={{ color: 'var(--status-danger)', fontSize: 12, marginBottom: 8 }}>
                                                {Object.entries(branchForm.errors).map(([k, v]) => <div key={k}>• {v}</div>)}
                                            </div>
                                        )}
                                        <div style={{ display: 'flex', gap: 8 }}>
                                            <button type="submit" className="btn btn-primary" style={{ flex: 1 }} disabled={branchForm.processing}>{editBranch ? <><AppIcon name="save" size={14} /> Update</> : <><AppIcon name="check" size={14} /> Create</>}</button>
                                            {editBranch && <button type="button" className="btn btn-secondary" onClick={() => { setEditBranch(null); branchForm.reset(); }}>Cancel</button>}
                                        </div>
                                    </form>
                                </div>
                                <div className="glass-card">
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
                                        <ST><AppIcon name="git-branch" size={16} /> Branches by Company ({branches?.filter(b => !branchCompanyFilter || String(b.company_id) === String(branchCompanyFilter))?.length || 0})</ST>
                                        <select className="form-input" style={{ fontSize: 11, width: 180 }} value={branchCompanyFilter} onChange={e => setBranchCompanyFilter(e.target.value)}>
                                            <option value="">All Companies</option>
                                            {companies?.map(c => <option key={c.company_id} value={c.company_id}>{c.company_name}</option>)}
                                        </select>
                                    </div>
                                    {companies?.filter(c => (branches || []).some(b => b.company_id === c.company_id && (!branchCompanyFilter || String(b.company_id) === String(branchCompanyFilter)))).map(c => {
                                        const compBranches = (branches || []).filter(b => b.company_id === c.company_id && (!branchCompanyFilter || String(b.company_id) === String(branchCompanyFilter)));
                                        if (compBranches.length === 0) return null;
                                        return (
                                            <div key={c.company_id} style={{ marginBottom: 16 }}>
                                                <div style={{ fontSize: 14, fontWeight: 700, color: 'var(--accent-primary)', marginBottom: 8, display: 'flex', alignItems: 'center', gap: 6 }}>
                                                    {c.company_name}
                                                    <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>— {compBranches.length} branch{compBranches.length !== 1 ? 'es' : ''}</span>
                                                </div>
                                                <div className="table-container" style={{ marginBottom: 0 }}>
                                                    <table className="data-table">
                                                        <thead><tr><th>Name</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead>
                                                        <tbody>
                                                            {compBranches.map(b => (
                                                                <tr key={b.id}>
                                                                    <td style={{ fontWeight: 600 }}>{b.name}</td>
                                                                    <td style={{ fontSize: 12, color: 'var(--text-secondary)', maxWidth: 200 }}>{b.description || '—'}</td>
                                                                    <td><span className={`badge ${b.is_active ? 'badge-approved' : 'badge-rejected'}`}>{b.is_active ? 'Active' : 'Inactive'}</span></td>
                                                                    <td>
                                                                        <div style={{ display: 'flex', gap: 4 }}>
                                                                            <button onClick={() => { setEditBranch(b); branchForm.setData({ company_id: b.company_id || auth?.user?.company_id || '', name: b.name, description: b.description || '', is_active: !!b.is_active }); }} className="btn btn-secondary" style={{ fontSize: 10, padding: '2px 8px' }}><AppIcon name="edit" size={10} /> Edit</button>
                                                                            <button onClick={() => { if (confirm(`Toggle status of branch "${b.name}"?`)) router.post(`/admin/branches/${b.id}/status`, {}, { preserveScroll: true }); }} className="btn btn-secondary" style={{ fontSize: 10, padding: '2px 8px' }}>{b.is_active ? <><AppIcon name="close" size={10} /> Deactivate</> : <><AppIcon name="check" size={10} /> Activate</>}</button>
                                                                            <button onClick={() => { if (confirm(`Delete branch "${b.name}"?`)) branchForm.delete(`/admin/branches/inline/${b.id}`); }} className="btn btn-danger" style={{ fontSize: 10, padding: '2px 8px' }}><AppIcon name="trash" size={10} /> Del</button>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        );
                                    })}
                                    {!branchCompanyFilter && branches?.filter(b => !b.company_id).length > 0 && (
                                        <div style={{ marginBottom: 16 }}>
                                            <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 8 }}>Unassigned Branches</div>
                                            <div className="table-container" style={{ marginBottom: 0 }}>
                                                <table className="data-table">
                                                    <thead><tr><th>Name</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead>
                                                    <tbody>
                                                        {branches.filter(b => !b.company_id).map(b => (
                                                            <tr key={b.id}>
                                                                <td style={{ fontWeight: 600 }}>{b.name}</td>
                                                                <td style={{ fontSize: 12, color: 'var(--text-secondary)', maxWidth: 200 }}>{b.description || '—'}</td>
                                                                <td><span className={`badge ${b.is_active ? 'badge-approved' : 'badge-rejected'}`}>{b.is_active ? 'Active' : 'Inactive'}</span></td>
                                                                 <td>
                                                                     <div style={{ display: 'flex', gap: 4 }}>
                                                                         <button onClick={() => { setEditBranch(b); branchForm.setData({ company_id: b.company_id || auth?.user?.company_id || '', name: b.name, description: b.description || '', is_active: !!b.is_active }); }} className="btn btn-secondary" style={{ fontSize: 10, padding: '2px 8px' }}><AppIcon name="edit" size={10} /> Edit</button>
                                                                         <button onClick={() => { if (confirm(`Toggle status of branch "${b.name}"?`)) router.post(`/admin/branches/${b.id}/status`, {}, { preserveScroll: true }); }} className="btn btn-secondary" style={{ fontSize: 10, padding: '2px 8px' }}>{b.is_active ? <><AppIcon name="close" size={10} /> Deactivate</> : <><AppIcon name="check" size={10} /> Activate</>}</button>
                                                                         <button onClick={() => { if (confirm(`Delete branch "${b.name}"?`)) branchForm.delete(`/admin/branches/inline/${b.id}`); }} className="btn btn-danger" style={{ fontSize: 10, padding: '2px 8px' }}><AppIcon name="trash" size={10} /> Del</button>
                                                                     </div>
                                                                 </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    )}
                                    {branches?.filter(b => !branchCompanyFilter || String(b.company_id) === String(branchCompanyFilter)).length === 0 && (
                                        <div style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 20 }}>{branchCompanyFilter ? 'No branches for this company yet.' : 'No branches created yet.'}</div>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}
                    {tab === 'departments' && (
                        <div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
                                <H2><AppIcon name="grid" size={18} /> Departments</H2>
                                <button onClick={() => { setEditDept(null); deptForm.setData('name', ''); deptForm.setData('capacity_limit', 50); deptForm.setData('company_id', auth?.user?.company_id || ''); deptForm.setData('branch_id', ''); deptForm.clearErrors(); }} className="btn btn-primary" style={{ fontSize: 12 }}>
                                    {editDept ? 'Close' : '+ New Department'}
                                </button>
                            </div>
                            <div className="grid-2col-asym" style={{ display: 'grid', gridTemplateColumns: '1fr 1.5fr', gap: 24 }}>
                                <div className="glass-card">
                                    <ST><AppIcon name="grid" size={16} /> {editDept ? 'Edit Department' : 'Create Department'}</ST>
                                    <form onSubmit={e => {
                                        e.preventDefault();
                                        if (editDept) {
                                            deptForm.put(`/admin/departments/${editDept.id}`, {
                                                onSuccess: () => { setEditDept(null); deptForm.setData('name', ''); deptForm.setData('capacity_limit', 50); deptForm.setData('company_id', auth?.user?.company_id || ''); deptForm.setData('branch_id', ''); deptForm.clearErrors(); }
                                            });
                                        } else {
                                            deptForm.post('/admin/departments', { onSuccess: () => deptForm.reset() });
                                        }
                                    }}>
                                        <div className="form-group"><label className="form-label">Name</label><input type="text" className="form-input" value={deptForm.data.name} onChange={e => deptForm.setData('name', e.target.value)} required /></div>
                                        <div className="form-group"><label className="form-label">Capacity</label><input type="number" className="form-input" value={deptForm.data.capacity_limit} onChange={e => deptForm.setData('capacity_limit', e.target.value)} min={1} required /></div>
                                        <div className="form-group"><label className="form-label">Company</label>
                                            <select className="form-input" value={deptForm.data.company_id} onChange={e => deptForm.setData('company_id', e.target.value)} required disabled={!!auth?.user?.company_id}>
                                                <option value="">— Select Company —</option>
                                                {myCompanies.map(c => <option key={c.company_id} value={c.company_id}>{c.company_name}</option>)}
                                            </select>
                                        </div>
                                        <div className="form-group"><label className="form-label">Branch</label>
                                            <select className="form-input" value={deptForm.data.branch_id} onChange={e => deptForm.setData('branch_id', e.target.value)}>
                                                <option value="">— None —</option>
                                                {branches?.filter(b => b.is_active && (!deptForm.data.company_id || String(b.company_id) === String(deptForm.data.company_id))).map(b => <option key={b.id} value={b.id}>{b.name}{b.location?.name ? ` (${b.location.name})` : ''}</option>)}
                                            </select>
                                        </div>
                                        {Object.keys(deptForm.errors || {}).length > 0 && (
                                            <div style={{ color: 'var(--status-danger)', fontSize: 12, marginBottom: 8 }}>
                                                {Object.entries(deptForm.errors).map(([k, v]) => <div key={k}>• {v}</div>)}
                                            </div>
                                        )}
                                        <div style={{ display: 'flex', gap: 8 }}>
                                            <button type="submit" className="btn btn-primary" style={{ flex: 1 }} disabled={deptForm.processing}>
                                                {editDept ? <><AppIcon name="save" size={14} /> Update</> : <><AppIcon name="check" size={14} /> Create</>}
                                            </button>
                                            {editDept && <button type="button" className="btn btn-secondary" style={{ flex: 1 }} onClick={() => { setEditDept(null); deptForm.setData('name', ''); deptForm.setData('capacity_limit', 50); deptForm.setData('company_id', auth?.user?.company_id || ''); deptForm.setData('branch_id', ''); deptForm.clearErrors(); }}>Cancel</button>}
                                        </div>
                                    </form>
                                </div>
                                <div className="glass-card">
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
                                        <ST><AppIcon name="grid" size={16} /> Departments by Company</ST>
                                        <input type="text" className="form-input" placeholder="Search departments..." value={deptSearch} onChange={e => setDeptSearch(e.target.value)} style={{ fontSize: 11, padding: '4px 10px', width: 180 }} />
                                    </div>
                                    {myCompanies?.filter(c => (departments || []).some(d => d.company_id === c.company_id)).map(c => {
                                        const compDepts = (departments || []).filter(d => d.company_id === c.company_id && (!deptSearch || d.name?.toLowerCase().includes(deptSearch.toLowerCase()) || d.manager?.name?.toLowerCase().includes(deptSearch.toLowerCase())));
                                        if (compDepts.length === 0) return null;
                                        const branchGroups = [...new Set(compDepts.map(d => d.branch_id).filter(Boolean))].map(bid => ({
                                            branch: (branches || []).find(b => String(b.id) === String(bid)),
                                            depts: compDepts.filter(d => String(d.branch_id) === String(bid)),
                                        }));
                                        const unassignedDepts = compDepts.filter(d => !d.branch_id);
                                        return (
                                            <div key={c.company_id} style={{ marginBottom: 20 }}>
                                                <div style={{ fontSize: 14, fontWeight: 700, color: 'var(--accent-primary)', marginBottom: 8, display: 'flex', alignItems: 'center', gap: 6 }}>
                                                    {c.company_name}
                                                    <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>— {compDepts.length} dept{compDepts.length !== 1 ? 's' : ''}</span>
                                                </div>
                                                {branchGroups.map(({ branch, depts }) => (
                                                    <div key={String(branch?.id) || 'branch'} style={{ marginBottom: 12 }}>
                                                        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-secondary)', marginBottom: 6 }}>
                                                            {branch?.name || 'Branch'}{branch?.location?.name ? ` (${branch.location.name})` : ''}
                                                            <span style={{ fontSize: 10, color: 'var(--text-muted)' }}> — {depts.length} dept{depts.length !== 1 ? 's' : ''}</span>
                                                        </div>
                                                        <div className="table-container" style={{ marginBottom: 0 }}>
                                                            <table className="data-table">
                                                                <thead><tr><th>Name</th><th>Manager</th><th>Capacity</th><th>Active</th><th>Actions</th></tr></thead>
                                                                <tbody>
                                                                    {depts.map(d => {
                                                                        const s = department_statistics?.find(x => x.id === d.id);
                                                                        return (<tr key={d.id}><td style={{ fontWeight: 600 }}>{d.name}</td><td style={{ fontSize: 12 }}>{d.manager?.name || '—'}</td><td>{d.capacity_limit}</td><td><span className={`badge ${(s?.active_visitors || 0) >= d.capacity_limit ? 'badge-rejected' : 'badge-approved'}`}>{s?.active_visitors || 0}</span></td><td><div style={{ display: 'flex', gap: 4 }}><button onClick={() => { setEditDept(d); deptForm.setData('name', d.name); deptForm.setData('capacity_limit', d.capacity_limit); deptForm.setData('company_id', d.company_id || auth?.user?.company_id || ''); deptForm.setData('branch_id', d.branch_id || ''); }} className="btn btn-secondary" style={{ fontSize: 10, padding: '2px 8px' }}><AppIcon name="edit" size={10} /> Edit</button><button onClick={() => { if (confirm(`Delete department "${d.name}"? This cannot be undone.`)) router.delete(`/admin/departments/${d.id}`); }} className="btn btn-danger" style={{ fontSize: 10, padding: '2px 8px' }}><AppIcon name="trash" size={10} /> Del</button></div></td></tr>);
                                                                    })}
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                ))}
                                                {unassignedDepts.length > 0 && (
                                                    <div style={{ marginBottom: 12 }}>
                                                        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6 }}>
                                                            No Branch — {unassignedDepts.length} dept{unassignedDepts.length !== 1 ? 's' : ''}
                                                        </div>
                                                        <div className="table-container" style={{ marginBottom: 0 }}>
                                                            <table className="data-table">
                                                                <thead><tr><th>Name</th><th>Manager</th><th>Capacity</th><th>Active</th><th>Actions</th></tr></thead>
                                                                <tbody>
                                                                    {unassignedDepts.map(d => {
                                                                        const s = department_statistics?.find(x => x.id === d.id);
                                                                        return (<tr key={d.id}><td style={{ fontWeight: 600 }}>{d.name}</td><td style={{ fontSize: 12 }}>{d.manager?.name || '—'}</td><td>{d.capacity_limit}</td><td><span className={`badge ${(s?.active_visitors || 0) >= d.capacity_limit ? 'badge-rejected' : 'badge-approved'}`}>{s?.active_visitors || 0}</span></td><td><div style={{ display: 'flex', gap: 4 }}><button onClick={() => { setEditDept(d); deptForm.setData('name', d.name); deptForm.setData('capacity_limit', d.capacity_limit); deptForm.setData('company_id', d.company_id || auth?.user?.company_id || ''); deptForm.setData('branch_id', d.branch_id || ''); }} className="btn btn-secondary" style={{ fontSize: 10, padding: '2px 8px' }}><AppIcon name="edit" size={10} /> Edit</button><button onClick={() => { if (confirm(`Delete department "${d.name}"? This cannot be undone.`)) router.delete(`/admin/departments/${d.id}`); }} className="btn btn-danger" style={{ fontSize: 10, padding: '2px 8px' }}><AppIcon name="trash" size={10} /> Del</button></div></td></tr>);
                                                                    })}
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        )
                                    })}
                                    {departments?.filter(d => !d.company_id && (!deptSearch || d.name?.toLowerCase().includes(deptSearch.toLowerCase()) || d.manager?.name?.toLowerCase().includes(deptSearch.toLowerCase()))).length > 0 && (
                                        <div style={{ marginBottom: 20 }}>
                                            <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 8 }}>Unassigned Departments</div>
                                            <div className="table-container" style={{ marginBottom: 0 }}>
                                                <table className="data-table">
                                                    <thead><tr><th>Name</th><th>Manager</th><th>Capacity</th><th>Active</th><th>Actions</th></tr></thead>
                                                    <tbody>
                                                        {departments?.filter(d => !d.company_id && (!deptSearch || d.name?.toLowerCase().includes(deptSearch.toLowerCase()) || d.manager?.name?.toLowerCase().includes(deptSearch.toLowerCase()))).map(d => {
                                                            const s = department_statistics?.find(x => x.id === d.id);
                                                            return (<tr key={d.id}><td style={{ fontWeight: 600 }}>{d.name}</td><td style={{ fontSize: 12 }}>{d.manager?.name || '—'}</td><td>{d.capacity_limit}</td><td><span className={`badge ${(s?.active_visitors || 0) >= d.capacity_limit ? 'badge-rejected' : 'badge-approved'}`}>{s?.active_visitors || 0}</span></td><td><div style={{ display: 'flex', gap: 4 }}><button onClick={() => { setEditDept(d); deptForm.setData('name', d.name); deptForm.setData('capacity_limit', d.capacity_limit); deptForm.setData('company_id', d.company_id || auth?.user?.company_id || ''); deptForm.setData('branch_id', d.branch_id || ''); }} className="btn btn-secondary" style={{ fontSize: 10, padding: '2px 8px' }}><AppIcon name="edit" size={10} /> Edit</button><button onClick={() => { if (confirm(`Delete department "${d.name}"? This cannot be undone.`)) router.delete(`/admin/departments/${d.id}`); }} className="btn btn-danger" style={{ fontSize: 10, padding: '2px 8px' }}><AppIcon name="trash" size={10} /> Del</button></div></td></tr>);
                                                        })}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    )}
                                    {(!departments || departments.length === 0) && <div style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 20 }}>No departments created yet.</div>}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ══ 3. USER MANAGEMENT ══ */}
                    {tab === 'users' && (
                        <div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
                                <H2><AppIcon name="users" size={18} /> User Management</H2>
                                <button onClick={() => setShowUserForm(p => !p)} className="btn btn-primary" style={{ fontSize: 12 }}>
                                    {showUserForm ? 'Close' : '+ New User'}
                                </button>
                            </div>
                            {showUserForm && (
                                <div className="glass-card" style={{ marginBottom: 24, borderColor: editUser ? 'rgba(99,102,241,0.3)' : 'rgba(34,197,94,0.2)' }}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                        <ST>{editUser ? <><AppIcon name="edit" size={16} /> Edit User</> : <><AppIcon name="users" size={16} /> Create New User</>}</ST>
                                        {editUser && <button className="btn btn-secondary" style={{ fontSize: 12 }} onClick={() => { setEditUser(null); userForm.reset(); setShowUserForm(false); }}><AppIcon name="close" size={14} /> Cancel</button>}
                                    </div>
                                    <form onSubmit={e => {
                                        e.preventDefault();
                                        if (editUser) {
                                            userForm.put(`/admin/users/${editUser.id}`, {
                                                onSuccess: () => { setEditUser(null); userForm.reset(); setShowUserForm(false); }
                                            });
                                        } else {
                                            userForm.post('/admin/users', {
                                                onSuccess: () => { userForm.reset(); setShowUserForm(false); router.reload({ only: ['all_staff', 'pending_users'] }); }
                                            });
                                        }
                                    }}>
                                        <div className="grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                                            <div className="form-group"><label className="form-label">Full Name</label><input type="text" className="form-input" value={userForm.data.name} onChange={e => userForm.setData('name', e.target.value)} required /></div>
                                            <div className="form-group"><label className="form-label">Email</label><input type="email" className="form-input" value={userForm.data.email} onChange={e => userForm.setData('email', e.target.value)} required /></div>
                                            <div className="form-group"><label className="form-label">Phone *</label><input type="tel" className="form-input" value={userForm.data.phone} onChange={e => userForm.setData('phone', e.target.value)} required /></div>
                                            <div className="form-group"><label className="form-label">Role</label>
                                                <select className="form-select" value={userForm.data.role} onChange={e => userForm.setData('role', e.target.value)} required>
                                                    <option value="host">Host</option>
                                                    <option value="manager">Manager</option>
                                                    <option value="security_guard">Security Guard</option>
                                                    <option value="admin">Admin</option>
                                                </select>
                                            </div>
                                            <div className="form-group"><label className="form-label">Department</label>
                                                <select className="form-select" value={userForm.data.department_id} onChange={e => userForm.setData('department_id', e.target.value)}>
                                                    <option value="">— None —</option>
                                                    {userFormDepartments.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                                                    {userFormDepartments.length === 0 && <option value="" disabled>No departments in this scope</option>}
                                                </select>
                                                {userForm.data.branch_id
                                                    ? <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 4 }}>Showing departments under the selected branch only.</div>
                                                    : <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 4 }}>Showing company-level departments (no branch).</div>}
                                            </div>
                                            <div className="form-group"><label className="form-label">Branch</label>
                                                <select className="form-select" value={userForm.data.branch_id} onChange={e => { userForm.setData('branch_id', e.target.value); userForm.setData('department_id', ''); }}>
                                                    <option value="">— None —</option>
                                                    {branches?.filter(b => !userForm.data.company_id || String(b.company_id) === String(userForm.data.company_id)).map(b => <option key={b.id} value={b.id}>{b.name}{b.location ? ` (${b.location.name})` : ''}</option>)}
                                                </select>
                                            </div>
                                            <div className="form-group"><label className="form-label">Company</label>
                                                <select className="form-select" value={userForm.data.company_id} onChange={e => { userForm.setData('company_id', e.target.value); userForm.setData('branch_id', ''); userForm.setData('department_id', ''); }} required disabled={!!auth?.user?.company_id}>
                                                    <option value="">— Select Company —</option>
                                                    {myCompanies.map(c => <option key={c.company_id} value={c.company_id}>{c.company_name}</option>)}
                                                </select>
                                            </div>
                                            <div className="form-group"><label className="form-label">Status</label>
                                                <select className="form-select" value={userForm.data.status} onChange={e => userForm.setData('status', e.target.value)} required>
                                                    <option value="active">Active</option>
                                                    <option value="pending">Pending</option>
                                                    {editUser && <option value="suspended">Suspended</option>}
                                                </select>
                                            </div>
                                            <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                                                <label className="form-label">{editUser ? 'New Password (leave blank to keep)' : 'Password'}</label>
                                                <input type="password" className="form-input" value={userForm.data.password} onChange={e => userForm.setData('password', e.target.value)} {...(!editUser && { required: true })} />
                                            </div>
                                        </div>
                                        {Object.keys(userForm.errors || {}).length > 0 && (
                                            <div style={{ color: 'var(--status-danger)', fontSize: 12, marginBottom: 8 }}>
                                                {Object.entries(userForm.errors).map(([k, v]) => <div key={k}>• {v}</div>)}
                                            </div>
                                        )}
                                        <button type="submit" className="btn btn-primary" style={{ width: '100%' }} disabled={userForm.processing}>
                                            {editUser ? <><AppIcon name="save" size={14} /> Update User</> : <><AppIcon name="check" size={14} /> Create User</>}
                                        </button>
                                    </form>
                                </div>
                            )}

                            {/* Pending approvals */}
                            {pending_users?.length > 0 && (
                                <div className="glass-card" style={{ marginBottom: 24, borderColor: 'rgba(245,158,11,0.3)' }}>
                                    <ST><AppIcon name="clock" size={16} /> Pending Approvals ({pending_users.length})</ST>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                                        {pending_users.map(s => (
                                            <div key={s.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '12px 16px', background: 'rgba(245,158,11,0.05)', borderRadius: 10, border: '1px solid rgba(245,158,11,0.15)', flexWrap: 'wrap', gap: 12 }}>
                                                <div>
                                                    <div style={{ fontWeight: 700 }}>{s.name} <span className="badge badge-pending" style={{ marginLeft: 6 }}>{s.role}</span></div>
                                                    <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginTop: 2 }}>{s.email} · {s.department?.name || 'No dept'}</div>
                                                </div>
                                                <div style={{ display: 'flex', gap: 8 }}>
                                                    <button onClick={() => router.post(`/admin/users/${s.id}/approve`, { action: 'approve' })} className="btn btn-primary" style={{ fontSize: 12 }}><AppIcon name="check" size={14} /> Approve</button>
                                                    <button onClick={() => router.post(`/admin/users/${s.id}/approve`, { action: 'reject' })} className="btn btn-danger" style={{ fontSize: 12 }}><AppIcon name="close" size={14} /> Decline</button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Filter + full table */}
                            <div className="glass-card">
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
                                    <ST><AppIcon name="users" size={16} /> Staff Directory</ST>
                                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                                        <select className="form-select" style={{ fontSize: 11, width: 130 }} value={staffBranchFilter} onChange={e => setStaffBranchFilter(e.target.value)}>
                                            <option value="">All Branches</option>
                                            {branches?.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                        </select>
                                        <input type="text" className="form-input" placeholder="Search..." value={staffSearch} onChange={e => setStaffSearch(e.target.value)} style={{ fontSize: 11, padding: '4px 10px', width: 160 }} />
                                        {['all', 'active', 'pending', 'suspended'].map(k => (
                                            <button key={k} onClick={() => setStaffFilter(k)} style={{
                                                padding: '4px 12px', borderRadius: 20, fontSize: 11, border: '1px solid', cursor: 'pointer',
                                                background: staffFilter === k ? 'var(--gradient-primary)' : 'transparent',
                                                color: staffFilter === k ? 'white' : 'var(--text-secondary)',
                                                borderColor: staffFilter === k ? 'transparent' : 'rgba(255,255,255,0.1)',
                                            }}>{k.charAt(0).toUpperCase() + k.slice(1)} ({(all_staff || []).filter(s => k === 'all' || s.status === k).length})</button>
                                        ))}
                                    </div>
                                </div>
                                <div className="table-container" style={{ marginBottom: 0 }}>
                                    <table className="data-table">
                                        <thead><tr><th>Name</th><th>Role</th><th>Email</th><th>Branch</th><th>Department</th><th>Status</th><th>Actions</th></tr></thead>
                                        <tbody>
                                            {filteredStaff.map(s => (
                                                <tr key={s.id}>
                                                    <td style={{ fontWeight: 600 }}>{s.name}</td>
                                                    <td><span className="badge badge-pending">{s.role}</span></td>
                                                    <td style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{s.email}</td>
                                                    <td style={{ fontSize: 12 }}>{s.branch?.name || branches?.find(b => b.id == s.branch_id)?.name || '—'}</td>
                                                    <td>{s.department?.name || '—'}</td>
                                                    <td><span className={`badge badge-${s.status === 'active' ? 'approved' : s.status === 'pending' ? 'pending' : 'rejected'}`}>{s.status}</span></td>
                                                    <td>
                                                        <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                                                            {s.status === 'pending' && <>
                                                                <button onClick={() => router.post(`/admin/users/${s.id}/approve`, { action: 'approve' })} className="btn btn-primary" style={{ fontSize: 10, padding: '2px 8px' }}><AppIcon name="check" size={10} /> Approve</button>
                                                                <button onClick={() => router.post(`/admin/users/${s.id}/approve`, { action: 'reject' })} className="btn btn-danger" style={{ fontSize: 10, padding: '2px 8px' }}><AppIcon name="close" size={10} /> Reject</button>
                                                            </>}
                                                            <button onClick={() => { setEditUser(s); setShowUserForm(true); userForm.setData({ name: s.name, email: s.email, phone: s.phone || '', role: s.role, department_id: s.department_id || '', branch_id: s.branch_id || '', company_id: s.company_id || auth?.user?.company_id || '', status: s.status, password: '' }); }} className="btn btn-secondary" style={{ fontSize: 10, padding: '2px 8px' }}><AppIcon name="edit" size={10} /> Edit</button>
                                                            {['ceo', 'admin'].includes(auth.user?.role) && <button onClick={() => { if (confirm(`Delete ${s.name}? This cannot be undone.`)) router.delete(`/admin/users/${s.id}`); }} className="btn btn-danger" style={{ fontSize: 10, padding: '2px 8px' }}><AppIcon name="trash" size={10} /> Del</button>}
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
                    {tab === 'categories' && (
                        <div>
                            <H2><AppIcon name="folder" size={18} /> Visitor Categories</H2>
                            <div className="grid-2col-asym" style={{ display: 'grid', gridTemplateColumns: '1fr 1.5fr', gap: 24 }}>
                                <div className="glass-card">
                                    <ST><AppIcon name="folder" size={16} /> Create Category</ST>
                                    <form onSubmit={e => { e.preventDefault(); catForm.post('/admin/visitor-categories', { onSuccess: () => catForm.reset() }); }}>
                                        <div className="form-group"><label className="form-label">Category Name</label><input type="text" className="form-input" value={catForm.data.name} onChange={e => catForm.setData('name', e.target.value)} required /></div>
                                        <div className="form-group"><label className="form-label">Color</label><input type="color" className="form-input" style={{ height: 40 }} value={catForm.data.color} onChange={e => catForm.setData('color', e.target.value)} /></div>
                                        <div className="form-group"><label className="form-label">Description</label><textarea className="form-textarea" style={{ minHeight: 60 }} value={catForm.data.description} onChange={e => catForm.setData('description', e.target.value)} /></div>
                                        <div style={{ display: 'flex', gap: 16, marginBottom: 12 }}>
                                            <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, cursor: 'pointer' }}>
                                                <input type="checkbox" checked={catForm.data.requires_approval} onChange={e => catForm.setData('requires_approval', e.target.checked)} />
                                                Requires approval
                                            </label>
                                            <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, cursor: 'pointer' }}>
                                                <input type="checkbox" checked={catForm.data.auto_approve} onChange={e => catForm.setData('auto_approve', e.target.checked)} />
                                                Auto-approve
                                            </label>
                                        </div>
                                        <button type="submit" className="btn btn-primary" style={{ width: '100%' }} disabled={catForm.processing}>+ Create Category</button>
                                    </form>
                                </div>
                                <div className="glass-card">
                                    <ST><AppIcon name="folder" size={16} /> Category List</ST>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                        {visitor_categories_with_stats?.map(c => {
                                            const isExpanded = expandedCatId === c.id;
                                            return (
                                                <div key={c.id} style={{ padding: '14px 16px', borderRadius: 10, background: 'rgba(255,255,255,0.02)', border: `1px solid ${c.color}30`, cursor: 'pointer', transition: 'all 0.2s' }}
                                                    onClick={() => setExpandedCatId(isExpanded ? null : c.id)}>
                                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                                        <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                                                            <div style={{ width: 14, height: 14, borderRadius: '50%', background: c.color, flexShrink: 0 }} />
                                                            <div>
                                                                <div style={{ fontWeight: 700, fontSize: 14 }}>{c.name}</div>
                                                                <div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>
                                                                    {c.requires_approval ? 'Approval required' : 'No approval'} · {c.auto_approve ? 'Auto' : 'Manual'}
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                                                            <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 10, background: 'rgba(99,102,241,0.15)', color: 'var(--accent-primary)', fontWeight: 700 }}>
                                                                {c.total_visits} total
                                                            </span>
                                                            <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 10, background: 'rgba(16,185,129,0.15)', color: '#10b981', fontWeight: 700 }}>
                                                                {c.today_visits} today
                                                            </span>
                                                            {c.active_visits > 0 && <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 10, background: 'rgba(6,182,212,0.15)', color: 'var(--accent-cyan)', fontWeight: 700 }}>{c.active_visits} active</span>}
                                                            <span style={{ fontSize: 14, color: 'var(--text-muted)', marginLeft: 4, transition: 'transform 0.2s', transform: isExpanded ? 'rotate(180deg)' : 'rotate(0deg)' }}>▾</span>
                                                        </div>
                                                    </div>

                                                    {isExpanded && (
                                                        <div style={{ borderTop: '1px solid rgba(255,255,255,0.06)', paddingTop: 10, marginTop: 10 }} onClick={e => e.stopPropagation()}>
                                                            {editCatId === c.id ? (
                                                                <form onSubmit={e => { e.preventDefault(); catEditForm.put(`/admin/visitor-categories/${c.id}`, { onSuccess: () => { setEditCatId(null); catEditForm.reset(); } }); }}>
                                                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                                                        <input type="text" className="form-input" value={catEditForm.data.name} onChange={e => catEditForm.setData('name', e.target.value)} required style={{ fontSize: 12 }} />
                                                                        <div style={{ display: 'flex', gap: 8 }}>
                                                                            <input type="color" className="form-input" style={{ height: 32, width: 50 }} value={catEditForm.data.color} onChange={e => catEditForm.setData('color', e.target.value)} />
                                                                        </div>
                                                                        <div style={{ display: 'flex', gap: 12 }}>
                                                                            <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, cursor: 'pointer' }}>
                                                                                <input type="checkbox" checked={catEditForm.data.requires_approval} onChange={e => catEditForm.setData('requires_approval', e.target.checked)} /> Requires approval
                                                                            </label>
                                                                            <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, cursor: 'pointer' }}>
                                                                                <input type="checkbox" checked={catEditForm.data.auto_approve} onChange={e => catEditForm.setData('auto_approve', e.target.checked)} /> Auto-approve
                                                                            </label>
                                                                        </div>
                                                                        <div style={{ display: 'flex', gap: 8 }}>
                                                                            <button type="submit" className="btn btn-primary" style={{ fontSize: 11, padding: '4px 12px' }} disabled={catEditForm.processing}>Save</button>
                                                                            <button type="button" className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 12px' }} onClick={() => { setEditCatId(null); catEditForm.reset(); }}>Cancel</button>
                                                                        </div>
                                                                    </div>
                                                                </form>
                                                            ) : (
                                                                <>
                                                                    {c.recent_visits?.length > 0 ? (
                                                                        <div>
                                                                            <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 8, fontWeight: 700 }}>Visitors in "{c.name}" category:</div>
                                                                            <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                                                                                {c.recent_visits.map(v => (
                                                                                    <div key={v.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 12px', borderRadius: 8, background: 'rgba(255,255,255,0.03)', border: '1px solid rgba(255,255,255,0.05)' }}>
                                                                                        <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                                                                                            <div style={{ width: 28, height: 28, borderRadius: '50%', background: 'var(--gradient-primary)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, color: 'white', fontWeight: 700, flexShrink: 0 }}>
                                                                                                {(v.visitor_name || 'V').charAt(0).toUpperCase()}
                                                                                            </div>
                                                                                            <div>
                                                                                                <div style={{ fontWeight: 600, fontSize: 13 }}>{v.visitor_name}</div>
                                                                                                <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>→ {v.host_name} · {v.purpose}</div>
                                                                                            </div>
                                                                                        </div>
                                                                                        <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                                                                                            <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 6, background: v.status === 'checked_in' ? 'rgba(16,185,129,0.15)' : v.status === 'checked_out' ? 'rgba(107,114,128,0.15)' : 'rgba(99,102,241,0.1)', color: v.status === 'checked_in' ? '#10b981' : v.status === 'checked_out' ? 'var(--text-muted)' : 'var(--accent-primary)', fontWeight: 600 }}>
                                                                                                {v.status.replace(/_/g, ' ')}
                                                                                            </span>
                                                                                            <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.requested_time}</span>
                                                                                        </div>
                                                                                    </div>
                                                                                ))}
                                                                            </div>
                                                                        </div>
                                                                    ) : (
                                                                        <div style={{ fontSize: 12, color: 'var(--text-muted)', padding: '8px 0' }}>No visitors in this category yet.</div>
                                                                    )}
                                                                    <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
                                                                        <button onClick={() => { setEditCatId(c.id); catEditForm.setData({ name: c.name, color: c.color || '#6366f1', requires_approval: c.requires_approval, auto_approve: c.auto_approve, description: c.description || '' }); }} className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 12px' }}><AppIcon name="edit" size={12} /> Edit</button>
                                                                        <button onClick={() => { if (confirm('Delete category?')) router.delete(`/admin/visitor-categories/${c.id}`); }} className="btn btn-danger" style={{ fontSize: 11, padding: '4px 12px' }}><AppIcon name="trash" size={12} /> Delete</button>
                                                                    </div>
                                                                </>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                    {!visitor_categories_with_stats?.length && <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No categories yet.</p>}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ══ 5. LIVE MONITOR ══ */}
                    {tab === 'monitor' && (
                        <div>
                            <H2><AppIcon name="monitor" size={18} /> Live Monitor</H2>
                            <div className="stats-grid" style={{ marginBottom: 24 }}>
                                <Stat label="On Premises" value={visitStatsState?.checked_in || 0} color="emerald" icon={<AppIcon name="user" size={16} />} />
                                <Stat label="Pending" value={visitStatsState?.pending || 0} color="warning" />
                                <Stat label="Today Total" value={visitStatsState?.today_total || 0} color="cyan" />
                            </div>

                            {/* Currently Inside the Building (Live) */}
                            <div className="glass-card" style={{ marginBottom: 24 }}>
                                <ST><AppIcon name="user" size={16} /> Currently Inside the Building ({liveNow.length})</ST>
                                {!liveNow.length ? <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No visitors are currently inside the building.</p> : (
                                    <div className="table-container" style={{ marginBottom: 0, maxHeight: 420, overflowY: 'auto' }}>
                                        <table className="data-table">
                                            <thead><tr><th>Visitor</th><th>Host</th><th>Company</th><th>Branch</th><th>Purpose</th><th>Meeting Status</th><th>Check-in</th><th>Appointment</th><th>Location</th></tr></thead>
                                            <tbody>
                                                {liveNow.map(v => (
                                                    <tr key={v.id}>
                                                        <td>
                                                            <div style={{ fontWeight: 600 }}>{v.visitor_name || '—'}</div>
                                                            <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.visitor_company_name || (v.visitor_email || '')}</div>
                                                        </td>
                                                        <td>
                                                            <div>{v.host_name || '—'}</div>
                                                            <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{[v.host_department, v.host_company, v.host_branch].filter(Boolean).join(' · ') || ''}</div>
                                                            {v.assigned_host_id && v.assigned_host_id !== v.host_id && <div style={{ fontSize: 11, color: 'var(--status-info)' }}>Transferred from {v.original_host_name || 'original host'}</div>}
                                                        </td>
                                                        <td style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.company_name || v.visitor_company_name || '—'}</td>
                                                        <td style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.branch_name || '—'}</td>
                                                        <td>{v.purpose}</td>
                                                        <td><span className="badge badge-approved" style={{ textTransform: 'capitalize' }}>{v.meeting_started_at ? 'In Progress' : 'Checked In'}</span>
                                                            {v.meeting_started_at && <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 4 }}>Started {fmt(v.meeting_started_at)}</div>}
                                                        </td>
                                                        <td style={{ fontSize: 12 }}>{v.checkin_time ? fmt(v.checkin_time) : '—'}</td>
                                                        <td style={{ fontSize: 12 }}>
                                                            <div>{v.requested_time ? fmt(v.requested_time) : '—'}</div>
                                                            <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.duration ? `${v.duration} min` : ''}</div>
                                                        </td>
                                                        <td style={{ fontSize: 12 }}>
                                                            <div>{[v.building, v.floor, v.meeting_room_id ? `Room ${v.meeting_room_id}` : null].filter(Boolean).join(' → ') || '—'}</div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>

                            {/* Department Capacity */}
                            <div className="glass-card">
                                <ST><AppIcon name="grid" size={16} /> Department Capacity</ST>
                                <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap', alignItems: 'center' }}>
                                    <select className="form-input" style={{ fontSize: 11, width: 190 }} value={capCompany} onChange={e => { setCapCompany(e.target.value); setCapBranch(''); }}>
                                        <option value="">All Companies</option>
                                        {myCompanies.map(c => <option key={c.company_id} value={c.company_id}>{c.company_name}</option>)}
                                    </select>
                                    <select className="form-input" style={{ fontSize: 11, width: 210 }} value={capBranch} onChange={e => setCapBranch(e.target.value)}>
                                        <option value="">No Branch (Company-level)</option>
                                        {branches?.filter(b => !capCompany || String(b.company_id) === String(capCompany)).map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                    </select>
                                    {capBranch
                                        ? <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>Showing departments under the selected branch only.</span>
                                        : <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>Showing company-level departments (no branch).</span>}
                                </div>
                                {capDepartments.length === 0 ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No departments in this scope.</p>
                                ) : (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                                        {capDepartments.map((d) => {
                                            const maxCap = d.capacity_limit || 0;
                                            const used = d.active_visitors || 0;
                                            const avail = Math.max(0, maxCap - used);
                                            const pct = maxCap > 0 ? Math.min(100, Math.round((used / maxCap) * 100)) : 0;
                                            return (
                                                <div key={d.id}>
                                                    <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 4 }}>
                                                        <span style={{ fontWeight: 600 }}>{d.name}</span>
                                                        <span style={{ color: pct >= 100 ? 'var(--status-danger)' : pct >= 80 ? 'var(--status-warning)' : 'var(--status-success)', fontWeight: 700 }}>
                                                            {used} / {maxCap} ({avail} available)
                                                        </span>
                                                    </div>
                                                    <div style={{ background: 'rgba(255,255,255,0.06)', borderRadius: 4, height: 8 }}>
                                                        <div style={{ width: `${pct}%`, height: '100%', background: pct >= 100 ? 'var(--status-danger)' : pct >= 80 ? 'var(--status-warning)' : 'var(--status-success)', borderRadius: 4, transition: 'width 0.5s' }} />
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ══ 6. SECURITY ══ */}
                    {tab === 'security' && (
                        <div>
                            <H2><AppIcon name="shield" size={18} /> Security</H2>
                            <div className="grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr', gap: 24, marginBottom: 24 }}>
                                {/* Add Blacklist */}
                                <div className="glass-card">
                                    <ST><AppIcon name="prohibited" size={16} /> Add to Blacklist</ST>
                                    <form onSubmit={e => { e.preventDefault(); blForm.post('/admin/blacklist', { onSuccess: () => blForm.reset() }); }}>
                                        <div className="form-group"><label className="form-label">Full Name</label><input type="text" className="form-input" value={blForm.data.name} onChange={e => blForm.setData('name', e.target.value)} required /></div>
                                        <div className="form-group"><label className="form-label">ID Document</label><input type="text" className="form-input" value={blForm.data.id_document_no} onChange={e => blForm.setData('id_document_no', e.target.value)} required />{blForm.errors.id_document_no && <div style={{ color: 'var(--status-danger)', fontSize: 12 }}>{blForm.errors.id_document_no}</div>}</div>
                                        <div className="form-group"><label className="form-label">Reason</label><input type="text" className="form-input" value={blForm.data.reason} onChange={e => blForm.setData('reason', e.target.value)} required /></div>
                                        <button type="submit" className="btn btn-danger" style={{ width: '100%' }}><AppIcon name="prohibited" size={14} /> Add to Blacklist</button>
                                    </form>
                                </div>
                            </div>
                            {/* Blacklist table */}
                            <div className="glass-card" style={{ marginBottom: 24 }}>
                                <ST><AppIcon name="prohibited" size={16} /> Blacklist ({blacklist?.length || 0})</ST>
                                <div className="table-container" style={{ marginBottom: 0, maxHeight: 320, overflowY: 'auto' }}>
                                    <table className="data-table">
                                        <thead><tr><th>Name</th><th>ID Document</th><th>Reason</th><th>Actions</th></tr></thead>
                                        <tbody>
                                            {blacklist?.map(item => (
                                                <tr key={item.id} style={{ background: 'rgba(239,68,68,0.03)' }}>
                                                    <td style={{ fontWeight: 600 }}>{item.name}</td>
                                                    <td style={{ fontFamily: 'monospace', fontSize: 12 }}>{item.id_document_no}</td>
                                                    <td style={{ fontSize: 12, color: 'var(--text-secondary)', maxWidth: 200 }}>{item.reason}</td>
                                                    <td><button onClick={() => { if (confirm('Remove?')) router.delete(`/admin/blacklist/${item.id}`); }} className="btn btn-secondary" style={{ fontSize: 11, padding: '3px 8px' }}>Remove</button></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ══ SECURITY INCIDENTS ══ */}
                    {tab === 'incidents' && (
                        <div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
                                <H2><AppIcon name="warning" size={18} /> Security Incidents</H2>
                                <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                                    <select className="form-input" style={{ width: 210, fontSize: 12 }} value={incidentBranch} onChange={e => setIncidentBranch(e.target.value)}>
                                        <option value="">All Branches</option>
                                        {branches?.map(b => <option key={b.id} value={b.id}>{b.name}{b.location ? ` — ${b.location.name}` : ''}</option>)}
                                    </select>
                                    <select className="form-input" style={{ width: 130, fontSize: 12 }} value={incidentPeriod} onChange={e => setIncidentPeriod(e.target.value)}>
                                        <option value="daily">Daily (Today)</option>
                                        <option value="weekly">Weekly (This Week)</option>
                                        <option value="monthly">Monthly (This Month)</option>
                                        <option value="yearly">Yearly (This Year)</option>
                                    </select>
                                    <button className="btn btn-primary" style={{ fontSize: 12 }} onClick={() => loadIncidents(incidentPeriod, incidentBranch)} disabled={incidentLoading}>
                                        <AppIcon name="refresh" size={14} /> {incidentLoading ? 'Loading…' : 'Refresh'}
                                    </button>
                                </div>
                            </div>

                            {incidentError && (
                                <div className="glass-card" style={{ marginBottom: 16, borderColor: 'rgba(239,68,68,0.4)' }}>
                                    <span style={{ color: 'var(--status-danger)', fontSize: 13 }}><AppIcon name="warning" size={14} /> {incidentError}</span>
                                </div>
                            )}

                            {incidentData && (
                                <>
                                    <div className="glass-card" style={{ marginBottom: 20, display: 'flex', flexWrap: 'wrap', gap: 24, alignItems: 'center' }}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                            <AppIcon name="calendar" size={16} />
                                            <span style={{ fontWeight: 700, fontSize: 14 }}>{incidentData.period_label}</span>
                                            <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>({incidentData.range.start} → {incidentData.range.end})</span>
                                        </div>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                            <AppIcon name="building" size={16} />
                                            <span style={{ fontSize: 13 }}>{incidentData.selected_branch ? incidentData.selected_branch.name : 'All Branches'}</span>
                                        </div>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                            <AppIcon name="file" size={16} />
                                            <span style={{ fontSize: 13, color: 'var(--text-muted)' }}>{incidentData.count} incident(s) in scope</span>
                                        </div>
                                    </div>

                                    <div className="glass-card" style={{ marginBottom: 20 }}>
                                        <div className="stats-grid" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(130px,1fr))' }}>
                                            <Stat label="Total Incidents" value={incidentData.stats.total} color="default" icon={<AppIcon name="warning" size={16} />} />
                                            <Stat label="Logged" value={incidentData.stats.open} color="warning" />
                                            <Stat label="Investigating" value={incidentData.stats.investigating} color="cyan" />
                                            <Stat label="Resolved" value={incidentData.stats.resolved} color="emerald" />
                                            <Stat label="Escalated" value={incidentData.stats.escalated} color="danger" />
                                            <Stat label="Critical" value={incidentData.stats.critical} color="danger" />
                                        </div>
                                    </div>

                                    <div className="glass-card">
                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginBottom: 12 }}>
                                            <div>
                                                <ST><AppIcon name="warning" size={16} /> Incident Log</ST>
                                                <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Click a row for full description, action taken & resolution details.</div>
                                            </div>
                                        </div>
                                        <div className="table-container">
                                            <table className="data-table">
                                                <thead><tr><th>ID</th><th>Date & Time</th><th>Branch</th><th>Category</th><th>Severity</th><th>Visitor / Person</th><th>Host Staff</th><th>Security Officer</th><th>Status</th><th>Actions</th></tr></thead>
                                                <tbody>
                                                    {incidentData.incidents.map(inc => {
                                                        const sevColor = { low: 'var(--status-info)', medium: 'var(--status-warning)', high: 'var(--status-danger)', critical: '#dc2626' };
                                                        const statusLabel = { open: 'Logged', investigating: 'Investigating', resolved: 'Resolved', closed: 'Closed', escalated: 'Escalated' };
                                                        const statusClass = { open: 'badge-pending', investigating: 'badge-pending', resolved: 'badge-approved', closed: 'badge-approved', escalated: 'badge-rejected' };
                                                        const isExpanded = expandedIncId === inc.id;
                                                        return (
                                                            <React.Fragment key={inc.id}>
                                                                <tr onClick={() => setExpandedIncId(isExpanded ? null : inc.id)} style={{ cursor: 'pointer' }}>
                                                                    <td style={{ fontWeight: 700 }}>#{inc.id}</td>
                                                                    <td style={{ fontSize: 11, whiteSpace: 'nowrap' }}>{inc.created_at}</td>
                                                                    <td>
                                                                        <div style={{ fontWeight: 600, fontSize: 12 }}>{inc.branch_name}</div>
                                                                        {inc.location_name && <div style={{ fontSize: 10, color: 'var(--text-muted)' }}>{inc.location_name}</div>}
                                                                    </td>
                                                                    <td><span className="badge badge-pending" style={{ fontSize: 10 }}>{inc.category}</span></td>
                                                                    <td><span style={{ color: sevColor[inc.severity] || 'var(--text-primary)', fontWeight: 700, fontSize: 12 }}>{inc.severity}</span></td>
                                                                    <td>{inc.visitor_name || '—'}</td>
                                                                    <td>{inc.host_name || '—'}</td>
                                                                    <td>{inc.reported_by_name || '—'}</td>
                                                                    <td><span className={`badge ${statusClass[inc.status] || 'badge-pending'}`} style={{ fontSize: 10 }}>{statusLabel[inc.status] || inc.status}</span></td>
                                                                    <td>
                                                                        <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                                                                            {inc.status !== 'investigating' && <button onClick={e => { e.stopPropagation(); handleIncidentStatus(inc.id, 'investigating'); }} className="btn btn-secondary" style={{ fontSize: 10, padding: '3px 8px' }}><AppIcon name="search" size={10} /> Invest</button>}
                                                                            {inc.status !== 'resolved' && <button onClick={e => { e.stopPropagation(); handleIncidentStatus(inc.id, 'resolved'); }} className="btn btn-primary" style={{ fontSize: 10, padding: '3px 8px' }}><AppIcon name="check" size={10} /> Resolve</button>}
                                                                            {inc.status !== 'closed' && <button onClick={e => { e.stopPropagation(); handleIncidentStatus(inc.id, 'closed'); }} className="btn btn-danger" style={{ fontSize: 10, padding: '3px 8px' }}><AppIcon name="close" size={10} /> Close</button>}
                                                                            {inc.status !== 'escalated' && <button onClick={e => { e.stopPropagation(); handleIncidentStatus(inc.id, 'escalated'); }} className="btn btn-secondary" style={{ fontSize: 10, padding: '3px 8px' }}><AppIcon name="emergency" size={10} /> Escalate</button>}
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                                {isExpanded && (
                                                                    <tr>
                                                                        <td colSpan={10} style={{ background: 'rgba(255,255,255,0.02)', padding: '12px 16px' }}>
                                                                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px 24px', fontSize: 12 }}>
                                                                                <div><span style={{ color: 'var(--text-muted)' }}>Description:</span> {inc.description || '—'}</div>
                                                                                <div><span style={{ color: 'var(--text-muted)' }}>Action Taken:</span> {inc.action_taken || '—'}</div>
                                                                                <div><span style={{ color: 'var(--text-muted)' }}>Resolved At:</span> {inc.resolved_at || '—'}</div>
                                                                                <div><span style={{ color: 'var(--text-muted)' }}>Related Visit:</span> {inc.related_visit_id ? `#${inc.related_visit_id}` : '—'}</div>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                                )}
                                                            </React.Fragment>
                                                        );
                                                    })}
                                                    {incidentData.incidents.length === 0 && <tr><td colSpan={10} style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 24 }}>No incidents found for the selected period.</td></tr>}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </>
                            )}
                        </div>
                    )}

                    {/* ══ NOTIFICATION TEMPLATES ══ */}
                    {tab === 'notif' && (
                        <div>
                            <H2><AppIcon name="bell" size={18} /> Notifications & Alerts</H2>
                            <div className="grid-2col-asym" style={{ display: 'grid', gridTemplateColumns: '1fr 1.4fr', gap: 24, marginBottom: 24 }}>
                                <div className="glass-card">
                                    <ST><AppIcon name="bell" size={16} /> Create Notification Template</ST>
                                    <form onSubmit={e => { e.preventDefault(); notifForm.post('/admin/notification-templates', { onSuccess: () => notifForm.reset() }); }}>
                                        <div className="form-group"><label className="form-label">Event</label>
                                            <select className="form-select" value={notifForm.data.event} onChange={e => notifForm.setData('event', e.target.value)}>
                                                {['visit_approved', 'visit_rejected', 'visit_escalated', 'visitor_arrived', 'visit_completed', 'qr_generated', 'blacklist_alert', 'lockdown_alert'].map(ev => (
                                                    <option key={ev} value={ev}>{ev.replace(/_/g, ' ')}</option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="form-group"><label className="form-label">Channel</label>
                                            <select className="form-select" value={notifForm.data.channel} onChange={e => notifForm.setData('channel', e.target.value)}>
                                                <option value="in_app">In-App</option>
                                                <option value="email">Email</option>
                                                <option value="sms">SMS</option>
                                            </select>
                                        </div>
                                        <div className="form-group"><label className="form-label">Subject (Email)</label><input type="text" className="form-input" value={notifForm.data.subject} onChange={e => notifForm.setData('subject', e.target.value)} /></div>
                                        <div className="form-group"><label className="form-label">Message Body</label><textarea className="form-textarea" style={{ minHeight: 80 }} value={notifForm.data.body} onChange={e => notifForm.setData('body', e.target.value)} required placeholder="Use {visitor_name}, {host_name}, {date} as placeholders..." /></div>
                                        <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, marginBottom: 12, cursor: 'pointer' }}><input type="checkbox" checked={notifForm.data.is_active} onChange={e => notifForm.setData('is_active', e.target.checked)} /> Active</label>
                                        <button type="submit" className="btn btn-primary" style={{ width: '100%' }} disabled={notifForm.processing}><AppIcon name="save" size={14} /> Create Template</button>
                                    </form>
                                </div>
                                <div className="glass-card">
                                    <ST><AppIcon name="bell" size={16} /> Existing Templates</ST>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8, maxHeight: 400, overflowY: 'auto' }}>
                                        {notif_templates?.map(n => (
                                            <div key={n.id} style={{ padding: '10px 14px', borderRadius: 8, background: 'rgba(255,255,255,0.02)', border: '1px solid rgba(255,255,255,0.06)' }}>
                                                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 4 }}>
                                                    <div><span style={{ fontWeight: 700, fontSize: 13 }}>{n.event.replace(/_/g, ' ')}</span><span style={{ fontSize: 11, marginLeft: 8, padding: '2px 8px', borderRadius: 10, background: 'rgba(99,102,241,0.1)', color: 'var(--accent-primary)' }}>{n.channel}</span></div>
                                                    <div style={{ display: 'flex', gap: 6 }}>
                                                        <span style={{ fontSize: 11, color: n.is_active ? 'var(--status-success)' : 'var(--text-muted)' }}>{n.is_active ? ' Active' : 'Inactive'}</span>
                                                        <button onClick={() => router.delete(`/admin/notification-templates/${n.id}`)} style={{ background: 'none', border: 'none', fontSize: 12, color: 'var(--status-danger)', cursor: 'pointer' }}><AppIcon name="trash" size={12} /></button>
                                                    </div>
                                                </div>
                                                <div style={{ fontSize: 12, color: 'var(--text-secondary)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{n.body}</div>
                                            </div>
                                        ))}
                                        {!notif_templates?.length && <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No templates yet.</p>}
                                    </div>
                                </div>
                            </div>
                            {/* Emergency Broadcast */}
                            <div className="glass-card" style={{ borderColor: 'rgba(239,68,68,0.25)' }}>
                                <ST><AppIcon name="broadcast" size={16} /> Emergency Broadcast</ST>
                                <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
                                    <div className="form-group" style={{ marginBottom: 0 }}>
                                        <label className="form-label">Priority</label>
                                        <select className="form-select" style={{ width: 140 }} value={broadcastPri} onChange={e => setBroadcastPri(e.target.value)}>
                                            <option value="info">Info</option>
                                            <option value="warning">Warning</option>
                                            <option value="critical">Critical</option>
                                        </select>
                                    </div>
                                    <div className="form-group" style={{ flex: 1, marginBottom: 0 }}>
                                        <label className="form-label">Message</label>
                                        <input type="text" className="form-input" value={broadcastMsg} onChange={e => setBroadcastMsg(e.target.value)} placeholder="Enter message" />
                                    </div>
                                    <button onClick={() => { if (!broadcastMsg) { alert('Enter message'); return; } router.post('/admin/broadcast', { message: broadcastMsg, priority: broadcastPri }, { onSuccess: () => setBroadcastMsg('') }); }} className="btn btn-danger" style={{ flexShrink: 0 }}><AppIcon name="broadcast" size={14} /> Send Broadcast</button>
                                </div>
                            </div>
                        </div>
                    )}
                    {/* ══ EXPORT ══ */}
                    {tab === 'export' && (
                        <div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
                                <H2><AppIcon name="download" size={18} /> Export</H2>
                                <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                                    <select className="form-input" style={{ width: 210, fontSize: 12 }} value={reportBranch} onChange={e => setReportBranch(e.target.value)}>
                                        <option value="">All Branches</option>
                                        {branches?.map(b => <option key={b.id} value={b.id}>{b.name}{b.location ? ` — ${b.location.name}` : ''}</option>)}
                                    </select>
                                    <select className="form-input" style={{ width: 130, fontSize: 12 }} value={reportPeriod} onChange={e => setReportPeriod(e.target.value)}>
                                        <option value="daily">Daily (Today)</option>
                                        <option value="weekly">Weekly (This Week)</option>
                                        <option value="monthly">Monthly (This Month)</option>
                                        <option value="yearly">Yearly (This Year)</option>
                                    </select>
                                    <button className="btn btn-primary" style={{ fontSize: 12 }} onClick={() => loadReports(reportPeriod, reportBranch)} disabled={reportLoading}>
                                        <AppIcon name="refresh" size={14} /> {reportLoading ? 'Loading…' : 'Refresh'}
                                    </button>
                                </div>
                            </div>

                            {reportError && (
                                <div className="glass-card" style={{ marginBottom: 16, borderColor: 'rgba(239,68,68,0.4)' }}>
                                    <span style={{ color: 'var(--status-danger)', fontSize: 13 }}><AppIcon name="warning" size={14} /> {reportError}</span>
                                </div>
                            )}

                            {reportData && (
                                <div className="glass-card" style={{ marginBottom: 20, display: 'flex', flexWrap: 'wrap', gap: 24, alignItems: 'center' }}>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                        <AppIcon name="calendar" size={16} />
                                        <span style={{ fontWeight: 700, fontSize: 14 }}>{reportData.period_label}</span>
                                        <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>({reportData.range.start} → {reportData.range.end})</span>
                                    </div>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                        <AppIcon name="building" size={16} />
                                        <span style={{ fontSize: 13 }}>{reportData.selected_branch ? reportData.selected_branch.name : 'All Branches'}</span>
                                    </div>
                                </div>
                            )}

                            <div className="grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 32 }}>
                                <div className="glass-card" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                                    <ST><AppIcon name="file" size={16} /> Visit Requests Export</ST>
                                    <p style={{ fontSize: 13, color: 'var(--text-secondary)', lineHeight: 1.6 }}>
                                        Exports all visit requests for the selected period & branch as a CSV file including visitor name, host, department, purpose, status, and timestamp.
                                    </p>
                                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                        <a href="/admin/export/visits" className="btn btn-primary" style={{ padding: '8px 16px', textDecoration: 'none' }}><AppIcon name="download" size={14} /> Download Visits CSV</a>
                                    </div>
                                </div>
                                <div className="glass-card" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                                    <ST><AppIcon name="file" size={16} /> Audit Log Export</ST>
                                    <p style={{ fontSize: 13, color: 'var(--text-secondary)', lineHeight: 1.6 }}>
                                        Exports the complete audit trail as a CSV file including all actor actions, timestamps, and IP addresses for compliance and governance review.
                                    </p>
                                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                        <a href="/admin/export/audit" className="btn btn-primary" style={{ padding: '8px 16px', textDecoration: 'none' }}><AppIcon name="download" size={14} /> Download Audit CSV</a>
                                    </div>
                                </div>
                                <div className="glass-card" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                                    <ST><AppIcon name="file" size={16} /> Staff Export</ST>
                                    <p style={{ fontSize: 13, color: 'var(--text-secondary)', lineHeight: 1.6 }}>
                                        Exports the staff directory as a CSV file including name, role, department, status, and branch.
                                    </p>
                                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                        <a href="/admin/export/staff" className="btn btn-primary" style={{ padding: '8px 16px', textDecoration: 'none' }}><AppIcon name="download" size={14} /> Download Staff CSV</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ══ REPORTS ══ */}
                    {tab === 'reports' && (
                        <div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
                                <H2><AppIcon name="chart" size={18} /> Reports</H2>
                                <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                                    <select className="form-input" style={{ width: 210, fontSize: 12 }} value={reportBranch} onChange={e => setReportBranch(e.target.value)}>
                                        <option value="">All Branches</option>
                                        {branches?.map(b => <option key={b.id} value={b.id}>{b.name}{b.location ? ` — ${b.location.name}` : ''}</option>)}
                                    </select>
                                    <select className="form-input" style={{ width: 130, fontSize: 12 }} value={reportPeriod} onChange={e => setReportPeriod(e.target.value)}>
                                        <option value="daily">Daily (Today)</option>
                                        <option value="weekly">Weekly (This Week)</option>
                                        <option value="monthly">Monthly (This Month)</option>
                                        <option value="yearly">Yearly (This Year)</option>
                                    </select>
                                    <button className="btn btn-primary" style={{ fontSize: 12 }} onClick={() => loadReports(reportPeriod, reportBranch)} disabled={reportLoading}>
                                        <AppIcon name="refresh" size={14} /> {reportLoading ? 'Loading…' : 'Refresh'}
                                    </button>
                                </div>
                            </div>

                            {reportError && (
                                <div className="glass-card" style={{ marginBottom: 16, borderColor: 'rgba(239,68,68,0.4)' }}>
                                    <span style={{ color: 'var(--status-danger)', fontSize: 13 }}><AppIcon name="warning" size={14} /> {reportError}</span>
                                </div>
                            )}

                            {reportData && (
                                <>
                                    <div className="glass-card" style={{ marginBottom: 20, display: 'flex', flexWrap: 'wrap', gap: 24, alignItems: 'center' }}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                            <AppIcon name="calendar" size={16} />
                                            <span style={{ fontWeight: 700, fontSize: 14 }}>{reportData.period_label}</span>
                                            <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>({reportData.range.start} → {reportData.range.end})</span>
                                        </div>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                            <AppIcon name="building" size={16} />
                                            <span style={{ fontSize: 13 }}>{reportData.selected_branch ? reportData.selected_branch.name : 'All Branches'}</span>
                                        </div>
                                    </div>

                                    <div className="glass-card" style={{ marginBottom: 20 }}>
                                        <div className="stats-grid" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(130px,1fr))' }}>
                                            <Stat label="Visit Requests" value={reportData.visits.count} color="default" icon={<AppIcon name="calendar" size={16} />} />
                                            <Stat label="Approved" value={reportData.visits.stats.approved} color="emerald" />
                                            <Stat label="Pending" value={reportData.visits.stats.pending} color="warning" />
                                            <Stat label="Rejected" value={reportData.visits.stats.rejected} color="danger" />
                                            <Stat label="Checked In" value={reportData.visits.stats.checked_in} color="cyan" />
                                            <Stat label="Checked Out" value={reportData.visits.stats.checked_out} color="blue" />
                                            <Stat label="Audit Records" value={reportData.audit.count} color="default" icon={<AppIcon name="file" size={16} />} />
                                            <Stat label="Staff Accounts" value={reportData.staff.count} color="default" icon={<AppIcon name="users" size={16} />} />
                                        </div>
                                    </div>

                                    <div className="glass-card" style={{ marginBottom: 20 }}>
                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginBottom: 12 }}>
                                            <div>
                                                <ST><AppIcon name="calendar" size={16} /> Visit Requests ({reportData.visits.count})</ST>
                                                <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>All visit requests created in the selected period & branch.</div>
                                            </div>
                                            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                                                <a href={reportExportUrl('visits', 'csv')} className="btn btn-primary" style={{ fontSize: 11, padding: '5px 10px', textDecoration: 'none' }}><AppIcon name="download" size={12} /> CSV</a>
                                                <a href={reportExportUrl('visits', 'excel')} className="btn btn-secondary" style={{ fontSize: 11, padding: '5px 10px', textDecoration: 'none' }}><AppIcon name="download" size={12} /> Excel</a>
                                                <a href={reportExportUrl('visits', 'pdf')} className="btn btn-danger" style={{ fontSize: 11, padding: '5px 10px', textDecoration: 'none' }}><AppIcon name="download" size={12} /> PDF</a>
                                            </div>
                                        </div>
                                        <div className="table-container">
                                            <table className="data-table">
                                                <thead><tr><th>ID</th><th>Visitor</th><th>Host</th><th>Department</th><th>Purpose</th><th>Status</th><th>Timestamp</th></tr></thead>
                                                <tbody>
                                                    {reportData.visits.rows.map(r => (
                                                        <tr key={r.id}>
                                                            <td style={{ fontWeight: 700 }}>#{r.id}</td>
                                                            <td>{r.visitor_name || '—'}</td>
                                                            <td>{r.host_name || '—'}</td>
                                                            <td>{r.department || '—'}</td>
                                                            <td style={{ fontSize: 12 }}>{r.purpose || '—'}</td>
                                                            <td><span className="badge badge-pending" style={{ fontSize: 10, textTransform: 'capitalize' }}>{String(r.status || '').replace(/_/g, ' ')}</span></td>
                                                            <td style={{ fontSize: 11, whiteSpace: 'nowrap' }}>{r.timestamp || '—'}</td>
                                                        </tr>
                                                    ))}
                                                    {reportData.visits.rows.length === 0 && <tr><td colSpan={7} style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 24 }}>No visit requests found for the selected period.</td></tr>}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <div className="glass-card" style={{ marginBottom: 20 }}>
                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginBottom: 12 }}>
                                            <div>
                                                <ST><AppIcon name="file" size={16} /> Audit Logs ({reportData.audit.count})</ST>
                                                <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Audit trail records in the selected period & branch.</div>
                                            </div>
                                            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                                                <a href={reportExportUrl('audit', 'csv')} className="btn btn-primary" style={{ fontSize: 11, padding: '5px 10px', textDecoration: 'none' }}><AppIcon name="download" size={12} /> CSV</a>
                                                <a href={reportExportUrl('audit', 'excel')} className="btn btn-secondary" style={{ fontSize: 11, padding: '5px 10px', textDecoration: 'none' }}><AppIcon name="download" size={12} /> Excel</a>
                                                <a href={reportExportUrl('audit', 'pdf')} className="btn btn-danger" style={{ fontSize: 11, padding: '5px 10px', textDecoration: 'none' }}><AppIcon name="download" size={12} /> PDF</a>
                                            </div>
                                        </div>
                                        <div className="table-container">
                                            <table className="data-table">
                                                <thead><tr><th>ID</th><th>Actor</th><th>Action</th><th>IP Address</th><th>Timestamp</th></tr></thead>
                                                <tbody>
                                                    {reportData.audit.rows.map(r => (
                                                        <tr key={r.id}>
                                                            <td style={{ fontWeight: 700 }}>#{r.id}</td>
                                                            <td>{r.actor || '—'}</td>
                                                            <td style={{ fontSize: 12 }}>{r.action || '—'}</td>
                                                            <td style={{ fontSize: 11 }}>{r.ip_address || '—'}</td>
                                                            <td style={{ fontSize: 11, whiteSpace: 'nowrap' }}>{r.timestamp || '—'}</td>
                                                        </tr>
                                                    ))}
                                                    {reportData.audit.rows.length === 0 && <tr><td colSpan={5} style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 24 }}>No audit records found for the selected period.</td></tr>}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <div className="glass-card" style={{ marginBottom: 20 }}>
                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginBottom: 12 }}>
                                            <div>
                                                <ST><AppIcon name="users" size={16} /> Staff ({reportData.staff.count})</ST>
                                                <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Staff accounts created in the selected period & branch.</div>
                                            </div>
                                            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                                                <a href={reportExportUrl('staff', 'csv')} className="btn btn-primary" style={{ fontSize: 11, padding: '5px 10px', textDecoration: 'none' }}><AppIcon name="download" size={12} /> CSV</a>
                                                <a href={reportExportUrl('staff', 'excel')} className="btn btn-secondary" style={{ fontSize: 11, padding: '5px 10px', textDecoration: 'none' }}><AppIcon name="download" size={12} /> Excel</a>
                                                <a href={reportExportUrl('staff', 'pdf')} className="btn btn-danger" style={{ fontSize: 11, padding: '5px 10px', textDecoration: 'none' }}><AppIcon name="download" size={12} /> PDF</a>
                                            </div>
                                        </div>
                                        <div className="table-container">
                                            <table className="data-table">
                                                <thead><tr><th>ID</th><th>Name</th><th>Role</th><th>Department</th><th>Status</th><th>Branch</th></tr></thead>
                                                <tbody>
                                                    {reportData.staff.rows.map(r => (
                                                        <tr key={r.id}>
                                                            <td style={{ fontWeight: 700 }}>#{r.id}</td>
                                                            <td>{r.name || '—'}</td>
                                                            <td><span className="badge badge-pending" style={{ fontSize: 10 }}>{r.role || '—'}</span></td>
                                                            <td style={{ fontSize: 12 }}>{r.department || '—'}</td>
                                                            <td><span className="badge badge-approved" style={{ fontSize: 10, textTransform: 'capitalize' }}>{r.status || '—'}</span></td>
                                                            <td style={{ fontSize: 12 }}>{r.branch || '—'}</td>
                                                        </tr>
                                                    ))}
                                                    {reportData.staff.rows.length === 0 && <tr><td colSpan={6} style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 24 }}>No staff accounts found for the selected period.</td></tr>}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </>
                            )}
                        </div>
                    )}

                    {/* ══ 10. AUDIT LOGS ══ */}
                    {tab === 'audit' && (
                        <div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
                                <H2><AppIcon name="file" size={18} /> Audit Logs</H2>
                                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                                    <button className="btn btn-danger" style={{ fontSize: 11, padding: '5px 10px' }} onClick={() => { if (confirm('Delete ALL audit records for your scope? This cannot be undone.')) router.delete('/admin/audit-logs'); }}>
                                        <AppIcon name="trash" size={12} /> Delete All
                                    </button>
                                    <select className="form-input" style={{ fontSize: 11, width: 140 }} value={auditLocationFilter} onChange={e => setAuditLocationFilter(e.target.value)}>
                                        <option value="">All Locations</option>
                                        {locations?.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
                                    </select>
                                    <select className="form-input" style={{ fontSize: 11, width: 140 }} value={auditBranchFilter} onChange={e => setAuditBranchFilter(e.target.value)}>
                                        <option value="">All Branches</option>
                                        {branches?.filter(b => !auditLocationFilter || String(b.location_id) === String(auditLocationFilter)).map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                    </select>
                                    <input type="text" className="form-input" style={{ flex: 1, minWidth: 160, fontSize: 12 }} placeholder="Search actions..." value={auditFilter} onChange={e => setAuditFilter(e.target.value)} />
                                </div>
                            </div>
                            <div className="table-container" style={{ marginBottom: 0 }}>
                                <table className="data-table">
                                    <thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>IP</th><th>Location</th><th>Branch</th><th>Details</th><th>Actions</th></tr></thead>
                                    <tbody>
                                        {filteredAudit.map(log => (
                                            <tr key={log.id}>
                                                <td style={{ fontSize: 11, whiteSpace: 'nowrap' }}>{fmt(log.created_at)}</td>
                                                <td style={{ fontSize: 12 }}>{(log.actor_type || 'system').toUpperCase()} #{log.actor_id || '—'}</td>
                                                <td>
                                                    <span className="badge" style={{ background: log.action?.includes('fail') || log.action?.includes('blacklist') || log.action?.includes('lockdown') ? 'rgba(239,68,68,0.1)' : 'rgba(34,197,94,0.1)', color: log.action?.includes('fail') || log.action?.includes('blacklist') || log.action?.includes('lockdown') ? 'var(--status-danger)' : 'var(--status-success)', border: '1px solid currentColor' }}>
                                                        {log.action?.replace(/_/g, ' ')}
                                                    </span>
                                                </td>
                                                <td style={{ fontSize: 12, color: 'var(--text-muted)' }}>{log.entity || '—'} {log.entity_id ? `#${log.entity_id}` : ''}</td>
                                                <td style={{ fontSize: 12 }}>{log.ip_address || '—'}</td>
                                                <td style={{ fontSize: 11, color: 'var(--text-muted)' }}>{log.location_name || '—'}</td>
                                                <td style={{ fontSize: 11, color: 'var(--text-muted)' }}>{log.branch_name || '—'}</td>
                                                <td style={{ fontSize: 11, color: 'var(--text-muted)', maxWidth: 140, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{log.after_value ? JSON.stringify(log.after_value).substring(0, 60) : '—'}</td>
                                                <td>
                                                    <button className="btn btn-danger" style={{ fontSize: 10, padding: '2px 8px' }} onClick={() => { if (confirm(`Delete audit record #${log.id}? This cannot be undone.`)) router.delete(`/admin/audit-logs/${log.id}`); }}>
                                                        <AppIcon name="trash" size={11} /> Del
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                        {filteredAudit.length === 0 && <tr><td colSpan={9} style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 24 }}>No records.</td></tr>}
                                    </tbody>
                                </table>
                            </div>
                            <p style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 8 }}>Showing latest 50 records.</p>
                        </div>
                    )}

                    {/* ══ 11. SYSTEM & INTEGRATIONS ══ */}
                    {tab === 'system' && (
                        <div>
                            <H2><AppIcon name="settings" size={18} /> System & Integrations</H2>
                            <div className="grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24, marginBottom: 24 }}>
                                {/* Integrations status */}
                                <div className="glass-card">
                                    <ST><AppIcon name="plug" size={16} /> System Integrations</ST>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                        {integrations?.map(int => {
                                            const sc = { active: 'var(--status-success)', inactive: 'var(--text-muted)', error: 'var(--status-danger)' };
                                            const si = { active: <AppIcon name="check" size={14} />, inactive: <AppIcon name="close" size={14} />, error: <AppIcon name="warning" size={14} /> };
                                            return (
                                                <div key={int.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '12px 16px', borderRadius: 10, background: 'rgba(255,255,255,0.02)', border: '1px solid rgba(255,255,255,0.06)' }}>
                                                    <div>
                                                        <div style={{ fontWeight: 700, fontSize: 14 }}>{int.name}</div>
                                                        <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{int.type.toUpperCase()}</div>
                                                    </div>
                                                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                                        <span style={{ fontSize: 13, color: sc[int.status], fontWeight: 700 }}>{si[int.status]} {int.status}</span>
                                                        <button className="btn btn-secondary" style={{ fontSize: 11, padding: '3px 10px' }}>Configure</button>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                                {/* System health */}
                                <div className="glass-card">
                                    <ST><AppIcon name="monitor" size={16} /> System Health</ST>
                                    {[
                                        { label: 'Database', status: 'operational', icon: 'database' },
                                        { label: 'Cache (Redis)', status: 'operational', icon: 'zap' },
                                        { label: 'Queue Worker', status: 'operational', icon: 'play' },
                                        { label: 'File Storage', status: 'operational', icon: 'folder' },
                                        { label: 'Email Service', status: 'inactive', icon: 'mail' },
                                        { label: 'SMS Service', status: 'inactive', icon: 'smartphone' },
                                    ].map(s => (
                                        <div key={s.label} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '9px 0', borderBottom: '1px solid rgba(255,255,255,0.04)' }}>
                                            <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                                                <span style={{ fontSize: 18 }}><AppIcon name={s.icon} size={18} /></span>
                                                <span style={{ fontSize: 14 }}>{s.label}</span>
                                            </div>
                                            <span style={{ fontSize: 12, fontWeight: 700, color: s.status === 'operational' ? 'var(--status-success)' : 'var(--text-muted)' }}>
                                                {s.status === 'operational' ? ' Operational' : ' Inactive'}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                            {/* ── SMS Gateway Configuration ── */}
                            <div className="glass-card">
                                <ST><AppIcon name="smartphone" size={16} /> SMS Gateway Configuration</ST>
                                <p style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 16 }}>
                                    SMS notifications (visit confirmations, alerts, contact messages) are delivered through this gateway. Without credentials the system reports <code style={{ background: 'rgba(99,102,241,0.15)', padding: '2px 6px', borderRadius: 4 }}>skipped</code>.
                                </p>
                                <form onSubmit={e => { e.preventDefault(); smsForm.post('/admin/integrations/sms'); }}>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', background: 'rgba(255,255,255,0.03)', borderRadius: 10, border: '1px solid rgba(255,255,255,0.06)' }}>
                                            <div>
                                                <div style={{ fontSize: 14, fontWeight: 600 }}>Gateway Enabled</div>
                                                <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginTop: 2 }}>Allow the system to send SMS through this provider.</div>
                                            </div>
                                            <label style={{ position: 'relative', display: 'inline-block', width: 44, height: 24, cursor: 'pointer', flexShrink: 0 }}>
                                                <input type="checkbox" checked={smsForm.data.status} onChange={e => smsForm.setData('status', e.target.checked)} style={{ display: 'none' }} />
                                                <span style={{ position: 'absolute', inset: 0, background: smsForm.data.status ? 'var(--status-success)' : 'rgba(255,255,255,0.15)', borderRadius: 12, transition: 'background 0.2s' }} />
                                                <span style={{ position: 'absolute', top: 2, left: smsForm.data.status ? 22 : 2, width: 20, height: 20, background: '#fff', borderRadius: '50%', transition: 'left 0.2s', boxShadow: '0 1px 3px rgba(0,0,0,0.3)' }} />
                                            </label>
                                        </div>

                                        <div>
                                            <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 8 }}>Provider</div>
                                            <div style={{ display: 'flex', gap: 12 }}>
                                                {[
                                                    { v: 'webhook', label: 'Webhook / HTTP Gateway' },
                                                    { v: 'twilio', label: 'Twilio' },
                                                    { v: 'smsethiopia', label: 'SMSEthiopia' },
                                                ].map(p => (
                                                    <label key={p.v} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, cursor: 'pointer', padding: '8px 14px', borderRadius: 8, border: '1px solid ' + (smsForm.data.provider === p.v ? 'var(--accent-primary)' : 'rgba(255,255,255,0.12)'), background: smsForm.data.provider === p.v ? 'rgba(99,102,241,0.12)' : 'transparent' }}>
                                                        <input type="radio" name="smsProvider" value={p.v} checked={smsForm.data.provider === p.v} onChange={e => smsForm.setData('provider', p.v)} style={{ display: 'none' }} />
                                                        {p.label}
                                                    </label>
                                                ))}
                                            </div>
                                            {smsForm.errors?.provider && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{smsForm.errors.provider}</div>}
                                        </div>

                                        {smsForm.data.provider === 'webhook' ? (
                                            <div>
                                                <label style={{ fontSize: 13, fontWeight: 600 }}>Webhook URL</label>
                                                <input type="url" className="form-input" style={{ marginTop: 6 }} placeholder="https://sms-provider.example.com/send"
                                                    value={smsForm.data.webhook_url} onChange={e => smsForm.setData('webhook_url', e.target.value)} />
                                                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>Receives a JSON POST: <code>{'{ "to": "...", "message": "...", "source": "vms" }'}</code></div>
                                                {smsForm.errors?.webhook_url && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{smsForm.errors.webhook_url}</div>}
                                            </div>
                                        ) : smsForm.data.provider === 'twilio' ? (
                                            <>
                                                <div>
                                                    <label style={{ fontSize: 13, fontWeight: 600 }}>Account SID</label>
                                                    <input type="text" className="form-input" style={{ marginTop: 6 }} placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                                        value={smsForm.data.account_sid} onChange={e => smsForm.setData('account_sid', e.target.value)} />
                                                    {smsForm.errors?.account_sid && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{smsForm.errors.account_sid}</div>}
                                                </div>
                                                <div>
                                                    <label style={{ fontSize: 13, fontWeight: 600 }}>Auth Token</label>
                                                    <input type="password" className="form-input" style={{ marginTop: 6 }} placeholder="Enter a new Auth Token to replace the saved one" autoComplete="new-password"
                                                        value={smsForm.data.auth_token} onChange={e => smsForm.setData('auth_token', e.target.value)} />
                                                    <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>Leave blank to keep the current token. The stored token is never shown again.</div>
                                                    {smsForm.errors?.auth_token && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{smsForm.errors.auth_token}</div>}
                                                </div>
                                                <div>
                                                    <label style={{ fontSize: 13, fontWeight: 600 }}>From Number</label>
                                                    <input type="text" className="form-input" style={{ marginTop: 6 }} placeholder="+12025550123"
                                                        value={smsForm.data.from_number} onChange={e => smsForm.setData('from_number', e.target.value)} />
                                                    {smsForm.errors?.from_number && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{smsForm.errors.from_number}</div>}
                                                </div>
                                            </>
                                        ) : (
                                            <div>
                                                <label style={{ fontSize: 13, fontWeight: 600 }}>API Key</label>
                                                <input type="password" className="form-input" style={{ marginTop: 6 }} placeholder="Enter a new API Key to replace the saved one" autoComplete="new-password"
                                                    value={smsForm.data.api_key} onChange={e => smsForm.setData('api_key', e.target.value)} />
                                                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>Leave blank to keep the current key. The stored API Key is never shown again.</div>
                                                {smsForm.errors?.api_key && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{smsForm.errors.api_key}</div>}
                                            </div>
                                        )}

                                        <button type="submit" className="btn btn-primary" disabled={smsForm.processing} style={{ alignSelf: 'flex-start' }}>
                                            {smsForm.processing ? 'Saving...' : <><AppIcon name="save" size={14} /> Save SMS Settings</>}
                                        </button>
                                    </div>
                                </form>

                                <div style={{ marginTop: 16, paddingTop: 16, borderTop: '1px solid rgba(255,255,255,0.06)' }}>
                                    <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 8 }}>Send Test SMS</div>
                                    <form onSubmit={e => { e.preventDefault(); router.post('/admin/integrations/sms/test', { test_phone: smsTestPhone }, { preserveScroll: true }); }} style={{ display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
                                        <div>
                                            <input type="text" className="form-input" style={{ minWidth: 180 }} placeholder="+251911223344"
                                                value={smsTestPhone} onChange={e => setSmsTestPhone(e.target.value)} />
                                            {smsForm.errors?.test_phone && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{smsForm.errors.test_phone}</div>}
                                        </div>
                                        <button type="submit" className="btn btn-secondary"><AppIcon name="smartphone" size={14} /> Send Test</button>
                                    </form>
                                </div>
                            </div>

                            {/* ── SLA & Escalation Settings ── */}
                            <div className="glass-card">
                                <ST><AppIcon name="clock" size={16} /> SLA & Escalation Settings</ST>
                                <p style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 16 }}>
                                    Pending visit requests that remain unattended beyond this timeout are automatically escalated to Admin. The system checks every minute via cron scheduler.
                                </p>
                                <form onSubmit={e => { e.preventDefault(); slaForm.post('/admin/sla-settings'); }}>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                                        {/* Allow Weekend Visits toggle */}
                                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', background: 'rgba(255,255,255,0.03)', borderRadius: 10, border: '1px solid rgba(255,255,255,0.06)' }}>
                                            <div>
                                                <div style={{ fontSize: 14, fontWeight: 600 }}>Allow Weekend Visits</div>
                                                <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginTop: 2 }}>When enabled, visitors can schedule visits on Saturdays and Sundays.</div>
                                            </div>
                                            <label style={{ position: 'relative', display: 'inline-block', width: 44, height: 24, cursor: 'pointer', flexShrink: 0 }}>
                                                <input type="checkbox" checked={slaForm.data.allow_weekends} onChange={e => slaForm.setData('allow_weekends', e.target.checked)} style={{ display: 'none' }} />
                                                <span style={{ position: 'absolute', inset: 0, background: slaForm.data.allow_weekends ? 'var(--status-success)' : 'rgba(255,255,255,0.15)', borderRadius: 12, transition: 'background 0.2s' }} />
                                                <span style={{ position: 'absolute', top: 2, left: slaForm.data.allow_weekends ? 22 : 2, width: 20, height: 20, background: '#fff', borderRadius: '50%', transition: 'left 0.2s', boxShadow: '0 1px 3px rgba(0,0,0,0.3)' }} />
                                            </label>
                                        </div>

                                        {/* Enforce Business Hours toggle */}
                                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', background: 'rgba(255,255,255,0.03)', borderRadius: 10, border: '1px solid rgba(255,255,255,0.06)' }}>
                                            <div>
                                                <div style={{ fontSize: 14, fontWeight: 600 }}>Enforce Business Hours (08:00–18:00)</div>
                                                <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginTop: 2 }}>When enabled, visit requests outside standard operating hours are rejected.</div>
                                            </div>
                                            <label style={{ position: 'relative', display: 'inline-block', width: 44, height: 24, cursor: 'pointer', flexShrink: 0 }}>
                                                <input type="checkbox" checked={slaForm.data.enforce_biz_hours} onChange={e => slaForm.setData('enforce_biz_hours', e.target.checked)} style={{ display: 'none' }} />
                                                <span style={{ position: 'absolute', inset: 0, background: slaForm.data.enforce_biz_hours ? 'var(--status-success)' : 'rgba(255,255,255,0.15)', borderRadius: 12, transition: 'background 0.2s' }} />
                                                <span style={{ position: 'absolute', top: 2, left: slaForm.data.enforce_biz_hours ? 22 : 2, width: 20, height: 20, background: '#fff', borderRadius: '50%', transition: 'left 0.2s', boxShadow: '0 1px 3px rgba(0,0,0,0.3)' }} />
                                            </label>
                                        </div>

                                        {/* SLA Timeout */}
                                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', background: 'rgba(255,255,255,0.03)', borderRadius: 10, border: '1px solid rgba(255,255,255,0.06)' }}>
                                            <div>
                                                <div style={{ fontSize: 14, fontWeight: 600 }}>Auto-Escalation SLA Timeout</div>
                                                <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginTop: 2 }}>Pending requests idle longer than this duration are auto-escalated to Admin.</div>
                                            </div>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                                <input type="number" className="form-input" style={{ width: 80 }} min={1} max={120}
                                                    value={slaForm.data.sla_minutes}
                                                    onChange={e => slaForm.setData('sla_minutes', Number(e.target.value))} />
                                                <span style={{ fontSize: 13, color: 'var(--text-secondary)' }}>min</span>
                                            </div>
                                        </div>

                                        <button type="submit" className="btn btn-primary" disabled={slaForm.processing} style={{ alignSelf: 'flex-start' }}>
                                            {slaForm.processing ? 'Saving...' : <><AppIcon name="save" size={14} /> Save Policies</>}
                                        </button>
                                    </div>
                                    {slaForm.errors?.sla_minutes && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{slaForm.errors.sla_minutes}</div>}
                                </form>
                                <div style={{ marginTop: 16, padding: '12px 16px', background: 'rgba(99,102,241,0.06)', borderRadius: 10, border: '1px solid rgba(99,102,241,0.15)', display: 'flex', alignItems: 'center', gap: 10 }}>
                                    <span style={{ fontSize: 20 }}><AppIcon name="info" size={20} /></span>
                                    <div>
                                        <div style={{ fontSize: 13, fontWeight: 600 }}>Current SLA: <span style={{ color: 'var(--accent-primary)' }}>{sla_minutes || 5} minute(s)</span></div>
                                        <div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>
                                            Auto-escalation runs every minute via <code style={{ background: 'rgba(99,102,241,0.15)', padding: '2px 6px', borderRadius: 4 }}>php artisan vms:process-sla</code>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Maintenance actions */}
                            <div className="glass-card">
                                <ST><AppIcon name="tool" size={16} /> Maintenance Actions</ST>
                                <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                                    {[
                                        { label: 'Process SLA Now', action: () => router.get('/visit-requests/process-sla'), style: 'btn-primary', icon: 'zap' },
                                        { label: 'Export Audit CSV', action: () => window.location = '/admin/export/audit', style: 'btn-secondary', icon: 'download' },
                                        { label: 'Export Staff CSV', action: () => window.location = '/admin/export/staff', style: 'btn-secondary', icon: 'download' },
                                    ].map(a => (
                                        <button key={a.label} onClick={a.action} className={`btn ${a.style}`} style={{ fontSize: 13 }}><AppIcon name={a.icon} size={14} /> {a.label}</button>
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
                            sender_id={auth.user?.id}
                            embedded
                        />
                    )}

                </div>
            </div>

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

            {/* ── Appointment action modals (Approve / Edit / Reject / Delete) ── */}
            {actModal && (() => {
                const v = actModal.visit;
                const meta = {
                    approve: { title: 'Approve Appointment', icon: 'check' },
                    edit: { title: 'Edit Appointment', icon: 'edit' },
                    reject: { title: 'Reject Appointment', icon: 'close' },
                    end: { title: 'End Visit', icon: 'exit' },
                }[actModal.type];
                return (
                    <div className="modal-overlay" style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 }} onClick={closeActModal}>
                        <div className="glass-card modal-content" style={{ width: 480, maxWidth: '92vw', padding: 24, border: '1px solid rgba(99,102,241,0.3)', maxHeight: '92vh', overflowY: 'auto' }} onClick={e => e.stopPropagation()}>
                            <h3 style={{ fontWeight: 700, fontSize: 16, marginBottom: 14, display: 'flex', alignItems: 'center', gap: 8 }}>
                                <AppIcon name={meta.icon} size={18} /> {meta.title}
                            </h3>

                            <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginBottom: 16, padding: '10px 12px', borderRadius: 8, background: 'rgba(99,102,241,0.06)', border: '1px solid rgba(99,102,241,0.15)' }}>
                                Visitor: <strong>{v.visitor_name || '—'}</strong> · Status: <span className={`badge badge-${v.status === 'checked_in' ? 'approved' : v.status}`}>{v.status.replace(/_/g, ' ')}</span>
                            </div>

                            {actModal.type === 'approve' && (
                                <div>
                                    <p style={{ fontSize: 13, marginBottom: 12 }}>Approve the appointment for <strong>{v.visitor_name || 'this visitor'}</strong> scheduled on <strong>{v.requested_time ? fmt(v.requested_time) : '—'}</strong>?</p>
                                    <label className="form-label">Comment / Note (optional)</label>
                                    <textarea className="form-input" style={{ width: '100%', minHeight: 64, marginBottom: 12 }} placeholder="Optional comment for the visitor or host..." value={actComment} onChange={e => setActComment(e.target.value)} />
                                </div>
                            )}

                            {actModal.type === 'reject' && (
                                <div>
                                    <p style={{ fontSize: 13, marginBottom: 12 }}>Reject the appointment for <strong>{v.visitor_name || 'this visitor'}</strong>? A rejection reason is required.</p>
                                    <label className="form-label">Rejection Reason <span style={{ color: 'var(--status-danger)' }}>*</span></label>
                                    <textarea className="form-input" style={{ width: '100%', minHeight: 72, marginBottom: 12 }} placeholder="Explain why this appointment is being rejected..." value={actReason} onChange={e => setActReason(e.target.value)} autoFocus />
                                </div>
                            )}

                            {actModal.type === 'edit' && (
                                <div>
                                    <p style={{ fontSize: 13, marginBottom: 12 }}>Update the date, time and duration for <strong>{v.visitor_name || 'this visitor'}</strong>.</p>
                                    <label className="form-label">Date & Time</label>
                                    <input type="datetime-local" className="form-input" style={{ width: '100%', marginBottom: 12 }} value={actEdit.requested_time} onChange={e => setActEdit(p => ({ ...p, requested_time: e.target.value }))} />
                                    <label className="form-label">Duration (minutes)</label>
                                    <input type="number" className="form-input" style={{ width: '100%', marginBottom: 12 }} min={15} max={480} step={15} value={actEdit.duration} onChange={e => setActEdit(p => ({ ...p, duration: Number(e.target.value) }))} />
                                </div>
                            )}

                            {actModal.type === 'end' && (
                                <div>
                                    <p style={{ fontSize: 13, marginBottom: 12 }}>End the active visit for <strong>{v.visitor_name || 'this visitor'}</strong>? The visitor will be checked out, the visit will be closed and a valid <strong>Exit QR code</strong> will be generated and sent to the visitor for the security exit gate. The record is kept for history and reporting.</p>
                                    <div style={{ fontSize: 12, color: 'var(--text-secondary)', padding: '10px 12px', borderRadius: 8, background: 'rgba(239,68,68,0.06)', border: '1px solid rgba(239,68,68,0.2)', marginBottom: 12 }}>
                                        Checked-in since: <strong>{v.checkin_time ? fmt(v.checkin_time) : '—'}</strong> · Scheduled: <strong>{v.requested_time ? fmt(v.requested_time) : '—'}</strong>
                                    </div>
                                </div>
                            )}

                            {actError && <div style={{ color: 'var(--status-danger)', fontSize: 13, marginBottom: 12, padding: '8px 10px', borderRadius: 8, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)' }}>{actError}</div>}

                            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                                <button type="button" onClick={closeActModal} className="btn btn-secondary" disabled={actBusy}>Cancel</button>
                                {actModal.type === 'approve' && <button type="button" onClick={() => submitApprove(v)} className="btn btn-primary" disabled={actBusy}>{actBusy ? 'Approving...' : <><AppIcon name="check" size={14} /> Confirm Approval</>}</button>}
                                {actModal.type === 'reject' && <button type="button" onClick={() => submitReject(v)} className="btn btn-danger" disabled={actBusy}>{actBusy ? 'Rejecting...' : <><AppIcon name="close" size={14} /> Confirm Rejection</>}</button>}
                                {actModal.type === 'edit' && <button type="button" onClick={() => submitEdit(v)} className="btn btn-primary" disabled={actBusy}>{actBusy ? 'Saving...' : <><AppIcon name="save" size={14} /> Save Changes</>}</button>}
                                {actModal.type === 'end' && <button type="button" onClick={() => submitEndVisit(v)} className="btn btn-danger" disabled={actBusy}>{actBusy ? 'Ending...' : <><AppIcon name="exit" size={14} /> Confirm End Visit</>}</button>}
                            </div>
                        </div>
                    </div>
                );
            })()}

            {/* Visitor Transfer (Admin — company-level) */}
            {transferVisit && <VisitorTransferModal visit={transferVisit} onClose={() => setTransferVisit(null)} />}
        </Layout>
    );
}

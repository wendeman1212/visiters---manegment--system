import React, { useState, useMemo, useEffect, useCallback } from 'react';
import Layout from '../Shared/Layout';
import { useForm, usePage, router, Link } from '@inertiajs/react';
import AppIcon from '../../config/iconMap';
import Messages from './Messages';
import PasswordInput from '../Shared/PasswordInput';
import { formatLocation } from '../../config/facilityData';
import { fmtDateTime, fmtDate, fmtTime, getMinDatetimeLocal, localToServerDatetime, toServerDatetimeInput, toLocalDatetimeInput, isPast, isFuture, getMeetingEnd } from '../../utils/timezone';

// ── Helpers ────────────────────────────────────────────────────
const fmt = fmtDateTime;
const fmtD = fmtDate;
const fmtT = fmtTime;
const pad = n => String(n).padStart(2, '0');

// Transfers and escalations are internal Admin/CEO/Host workflow — the visitor
// always sees the host they originally scheduled with, and never an "escalated"
// status (escalated visits display as a neutral "pending" instead).
const hostOf = r => r.original_host || r.host;
const deptOf = r => r.original_host?.department_name || r.department?.name || r.host?.department?.name || '';
const VISIBLE_STATUS = { escalated: 'pending' };
const displayStatus = s => VISIBLE_STATUS[s] || s;

function printBadge(badge, visitorName, idDocNo) {
    const name = badge.visitor_name || visitorName || 'Visitor';
    const dept = badge.department || '';
    const qrText = badge.qr_token || '';
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`<html><head><title>Badge - ${name}</title><style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Arial,sans-serif;padding:0;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f0f0f0;}
.badge{width:380px;background:white;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.15);border:2px solid #e0e0e0;}
.badge-header{background:linear-gradient(135deg,${badge.badge_color || '#6366f1'},#8b5cf6);color:white;padding:20px 24px;text-align:center;}
.badge-header h1{font-size:11px;letter-spacing:3px;text-transform:uppercase;opacity:0.8;margin-bottom:4px;}
.badge-header h2{font-size:20px;font-weight:800;letter-spacing:1px;}
.badge-status{background:#10b981;color:white;text-align:center;padding:8px;font-size:13px;font-weight:700;letter-spacing:1px;}
.badge-body{padding:20px 24px;}
.field{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f0f0f0;}
.field:last-child{border-bottom:none;}
.field-label{font-size:11px;color:#999;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;}
.field-value{font-size:14px;font-weight:700;color:#1a1a2e;text-align:right;}
.field-value.vip{color:#ec4899;}
.qr-section{text-align:center;padding:16px;background:#f8f9fa;border-radius:10px;margin-top:12px;}
.qr-section .label{font-size:10px;color:#999;letter-spacing:1px;margin-bottom:6px;}
.qr-section .token{font-family:'Courier New',monospace;font-size:13px;font-weight:700;color:#6366f1;letter-spacing:1px;word-break:break-all;}
.badge-footer{text-align:center;padding:12px 24px 16px;font-size:10px;color:#aaa;border-top:1px solid #f0f0f0;}
</style></head><body><div class="badge">
<div class="badge-header"><h1>VMS Enterprise</h1><h2> VISITOR ACCESS BADGE</h2></div>
<div class="badge-status"> CHECKED IN</div>
<div class="badge-body">
<div class="field"><span class="field-label">Full Name</span><span class="field-value">${name}</span></div>
<div class="field"><span class="field-label">ID Document</span><span class="field-value">${(badge.visitor_id_document || idDocNo || '').replace(/</g, '&lt;')}</span></div>
<div class="field"><span class="field-label">Host</span><span class="field-value">${(badge.host_name || '').replace(/</g, '&lt;')}</span></div>
<div class="field"><span class="field-label">Department</span><span class="field-value">${dept.replace(/</g, '&lt;')}</span></div>
<div class="field"><span class="field-label">Purpose</span><span class="field-value">${(badge.purpose || '').replace(/</g, '&lt;')}</span></div>
<div class="field"><span class="field-label">Scheduled</span><span class="field-value">${badge.scheduled_time || ''}</span></div>
<div class="field"><span class="field-label">Duration</span><span class="field-value">${badge.duration || ''}</span></div>
<div class="field"><span class="field-label">Priority</span><span class="field-value">${(badge.priority || 'normal').toUpperCase()}</span></div>
${badge.is_vip ? '<div class="field"><span class="field-label">VIP</span><span class="field-value vip"> YES</span></div>' : ''}
${qrText ? `<div class="qr-section"><div class="label">ACCESS TOKEN</div><div class="token">${qrText}</div></div>` : ''}
</div>
<div class="badge-footer">Visit #${badge.visit_request_id} &middot; Issued: ${badge.issued_at || new Date().toLocaleString()}<br>VMS Visitor Badge</div>
</div></body></html>`);
    printWindow.document.close();
    setTimeout(() => printWindow.print(), 400);
}

const STATUS_COLOR = {
    pending: { bg: 'rgba(245,158,11,0.12)', color: 'var(--status-warning)', border: 'rgba(245,158,11,0.3)' },
    approved: { bg: 'rgba(34,197,94,0.12)', color: 'var(--status-success)', border: 'rgba(34,197,94,0.3)' },
    rejected: { bg: 'rgba(239,68,68,0.12)', color: 'var(--status-danger)', border: 'rgba(239,68,68,0.3)' },
    escalated: { bg: 'rgba(6,182,212,0.12)', color: 'var(--accent-cyan)', border: 'rgba(6,182,212,0.3)' },
    checked_in: { bg: 'rgba(99,102,241,0.12)', color: 'var(--accent-primary)', border: 'rgba(99,102,241,0.3)' },
    checked_out: { bg: 'rgba(139,92,246,0.12)', color: 'var(--accent-secondary)', border: 'rgba(139,92,246,0.3)' },
    archived: { bg: 'rgba(107,114,128,0.12)', color: 'var(--text-muted)', border: 'rgba(107,114,128,0.3)' },
};

function StatusBadge({ status }) {
    const s = STATUS_COLOR[status] || STATUS_COLOR.pending;
    return (
        <span style={{
            display: 'inline-flex', alignItems: 'center', padding: '3px 10px', borderRadius: 9999,
            fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.5px',
            background: s.bg, color: s.color, border: `1px solid ${s.border}`
        }}>
            {status.replace(/_/g, ' ')}
        </span>
    );
}

function TabBtn({ active, onClick, children, badge }) {
    return (
        <button onClick={onClick} style={{
            background: active ? 'rgba(255,255,255,0.2)' : 'transparent',
            border: 'none',
            borderLeft: active ? '3px solid #ffffff' : '3px solid transparent',
            color: '#ffffff',
            fontWeight: active ? 700 : 500, fontSize: 13, padding: '10px 14px',
            cursor: 'pointer', whiteSpace: 'nowrap', transition: 'all 0.2s',
            display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%',
            borderRadius: active ? '0 8px 8px 0' : '0',
        }} onMouseEnter={e => { if (!active) { e.currentTarget.style.background = 'rgba(255,255,255,0.12)'; } }} onMouseLeave={e => { if (!active) { e.currentTarget.style.background = 'transparent'; } }}>
            <span>{children}</span>
            {badge > 0 && <span style={{ background: '#f59e0b', color: '#fff', borderRadius: 10, padding: '1px 7px', fontSize: 10, fontWeight: 800 }}>{badge}</span>}
        </button>
    );
}

function QrAutoRetry({ visitId, onReload }) {
    const [attempts, setAttempts] = useState(0);
    const [loading, setLoading] = useState(true);

    const retry = useCallback(() => {
        setLoading(true);
        onReload();
        const timer = setTimeout(() => {
            setLoading(false);
            setAttempts(a => a + 1);
        }, 2000);
        return () => clearTimeout(timer);
    }, [onReload]);

    useEffect(() => {
        if (attempts === 0) {
            const timer = setTimeout(() => {
                setLoading(false);
                setAttempts(1);
            }, 2000);
            return () => clearTimeout(timer);
        }
    }, []);

    if (attempts >= 5) {
        return (
            <div>
                <AppIcon name="warning" size={24} />
                <div style={{ fontSize: 13, fontWeight: 700, marginTop: 8, marginBottom: 4 }}>QR Token Temporarily Unavailable</div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 12 }}>Could not generate QR token after {attempts} attempts. Please try again later.</div>
                <button onClick={() => { setAttempts(0); setLoading(true); retry(); }} className="btn btn-primary" style={{ fontSize: 12 }}>
                    <AppIcon name="refresh" size={14} /> Try Again
                </button>
            </div>
        );
    }

    return (
        <div>
            <div style={{ width: 24, height: 24, border: '3px solid rgba(99,102,241,0.3)', borderTopColor: 'var(--accent-primary)', borderRadius: '50%', animation: 'spin 1s linear infinite', margin: '0 auto 12px' }} />
            <div style={{ fontSize: 13, fontWeight: 700, marginBottom: 4 }}>Generating QR Token...</div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 12 }}>Attempt {attempts + 1} of 5 — your QR code is being created.</div>
        </div>
    );
}

const TABS = [
    { key: 'overview', label: ' Overview' },
    { key: 'request', label: ' New Request' },
    { key: 'history', label: ' Visit History' },
    { key: 'upcoming', label: ' Upcoming' },
    { key: 'qrpass', label: ' QR Pass' },
    { key: 'feedback', label: ' Feedback' },
    { key: 'workflow', label: ' How It Works' },
    { key: 'messages', label: ' Messages' },
];

export default function VisitorDashboard({ requests, categories, companies, branches, hosts, departments, stats, notifications, unread_count, section, is_representative, company_visits, messages_inbox, messages_sent, messages_recipients, messages_unread_count, reschedule_requests }) {
    const { auth, app_timezone, flash } = usePage().props;
    const visitor = auth.visitor;
    const tz = app_timezone || 'Africa/Addis_Ababa';

    const [tab, setTab] = useState(section || 'overview');
    const [sidebarOpen, setSidebarOpen] = useState(true);
    const [selFeedbackId, setSelFeedbackId] = useState(null);
    const [activeBadge, setActiveBadge] = useState(null);
    const [editOpen, setEditOpen] = useState({});
    const [editState, setEditState] = useState({});
    const [selDept, setSelDept] = useState('');
    const [histFilter, setHistFilter] = useState('all');
    const [reqVisitType, setReqVisitType] = useState('individual');
    const [rescheduleModal, setRescheduleModal] = useState(null);
    const [rescheduleForm, setRescheduleForm] = useState({ new_requested_time: '', new_duration: 60, reason: '' });

    // Pending reschedule requests keyed by visit id so cards can show status.
    const pendingRescheduleByVisit = (reschedule_requests || []).reduce((acc, r) => {
        if (r.status === 'pending') acc[r.visit_request_id] = r;
        return acc;
    }, {});

    // ── Profile form — pre-filled from registered data ──
    const profileForm = useForm({
        name: visitor?.name || '',
        first_name: visitor?.first_name || '',
        last_name: visitor?.last_name || '',
        email: visitor?.email || '',
        id_document_no: visitor?.id_document_no || '',
        phone: visitor?.phone || '',
        age: visitor?.age || '',
        sex: visitor?.sex || '',
        company_position: visitor?.company_position || '',
        password: '',
        password_confirmation: '',
    });
    const [photoFile, setPhotoFile] = useState(null);
    const [photoPreview, setPhotoPreview] = useState(null);

    // ── New request form (pre-fill from logged-in visitor) ──
    const reqForm = useForm({
        visitor_name: visitor?.name || '',
        id_document_no: visitor?.id_document_no || '',
        visitor_email: visitor?.email || '',
        visitor_phone: visitor?.phone || '',
        visitor_category_id: '', company_id: '', branch_id: '', purpose: '', customPurpose: '', requested_time: '', duration: 60, visit_type: 'individual',
        host_id: '', meeting_room_id: '',
        company_name: '', company_email: '', company_phone: '', company_address: '', company_website: '',
        representative_gender: '', representative_nationality: '', representative_position: '',
        visitors: [],
    });

    // ── Company group members state ──
    const emptyMember = () => ({ name: '', id_document_no: '', email: '', phone: '', gender: '', nationality: '', position: '' });
    const [companyVisitors, setCompanyVisitors] = useState([emptyMember()]);
    const addCompanyVisitor = () => setCompanyVisitors(p => [...p, emptyMember()]);
    const removeCompanyVisitor = (i) => setCompanyVisitors(p => p.filter((_, idx) => idx !== i));
    const updateCompanyVisitor = (i, field, val) => setCompanyVisitors(p => p.map((v, idx) => idx === i ? { ...v, [field]: val } : v));

    // ── Feedback form ──
    const feedbackForm = useForm({ visit_request_id: '', rating: 5, comments: '' });

    // Filtered hosts by dept
    const filteredHosts = useMemo(() =>
        selDept ? hosts.filter(h => h.department?.id === Number(selDept)) : hosts,
        [hosts, selDept]);

    // Branches belonging to the selected company — same data + filtering logic as
    // the Public Request Visit form (filter the flat branches list by company_id).
    const filteredBranches = useMemo(() => {
        if (!reqForm.data.company_id) return [];
        return (branches || []).filter(b => String(b.company_id) === String(reqForm.data.company_id));
    }, [branches, reqForm.data.company_id]);

    // Merge groupMembers from company_visits prop (backend-enriched) into requests as fallback
    const enrichedRequests = useMemo(() => {
        if (!company_visits || company_visits.length === 0) return requests;
        return requests.map(r => {
            if (r.visit_type === 'company' && (!r.groupMembers || r.groupMembers.length === 0)) {
                const cv = company_visits.find(c => c.id === r.id);
                if (cv && cv.groupMembers && cv.groupMembers.length > 0) {
                    return { ...r, groupMembers: cv.groupMembers };
                }
            }
            return r;
        });
    }, [requests, company_visits]);

    // History filter
    const filteredHistory = useMemo(() => {
        if (histFilter === 'all') return enrichedRequests;
        if (histFilter === 'upcoming') return enrichedRequests.filter(r => r.status === 'approved' && new Date(r.requested_time) > new Date());
        if (histFilter === 'completed') return enrichedRequests.filter(r => ['checked_out', 'archived'].includes(r.status));
        return enrichedRequests.filter(r => r.status === histFilter);
    }, [enrichedRequests, histFilter]);

    // Approved visits with QR, AND approved visits missing QR (so regenerate button shows).
    // Visits whose expired QR pass was deleted are excluded entirely (completely removed).
    const qrVisits = enrichedRequests.filter(r => !r.qr_deleted_at && ((r.status === 'approved') || (r.status === 'checked_in' && (r.exit_qr_token || (r.visit_type === 'company' && r.groupMembers?.length > 0)))));

    // Company/group visits where this visitor is the representative
    const companyVisits = enrichedRequests.filter(r => r.visit_type === 'company' && r.groupMembers && r.groupMembers.length > 0);

    // Visits ready for feedback
    const feedbackReady = enrichedRequests.filter(r => r.status === 'checked_out' && !r.feedback);

    const [reqPastError, setReqPastError] = useState('');

    const handleReq = (e) => {
        e.preventDefault();
        setReqPastError('');
        if (!reqForm.data.requested_time) { setReqPastError('Please select a date and time.'); return; }
        const selected = new Date(reqForm.data.requested_time);
        if (isNaN(selected.getTime()) || selected <= new Date()) { setReqPastError('Please select a future date and time.'); return; }
        const serverTime = localToServerDatetime(reqForm.data.requested_time, tz);
        reqForm.setData('requested_time', serverTime);
        // For 'Other' purpose, use the typed custom purpose
        if (reqForm.data.purpose === 'Other') {
            reqForm.setData('purpose', reqForm.data.customPurpose);
        }
        // Sync visit_type from UI toggle
        reqForm.setData('visit_type', reqVisitType);
        // For company visits, attach group members
        if (reqVisitType === 'company') {
            reqForm.setData('visitors', companyVisitors);
        }
        reqForm.post('/request-visit', { onSuccess: () => { reqForm.reset(); reqForm.setData('visitor_name', visitor?.name || ''); reqForm.setData('id_document_no', visitor?.id_document_no || ''); reqForm.setData('visitor_email', visitor?.email || ''); reqForm.setData('visitor_phone', visitor?.phone || ''); reqForm.setData('visit_type', 'individual'); setReqVisitType('individual'); setCompanyVisitors([emptyMember()]); setTab('history'); } });
    };

    const handleProfileSave = (e) => {
        e.preventDefault();
        const fullName = [profileForm.data.first_name, profileForm.data.last_name].filter(Boolean).join(' ') || profileForm.data.name;
        const payload = { ...profileForm.data, name: fullName };
        const fd = new FormData();
        Object.entries(payload).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) fd.append(k, v); });
        if (photoFile) fd.append('profile_photo', photoFile);
        router.post('/visitor/profile/update', fd, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { setPhotoFile(null); setPhotoPreview(null); profileForm.setData('password', ''); profileForm.setData('password_confirmation', ''); },
        });
    };

    const handleFeedback = (e) => {
        e.preventDefault();
        feedbackForm.post('/visitor/feedback', {
            onSuccess: () => { feedbackForm.reset(); setSelFeedbackId(null); }
        });
    };

    const deleteExpiredQr = (id) => {
        if (!confirm('Delete this expired QR code? This pass will be permanently removed from your page.')) return;
        router.post(`/visitor/visit/${id}/delete-expired-qr`, {}, {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['requests'] }),
        });
    };

    const openFeedback = (id) => {
        setSelFeedbackId(id);
        feedbackForm.setData('visit_request_id', id);
        setTab('feedback');
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
    const cancelVisit = (id, hostName) => {
        if (!confirm(`Cancel this visit appointment${hostName ? ' with ' + hostName : ''}?`)) return;
        router.post(`/visit-requests/${id}/cancel`, {}, { preserveScroll: true });
    };

    return (
        <Layout title="Visitor Portal">
            <div className="admin-layout" style={section ? { minHeight: 'calc(100vh - 80px)' } : { display: 'flex', minHeight: 'calc(100vh - 80px)' }}>

                {!section && (
                    <div className="admin-sidebar" style={{
                        width: sidebarOpen ? 210 : 0, flexShrink: 0,
                        background: '#2563eb',
                        borderRight: sidebarOpen ? '1px solid rgba(255,255,255,0.15)' : 'none',
                        position: 'sticky', top: 68,
                        alignSelf: 'flex-start',
                        maxHeight: 'calc(100vh - 68px)',
                        overflow: sidebarOpen ? 'auto' : 'hidden',
                        borderRadius: '0 0 0 14px',
                        transition: 'width 0.25s ease',
                    }}>
                        <div style={{ padding: '10px 12px', borderBottom: '1px solid rgba(255,255,255,0.15)', display: 'flex', alignItems: 'center', justifyContent: sidebarOpen ? 'flex-end' : 'center' }}>
                            <button onClick={() => setSidebarOpen(p => !p)} style={{ background: 'rgba(255,255,255,0.2)', border: 'none', borderRadius: 8, width: 34, height: 34, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', color: '#fff', fontSize: 16, fontWeight: 700 }}><AppIcon name="chevron" /></button>
                        </div>
                        {sidebarOpen && <div style={{ padding: '16px', borderBottom: '1px solid rgba(255,255,255,0.15)' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 10 }}>
                                <label style={{ width: 38, height: 38, borderRadius: '50%', background: visitor?.photo_url ? 'none' : 'rgba(255,255,255,0.2)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 900, fontSize: 17, color: 'white', flexShrink: 0, overflow: 'hidden', cursor: 'pointer', position: 'relative' }} title="Click to change photo">
                                    {visitor?.photo_url ? <img src={`/storage/${visitor.photo_url}`} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : (visitor?.name?.charAt(0) || 'V')}
                                    <input type="file" accept="image/*" style={{ display: 'none' }} onChange={e => { const f = e.target.files[0]; if (!f) return; const fd = new FormData(); fd.append('profile_photo', f); fd.append('name', visitor?.name || ''); router.post('/visitor/profile/update', fd, { forceFormData: true, preserveScroll: true }); }} />
                                </label>
                                <div>
                                    <div style={{ fontWeight: 700, fontSize: 13, color: '#ffffff' }}>{visitor?.name}</div>
                                    <div style={{ fontSize: 11, color: 'rgba(255,255,255,0.7)' }}>Visitor</div>
                                </div>
                            </div>

                        </div>}

                        {/* Tab links */}
                        <ul style={{ listStyle: 'none', padding: '6px 8px', margin: 0, display: 'flex', flexDirection: 'column', gap: 2 }}>
                            {TABS.map(t => (
                                <li key={t.key}>
                                    <TabBtn active={tab === t.key} onClick={() => router.visit(`/visitor/section/${t.key}`)}
                                        badge={t.key === 'feedback' ? feedbackReady.length : t.key === 'qrpass' ? qrVisits.length : 0}>
                                        {t.label}
                                    </TabBtn>
                                </li>
                            ))}
                        </ul>

                        {/* Bottom */}
                        <div style={{ borderTop: '1px solid rgba(255,255,255,0.15)', padding: '12px 16px', display: 'flex', flexDirection: 'column', gap: 6 }}>
                            <button onClick={() => router.visit('/visitor/section/profile')} style={{ background: 'none', border: 'none', fontSize: 12, color: 'rgba(255,255,255,0.8)', cursor: 'pointer', textAlign: 'left', display: 'flex', alignItems: 'center', gap: 8 }}>
                                My Profile
                            </button>
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

                <div className="dashboard-content" style={{ flex: 1, padding: '28px 32px', minWidth: 0, overflow: section ? 'visible' : 'hidden' }}>
                    {section && (
                        <div style={{ marginBottom: 24, paddingBottom: 16, borderBottom: '1px solid #e5e7eb' }}>
                            <Link href="/visitor/dashboard" style={{ color: 'var(--accent-primary)', fontSize: 14, fontWeight: 600, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 6 }}>← Back to Visitor Dashboard</Link>
                        </div>
                    )}

                    {/* ══════ OVERVIEW ══════ */}
                    {tab === 'overview' && (
                        <div>
                            <div style={{ marginBottom: 28 }}>
                                <h2 style={{ fontFamily: 'var(--font-heading)', fontSize: 26, fontWeight: 800, background: 'var(--gradient-secondary)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent', marginBottom: 4 }}>
                                    Welcome, {visitor?.name}
                                </h2>
                                <p style={{ color: 'var(--text-secondary)', fontSize: 14 }}>Your visitor portal — manage requests, passes, and visit history</p>
                            </div>

                            {/* Stats */}
                            <div className="stats-grid" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', marginBottom: 28 }}>
                                {[
                                    { label: 'Total Visits', value: stats?.total || 0, color: 'cyan' },
                                    { label: 'Pending', value: stats?.pending || 0, color: 'warning' },
                                    { label: 'Approved', value: stats?.approved || 0, color: 'emerald' },
                                    { label: 'Completed', value: stats?.completed || 0, color: 'blue' },
                                    { label: 'Upcoming', value: stats?.upcoming || 0, color: 'cyan' },
                                    ...(is_representative ? [
                                        { label: 'Company Visits', value: stats?.company || 0, color: 'purple' },
                                    ] : []),
                                ].map(s => (
                                    <div key={s.label} className="stat-card" style={{ padding: '16px 20px' }}>
                                        <span className="stat-label">{s.label}</span>
                                        <span className={`stat-value ${s.color}`} style={{ fontSize: 26 }}>{s.value}</span>
                                    </div>
                                ))}
                            </div>

                            {/* ═══ Company/Group Representative Section ═══ */}
                            {companyVisits.filter(r => r.status === 'approved').length > 0 && (
                                <div style={{ marginBottom: 28 }}>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
                                        <div style={{ width: 36, height: 36, borderRadius: 10, background: 'linear-gradient(135deg, rgba(99,102,241,0.2), rgba(139,92,246,0.15))', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                            <AppIcon name="group" size={18} />
                                        </div>
                                        <div>
                                            <h3 style={{ fontFamily: 'var(--font-heading)', fontSize: 16, fontWeight: 700 }}>Company Representative</h3>
                                            <p style={{ fontSize: 11, color: 'var(--text-muted)' }}>Your approved company/group visits · {companyVisits.filter(r => r.status === 'approved').length} active</p>
                                        </div>
                                        <span style={{ marginLeft: 'auto', padding: '4px 12px', borderRadius: 8, background: 'rgba(99,102,241,0.12)', border: '1px solid rgba(99,102,241,0.3)', fontSize: 11, fontWeight: 700, color: 'var(--accent-primary)' }}>
                                            REPRESENTATIVE
                                        </span>
                                    </div>
                                    {companyVisits.filter(r => r.status === 'approved').map(r => {
                                        return (
                                            <div key={r.id} className="glass-card" style={{ marginBottom: 12, padding: 16, border: '1px solid rgba(99,102,241,0.25)', background: 'linear-gradient(135deg, rgba(99,102,241,0.06), rgba(139,92,246,0.04))' }}>
                                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                                                    <div style={{ minWidth: 0 }}>
                                                        <div style={{ fontWeight: 700, fontSize: 15, color: 'var(--text-primary)', marginBottom: 2 }}>{r.company_name || 'Company Visit'}</div>
                                                        <div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>Host: {hostOf(r).name} · {deptOf(r)}</div>
                                                        <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{fmtD(r.requested_time)} · {fmtT(r.requested_time)} · {r.duration}min</div>
                                                    </div>
                                                    <button onClick={() => { setActiveBadge(r.id); setTab('qrpass'); }} className="btn btn-primary" style={{ fontSize: 12, padding: '6px 14px', flexShrink: 0 }}>
                                                        <AppIcon name="key" size={12} /> View All QR Passes
                                                    </button>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}

                            {/* Quick actions */}
                            <div className="grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20, marginBottom: 28 }}>
                                <button onClick={() => setTab('request')} style={{ padding: '20px', borderRadius: 14, background: 'var(--gradient-primary)', border: 'none', color: 'white', cursor: 'pointer', textAlign: 'left', transition: 'transform 0.2s', display: 'flex', gap: 14, alignItems: 'center' }}
                                    onMouseEnter={e => e.currentTarget.style.transform = 'translateY(-2px)'}
                                    onMouseLeave={e => e.currentTarget.style.transform = 'none'}>
                                    <span style={{ fontSize: 32 }}><AppIcon name="calendar" /></span>
                                    <div>
                                        <div style={{ fontFamily: 'var(--font-heading)', fontWeight: 700 }}>New Visit Request</div>
                                        <div style={{ fontSize: 12, opacity: 0.8, marginTop: 4 }}>Schedule an appointment</div>
                                    </div>
                                </button>
                                <button onClick={() => setTab('qrpass')} style={{ padding: '20px', borderRadius: 14, background: 'rgba(99,102,241,0.12)', border: '1px solid rgba(99,102,241,0.3)', color: 'var(--text-primary)', cursor: 'pointer', textAlign: 'left', transition: 'transform 0.2s', display: 'flex', gap: 14, alignItems: 'center' }}
                                    onMouseEnter={e => e.currentTarget.style.transform = 'translateY(-2px)'}
                                    onMouseLeave={e => e.currentTarget.style.transform = 'none'}>
                                    <span style={{ fontSize: 32 }}><AppIcon name="key" /></span>
                                    <div>
                                        <div style={{ fontFamily: 'var(--font-heading)', fontWeight: 700 }}>My QR Passes</div>
                                        <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginTop: 4 }}>{qrVisits.length} active pass{qrVisits.length !== 1 ? 'es' : ''}</div>
                                    </div>
                                </button>
                                {requests.filter(r => r.badge).length > 0 && (
                                    <button onClick={() => { const b = requests.find(r => r.badge); if (b) printBadge(b.badge, visitor?.name, visitor?.id_document_no); }} style={{ padding: '20px', borderRadius: 14, background: 'rgba(16,185,129,0.12)', border: '1px solid rgba(16,185,129,0.3)', color: 'var(--text-primary)', cursor: 'pointer', textAlign: 'left', transition: 'transform 0.2s', display: 'flex', gap: 14, alignItems: 'center' }}
                                        onMouseEnter={e => e.currentTarget.style.transform = 'translateY(-2px)'}
                                        onMouseLeave={e => e.currentTarget.style.transform = 'none'}>
                                        <span style={{ fontSize: 32 }}><AppIcon name="download" /></span>
                                        <div>
                                            <div style={{ fontFamily: 'var(--font-heading)', fontWeight: 700 }}>Download Badge</div>
                                            <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginTop: 4 }}>Print your visitor badge</div>
                                        </div>
                                    </button>
                                )}
                            </div>

                            {/* Upcoming visits */}
                            {requests.filter(r => r.status === 'approved' && new Date(r.requested_time) > new Date()).length > 0 && (
                                <div className="glass-card" style={{ marginBottom: 24 }}>
                                    <h3 style={{ fontFamily: 'var(--font-heading)', fontSize: 16, fontWeight: 700, marginBottom: 16 }}>Upcoming Visits</h3>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                        {requests.filter(r => r.status === 'approved' && new Date(r.requested_time) > new Date()).map(r => (
                                            <div key={r.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '12px 16px', background: 'rgba(99,102,241,0.06)', borderRadius: 10, border: '1px solid rgba(99,102,241,0.15)', marginBottom: 8 }}>
                                                <div>
                                                    <div style={{ fontWeight: 700 }}>{hostOf(r).name} <span style={{ fontSize: 12, color: 'var(--text-muted)', fontWeight: 400 }}>· {deptOf(r)}</span></div>
                                                    <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginTop: 2 }}>{r.purpose}</div>
                                                </div>
                                                <div style={{ textAlign: 'right' }}>
                                                    <div style={{ fontWeight: 700, color: 'var(--accent-cyan)', fontSize: 14 }}>{fmtD(r.requested_time)}</div>
                                                    <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{fmtT(r.requested_time)} · {r.duration}min</div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Feedback needed */}
                            {feedbackReady.length > 0 && (
                                <div className="glass-card" style={{ borderColor: 'rgba(245,158,11,0.3)' }}>
                                    <h3 style={{ fontFamily: 'var(--font-heading)', fontSize: 16, fontWeight: 700, marginBottom: 16, color: 'var(--status-warning)' }}>Feedback Needed</h3>
                                    {feedbackReady.map(r => (
                                        <div key={r.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 0', borderBottom: '1px solid rgba(255,255,255,0.04)' }}>
                                            <div><div style={{ fontWeight: 600, fontSize: 14 }}>{hostOf(r).name}</div><div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>{r.purpose} · {fmtD(r.requested_time)}</div></div>
                                            <button onClick={() => openFeedback(r.id)} className="btn btn-secondary" style={{ fontSize: 12 }}>Give Feedback</button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {/* ══════ PROFILE ══════ */}
                    {tab === 'profile' && (
                        <div>
                            <h2 style={{ fontFamily: 'var(--font-heading)', fontSize: 22, fontWeight: 700, marginBottom: 24, background: 'var(--gradient-primary)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}>My Profile</h2>
                            <div className="grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24 }}>
                                {/* ── LEFT: Edit form ── */}
                                <div className="glass-card">
                                    <h3 style={{ fontFamily: 'var(--font-heading)', fontSize: 16, fontWeight: 700, marginBottom: 6 }}>Edit Information</h3>
                                    <p style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 20 }}>Update your profile details.</p>
                                    <form onSubmit={handleProfileSave} encType="multipart/form-data">
                                        {/* Profile Photo */}
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 20 }}>
                                            <div style={{ width: 72, height: 72, borderRadius: '50%', background: photoPreview ? 'none' : 'var(--gradient-secondary)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 28, color: 'white', fontWeight: 900, flexShrink: 0, overflow: 'hidden', border: '2px solid rgba(99,102,241,0.3)' }}>
                                                {photoPreview ? <img src={photoPreview} alt="Preview" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : (visitor?.name?.charAt(0) || 'V')}
                                            </div>
                                            <div>
                                                <label style={{ display: 'inline-block', padding: '6px 14px', borderRadius: 8, background: 'rgba(99,102,241,0.1)', border: '1px solid rgba(99,102,241,0.3)', color: 'var(--accent-primary)', fontSize: 12, fontWeight: 600, cursor: 'pointer' }}>
                                                    Change Photo <AppIcon name="camera" />
                                                    <input type="file" accept="image/*" style={{ display: 'none' }} onChange={e => { const f = e.target.files[0]; if (f) { setPhotoFile(f); setPhotoPreview(URL.createObjectURL(f)); } }} />
                                                </label>
                                                <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 4 }}>JPG, PNG. Max 2MB</div>
                                            </div>
                                        </div>

                                        <div style={{ height: 1, background: 'rgba(255,255,255,0.07)', margin: '16px 0' }} />

                                        {/* First Name + Last Name */}
                                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                                            <div className="form-group">
                                                <label className="form-label">First Name</label>
                                                <input type="text" className="form-input" value={profileForm.data.first_name}
                                                    onChange={e => profileForm.setData('first_name', e.target.value)} placeholder="First name" required />
                                                {profileForm.errors.first_name && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{profileForm.errors.first_name}</div>}
                                            </div>
                                            <div className="form-group">
                                                <label className="form-label">Last Name</label>
                                                <input type="text" className="form-input" value={profileForm.data.last_name}
                                                    onChange={e => profileForm.setData('last_name', e.target.value)} placeholder="Last name" required />
                                                {profileForm.errors.last_name && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{profileForm.errors.last_name}</div>}
                                            </div>
                                        </div>

                                        {/* Gender + Age */}
                                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                                            <div className="form-group">
                                                <label className="form-label">Gender</label>
                                                <select className="form-select" value={profileForm.data.sex}
                                                    onChange={e => profileForm.setData('sex', e.target.value)} required>
                                                    <option value="">Select...</option>
                                                    <option value="male">Male</option>
                                                    <option value="female">Female</option>
                                                </select>
                                                {profileForm.errors.sex && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{profileForm.errors.sex}</div>}
                                            </div>
                                            <div className="form-group">
                                                <label className="form-label">Age</label>
                                                <input type="number" className="form-input" value={profileForm.data.age}
                                                    onChange={e => profileForm.setData('age', e.target.value)} min={16} max={120} placeholder="Age" required />
                                                {profileForm.errors.age && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{profileForm.errors.age}</div>}
                                            </div>
                                        </div>

                                        {/* Email + Phone */}
                                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                                            <div className="form-group">
                                                <label className="form-label">Email Address</label>
                                                <input type="email" className="form-input" value={profileForm.data.email}
                                                    onChange={e => profileForm.setData('email', e.target.value)} required />
                                                {profileForm.errors.email && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{profileForm.errors.email}</div>}
                                            </div>
                                            <div className="form-group">
                                                <label className="form-label">Phone Number</label>
                                                <input type="tel" className="form-input" value={profileForm.data.phone}
                                                    onChange={e => profileForm.setData('phone', e.target.value)}
                                                    placeholder="e.g. +251 91 234 5678" required />
                                                {profileForm.errors.phone && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{profileForm.errors.phone}</div>}
                                            </div>
                                        </div>

                                        {/* National ID / Passport */}
                                        <div className="form-group">
                                            <label className="form-label">National ID / Passport Number</label>
                                            <input type="text" className="form-input" value={profileForm.data.id_document_no}
                                                onChange={e => { const v = e.target.value.replace(/\D/g,'').slice(0,6); profileForm.setData('id_document_no', v); }}
                                                maxLength={6} inputMode="numeric" required />
                                            {profileForm.errors.id_document_no && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{profileForm.errors.id_document_no}</div>}
                                        </div>

                                        {/* Company Position (Optional) */}
                                        <div className="form-group">
                                            <label className="form-label">Company Position (Optional)</label>
                                            <input type="text" className="form-input" value={profileForm.data.company_position}
                                                onChange={e => profileForm.setData('company_position', e.target.value)}
                                                placeholder="e.g. Sales Manager" />
                                            {profileForm.errors.company_position && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{profileForm.errors.company_position}</div>}
                                        </div>

                                        <div style={{ height: 1, background: 'rgba(255,255,255,0.07)', margin: '16px 0' }} />

                                        {/* Password */}
                                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                                            <div className="form-group">
                                                <label className="form-label">Password</label>
                                                <PasswordInput value={profileForm.data.password}
                                                    onChange={e => profileForm.setData('password', e.target.value)} placeholder="Leave blank to keep current" />
                                                {profileForm.errors.password && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{profileForm.errors.password}</div>}
                                            </div>
                                            <div className="form-group">
                                                <label className="form-label">Confirm Password</label>
                                                <PasswordInput value={profileForm.data.password_confirmation}
                                                    onChange={e => profileForm.setData('password_confirmation', e.target.value)} placeholder="Leave blank to keep current" />
                                            </div>
                                        </div>

                                        <button type="submit" className="btn btn-primary" style={{ width: '100%', padding: '11px' }} disabled={profileForm.processing}>
                                            {profileForm.processing ? 'Saving...' : <><AppIcon name="save" /> Save Changes</>}
                                        </button>
                                    </form>
                                </div>

                                {/* ── RIGHT: Summary + completeness ── */}
                                <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                                    {/* Current registered data summary */}
                                    <div className="glass-card">
                                        <h3 style={{ fontFamily: 'var(--font-heading)', fontSize: 16, fontWeight: 700, marginBottom: 16 }}>Registered Information</h3>
                                        <div style={{ display: 'flex', flexDirection: 'column', gap: 0 }}>
                                            {[
                                                { label: 'Full Name', value: visitor?.name, icon: 'user' },
                                                { label: 'Email', value: visitor?.email, icon: 'mail' },
                                                { label: 'Phone', value: visitor?.phone || '—', icon: 'smartphone' },
                                                { label: 'ID Document', value: visitor?.id_document_no, icon: 'creditcard' },
                                                { label: 'Age', value: visitor?.age || '—', icon: 'calendar' },
                                                { label: 'Sex', value: visitor?.sex || '—', icon: 'user' },
                                                { label: 'Position', value: visitor?.company_position || '—', icon: 'briefcase' },
                                            ].map((f, i) => (
                                                <div key={i} style={{ display: 'flex', gap: 12, alignItems: 'center', padding: '10px 0', borderBottom: '1px solid rgba(255,255,255,0.05)' }}>
                                                    <span style={{ fontSize: 18, width: 28, textAlign: 'center', flexShrink: 0 }}><AppIcon name={f.icon} /></span>
                                                    <div style={{ flex: 1 }}>
                                                        <div style={{ fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.5px', marginBottom: 1 }}>{f.label}</div>
                                                        <div style={{ fontSize: 14, fontWeight: 600 }}>{f.value}</div>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>

                    )

                    /* ══════ NEW REQUEST ══════ */}
                    {tab === 'request' && (
                        <div>
                            <h2 style={{ fontFamily: 'var(--font-heading)', fontSize: 22, fontWeight: 700, marginBottom: 24, background: 'var(--gradient-primary)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}>New Visit Request</h2>
                            <div style={{ display: 'grid', gridTemplateColumns: '1.4fr 0.6fr', gap: 24 }}>
                                <div className="glass-card">
                                    <form onSubmit={handleReq}>
                                        {((flash?.error) || Object.keys(reqForm.errors || {}).length > 0) && (
                                            <div style={{ background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.35)', color: '#f87171', borderRadius: 10, padding: '12px 14px', marginBottom: 18, fontSize: 13, fontWeight: 600 }}>
                                                {flash?.error && <div style={{ marginBottom: 6 }}>{flash.error}</div>}
                                                {Object.entries(reqForm.errors || {}).map(([k, v]) => (
                                                    <div key={k} style={{ marginBottom: 4 }}>• {v}</div>
                                                ))}
                                            </div>
                                        )}
                                        {/* Step 0: Visit Type */}
                                        <div className="form-group">
                                            <label className="form-label">Step 1 — Visit Type</label>
                                            <div style={{ display: 'flex', gap: 12 }}>
                                                {[{ key: 'individual', label: ' Individual Visit' }, { key: 'company', label: ' Company / Group Visit' }].map(({ key, label }) => (
                                                    <button key={key} type="button" onClick={() => { setReqVisitType(key); reqForm.setData('visit_type', key); }}
                                                        style={{
                                                            flex: 1, padding: '10px 16px', borderRadius: 10, fontSize: 12, border: '2px solid', cursor: 'pointer', transition: 'all 0.2s',
                                                            background: reqVisitType === key ? 'var(--gradient-primary)' : 'rgba(255,255,255,0.03)',
                                                            color: reqVisitType === key ? 'white' : 'var(--text-secondary)',
                                                            borderColor: reqVisitType === key ? 'transparent' : 'rgba(255,255,255,0.1)',
                                                        }}>
                                                        {label}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>

                                        {/* Visit Category */}
                                        <div className="form-group">
                                            <label className="form-label">Visit Category</label>
                                            <select className="form-select" value={reqForm.data.visitor_category_id}
                                                onChange={e => reqForm.setData('visitor_category_id', e.target.value)}>
                                                <option value="">— Select Category —</option>
                                                {(categories || []).map(c => (
                                                    <option key={c.id} value={c.id}>{c.name}</option>
                                                ))}
                                            </select>
                                            {reqForm.errors.visitor_category_id && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{reqForm.errors.visitor_category_id}</div>}
                                        </div>

                                        {/* Company */}
                                        {companies?.length > 0 && (
                                            <div className="form-group">
                                                <label className="form-label">Company</label>
                                                <select className="form-select" value={reqForm.data.company_id}
                                                    onChange={e => { reqForm.setData('company_id', e.target.value); reqForm.setData('branch_id', ''); reqForm.setData('host_id', ''); }}>
                                                    <option value="">— Select Company —</option>
                                                    {companies.map(c => (
                                                        <option key={c.company_id} value={c.company_id}>{c.company_name}</option>
                                                    ))}
                                                </select>
                                                {reqForm.errors.company_id && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{reqForm.errors.company_id}</div>}
                                            </div>
                                        )}

                                        {/* Branch — only shown for companies that have branches */}
                                        {filteredBranches.length > 0 && (
                                            <div className="form-group">
                                                <label className="form-label">Branch</label>
                                                <select className="form-select" value={reqForm.data.branch_id}
                                                    onChange={e => reqForm.setData('branch_id', e.target.value)}>
                                                    <option value="">— Select Branch —</option>
                                                    {filteredBranches.map(b => (
                                                        <option key={b.id} value={b.id}>{b.name}</option>
                                                    ))}
                                                </select>
                                                {reqForm.errors.branch_id && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{reqForm.errors.branch_id}</div>}
                                            </div>
                                        )}

                                        {/* Department & Host — auto-assigned by backend based on purpose */}
                                        <div className="form-group" style={{ display: 'none' }}>
                                            <select className="form-select" value={selDept} onChange={e => { setSelDept(e.target.value); reqForm.setData('host_id', ''); }}>
                                                <option value="">All Departments</option>
                                                {(departments || []).map(d => <option key={d.id} value={d.id}>{d.name} ({d.host_count} hosts)</option>)}
                                            </select>
                                        </div>

                                        <div className="form-group" style={{ display: 'none' }}>
                                            <select className="form-select" value={reqForm.data.host_id} onChange={e => reqForm.setData('host_id', e.target.value)}>
                                                <option value="">Auto-assigned</option>
                                                {filteredHosts.map(h => <option key={h.id} value={h.id}>{h.name} — {h.department?.name || '—'}</option>)}
                                            </select>
                                        </div>

                                        {/* Purpose (auto-assigns dept + host) */}
                                        <div className="form-group">
                                            <label className="form-label">Step 2 — Purpose of Visit</label>
                                            <select className="form-select" value={reqForm.data.purpose && !['Business Meeting', 'Job Interview', 'Delivery', 'Consultation', 'Client Visit', 'Government Affairs', 'Other'].includes(reqForm.data.purpose) ? 'Other' : reqForm.data.purpose} onChange={e => reqForm.setData('purpose', e.target.value)} required>
                                                <option value="">Select Purpose</option>
                                                {['Business Meeting', 'Job Interview', 'Delivery', 'Consultation', 'Client Visit', 'Government Affairs', 'Other'].map(p => (
                                                    <option key={p} value={p}>{p}</option>
                                                ))}
                                            </select>
                                            {reqForm.data.purpose === 'Other' && (
                                                <input type="text" className="form-input" style={{ marginTop: 8 }} placeholder="Type custom purpose..." value={reqForm.data.customPurpose} onChange={e => reqForm.setData('customPurpose', e.target.value)} required />
                                            )}
                                            {reqForm.errors.purpose && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{reqForm.errors.purpose}</div>}
                                        </div>

                                        {/* Date & Time */}
                                        <div className="form-group">
                                            <label className="form-label">Step 3 — Date & Time</label>
                                            <input type="datetime-local" className="form-input" value={reqForm.data.requested_time} onChange={e => { reqForm.setData('requested_time', e.target.value); setReqPastError(''); }} min={getMinDatetimeLocal()} required />
                                            {reqPastError && <div style={{ marginTop: '8px', fontSize: 12, color: 'var(--status-danger)', padding: '6px 10px', background: 'rgba(239,68,68,0.08)', borderRadius: 4, border: '1px solid rgba(239,68,68,0.3)' }}>{reqPastError}</div>}
                                            {reqForm.errors.requested_time && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{reqForm.errors.requested_time}</div>}
                                        </div>

                                        {/* Duration */}
                                        <div className="form-group">
                                            <label className="form-label">Step 4 — Duration</label>
                                            <select className="form-select" value={reqForm.data.duration} onChange={e => reqForm.setData('duration', Number(e.target.value))} required>
                                                {[[15, '15 min'], [30, '30 min'], [60, '1 hour'], [120, '2 hours'], [240, '4 hours']].map(([v, l]) => (
                                                    <option key={v} value={v}>{l}</option>
                                                ))}
                                            </select>
                                        </div>

                                        {/* ── Company / Group Visit Fields ── */}
                                        {reqVisitType === 'company' && (
                                            <>
                                                <h4 style={{ color: 'var(--accent-cyan)', fontFamily: 'var(--font-heading)', marginBottom: '16px', marginTop: '24px', borderBottom: '1px solid var(--glass-border)', paddingBottom: '8px' }}>Company Details</h4>
                                                <div className="form-group">
                                                    <label className="form-label">Company Name</label>
                                                    <input type="text" className="form-input" value={reqForm.data.company_name} onChange={e => reqForm.setData('company_name', e.target.value)} placeholder="Company or organization name" required />
                                                    {reqForm.errors.company_name && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{reqForm.errors.company_name}</div>}
                                                </div>
                                                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                                                    <div className="form-group">
                                                        <label className="form-label">Company Email</label>
                                                        <input type="email" className="form-input" value={reqForm.data.company_email} onChange={e => reqForm.setData('company_email', e.target.value)} placeholder="company@example.com" required />
                                                        {reqForm.errors.company_email && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{reqForm.errors.company_email}</div>}
                                                    </div>
                                                    <div className="form-group">
                                                        <label className="form-label">Company Phone</label>
                                                        <input type="tel" className="form-input" value={reqForm.data.company_phone} onChange={e => reqForm.setData('company_phone', e.target.value)} placeholder="+251 911 234 567" required />
                                                        {reqForm.errors.company_phone && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{reqForm.errors.company_phone}</div>}
                                                    </div>
                                                </div>
                                                <div className="form-group">
                                                    <label className="form-label">Company Address</label>
                                                    <input type="text" className="form-input" value={reqForm.data.company_address} onChange={e => reqForm.setData('company_address', e.target.value)} placeholder="Full company address" required />
                                                    {reqForm.errors.company_address && <div style={{ color: 'var(--status-danger)', fontSize: 12, marginTop: 4 }}>{reqForm.errors.company_address}</div>}
                                                </div>
                                                <div className="form-group">
                                                    <label className="form-label">Company Website <span style={{ color: 'var(--text-muted)', fontWeight: 400 }}>(optional)</span></label>
                                                    <input type="url" className="form-input" value={reqForm.data.company_website} onChange={e => reqForm.setData('company_website', e.target.value)} placeholder="https://example.com" />
                                                </div>

                                                {/* Representative Info (auto-filled from logged-in visitor) */}
                                                <h4 style={{ color: 'var(--accent-cyan)', fontFamily: 'var(--font-heading)', marginBottom: '16px', marginTop: '24px', borderBottom: '1px solid var(--glass-border)', paddingBottom: '8px' }}>Representative Information</h4>

                                                {/* Group Members */}
                                                <h4 style={{ color: 'var(--accent-cyan)', fontFamily: 'var(--font-heading)', marginBottom: '16px', marginTop: '24px', borderBottom: '1px solid var(--glass-border)', paddingBottom: '8px' }}>Group Members</h4>
                                                <p style={{ fontSize: 12, color: 'var(--text-secondary)', marginBottom: 16, lineHeight: 1.5 }}>Add each visitor who will be part of this group visit. Each member gets their own QR entry token.</p>
                                                {companyVisitors.map((v, idx) => (
                                                    <div key={idx} style={{ padding: 16, marginBottom: 12, borderRadius: 10, background: 'rgba(255,255,255,0.02)', border: '1px solid rgba(255,255,255,0.06)' }}>
                                                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
                                                            <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--accent-cyan)' }}>Visitor {idx + 1}</span>
                                                            {companyVisitors.length > 1 && <button type="button" onClick={() => removeCompanyVisitor(idx)} style={{ background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.2)', borderRadius: 6, padding: '4px 10px', color: '#f87171', fontSize: 11, cursor: 'pointer', fontWeight: 600 }}>Remove</button>}
                                                        </div>
                                                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                                                            <div className="form-group"><label className="form-label">Full Name</label><input type="text" className="form-input" value={v.name} onChange={e => updateCompanyVisitor(idx, 'name', e.target.value)} required /></div>
                                                            <div className="form-group"><label className="form-label">ID Document No</label><input type="text" className="form-input" value={v.id_document_no} onChange={e => updateCompanyVisitor(idx, 'id_document_no', e.target.value.replace(/\D/g, '').slice(0, 6))} placeholder="6-digit" maxLength={6} inputMode="numeric" required /></div>
                                                        </div>
                                                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                                                            <div className="form-group"><label className="form-label">Email</label><input type="email" className="form-input" value={v.email} onChange={e => updateCompanyVisitor(idx, 'email', e.target.value)} required /></div>
                                                            <div className="form-group"><label className="form-label">Phone</label><input type="tel" className="form-input" value={v.phone} onChange={e => updateCompanyVisitor(idx, 'phone', e.target.value)} required /></div>
                                                        </div>
                                                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12 }}>
                                                            <div className="form-group"><label className="form-label">Gender</label><select className="form-select" value={v.gender} onChange={e => updateCompanyVisitor(idx, 'gender', e.target.value)} required><option value="">Select</option><option value="male">Male</option><option value="female">Female</option></select></div>
                                                            <div className="form-group"><label className="form-label">Nationality</label><input type="text" className="form-input" value={v.nationality} onChange={e => updateCompanyVisitor(idx, 'nationality', e.target.value)} placeholder="e.g. Ethiopian" required /></div>
                                                            <div className="form-group"><label className="form-label">Position <span style={{ color: 'var(--text-muted)', fontWeight: 400 }}>(opt)</span></label><input type="text" className="form-input" value={v.position} onChange={e => updateCompanyVisitor(idx, 'position', e.target.value)} placeholder="Optional" /></div>
                                                        </div>
                                                    </div>
                                                ))}
                                                <button type="button" onClick={addCompanyVisitor} style={{ width: '100%', padding: 10, borderRadius: 10, border: '2px dashed rgba(0,240,255,0.25)', background: 'rgba(0,240,255,0.03)', color: 'var(--accent-cyan)', fontSize: 13, fontWeight: 600, cursor: 'pointer', marginBottom: 8 }}>+ Add Visitor</button>
                                            </>
                                        )}

                                        {/* ── Attach Documents ── */}
                                        <h4 style={{ color: 'var(--accent-cyan)', fontFamily: 'var(--font-heading)', marginBottom: '16px', marginTop: '24px', borderBottom: '1px solid var(--glass-border)', paddingBottom: '8px' }}>
                                            Attach Documents
                                        </h4>
                                        <p style={{ fontSize: '12px', color: 'var(--text-secondary)', marginBottom: '16px', lineHeight: 1.5 }}>
                                            Upload supporting documents (invitation letter, government letter, etc.) to help expedite your visit approval.
                                        </p>

                                        <div className="form-group" style={{ marginBottom: '12px' }}>
                                            <label className="form-label">Select Document Type</label>
                                            <select className="form-select" id="dashDocTypeSelect">
                                                <option value="">Choose document type...</option>
                                                <option value="invitation_letter">Invitation Letter</option>
                                                {reqVisitType === 'company' && <option value="visitor_list">Visitor List File</option>}
                                                <option value="government_letter">Government Letter</option>
                                                <option value="purchase_order">Purchase Order</option>
                                                <option value="nda">NDA</option>
                                                <option value="other_docs">Other Documents</option>
                                            </select>
                                        </div>

                                        <div className="form-group" style={{ marginBottom: '12px' }}>
                                            <label className="form-label">Upload File</label>
                                            <input type="file" className="form-input" id="dashDocFileInput" style={{ fontSize: '12px', padding: '8px 12px' }}
                                                accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" />
                                        </div>

                                        <button type="button" onClick={() => {
                                            const sel = document.getElementById('dashDocTypeSelect');
                                            const fileInput = document.getElementById('dashDocFileInput');
                                            if (!sel.value || !fileInput.files[0]) return;
                                            reqForm.setData(sel.value, fileInput.files[0]);
                                            sel.value = '';
                                            fileInput.value = '';
                                        }} style={{
                                            padding: '8px 16px', borderRadius: '8px',
                                            border: '1px solid rgba(0,240,255,0.25)', background: 'rgba(0,240,255,0.05)',
                                            color: 'var(--accent-cyan)', fontSize: '12px', fontWeight: 600,
                                            cursor: 'pointer', marginBottom: '16px',
                                        }}>
                                            + Attach File
                                        </button>

                                        {Object.entries(reqForm.data).filter(([k]) => ['invitation_letter', 'government_letter', 'purchase_order', 'nda', 'other_docs'].includes(k) && reqForm.data[k]).map(([k, file]) => (
                                            <div key={k} style={{
                                                display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                                                padding: '6px 12px', marginBottom: '6px', borderRadius: '6px',
                                                background: 'rgba(16,185,129,0.06)', border: '1px solid rgba(16,185,129,0.15)',
                                            }}>
                                                <span style={{ fontSize: '12px', color: '#10b981' }}> {file.name}</span>
                                                <button type="button" onClick={() => reqForm.setData(k, null)} style={{
                                                    background: 'none', border: 'none', color: '#f87171',
                                                    fontSize: '12px', cursor: 'pointer', fontWeight: 700,
                                                }}></button>
                                            </div>
                                        ))}

                                        <button type="submit" className="btn btn-primary" style={{ width: '100%', padding: '12px', marginTop: 8 }} disabled={reqForm.processing}>
                                            {reqForm.processing ? <><AppIcon name="upload" /> Submitting...</> : <><AppIcon name="upload" /> Submit Visit Request</>}
                                        </button>
                                    </form>
                                </div>

                                {/* Info sidebar */}
                                <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                                    <div className="glass-card" style={{ background: 'rgba(99,102,241,0.07)' }}>
                                        <h4 style={{ fontWeight: 700, fontSize: 14, marginBottom: 12 }}>How It Works</h4>
                                        {['Request submitted to host', 'Host reviews & approves', 'You receive a QR pass', 'Arrive & show QR to security', 'Check in & meet your host'].map((s, i) => (
                                            <div key={i} style={{ display: 'flex', gap: 10, alignItems: 'flex-start', padding: '6px 0', borderBottom: '1px solid rgba(255,255,255,0.04)' }}>
                                                <span style={{ width: 22, height: 22, borderRadius: '50%', background: 'var(--gradient-primary)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 800, color: 'white', flexShrink: 0 }}>{i + 1}</span>
                                                <span style={{ fontSize: 12, color: 'var(--text-secondary)', lineHeight: 1.5 }}>{s}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ══════ HISTORY ══════ */}
                    {tab === 'history' && (
                        <div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 20 }}>
                                <h2 style={{ fontFamily: 'var(--font-heading)', fontSize: 22, fontWeight: 700, background: 'var(--gradient-primary)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}>Visit History</h2>
                                {requests.filter(r => ['checked_out', 'archived', 'rejected'].includes(r.status)).length > 0 && (
                                    <button
                                        onClick={() => {
                                            if (!confirm('Delete ALL completed visits from your history? This permanently removes the visits, QR passes, and all related records. This cannot be undone.')) return;
                                            router.delete('/visit-requests/history/delete', { preserveScroll: true });
                                        }}
                                        className="btn btn-secondary"
                                        style={{ fontSize: 11, padding: '4px 10px', color: 'var(--status-danger)', borderColor: 'rgba(239,68,68,0.3)' }}
                                    >
                                        <AppIcon name="trash" size={11} /> Delete All
                                    </button>
                                )}
                            </div>
                            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 20 }}>
                                {[['all', 'All'], ['pending', 'Pending'], ['approved', 'Approved'], ['checked_in', 'Active'], ['checked_out', 'Completed'], ['rejected', 'Rejected']].map(([k, l]) => (
                                    <button key={k} onClick={() => setHistFilter(k)} style={{
                                        padding: '5px 14px', borderRadius: 20, fontSize: 12, border: '1px solid', cursor: 'pointer', transition: 'all 0.2s',
                                        background: histFilter === k ? 'var(--gradient-primary)' : 'rgba(255,255,255,0.04)',
                                        color: histFilter === k ? 'white' : 'var(--text-secondary)',
                                        borderColor: histFilter === k ? 'transparent' : 'rgba(255,255,255,0.1)',
                                    }}>{l} {k !== 'all' && `(${requests.filter(r => r.status === k).length})`}</button>
                                ))}
                            </div>

                            {filteredHistory.length === 0 ? (
                                <div className="glass-card" style={{ textAlign: 'center', padding: 48 }}>
                                    <div style={{ fontSize: 48, marginBottom: 16 }}><AppIcon name="clipboard" size={48} /></div>
                                    <p style={{ color: 'var(--text-muted)' }}>No visits in this category.</p>
                                    <button onClick={() => setTab('request')} className="btn btn-primary" style={{ marginTop: 16 }}>Request a Visit</button>
                                </div>
                            ) : (
                                <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                                    {filteredHistory.map(r => (
                                        <div key={r.id} className="glass-card" style={{ padding: '18px 22px' }}>
                                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16 }}>
                                                <div style={{ flex: 1, minWidth: 0 }}>
                                                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', marginBottom: 6 }}>
                                                        <span style={{ fontWeight: 700, fontSize: 15 }}>{hostOf(r).name}</span>
                                                        <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>· {deptOf(r)}</span>
                                                        <StatusBadge status={displayStatus(r.status)} />
                                                        {r.is_vip && <span style={{ fontSize: 11, color: '#ec4899' }}>VIP</span>}
                                                        {r.purpose && <span style={{ fontSize: 12, color: 'var(--text-muted)' }}> → {r.purpose}</span>}
                                                    </div>
                                                    {r.comments && <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginTop: 4 }}>{r.comments}</div>}
                                                    {formatLocation(r.building, r.floor, r.meeting_room_id) && (
                                                        <div style={{ fontSize: 12, color: 'var(--status-info)', marginTop: 4 }}><AppIcon name="building" size={12} /> {formatLocation(r.building, r.floor, r.meeting_room_id)}</div>
                                                    )}
                                                    {r.feedback && <div style={{ fontSize: 12, color: 'var(--status-warning)', marginTop: 4 }}>{[...Array(r.feedback.rating)].map((_, i) => <AppIcon key={i} name="star" size={12} />)} {r.feedback.comments}</div>}
                                                </div>
                                                <div style={{ display: 'flex', flexDirection: 'column', gap: 8, alignItems: 'flex-end', flexShrink: 0 }}>
                                                    <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>#{r.id}</span>
                                                    {r.status === 'approved' && r.qr_token && (
                                                        <button onClick={() => { setActiveBadge(r.id); setTab('qrpass'); }} className="btn btn-primary" style={{ fontSize: 11, padding: '4px 10px' }}>View QR</button>
                                                    )}
                                                    {['pending', 'approved'].includes(r.status) && (
                                                        <button onClick={() => { setRescheduleModal(r); setRescheduleForm({ new_requested_time: toLocalDatetimeInput(r.requested_time) || '', new_duration: r.duration || 60, reason: '' }); }} className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 10px' }}>
                                                            <AppIcon name="calendar" size={12} /> Request Reschedule
                                                        </button>
                                                    )}
                                                    {r.status === 'pending' && (
                                                        <button onClick={() => toggleEdit(r.id, r.requested_time, r.duration)} style={{ background: 'none', border: '1px solid rgba(255,255,255,0.1)', color: 'var(--text-muted)', fontSize: 11, padding: '3px 10px', borderRadius: 6, cursor: 'pointer' }}>
                                                            Edit Time
                                                        </button>
                                                    )}
                                                    {['pending', 'approved'].includes(r.status) && new Date(r.requested_time) > new Date() && (
                                                        <button onClick={() => cancelVisit(r.id, hostOf(r).name)} className="btn btn-danger" style={{ fontSize: 11, padding: '4px 10px' }}>
                                                            <AppIcon name="close" size={12} /> Cancel Visit
                                                        </button>
                                                    )}
                                                    {r.status === 'checked_out' && !r.feedback && (
                                                        <button onClick={() => openFeedback(r.id)} className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 10px' }}>Feedback</button>
                                                    )}
                                                </div>
                                            </div>
                                            {editOpen[r.id] && (
                                                <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', padding: '12px', background: 'rgba(99,102,241,0.05)', borderRadius: 8, border: '1px solid rgba(99,102,241,0.15)' }}>
                                                    <input type="datetime-local" className="form-input" style={{ flex: 1, minWidth: 170, fontSize: 12 }} value={editState[r.id]?.requested_time || ''} onChange={e => setEditState(p => ({ ...p, [r.id]: { ...p[r.id], requested_time: e.target.value } }))} />
                                                    <select className="form-select" style={{ width: 120, fontSize: 12 }} value={editState[r.id]?.duration || 60} onChange={e => setEditState(p => ({ ...p, [r.id]: { ...p[r.id], duration: Number(e.target.value) } }))}>
                                                        {[15, 30, 60, 120, 240].map(d => <option key={d} value={d}>{d}min</option>)}
                                                    </select>
                                                    <button onClick={() => saveTime(r.id)} className="btn btn-primary" style={{ fontSize: 12 }}>Save</button>
                                                    <button onClick={() => setEditOpen(p => ({ ...p, [r.id]: false }))} className="btn btn-secondary" style={{ fontSize: 12 }}>Cancel</button>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {/* ══════ UPCOMING ══════ */}
                    {tab === 'upcoming' && (
                        <div>
                            <h2 style={{ fontFamily: 'var(--font-heading)', fontSize: 22, fontWeight: 700, marginBottom: 20, background: 'var(--gradient-primary)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}>Upcoming Visits</h2>
                            {requests.filter(r => r.status === 'approved' && new Date(r.requested_time) > new Date()).length === 0 ? (
                                <div className="glass-card" style={{ textAlign: 'center', padding: 48 }}>
                                    <div style={{ fontSize: 48, marginBottom: 16 }}><AppIcon name="calendar" size={48} /></div>
                                    <p style={{ color: 'var(--text-muted)', marginBottom: 16 }}>No upcoming approved appointments.</p>
                                    <button onClick={() => setTab('request')} className="btn btn-primary">Schedule a Visit</button>
                                </div>
                            ) : requests.filter(r => r.status === 'approved' && new Date(r.requested_time) > new Date()).map(r => (
                                <div key={r.id} className="glass-card" style={{ marginBottom: 16, borderLeft: '4px solid var(--status-success)' }}>
                                    <div style={{ display: 'grid', gridTemplateColumns: '1fr auto', gap: 16, alignItems: 'start' }}>
                                        <div>
                                            <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 8, flexWrap: 'wrap' }}>
                                                <span style={{ fontWeight: 800, fontSize: 17, fontFamily: 'var(--font-heading)' }}>{hostOf(r).name}</span>
                                                <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>· {deptOf(r)}</span>
                                                <StatusBadge status={displayStatus(r.status)} />
                                                {pendingRescheduleByVisit[r.id] && (
                                                    <span className="badge badge-pending" style={{ fontSize: 10 }}>reschedule pending</span>
                                                )}
                                            </div>
                                            <div style={{ fontSize: 13, marginBottom: 6 }}><strong>Purpose:</strong> {r.purpose}</div>
                                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, fontSize: 12, color: 'var(--text-secondary)' }}>
                                                <div><div style={{ color: 'var(--text-muted)', marginBottom: 2 }}>Date</div><strong style={{ color: 'var(--text-primary)' }}>{fmtD(r.requested_time)}</strong></div>
                                                <div><div style={{ color: 'var(--text-muted)', marginBottom: 2 }}>Time</div><strong style={{ color: 'var(--accent-cyan)' }}>{fmtT(r.requested_time)}</strong></div>
                                                <div><div style={{ color: 'var(--text-muted)', marginBottom: 2 }}>Duration</div><strong>{r.duration} min</strong></div>
                                            </div>
                                            {r.qr_token && (
                                                <div style={{ marginTop: 12, padding: '10px 14px', background: 'rgba(99,102,241,0.08)', borderRadius: 8, border: '1px solid rgba(99,102,241,0.2)' }}>
                                                    <div style={{ fontSize: 11, color: 'var(--text-muted)', marginBottom: 4 }}>YOUR QR PASS</div>
                                                    <div style={{ fontFamily: 'monospace', fontSize: 15, fontWeight: 800, color: 'var(--accent-primary)', letterSpacing: 2 }}>{r.qr_token.token_value}</div>
                                                    <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 4 }}>Present this to security on arrival</div>
                                                </div>
                                            )}
                                        </div>
                                        <div style={{ textAlign: 'right' }}>
                                            <button onClick={() => { setActiveBadge(r.id); setTab('qrpass'); }} className="btn btn-primary" style={{ fontSize: 12 }}>View QR Pass</button>
                                            <button onClick={() => { setRescheduleModal(r); setRescheduleForm({ new_requested_time: toLocalDatetimeInput(r.requested_time) || '', new_duration: r.duration || 60, reason: '' }); }} className="btn btn-secondary" style={{ fontSize: 11, padding: '4px 10px', marginTop: 8 }} disabled={!!pendingRescheduleByVisit[r.id]}>
                                                <AppIcon name="calendar" size={12} /> {pendingRescheduleByVisit[r.id] ? 'Reschedule Pending' : 'Request Reschedule'}
                                            </button>
                                            <button onClick={() => cancelVisit(r.id, hostOf(r).name)} className="btn btn-danger" style={{ fontSize: 11, padding: '4px 10px', marginTop: 8 }}>
                                                <AppIcon name="close" size={12} /> Cancel Visit
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {/* ══════ QR PASS ══════ */}
                    {tab === 'qrpass' && (
                        <div>
                            <h2 style={{ fontFamily: 'var(--font-heading)', fontSize: 22, fontWeight: 700, marginBottom: 20, background: 'var(--gradient-primary)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}>My QR Passes</h2>
                            {qrVisits.length === 0 ? (
                                <div className="glass-card" style={{ textAlign: 'center', padding: 48 }}>
                                    <div style={{ fontSize: 48, marginBottom: 16 }}><AppIcon name="key" size={48} /></div>
                                    <p style={{ color: 'var(--text-muted)', marginBottom: 16 }}>No active QR passes. Get a visit approved to receive your pass.</p>
                                    <button onClick={() => setTab('request')} className="btn btn-primary">Request a Visit</button>
                                </div>
                            ) : qrVisits.map(r => {
                                const isExit = r.status === 'checked_in' && r.exit_qr_token;
                                const tokenValue = isExit ? r.exit_qr_token : (r.qr_token?.token_value || '');
                                const isCompany = r.visit_type === 'company' && r.groupMembers && r.groupMembers.length > 0;
                                const qrTokenObj = r.qr_token;
                                const isUsed = qrTokenObj?.used_at || r.status === 'checked_in';
                                const isExpired = qrTokenObj?.expires_at && new Date(qrTokenObj.expires_at) < new Date();
                                const qrStatus = isExit ? 'active' : (isUsed ? 'used' : isExpired ? 'expired' : 'active');
                                const qrStatusColor = isExit ? '#f59e0b' : (isUsed ? '#ef4444' : isExpired ? '#f59e0b' : '#10b981');
                                const qrStatusBg = isExit ? 'rgba(245,158,11,0.12)' : (isUsed ? 'rgba(239,68,68,0.12)' : isExpired ? 'rgba(245,158,11,0.12)' : 'rgba(16,185,129,0.12)');
                                const qrStatusBorder = isExit ? 'rgba(245,158,11,0.3)' : (isUsed ? 'rgba(239,68,68,0.3)' : isExpired ? 'rgba(245,158,11,0.3)' : 'rgba(16,185,129,0.3)');
                                const qrStatusLabel = isExit ? 'EXIT' : (isUsed ? 'USED' : isExpired ? 'EXPIRED' : 'ACTIVE');
                                return (
                                    <div key={r.id} style={{ marginBottom: 24 }}>
                                        {/* Representative's own QR pass */}
                                        <div style={{
                                            background: isExit
                                                ? 'linear-gradient(135deg, rgba(245,158,11,0.15), rgba(239,68,68,0.1))'
                                                : 'linear-gradient(135deg, rgba(99,102,241,0.15), rgba(139,92,246,0.1))',
                                            border: isExit ? '1px solid rgba(245,158,11,0.35)' : '1px solid rgba(99,102,241,0.35)',
                                            borderRadius: 16, padding: 28, maxWidth: 480, position: 'relative', overflow: 'hidden'
                                        }}>
                                            <div style={{ position: 'absolute', top: -30, right: -30, width: 100, height: 100, borderRadius: '50%', background: isExit ? 'rgba(245,158,11,0.1)' : 'rgba(99,102,241,0.1)', pointerEvents: 'none' }} />
                                            <div style={{ position: 'absolute', bottom: -20, left: -20, width: 70, height: 70, borderRadius: '50%', background: isExit ? 'rgba(239,68,68,0.1)' : 'rgba(139,92,246,0.1)', pointerEvents: 'none' }} />

                                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 20 }}>
                                                <div>
                                                    <div style={{ fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 4 }}>VMS Enterprise</div>
                                                    <div style={{ fontWeight: 900, fontSize: 20, fontFamily: 'var(--font-heading)', color: isExit ? 'var(--status-warning)' : 'var(--text-primary)' }}>
                                                        {isExit ? ' EXIT PASS — SHOW TO GUARD' : (isCompany ? ' REPRESENTATIVE ACCESS PASS' : 'VISITOR ACCESS PASS')}
                                                    </div>
                                                </div>
                                                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                                    <span style={{ fontSize: 10, padding: '3px 8px', borderRadius: 6, fontWeight: 700, background: qrStatusBg, color: qrStatusColor, border: `1px solid ${qrStatusBorder}` }}>
                                                        {qrStatusLabel}
                                                    </span>
                                                    <div style={{ width: 44, height: 44, borderRadius: 10, background: isExit ? 'rgba(245,158,11,0.3)' : 'var(--gradient-primary)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 22 }}>{isExit ? <AppIcon name="exit" size={22} /> : (isCompany ? <AppIcon name="group" size={22} /> : <AppIcon name="key" size={22} />)}</div>
                                                </div>
                                            </div>

                                            {tokenValue ? (
                                                <div style={{ textAlign: 'center', margin: '20px 0', padding: '16px 20px', background: 'rgba(0,0,0,0.2)', borderRadius: 12, border: isExit ? '1px solid rgba(245,158,11,0.2)' : '1px solid rgba(255,255,255,0.08)' }}>
                                                    {!isExit && (
                                                        <div style={{ marginBottom: 12 }}>
                                                            <img
                                                                src={`/visitor/visit/${r.id}/qr-image`}
                                                                alt="Your QR Code"
                                                                style={{ width: 160, height: 160, borderRadius: 10, border: '1px solid rgba(255,255,255,0.1)', background: '#fff', padding: 6 }}
                                                            />
                                                        </div>
                                                    )}
                                                    <div style={{ fontSize: 11, color: isExit ? 'var(--status-warning)' : 'var(--text-muted)', marginBottom: 8, letterSpacing: 1, fontWeight: 700 }}>
                                                        {isExit ? 'EXIT TOKEN — SECURITY EXIT GATE' : 'ACCESS TOKEN'}
                                                    </div>
                                                    <div style={{ fontFamily: 'monospace', fontSize: 18, fontWeight: 900, color: isExit ? 'var(--status-warning)' : 'var(--accent-primary)', letterSpacing: 3, wordBreak: 'break-all' }}>
                                                        {tokenValue}
                                                    </div>
                                                    <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 8 }}>
                                                        {isExit ? 'Show to security guard at exit gate for property verification & checkout' : 'Single-use · Show to security guard'}
                                                    </div>
                                                </div>
                                            ) : (
                                                <div style={{ textAlign: 'center', margin: '20px 0', padding: '24px 20px', background: 'rgba(0,0,0,0.2)', borderRadius: 12, border: '1px solid rgba(255,255,255,0.08)' }}>
                                                    <QrAutoRetry visitId={r.id} onReload={() => router.reload({ only: ['requests'] })} />
                                                </div>
                                            )}

                                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, fontSize: 13 }}>
                                                {[
                                                    ['Visitor', visitor?.name],
                                                    ['Host', hostOf(r).name],
                                                    ['Dept', deptOf(r) || '—'],
                                                    ['Purpose', r.purpose],
                                                    ['Date', fmtD(r.requested_time)],
                                                    ['Time', fmtT(r.requested_time)],
                                                    ['Duration', r.duration + ' min'],
                                                    ['Status', displayStatus(r.status).replace(/_/g, ' ')],
                                                ].map(([k, v]) => (
                                                    <div key={k} style={{ padding: '8px 12px', background: 'rgba(255,255,255,0.04)', borderRadius: 8 }}>
                                                        <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 2 }}>{k}</div>
                                                        <div style={{ fontWeight: 700, fontSize: 13 }}>{v}</div>
                                                    </div>
                                                ))}
                                            </div>

                                            <div style={{ marginTop: 16, padding: '10px 14px', background: 'rgba(245,158,11,0.08)', borderRadius: 8, border: '1px solid rgba(245,158,11,0.2)', fontSize: 12, color: 'var(--status-warning)' }}>
                                                {isExit
                                                    ? <> Proceed to the <strong>security exit gate</strong>. Guard will scan this token to verify returned property before checkout.</>
                                                    : isCompany
                                                        ? <> This is your <strong>representative pass</strong>. Below are your group members' passes.</>
                                                        : <> Show to security guard for access. Single-use token.</>
                                                }
                                            </div>

                                            {/* Download buttons */}
                                            {tokenValue && (
                                                <div style={{ display: 'flex', gap: 8, marginTop: 16, flexWrap: 'wrap' }}>
                                                    <a href={`/visitor/visit/${r.id}/qr-download`} target="_blank" rel="noopener noreferrer"
                                                        style={{ fontSize: 12, padding: '6px 14px', borderRadius: 8, background: 'rgba(99,102,241,0.1)', border: '1px solid rgba(99,102,241,0.3)', color: 'var(--accent-primary)', fontWeight: 600, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                                                        <AppIcon name="download" size={12} /> Download QR
                                                    </a>
                                                    {isCompany && (
                                                        <>
                                                            <a href={`/visitor/visit/${r.id}/qr-all-download`} target="_blank" rel="noopener noreferrer"
                                                                style={{ fontSize: 12, padding: '6px 14px', borderRadius: 8, background: 'rgba(16,185,129,0.1)', border: '1px solid rgba(16,185,129,0.3)', color: '#10b981', fontWeight: 600, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                                                                <AppIcon name="download" size={12} /> All QR (ZIP)
                                                            </a>
                                                            <a href={`/visitor/visit/${r.id}/group-qr-package`} target="_blank" rel="noopener noreferrer"
                                                                style={{ fontSize: 12, padding: '6px 14px', borderRadius: 8, background: 'rgba(6,182,212,0.1)', border: '1px solid rgba(6,182,212,0.3)', color: '#22d3ee', fontWeight: 600, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                                                                <AppIcon name="download" size={12} /> Members ZIP
                                                            </a>
                                                            <a href={`/visitor/visit/${r.id}/all-passes-download`} target="_blank" rel="noopener noreferrer"
                                                                style={{ fontSize: 12, padding: '6px 14px', borderRadius: 8, background: 'rgba(245,158,11,0.1)', border: '1px solid rgba(245,158,11,0.3)', color: '#f59e0b', fontWeight: 600, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                                                                <AppIcon name="download" size={12} /> All Passes (ZIP)
                                                            </a>
                                                        </>
                                                    )}
                                                    {isExpired && !isExit && !isUsed && (
                                                        <button onClick={() => deleteExpiredQr(r.id)} className="btn btn-danger"
                                                            style={{ fontSize: 12, padding: '6px 14px', marginLeft: 'auto', marginTop: 4 }}>
                                                            <AppIcon name="close" size={12} /> Delete Expired QR
                                                        </button>
                                                    )}
                                                </div>
                                            )}
                                        </div>

                                        {/* Group members */}
                                        {isCompany && (
                                            <div style={{ marginTop: 16 }}>
                                                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, marginBottom: 12, flexWrap: 'wrap' }}>
                                                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                                        <span style={{ fontSize: 18 }}><AppIcon name="group" /></span>
                                                        <span style={{ fontWeight: 700, fontFamily: 'var(--font-heading)', color: 'var(--text-primary)' }}>Group Members ({r.groupMembers.length})</span>
                                                        <span style={{ fontSize: 11, color: 'var(--text-muted)', background: 'rgba(255,255,255,0.06)', padding: '2px 8px', borderRadius: 6 }}>{r.company_name}</span>
                                                    </div>
                                                </div>
                                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))', gap: 12 }}>
                                                    {r.groupMembers.map((m, idx) => {
                                                        const memberToken = m.qr_token || '';
                                                        const isMemberUsed = m.qr_used_at;
                                                        const isMemberExpired = m.qr_expires_at && new Date(m.qr_expires_at) < new Date();
                                                        const memberStatusColor = isMemberUsed ? '#ef4444' : isMemberExpired ? '#f59e0b' : '#10b981';
                                                        const memberStatusBg = isMemberUsed ? 'rgba(239,68,68,0.12)' : isMemberExpired ? 'rgba(245,158,11,0.12)' : 'rgba(16,185,129,0.12)';
                                                        const memberStatusLabel = isMemberUsed ? 'USED' : isMemberExpired ? 'EXPIRED' : 'ACTIVE';
                                                        const memberExitToken = m.exit_qr_token || '';
                                                        const isMemberInside = !!m.checked_in_at && !m.checked_out_at;
                                                        const canShowExit = r.status === 'checked_in' && isMemberInside && memberExitToken;
                                                        return (
                                                            <div key={m.id || idx} style={{
                                                                background: isMemberUsed ? 'rgba(239,68,68,0.06)' : isMemberExpired ? 'rgba(245,158,11,0.06)' : 'rgba(16,185,129,0.06)',
                                                                border: isMemberUsed ? '1px solid rgba(239,68,68,0.25)' : isMemberExpired ? '1px solid rgba(245,158,11,0.25)' : '1px solid rgba(16,185,129,0.25)',
                                                                borderRadius: 12, padding: 16, position: 'relative',
                                                            }}>
                                                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 10 }}>
                                                                    <div>
                                                                        <div style={{ fontWeight: 700, fontSize: 14, color: 'var(--text-primary)' }}>{m.name} {m.checked_out_at && <span style={{ fontSize: 9, padding: '1px 5px', borderRadius: 4, background: 'rgba(139,92,246,0.12)', color: '#a78bfa', fontWeight: 700, marginLeft: 4 }}>DEPARTED</span>} {m.checked_in_at && !m.checked_out_at && <span style={{ fontSize: 9, padding: '1px 5px', borderRadius: 4, background: 'rgba(16,185,129,0.12)', color: '#10b981', fontWeight: 700, marginLeft: 4 }}>INSIDE</span>}</div>
                                                                        <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>ID: {m.id_document_no || '—'} · {m.position || 'Group Member'}</div>
                                                                        {m.checked_in_at && <div style={{ fontSize: 10, color: 'var(--text-muted)', marginTop: 2 }}>In: {m.checked_in_at}</div>}
                                                                        {m.checked_out_at && <div style={{ fontSize: 10, color: 'var(--text-muted)', marginTop: 2 }}>Out: {m.checked_out_at}</div>}
                                                                    </div>
                                                                    <span style={{ fontSize: 10, padding: '3px 8px', borderRadius: 6, fontWeight: 700, background: memberStatusBg, color: memberStatusColor }}>
                                                                        {memberStatusLabel}
                                                                    </span>
                                                                </div>
                                                                {memberToken ? (
                                                                    <>
                                                                        <div style={{ textAlign: 'center', marginBottom: 8 }}>
                                                                            <img
                                                                                src={`/visitor/visit/${r.id}/group-member/${m.id}/qr-image`}
                                                                                alt={`QR Code for ${m.name}`}
                                                                                style={{ width: 140, height: 140, borderRadius: 8, border: '1px solid rgba(255,255,255,0.1)', background: '#fff', padding: 4 }}
                                                                            />
                                                                        </div>
                                                                        <div style={{ textAlign: 'center', padding: '8px 12px', background: 'rgba(0,0,0,0.15)', borderRadius: 8, border: '1px solid rgba(255,255,255,0.06)', marginBottom: 8 }}>
                                                                            <div style={{ fontSize: 9, color: 'var(--text-muted)', letterSpacing: 1, fontWeight: 700, marginBottom: 4 }}>GROUP MEMBER ACCESS TOKEN</div>
                                                                            <div style={{ fontFamily: 'monospace', fontSize: 11, fontWeight: 800, color: memberStatusColor, letterSpacing: 1, wordBreak: 'break-all' }}>
                                                                                {memberToken}
                                                                            </div>
                                                                        </div>
                                                                        <div style={{ display: 'flex', gap: 6, justifyContent: 'center' }}>
                                                                            <a href={`/visitor/visit/${r.id}/group-member/${m.id}/qr-download`}
                                                                                target="_blank" rel="noopener noreferrer"
                                                                                style={{ fontSize: 10, padding: '4px 10px', borderRadius: 6, background: 'rgba(99,102,241,0.1)', border: '1px solid rgba(99,102,241,0.3)', color: 'var(--accent-primary)', fontWeight: 600, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                                                                                <AppIcon name="download" size={10} /> Download QR
                                                                            </a>
                                                                        </div>
                                                                    </>
                                                                ) : (
                                                                    <div style={{ textAlign: 'center', padding: '12px', color: 'var(--text-muted)', fontSize: 11, fontStyle: 'italic' }}>Token pending generation</div>
                                                                )}
                                                                {canShowExit && (
                                                                    <>
                                                                        <div style={{ textAlign: 'center', padding: '8px 12px', background: 'rgba(245,158,11,0.1)', borderRadius: 8, border: '1px solid rgba(245,158,11,0.3)', marginTop: 10, marginBottom: 8 }}>
                                                                            <div style={{ fontSize: 9, color: 'var(--status-warning)', letterSpacing: 1, fontWeight: 700, marginBottom: 6 }}>EXIT TOKEN — SECURITY EXIT GATE</div>
                                                                            <img
                                                                                src={`/visitor/visit/${r.id}/group-member/${m.id}/exit-qr-image`}
                                                                                alt={`Exit QR Code for ${m.name}`}
                                                                                style={{ width: 120, height: 120, borderRadius: 8, border: '1px solid rgba(245,158,11,0.3)', background: '#fff', padding: 4, marginBottom: 6 }}
                                                                            />
                                                                            <div style={{ fontFamily: 'monospace', fontSize: 10, fontWeight: 800, color: 'var(--status-warning)', letterSpacing: 1, wordBreak: 'break-all' }}>
                                                                                {memberExitToken}
                                                                            </div>
                                                                            <div style={{ fontSize: 10, color: 'var(--text-muted)', marginTop: 6 }}>
                                                                                Show to guard at the exit gate for property verification & checkout
                                                                            </div>
                                                                        </div>
                                                                        <div style={{ display: 'flex', gap: 6, justifyContent: 'center' }}>
                                                                            <a href={`/visitor/visit/${r.id}/group-member/${m.id}/exit-qr-download`}
                                                                                target="_blank" rel="noopener noreferrer"
                                                                                style={{ fontSize: 10, padding: '4px 10px', borderRadius: 6, background: 'rgba(245,158,11,0.1)', border: '1px solid rgba(245,158,11,0.3)', color: '#f59e0b', fontWeight: 600, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                                                                                <AppIcon name="download" size={10} /> Download Exit QR
                                                                            </a>
                                                                        </div>
                                                                    </>
                                                                )}
                                                                <div style={{ fontSize: 10, color: 'var(--text-muted)', textAlign: 'center', marginTop: 4 }}>
                                                                    {canShowExit ? 'Exit QR active — single use at exit gate' : (isMemberUsed ? 'Token has been used at entry gate' : isMemberExpired ? 'Token has expired' : 'Single-use · Show to security guard')}
                                                                </div>
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {/* ══════ FEEDBACK ══════ */}
                    {tab === 'feedback' && (
                        <div>
                            <h2 style={{ fontFamily: 'var(--font-heading)', fontSize: 22, fontWeight: 700, marginBottom: 20, background: 'var(--gradient-primary)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}>Feedback</h2>
                            {feedbackReady.length === 0 ? (
                                <div className="glass-card" style={{ textAlign: 'center', padding: 48 }}>
                                    <div style={{ fontSize: 48, marginBottom: 16 }}><AppIcon name="star" size={48} /></div>
                                    <p style={{ color: 'var(--text-muted)' }}>No visits awaiting feedback. Complete a visit to leave a rating.</p>
                                </div>
                            ) : (
                                <div className="grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24 }}>
                                    {feedbackReady.map(r => (
                                        <div key={r.id} className="glass-card" style={{ borderColor: selFeedbackId === r.id ? 'rgba(99,102,241,0.4)' : undefined }}>
                                            <div style={{ fontWeight: 700, fontSize: 15, marginBottom: 4 }}>{hostOf(r).name}</div>
                                            <div style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 12 }}>{r.purpose} · {fmtD(r.requested_time)}</div>
                                            {selFeedbackId === r.id ? (
                                                <form onSubmit={handleFeedback}>
                                                    <div className="form-group">
                                                        <label className="form-label">Rating</label>
                                                        <div style={{ display: 'flex', gap: 8 }}>
                                                            {[1, 2, 3, 4, 5].map(n => (
                                                                <button key={n} type="button" onClick={() => feedbackForm.setData('rating', n)}
                                                                    style={{
                                                                        width: 40, height: 40, borderRadius: 8, border: '1px solid', fontSize: 18, cursor: 'pointer', transition: 'all 0.2s',
                                                                        background: feedbackForm.data.rating >= n ? 'rgba(245,158,11,0.2)' : 'rgba(255,255,255,0.04)',
                                                                        borderColor: feedbackForm.data.rating >= n ? 'var(--status-warning)' : 'rgba(255,255,255,0.1)',
                                                                    }}>
                                                                    {feedbackForm.data.rating >= n ? <AppIcon name="star" /> : '☆'}
                                                                </button>
                                                            ))}
                                                            <span style={{ display: 'flex', alignItems: 'center', fontSize: 14, fontWeight: 700, color: 'var(--status-warning)' }}>{feedbackForm.data.rating}/5</span>
                                                        </div>
                                                    </div>
                                                    <div className="form-group">
                                                        <label className="form-label">Comments</label>
                                                        <textarea className="form-textarea" style={{ minHeight: 80, fontSize: 13 }} placeholder="Share your experience..." value={feedbackForm.data.comments} onChange={e => feedbackForm.setData('comments', e.target.value)} required />
                                                    </div>
                                                    <div style={{ display: 'flex', gap: 8 }}>
                                                        <button type="submit" className="btn btn-primary" style={{ flex: 1 }} disabled={feedbackForm.processing}>Submit</button>
                                                        <button type="button" className="btn btn-secondary" onClick={() => setSelFeedbackId(null)}>Cancel</button>
                                                    </div>
                                                </form>
                                            ) : (
                                                <button onClick={() => openFeedback(r.id)} className="btn btn-primary" style={{ width: '100%', fontSize: 13 }}>Give Feedback</button>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}

                            {/* Given feedbacks */}
                            {requests.filter(r => r.feedback).length > 0 && (
                                <div className="glass-card" style={{ marginTop: 24 }}>
                                    <h3 style={{ fontFamily: 'var(--font-heading)', fontSize: 16, fontWeight: 700, marginBottom: 16 }}>Past Feedback</h3>
                                    <div style={{ overflowX: 'auto' }}>
                                        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                                            <thead>
                                                <tr>
                                                    <th style={{ padding: '8px 12px', textAlign: 'left', fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>Host</th>
                                                    <th style={{ padding: '8px 12px', textAlign: 'left', fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>Rating</th>
                                                    <th style={{ padding: '8px 12px', textAlign: 'left', fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>Comments</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {requests.filter(r => r.feedback).map(r => (
                                                    <tr key={r.id} style={{ borderBottom: '1px solid rgba(255,255,255,0.04)' }}>
                                                        <td style={{ padding: '8px 12px', fontWeight: 600, fontSize: 13 }}>{hostOf(r).name}</td>
                                                        <td style={{ padding: '8px 12px', fontSize: 14, fontWeight: 700, color: 'var(--status-warning)' }}>{[...Array(r.feedback.rating)].map((_, i) => <AppIcon key={i} name="star" size={14} />)} {r.feedback.rating}/5</td>
                                                        <td style={{ fontSize: 12, color: 'var(--text-secondary)', maxWidth: 200 }}>{r.feedback.comments || '—'}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* ══════ HOW IT WORKS ══════ */}
                    {tab === 'workflow' && (
                        <div>
                            <h2 style={{ fontFamily: 'var(--font-heading)', fontSize: 22, fontWeight: 700, marginBottom: 8, background: 'var(--gradient-primary)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}> How It Works</h2>
                            <p style={{ color: 'var(--text-secondary)', fontSize: 14, marginBottom: 28 }}>Your complete visitor journey from registration to checkout</p>

                            {[
                                {
                                    phase: 'Before Your Visit', color: 'rgba(99,102,241,0.15)', border: 'rgba(99,102,241,0.3)', steps: [
                                        { icon: 'user', title: 'Complete Profile', desc: 'Fill in your name, phone, company, and ID document number for faster approvals.' },
                                        { icon: 'search', title: 'Search Host & Department', desc: 'Browse hosts by department to find the right person to visit.' },
                                        { icon: 'clipboard', title: 'Select Visit Purpose', desc: 'Choose from predefined purposes or type a custom reason.' },
                                        { icon: 'calendar', title: 'Select Date & Time', desc: 'Pick a slot within business hours (Mon–Fri, 08:00–18:00).' },
                                        { icon: 'zap', title: 'Submit Request', desc: 'The system automatically validates your request and sends it to the host.' },
                                    ]
                                },
                                {
                                    phase: 'Approval Stage', color: 'rgba(245,158,11,0.1)', border: 'rgba(245,158,11,0.3)', steps: [
                                        { icon: 'check', title: 'System Validates', desc: 'Auto-checks blacklist, capacity, business hours, and duplicate bookings.' },
                                        { icon: 'shield', title: 'Host Approves / Rejects', desc: 'Your host reviews the request and makes a decision.' },
                                        { icon: 'bell', title: 'Receive Notification', desc: 'You are notified of the approval or rejection immediately.' },
                                        { icon: 'key', title: 'Download QR Pass', desc: 'Once approved, your unique QR access token appears in your portal.' },
                                    ]
                                },
                                {
                                    phase: 'Day of Visit', color: 'rgba(34,197,94,0.08)', border: 'rgba(34,197,94,0.25)', steps: [
                                        { icon: 'building', title: 'Arrive at Company', desc: 'Come to the building entrance at your scheduled time.' },
                                        { icon: 'key', title: 'Security Scans QR', desc: 'Show your QR token to the security guard for scanning.' },
                                        { icon: 'user', title: 'Verify ID', desc: 'Present a valid photo ID matching your registered document number.' },
                                        { icon: 'check', title: 'Check In', desc: 'Security logs you in and notifies your host of your arrival.' },
                                        { icon: 'users', title: 'Meet Your Host', desc: 'Proceed to your host\'s location for your meeting.' },
                                    ]
                                },
                                {
                                    phase: 'After Your Visit', color: 'rgba(139,92,246,0.1)', border: 'rgba(139,92,246,0.25)', steps: [
                                        { icon: 'check', title: 'Complete Meeting', desc: 'Your host marks the meeting as complete.' },
                                        { icon: 'exit', title: 'Check Out', desc: 'Security or host logs your departure from the premises.' },
                                        { icon: 'creditcard', title: 'Return Visitor Badge', desc: 'Return any physical badge or access card at reception.' },
                                        { icon: 'star', title: 'Submit Feedback', desc: 'Rate your visit experience — your feedback helps us improve.' },
                                        { icon: 'clipboard', title: 'View Visit History', desc: 'All your past visits are stored in your portal for reference.' },
                                    ]
                                },
                            ].map((phase, pi) => (
                                <div key={pi} style={{ marginBottom: 28 }}>
                                    <h3 style={{ fontFamily: 'var(--font-heading)', fontSize: 16, fontWeight: 700, marginBottom: 14, padding: '8px 16px', background: phase.color, borderRadius: 8, border: `1px solid ${phase.border}`, display: 'inline-block' }}>
                                        Phase {pi + 1}: {phase.phase}
                                    </h3>
                                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px,1fr))', gap: 12 }}>
                                        {phase.steps.map((step, si) => (
                                            <div key={si} style={{ padding: '16px', background: 'rgba(18,23,43,0.6)', borderRadius: 12, border: '1px solid rgba(255,255,255,0.07)', display: 'flex', gap: 12, alignItems: 'flex-start' }}>
                                                <div style={{ width: 36, height: 36, borderRadius: 10, background: 'rgba(99,102,241,0.15)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 18, flexShrink: 0 }}>
                                                    <AppIcon name={step.icon} size={18} />
                                                </div>
                                                <div>
                                                    <div style={{ fontWeight: 700, fontSize: 13, marginBottom: 4, color: '#ffffff' }}>{step.title}</div>
                                                    <div style={{ fontSize: 12, color: '#ffffff', lineHeight: 1.5 }}>{step.desc}</div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>

                    )

                /* end right content */}
                </div> {/* end flex */}

                {tab === 'messages' && (
                    <Messages
                        inbox={messages_inbox || []}
                        sent={messages_sent || []}
                        recipients={messages_recipients || []}
                        unread_count={messages_unread_count || 0}
                        sender_type="visitor"
                        sender_id={visitor?.id}
                        embedded
                    />
                )}

                {/* Reschedule Modal */}
                {rescheduleModal && (
                    <div className="modal-overlay" style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 }} onClick={() => setRescheduleModal(null)}>
                        <div className="modal-content" style={{ background: 'var(--glass-bg)', border: '1px solid var(--glass-border)', borderRadius: 16, padding: 32, maxWidth: 480, width: '90%' }} onClick={e => e.stopPropagation()}>
                            <h3 style={{ fontFamily: 'var(--font-heading)', marginBottom: 20 }}>
                                <AppIcon name="calendar" size={18} /> Request Reschedule - Visit #{rescheduleModal.id}
                            </h3>
                            <p style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 16 }}>
                                Current: {new Date(rescheduleModal.requested_time).toLocaleString()} ({rescheduleModal.duration} min)
                            </p>
                            <form onSubmit={(e) => {
                                e.preventDefault();
                                const serverTime = localToServerDatetime(rescheduleForm.new_requested_time, tz);
                                router.post(`/visit-requests/${rescheduleModal.id}/request-reschedule`, {
                                    new_requested_time: serverTime,
                                    new_duration: rescheduleForm.new_duration,
                                    reason: rescheduleForm.reason,
                                }, { onSuccess: () => setRescheduleModal(null) });
                            }}>
                                <div style={{ marginBottom: 12 }}>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>New Date & Time</label>
                                    <input type="datetime-local" className="form-input" value={rescheduleForm.new_requested_time} onChange={e => setRescheduleForm(p => ({ ...p, new_requested_time: e.target.value }))} required style={{ width: '100%' }} />
                                </div>
                                <div style={{ marginBottom: 12 }}>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Duration (minutes)</label>
                                    <select className="form-input" value={rescheduleForm.new_duration} onChange={e => setRescheduleForm(p => ({ ...p, new_duration: Number(e.target.value) }))} required>
                                        {[15, 30, 60, 120, 240].map(v => <option key={v} value={v}>{v} min</option>)}
                                    </select>
                                </div>
                                <div style={{ marginBottom: 20 }}>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Reason for Reschedule</label>
                                    <textarea className="form-input" value={rescheduleForm.reason} onChange={e => setRescheduleForm(p => ({ ...p, reason: e.target.value }))} rows={3} placeholder="Why do you need to reschedule?" style={{ width: '100%' }} />
                                </div>
                                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                                    <button type="button" onClick={() => setRescheduleModal(null)} className="btn btn-secondary">Cancel</button>
                                    <button type="submit" className="btn btn-primary">Submit Request</button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

            </div>    </Layout>
    );
}


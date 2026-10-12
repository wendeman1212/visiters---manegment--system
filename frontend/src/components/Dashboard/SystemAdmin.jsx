import React, { useState, useEffect } from 'react';
import Layout from '../Shared/Layout';
import { useForm, usePage, router, Link } from '@inertiajs/react';
import AppIcon from '../../config/iconMap';
import Messages from './Messages';

const TABS = [
    { key: 'overview', label: 'Platform Overview', icon: 'chart' },
    { key: 'companies', label: 'Companies', icon: 'building' },
    { key: 'users', label: 'Users', icon: 'users' },
    { key: 'activity', label: 'Activity', icon: 'clipboard' },
    { key: 'sys_activity', label: 'Sys Admin Activity', icon: 'users' },
    { key: 'security', label: 'Security', icon: 'shield' },
    { key: 'messages', label: 'Messages', icon: 'mail' },
];

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

function SectionTitle({ children }) {
    return (
        <h3 style={{ fontFamily: 'var(--font-heading)', marginBottom: '16px', fontSize: '16px', fontWeight: 700 }}>
            {children}
        </h3>
    );
}

function CompanyForm({ company, users, onCancel }) {
    const editing = !!company;
    const form = useForm({
        company_name: company?.company_name || '',
        code: company?.code || '',
        industry: company?.industry || '',
        description: company?.description || '',
        phone: company?.phone || '',
        email: company?.email || '',
        website: company?.website || '',
        is_active: company?.is_active ?? true,
        ceo_id: company?.ceo_id ? String(company.ceo_id) : '',
        admin_id: company?.admin_id ? String(company.admin_id) : '',
        ceo_first_name: '',
        ceo_last_name: '',
        ceo_gender: '',
        ceo_age: '',
        ceo_national_id: '',
        ceo_position: '',
        ceo_email: '',
        ceo_phone: '',
        ceo_password: '',
        ceo_password_confirmation: '',
        admin_first_name: '',
        admin_last_name: '',
        admin_gender: '',
        admin_age: '',
        admin_national_id: '',
        admin_position: '',
        admin_email: '',
        admin_phone: '',
        admin_password: '',
        admin_password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        if (editing) {
            form.put(`/system-admin/companies/${company.company_id}`);
        } else {
            form.post('/system-admin/companies');
        }
    };

    return (
        <form onSubmit={submit} style={{ background: 'rgba(255,255,255,0.04)', border: '1px solid var(--glass-border)', borderRadius: '10px', padding: 16, marginBottom: 20 }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '12px', marginBottom: 12 }}>
                <div>
                    <label className="form-label">Company Name *</label>
                    <input type="text" className="form-input" value={form.data.company_name}
                        onChange={e => form.setData('company_name', e.target.value)} required />
                    {form.errors.company_name && <div style={{ color: 'var(--status-danger)', fontSize: 12 }}>{form.errors.company_name}</div>}
                </div>
                <div>
                    <label className="form-label">Code</label>
                    <input type="text" className="form-input" value={form.data.code}
                        onChange={e => form.setData('code', e.target.value)} />
                    {form.errors.code && <div style={{ color: 'var(--status-danger)', fontSize: 12 }}>{form.errors.code}</div>}
                </div>
                <div>
                    <label className="form-label">Industry</label>
                    <input type="text" className="form-input" value={form.data.industry}
                        onChange={e => form.setData('industry', e.target.value)} />
                </div>
            </div>
            <div style={{ marginBottom: 12 }}>
                <label className="form-label">Description</label>
                <textarea rows={3} className="form-input" value={form.data.description}
                    onChange={e => form.setData('description', e.target.value)} />
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px', marginBottom: 12 }}>
                <div>
                    <label className="form-label">Phone</label>
                    <input type="text" className="form-input" value={form.data.phone}
                        onChange={e => form.setData('phone', e.target.value)} />
                </div>
                <div>
                    <label className="form-label">Email</label>
                    <input type="email" className="form-input" value={form.data.email}
                        onChange={e => form.setData('email', e.target.value)} />
                </div>
                <div>
                    <label className="form-label">Website</label>
                    <input type="url" className="form-input" value={form.data.website}
                        onChange={e => form.setData('website', e.target.value)} />
                </div>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px', marginBottom: 12 }}>
                <div>
                    <label className="form-label">Assign Existing CEO</label>
                    <select className="form-input" value={form.data.ceo_id}
                        onChange={e => form.setData('ceo_id', e.target.value)}>
                        <option value="">— Select Existing CEO —</option>
                        {users.filter(u => u.role === 'ceo').map(u => <option key={u.id} value={u.id}>{u.name} ({u.email})</option>)}
                    </select>
                </div>
                <div>
                    <label className="form-label">Assign Existing Admin</label>
                    <select className="form-input" value={form.data.admin_id}
                        onChange={e => form.setData('admin_id', e.target.value)}>
                        <option value="">— Select Existing Admin —</option>
                        {users.filter(u => u.role === 'admin').map(u => <option key={u.id} value={u.id}>{u.name} ({u.email})</option>)}
                    </select>
                </div>
                <div style={{ display: 'flex', alignItems: 'flex-end', paddingBottom: 6 }}>
                    <label className="inline-flex items-center" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <input type="checkbox" checked={form.data.is_active}
                            onChange={e => form.setData('is_active', e.target.checked)} />
                        Active
                    </label>
                </div>
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
                <button type="submit" className="btn btn-primary" disabled={form.processing}>
                    {editing ? 'Save Changes' : 'Create Company'}
                </button>
                <button type="button" className="btn" onClick={onCancel}>Cancel</button>
            </div>
        </form>
    );
}

export default function SystemAdminDashboard({ stats, companies, users, system_admin_activity, activity_log, security, section, messages_inbox, messages_sent, messages_recipients, messages_unread_count }) {
    const { auth, app_timezone } = usePage().props;
    const user = auth.user;
    const tz = app_timezone || 'Africa/Addis_Ababa';

    const [activeTab, setActiveTab] = useState(() => {
        if (section) return section;
        const hash = window.location.hash.replace('#', '');
        return TABS.some(t => t.key === hash) ? hash : 'overview';
    });

    useEffect(() => {
        if (section) setActiveTab(section);
    }, [section]);

    const [sidebarOpen, setSidebarOpen] = useState(true);

    const [mode, setMode] = useState('list');
    const [editingCompany, setEditingCompany] = useState(null);

    const [userMode, setUserMode] = useState('list');
    const [editingUser, setEditingUser] = useState(null);
    const [userRoleFilter, setUserRoleFilter] = useState('');
    const [userStatusFilter, setUserStatusFilter] = useState('');
    const [userSearch, setUserSearch] = useState('');
    const userForm = useForm({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        role: 'admin',
        company_id: '',
        position: '',
        status: 'active',
        password: '',
    });

    const filteredUsers = (users || []).filter(u => {
        if (userRoleFilter && u.role !== userRoleFilter) return false;
        if (userStatusFilter && u.status !== userStatusFilter) return false;
        if (userSearch && !`${u.name} ${u.email} ${u.role}`.toLowerCase().includes(userSearch.toLowerCase())) return false;
        return true;
    });

    const startCreateUser = () => {
        setEditingUser(null);
        userForm.reset();
        setUserMode('create');
    };

    const startEditUser = (u) => {
        setEditingUser(u);
        userForm.setData({
            first_name: u.first_name || '',
            last_name: u.last_name || '',
            name: u.name || '',
            email: u.email || '',
            phone: u.phone || '',
            role: u.role || 'admin',
            company_id: u.company_id ? String(u.company_id) : '',
            position: u.position || '',
            status: u.status || 'active',
            password: '',
        });
        setUserMode('edit');
    };

    const submitUser = (e) => {
        e.preventDefault();
        if (editingUser) {
            userForm.put(`/system-admin/users/${editingUser.id}`, {
                onSuccess: () => { setUserMode('list'); setEditingUser(null); userForm.reset(); }
            });
        } else {
            userForm.post('/system-admin/users', {
                onSuccess: () => { setUserMode('list'); setEditingUser(null); userForm.reset(); }
            });
        }
    };

    const deleteUser = (u) => {
        if (confirm(`Are you sure you want to delete ${u.name} (${u.email})? This cannot be undone.`)) {
            router.delete(`/system-admin/users/${u.id}`);
        }
    };

    const toggleUserStatus = (u) => {
        router.post(`/system-admin/users/${u.id}/toggle-status`);
    };

    // Activity filtering state
    const [activityFilters, setActivityFilters] = useState({
        date_from: '',
        date_to: '',
        company_id: '',
        action: '',
        admin_id: '',
        today_only: false,
    });
    const [filteredActivities, setFilteredActivities] = useState([]);
    const [loadingActivities, setLoadingActivities] = useState(false);
    const [filtersApplied, setFiltersApplied] = useState(false);

    // Security incident log state
    const [incidentPeriod, setIncidentPeriod] = useState('daily');
    const [incidentCompany, setIncidentCompany] = useState('');
    const [incidentBranch, setIncidentBranch] = useState('');
    const [incidentData, setIncidentData] = useState(security?.incident_log || null);
    const [incidentLoading, setIncidentLoading] = useState(false);
    const [incidentError, setIncidentError] = useState('');
    const [expandedIncId, setExpandedIncId] = useState(null);

    const loadIncidents = async (period, companyId, branchId) => {
        setIncidentLoading(true);
        setIncidentError('');
        try {
            const params = new URLSearchParams({ period });
            if (companyId) params.set('company_id', companyId);
            if (branchId) params.set('branch_id', branchId);
            const res = await fetch(`/system-admin/incidents/data?${params.toString()}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('Failed to load incidents.');
            const data = await res.json();
            setIncidentData(data);
        } catch (err) {
            setIncidentError(err.message || 'Failed to load incidents.');
        } finally {
            setIncidentLoading(false);
        }
    };

    useEffect(() => {
        if (activeTab === 'security') {
            loadIncidents(incidentPeriod, incidentCompany, incidentBranch);
        }
    }, [activeTab, incidentPeriod, incidentCompany, incidentBranch]);

    const incidentCompanies = incidentData?.companies || security?.incident_log?.companies || [];
    const selectedIncidentCompany = incidentCompanies.find(c => String(c.company_id) === String(incidentCompany));
    const incidentBranchOptions = incidentCompany
        ? (selectedIncidentCompany?.branches || [])
        : incidentCompanies.flatMap(c => c.branches || []);

    // Global activity log state
    const [activityPeriod, setActivityPeriod] = useState('daily');
    const [activityCompany, setActivityCompany] = useState('');
    const [activityBranch, setActivityBranch] = useState('');
    const [activityDateFrom, setActivityDateFrom] = useState('');
    const [activityDateTo, setActivityDateTo] = useState('');
    const [activityData, setActivityData] = useState(activity_log || null);
    const [activityLoading, setActivityLoading] = useState(false);
    const [activityError, setActivityError] = useState('');

    const loadActivities = async (period, companyId, branchId, dateFrom, dateTo) => {
        setActivityLoading(true);
        setActivityError('');
        try {
            const params = new URLSearchParams({ period });
            if (companyId) params.set('company_id', companyId);
            if (branchId) params.set('branch_id', branchId);
            if (period === 'custom') {
                if (dateFrom) params.set('date_from', dateFrom);
                if (dateTo) params.set('date_to', dateTo);
            }
            const res = await fetch(`/system-admin/activity/data?${params.toString()}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('Failed to load activities.');
            const data = await res.json();
            setActivityData(data);
        } catch (err) {
            setActivityError(err.message || 'Failed to load activities.');
        } finally {
            setActivityLoading(false);
        }
    };

    useEffect(() => {
        if (activeTab === 'activity') {
            loadActivities(activityPeriod, activityCompany, activityBranch, activityDateFrom, activityDateTo);
        }
    }, [activeTab, activityPeriod, activityCompany, activityBranch, activityDateFrom, activityDateTo]);

    const activityCompanies = activityData?.companies || activity_log?.companies || [];
    const selectedActivityCompany = activityCompanies.find(c => String(c.company_id) === String(activityCompany));
    const activityBranchOptions = activityCompany
        ? (selectedActivityCompany?.branches || [])
        : activityCompanies.flatMap(c => c.branches || []);

    const activityLogRows = activityData?.logs || activity_log?.logs || [];

    const toggle = (company) => {
        router.post(`/system-admin/companies/${company.company_id}/status`, {}, {
            onSuccess: () => {
                setMode('list');
                setEditingCompany(null);
            }
        });
    };

    const remove = (company) => {
        if (confirm(`Are you sure you want to delete ${company.company_name}?`)) {
            router.delete(`/system-admin/companies/${company.company_id}`, {
                onSuccess: () => {
                    setMode('list');
                    setEditingCompany(null);
                }
            });
        }
    };

    const applyFilters = async (extra = {}) => {
        setLoadingActivities(true);
        setFiltersApplied(true);
        try {
            const f = { ...activityFilters, ...extra };
            const params = new URLSearchParams();
            if (f.date_from) params.append('date_from', f.date_from);
            if (f.date_to) params.append('date_to', f.date_to);
            if (f.company_id) params.append('company_id', f.company_id);
            if (f.action) params.append('action', f.action);
            if (f.admin_id) params.append('admin_id', f.admin_id);
            if (f.today_only) params.append('today_only', 'true');

            const response = await fetch(`/system-admin/activities/filter?${params.toString()}`);
            const data = await response.json();
            setFilteredActivities(data.activities || []);
        } catch (error) {
            console.error('Failed to filter activities:', error);
            setFilteredActivities([]);
        } finally {
            setLoadingActivities(false);
        }
    };

    const resetFilters = () => {
        setActivityFilters({
            date_from: '',
            date_to: '',
            company_id: '',
            action: '',
            admin_id: '',
            today_only: false,
        });
        setFilteredActivities([]);
        setFiltersApplied(false);
    };

    const loadTodayActivities = () => {
        setActivityFilters({
            ...activityFilters,
            today_only: true,
        });
        applyFilters({ today_only: true });
    };

    return (
        <Layout title={section ? `System Admin - ${section}` : "System Admin Control"}>
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
                                    <div style={{ fontWeight: 700, fontSize: 13, color: '#ffffff' }}>System Admin</div>
                                    <div style={{ fontSize: 11, color: 'rgba(255,255,255,0.7)' }}>Platform Control</div>
                                </div>
                            </div>
                        </div>}

                        <ul style={{ listStyle: 'none', padding: '6px 8px', margin: 0, display: 'flex', flexDirection: 'column', gap: 2 }}>
                            {TABS.map(t => {
                                const isActive = activeTab === t.key;
                                return (
                                    <li key={t.key}>
                                        <button
                                            onClick={() => router.visit(`/system-admin/section/${t.key}`)}
                                            title={sidebarOpen ? '' : t.label}
                                            style={{
                                                width: '100%',
                                                textAlign: 'left',
                                                background: isActive ? 'rgba(255,255,255,0.2)' : 'transparent',
                                                border: 'none',
                                                borderLeft: isActive ? '3px solid #ffffff' : '3px solid transparent',
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
                                                borderRadius: isActive ? '0 8px 8px 0' : '0',
                                            }}
                                            onMouseEnter={(e) => {
                                                if (!isActive) e.currentTarget.style.background = 'rgba(255,255,255,0.12)';
                                            }}
                                            onMouseLeave={(e) => {
                                                if (!isActive) e.currentTarget.style.background = 'transparent';
                                            }}
                                        >
                                            <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}><AppIcon name={t.icon} size={14} /> {sidebarOpen ? t.label : t.label.split(' ')[0]}</span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>

                        {/* Bottom links */}
                        <div style={{ borderTop: '1px solid rgba(255,255,255,0.15)', padding: '12px 16px', display: 'flex', flexDirection: 'column', gap: 6 }}>
                            <Link href="/" style={{ fontSize: 12, color: 'rgba(255,255,255,0.8)', textDecoration: 'none', padding: '6px 0', display: 'flex', alignItems: 'center', gap: 8 }}><AppIcon name="home" size={14} /> Home</Link>
                            <form method="POST" action="/logout" style={{ margin: 0 }}>
                                <button type="submit" style={{ background: 'none', border: 'none', fontSize: 12, color: 'rgba(255,255,255,0.8)', cursor: 'pointer', textAlign: 'left', padding: '6px 0', display: 'flex', alignItems: 'center', gap: 8 }}>
                                    <AppIcon name="exit" size={14} /> Logout
                                </button>
                            </form>
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
                            <Link href="/system-admin/dashboard" style={{ color: 'var(--accent-primary)', fontSize: 14, fontWeight: 600, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 6 }}>← Back to System Admin Dashboard</Link>
                        </div>
                    )}
                    {/* ── Header ── */}
                    <div className="dashboard-header">
                        <div className="dashboard-title">
                            <h2>Platform Administration Console</h2>
                            <p>Multi-tenant platform management, company oversight, security monitoring, and system-wide activity tracking</p>
                        </div>
                    </div>

                    {activeTab === 'overview' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '32px' }}>
                            {/* Platform KPIs */}
                            <div className="stats-grid">
                                <StatCard label="Companies" value={stats.total_companies} color="blue" />
                                <StatCard label="Active" value={stats.active_companies} color="emerald" />
                                <StatCard label="Inactive" value={stats.inactive_companies} color="warning" />
                                <StatCard label="Users" value={stats.total_users} color="default" />
                                <StatCard label="Active Users" value={stats.active_users} color="emerald" />
                                <StatCard label="Pending Users" value={stats.pending_users} color="warning" />
                                <StatCard label="Visitors" value={stats.total_visitors} color="default" />
                                <StatCard label="Visits Today" value={stats.visits_today} color="cyan" />
                                <StatCard label="Active Sessions" value={stats.active_sessions} color="blue" />
                                <StatCard label="Pending Approvals" value={stats.pending_approvals} color="warning" />
                                <StatCard label="Escalations" value={stats.escalations} color="danger" />
                                <StatCard label="Open Incidents" value={stats.open_incidents} color="danger" />
                            </div>
                        </div>
                    )}

                    {activeTab === 'companies' && (
                        <section>
                            <SectionTitle>Company Management</SectionTitle>
                            <div style={{ marginBottom: 16, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                {mode === 'list' ? (
                                    <button className="btn btn-primary" onClick={() => setMode('create')}>+ Add Company</button>
                                ) : (
                                    <button className="btn" onClick={() => { setMode('list'); setEditingCompany(null); }}>← Back to list</button>
                                )}
                            </div>

                            {mode === 'create' && (
                                <CompanyForm users={users} onCancel={() => setMode('list')} />
                            )}

                            {mode === 'edit' && editingCompany && (
                                <CompanyForm company={editingCompany} users={users}
                                    onCancel={() => { setMode('list'); setEditingCompany(null); }} />
                            )}

                            {mode === 'list' && (
                                <div className="table-container">
                                    <table className="data-table">
                                        <thead>
                                            <tr>
                                                <th>Company</th>
                                                <th>Code</th>
                                                <th>Description</th>
                                                <th>Status</th>
                                                <th>CEO</th>
                                                <th>Admin</th>
                                                <th>Users</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {companies.map(c => (
                                                <tr key={c.company_id}>
                                                    <td>{c.company_name}</td>
                                                    <td>{c.code || '—'}</td>
                                                    <td>{c.description ? (c.description.length > 60 ? c.description.slice(0, 60) + '…' : c.description) : '—'}</td>
                                                    <td>
                                                        <span style={{ color: c.is_active ? 'var(--status-success)' : 'var(--status-danger)' }}>
                                                            {c.is_active ? 'Active' : 'Inactive'}
                                                        </span>
                                                    </td>
                                                    <td>{c.ceo_name ? `${c.ceo_name} (${c.ceo_email})` : <span style={{ color: 'var(--status-warning)' }}>—</span>}</td>
                                                    <td>{c.admin_name ? `${c.admin_name} (${c.admin_email})` : <span style={{ color: 'var(--status-warning)' }}>—</span>}</td>
                                                    <td>{c.users_count}</td>
                                                    <td style={{ whiteSpace: 'nowrap' }}>
                                                        <button className="btn btn-sm" title="Edit"
                                                            onClick={() => { setEditingCompany(c); setMode('edit'); }}>
                                                            <AppIcon name="edit" size={14} />
                                                        </button>{' '}
                                                        <button className="btn btn-sm" title="Activate / Deactivate"
                                                            onClick={() => toggle(c)}>
                                                            <AppIcon name="power" size={14} color={c.is_active ? 'var(--status-danger)' : 'var(--status-success)'} />
                                                        </button>{' '}
                                                        <button className="btn btn-sm" title="Delete"
                                                            onClick={() => remove(c)}>
                                                            <AppIcon name="trash" size={14} color="var(--status-danger)" />
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                            {companies.length === 0 && (
                                                <tr><td colSpan={8} style={{ textAlign: 'center', color: 'var(--text-muted)' }}>No companies yet.</td></tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>
                    )}

                    {activeTab === 'users' && (
                        <section>
                            <SectionTitle>User Management</SectionTitle>
                            <div style={{ marginBottom: 16, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                {userMode === 'list' ? (
                                    <button className="btn btn-primary" onClick={startCreateUser}>+ Add User</button>
                                ) : (
                                    <button className="btn" onClick={() => { setUserMode('list'); setEditingUser(null); userForm.reset(); }}>← Back to list</button>
                                )}
                            </div>

                            {userMode === 'create' && (
                                <div className="glass-card" style={{ marginBottom: 20, borderColor: 'rgba(34,197,94,0.2)' }}>
                                    <div style={{ fontWeight: 700, fontSize: 14, marginBottom: 12 }}>Create CEO / Company Admin</div>
                                    <form onSubmit={submitUser}>
                                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12 }}>
                                            <div>
                                                <label className="form-label">First Name *</label>
                                                <input type="text" className="form-input" value={userForm.data.first_name}
                                                    onChange={e => userForm.setData('first_name', e.target.value)} required />
                                            </div>
                                            <div>
                                                <label className="form-label">Last Name</label>
                                                <input type="text" className="form-input" value={userForm.data.last_name}
                                                    onChange={e => userForm.setData('last_name', e.target.value)} />
                                            </div>
                                            <div>
                                                <label className="form-label">Email *</label>
                                                <input type="email" className="form-input" value={userForm.data.email}
                                                    onChange={e => userForm.setData('email', e.target.value)} required />
                                            </div>
                                            <div>
                                                <label className="form-label">Phone *</label>
                                                <input type="tel" className="form-input" value={userForm.data.phone}
                                                    onChange={e => userForm.setData('phone', e.target.value)} required />
                                            </div>
                                            <div>
                                                <label className="form-label">Role *</label>
                                                <select className="form-input" value={userForm.data.role}
                                                    onChange={e => userForm.setData('role', e.target.value)} required>
                                                    <option value="ceo">CEO</option>
                                                    <option value="admin">Company Admin</option>
                                                    <option value="manager">Manager</option>
                                                    <option value="host">Host</option>
                                                    <option value="security_guard">Security Guard</option>
                                                    <option value="system_admin">System Admin</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label className="form-label">Company</label>
                                                <select className="form-input" value={userForm.data.company_id}
                                                    onChange={e => userForm.setData('company_id', e.target.value)}>
                                                    <option value="">— No Company —</option>
                                                    {companies.map(c => <option key={c.company_id} value={c.company_id}>{c.company_name}</option>)}
                                                </select>
                                            </div>
                                            <div>
                                                <label className="form-label">Company Position</label>
                                                <input type="text" className="form-input" value={userForm.data.position}
                                                    onChange={e => userForm.setData('position', e.target.value)} />
                                            </div>
                                            <div>
                                                <label className="form-label">Status *</label>
                                                <select className="form-input" value={userForm.data.status}
                                                    onChange={e => userForm.setData('status', e.target.value)} required>
                                                    <option value="active">Active</option>
                                                    <option value="pending">Pending</option>
                                                    <option value="suspended">Suspended</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label className="form-label">Password *</label>
                                                <input type="password" className="form-input" value={userForm.data.password}
                                                    onChange={e => userForm.setData('password', e.target.value)} required minLength={8} />
                                            </div>
                                        </div>
                                        {Object.keys(userForm.errors || {}).length > 0 && (
                                            <div style={{ color: 'var(--status-danger)', fontSize: 12, margin: '8px 0' }}>
                                                {Object.entries(userForm.errors).map(([k, v]) => <div key={k}>• {v}</div>)}
                                            </div>
                                        )}
                                        <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
                                            <button type="submit" className="btn btn-primary" disabled={userForm.processing}>
                                                Create User
                                            </button>
                                            <button type="button" className="btn" onClick={() => { setUserMode('list'); userForm.reset(); }}>Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            )}

                            {userMode === 'edit' && editingUser && (
                                <div className="glass-card" style={{ marginBottom: 20, borderColor: 'rgba(99,102,241,0.3)' }}>
                                    <div style={{ fontWeight: 700, fontSize: 14, marginBottom: 12 }}>Edit User — {editingUser.name}</div>
                                    <form onSubmit={submitUser}>
                                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12 }}>
                                            <div>
                                                <label className="form-label">First Name *</label>
                                                <input type="text" className="form-input" value={userForm.data.first_name}
                                                    onChange={e => userForm.setData('first_name', e.target.value)} required />
                                            </div>
                                            <div>
                                                <label className="form-label">Last Name</label>
                                                <input type="text" className="form-input" value={userForm.data.last_name}
                                                    onChange={e => userForm.setData('last_name', e.target.value)} />
                                            </div>
                                            <div>
                                                <label className="form-label">Email *</label>
                                                <input type="email" className="form-input" value={userForm.data.email}
                                                    onChange={e => userForm.setData('email', e.target.value)} required />
                                            </div>
                                            <div>
                                                <label className="form-label">Phone *</label>
                                                <input type="tel" className="form-input" value={userForm.data.phone}
                                                    onChange={e => userForm.setData('phone', e.target.value)} required />
                                            </div>
                                            <div>
                                                <label className="form-label">Role *</label>
                                                <select className="form-input" value={userForm.data.role}
                                                    onChange={e => userForm.setData('role', e.target.value)} required>
                                                    <option value="ceo">CEO</option>
                                                    <option value="admin">Company Admin</option>
                                                    <option value="manager">Manager</option>
                                                    <option value="host">Host</option>
                                                    <option value="security_guard">Security Guard</option>
                                                    <option value="system_admin">System Admin</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label className="form-label">Company</label>
                                                <select className="form-input" value={userForm.data.company_id}
                                                    onChange={e => userForm.setData('company_id', e.target.value)}>
                                                    <option value="">— No Company —</option>
                                                    {companies.map(c => <option key={c.company_id} value={c.company_id}>{c.company_name}</option>)}
                                                </select>
                                            </div>
                                            <div>
                                                <label className="form-label">Company Position</label>
                                                <input type="text" className="form-input" value={userForm.data.position}
                                                    onChange={e => userForm.setData('position', e.target.value)} />
                                            </div>
                                            <div>
                                                <label className="form-label">Status *</label>
                                                <select className="form-input" value={userForm.data.status}
                                                    onChange={e => userForm.setData('status', e.target.value)} required>
                                                    <option value="active">Active</option>
                                                    <option value="pending">Pending</option>
                                                    <option value="suspended">Suspended</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label className="form-label">New Password (leave blank to keep)</label>
                                                <input type="password" className="form-input" value={userForm.data.password}
                                                    onChange={e => userForm.setData('password', e.target.value)} minLength={8} />
                                            </div>
                                        </div>
                                        {Object.keys(userForm.errors || {}).length > 0 && (
                                            <div style={{ color: 'var(--status-danger)', fontSize: 12, margin: '8px 0' }}>
                                                {Object.entries(userForm.errors).map(([k, v]) => <div key={k}>• {v}</div>)}
                                            </div>
                                        )}
                                        <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
                                            <button type="submit" className="btn btn-primary" disabled={userForm.processing}>
                                                Save Changes
                                            </button>
                                            <button type="button" className="btn" onClick={() => { setUserMode('list'); setEditingUser(null); userForm.reset(); }}>Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            )}

                            {userMode === 'list' && (
                                <div className="glass-card">
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12, flexWrap: 'wrap', gap: 12 }}>
                                        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                                            <select className="form-input" style={{ width: 150, fontSize: 12 }} value={userRoleFilter} onChange={e => setUserRoleFilter(e.target.value)}>
                                                <option value="">All Roles</option>
                                                <option value="ceo">CEO</option>
                                                <option value="admin">Company Admin</option>
                                                <option value="manager">Manager</option>
                                                <option value="host">Host</option>
                                                <option value="security_guard">Security Guard</option>
                                                <option value="system_admin">System Admin</option>
                                            </select>
                                            <select className="form-input" style={{ width: 130, fontSize: 12 }} value={userStatusFilter} onChange={e => setUserStatusFilter(e.target.value)}>
                                                <option value="">All Status</option>
                                                <option value="active">Active</option>
                                                <option value="pending">Pending</option>
                                                <option value="suspended">Suspended</option>
                                            </select>
                                            <input type="text" className="form-input" placeholder="Search name, email, role..." value={userSearch}
                                                onChange={e => setUserSearch(e.target.value)} style={{ fontSize: 12, width: 200 }} />
                                        </div>
                                    </div>
                                    <div className="table-container" style={{ marginBottom: 0 }}>
                                        <table className="data-table">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Role</th>
                                                    <th>Email</th>
                                                    <th>Company</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {filteredUsers.map(u => (
                                                    <tr key={u.id}>
                                                        <td style={{ fontWeight: 600 }}>{u.name}</td>
                                                        <td><span className="badge badge-pending">{u.role}</span></td>
                                                        <td style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{u.email}</td>
                                                        <td style={{ fontSize: 13 }}>{u.company?.company_name || companies.find(c => String(c.company_id) === String(u.company_id))?.company_name || '—'}</td>
                                                        <td>
                                                            <span className={`badge badge-${u.status === 'active' ? 'approved' : u.status === 'pending' ? 'pending' : 'rejected'}`}>{u.status}</span>
                                                        </td>
                                                        <td style={{ whiteSpace: 'nowrap' }}>
                                                            <button className="btn btn-sm" title="Edit"
                                                                onClick={() => startEditUser(u)}>
                                                                <AppIcon name="edit" size={14} />
                                                            </button>{' '}
                                                            <button className="btn btn-sm" title="Activate / Suspend"
                                                                onClick={() => toggleUserStatus(u)}>
                                                                <AppIcon name="power" size={14} color={u.status === 'active' ? 'var(--status-danger)' : 'var(--status-success)'} />
                                                            </button>{' '}
                                                            <button className="btn btn-sm" title="Delete"
                                                                onClick={() => deleteUser(u)}>
                                                                <AppIcon name="trash" size={14} color="var(--status-danger)" />
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                                {filteredUsers.length === 0 && (
                                                    <tr><td colSpan={6} style={{ textAlign: 'center', color: 'var(--text-muted)' }}>No users found.</td></tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                        </section>
                    )}

                    {activeTab === 'activity' && (
                        <section>
                            <SectionTitle>Global Activity Log</SectionTitle>

                            <div className="glass-card" style={{ marginBottom: 20, padding: 14 }}>
                                <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                                    <select className="form-input" style={{ width: 220, fontSize: 12 }} value={activityCompany} onChange={e => { setActivityCompany(e.target.value); setActivityBranch(''); }}>
                                        <option value="">All Companies</option>
                                        {activityCompanies.map(c => <option key={c.company_id} value={c.company_id}>{c.company_name}</option>)}
                                    </select>
                                    <select className="form-input" style={{ width: 220, fontSize: 12 }} value={activityBranch} onChange={e => setActivityBranch(e.target.value)}>
                                        <option value="">All Branches</option>
                                        {activityBranchOptions.map(b => <option key={b.id} value={b.id}>{b.name}{b.location_name ? ` — ${b.location_name}` : ''}</option>)}
                                    </select>
                                    <select className="form-input" style={{ width: 170, fontSize: 12 }} value={activityPeriod} onChange={e => setActivityPeriod(e.target.value)}>
                                        <option value="daily">Daily (Today)</option>
                                        <option value="weekly">Weekly (This Week)</option>
                                        <option value="monthly">Monthly (This Month)</option>
                                        <option value="yearly">Yearly (This Year)</option>
                                        <option value="custom">Custom Date Range</option>
                                    </select>
                                    {activityPeriod === 'custom' && (
                                        <>
                                            <input type="date" className="form-input" style={{ width: 160, fontSize: 12 }} value={activityDateFrom} onChange={e => setActivityDateFrom(e.target.value)} />
                                            <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>→</span>
                                            <input type="date" className="form-input" style={{ width: 160, fontSize: 12 }} value={activityDateTo} onChange={e => setActivityDateTo(e.target.value)} />
                                        </>
                                    )}
                                    <button className="btn btn-primary" style={{ fontSize: 12 }} onClick={() => loadActivities(activityPeriod, activityCompany, activityBranch, activityDateFrom, activityDateTo)} disabled={activityLoading}>
                                        <AppIcon name="refresh" size={14} /> {activityLoading ? 'Loading…' : 'Refresh'}
                                    </button>
                                </div>
                            </div>

                            {activityError && (
                                <div className="glass-card" style={{ marginBottom: 16, borderColor: 'rgba(239,68,68,0.4)' }}>
                                    <span style={{ color: 'var(--status-danger)', fontSize: 13 }}><AppIcon name="warning" size={14} /> {activityError}</span>
                                </div>
                            )}

                            {activityData && (
                                <div className="glass-card" style={{ marginBottom: 20, padding: 14, display: 'flex', flexWrap: 'wrap', gap: 24, alignItems: 'center' }}>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                        <AppIcon name="calendar" size={16} />
                                        <span style={{ fontWeight: 700, fontSize: 14 }}>{activityData.period_label}</span>
                                        <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>({activityData.range.start} → {activityData.range.end})</span>
                                    </div>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                        <AppIcon name="building" size={16} />
                                        <span style={{ fontSize: 13 }}>{activityData.selected_company ? activityData.selected_company.name : 'All Companies'}{activityData.selected_branch ? ` / ${activityData.selected_branch.name}` : ''}</span>
                                    </div>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                        <AppIcon name="clipboard" size={16} />
                                        <span style={{ fontSize: 13, color: 'var(--text-muted)' }}>{activityData.count} activity record(s) in scope</span>
                                    </div>
                                </div>
                            )}

                            <div className="table-container">
                                <table className="data-table">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th>Actor</th>
                                            <th>Role</th>
                                            <th>Phone</th>
                                            <th>Company</th>
                                            <th>Branch</th>
                                            <th>Action</th>
                                            <th>Entity</th>
                                            <th>IP Address</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {activityLogRows.map(a => {
                                            const statusClass = { success: 'badge-approved', failed: 'badge-rejected', unauthorized: 'badge-pending' };
                                            return (
                                                <tr key={a.id}>
                                                    <td style={{ whiteSpace: 'nowrap' }}>{a.created_at}</td>
                                                    <td style={{ fontWeight: 600 }}>{a.actor_name}</td>
                                                    <td>{a.actor_role || '—'}</td>
                                                    <td style={{ whiteSpace: 'nowrap' }}>{a.actor_phone || '—'}</td>
                                                    <td style={{ fontSize: 12 }}>{a.company_name || '—'}</td>
                                                    <td style={{ fontSize: 12 }}>{a.branch_name || '—'}</td>
                                                    <td>{a.action}</td>
                                                    <td>{a.entity ? `${a.entity}${a.entity_id ? ` #${a.entity_id}` : ''}` : '—'}</td>
                                                    <td style={{ whiteSpace: 'nowrap' }}>{a.ip_address || '—'}</td>
                                                    <td><span className={`badge ${statusClass[a.status] || 'badge-approved'}`} style={{ fontSize: 10 }}>{a.status || 'success'}</span></td>
                                                </tr>
                                            );
                                        })}
                                        {activityLogRows.length === 0 && (
                                            <tr><td colSpan={10} style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 24 }}>No activity found for the selected scope.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    )}

                    {activeTab === 'sys_activity' && (
                        <section>
                            <SectionTitle>System Admin Activity</SectionTitle>
                            <p style={{ fontSize: '12px', color: 'var(--text-muted)', marginBottom: 12 }}>
                                Actions performed by System Admin users across the platform. Independent of company/branch scoping.
                            </p>

                            {/* Activity Filters */}
                            <div style={{ background: 'rgba(255,255,255,0.04)', border: '1px solid var(--glass-border)', borderRadius: '8px', padding: 12, marginBottom: 16 }}>
                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12, alignItems: 'end' }}>
                                    <div>
                                        <label className="form-label" style={{ fontSize: '12px' }}>Date From</label>
                                        <input
                                            type="date"
                                            className="form-input"
                                            value={activityFilters.date_from || ''}
                                            onChange={e => setActivityFilters({ ...activityFilters, date_from: e.target.value })}
                                        />
                                    </div>
                                    <div>
                                        <label className="form-label" style={{ fontSize: '12px' }}>Date To</label>
                                        <input
                                            type="date"
                                            className="form-input"
                                            value={activityFilters.date_to || ''}
                                            onChange={e => setActivityFilters({ ...activityFilters, date_to: e.target.value })}
                                        />
                                    </div>
                                    <div>
                                        <label className="form-label" style={{ fontSize: '12px' }}>Company</label>
                                        <select
                                            className="form-input"
                                            value={activityFilters.company_id || ''}
                                            onChange={e => setActivityFilters({ ...activityFilters, company_id: e.target.value })}
                                        >
                                            <option value="">All Companies</option>
                                            {companies.map(c => (
                                                <option key={c.company_id} value={c.company_id}>{c.company_name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className="form-label" style={{ fontSize: '12px' }}>Activity Type</label>
                                        <select
                                            className="form-input"
                                            value={activityFilters.action || ''}
                                            onChange={e => setActivityFilters({ ...activityFilters, action: e.target.value })}
                                        >
                                            <option value="">All Actions</option>
                                            <option value="company_created">Company Created</option>
                                            <option value="company_updated">Company Updated</option>
                                            <option value="company_status_update">Company Status Changed</option>
                                            <option value="company_deleted">Company Deleted</option>
                                            <option value="system_admin_user_created">User Created</option>
                                            <option value="system_admin_user_updated">User Updated</option>
                                            <option value="system_admin_user_status_updated">User Status Changed</option>
                                            <option value="login">Login</option>
                                            <option value="logout">Logout</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="form-label" style={{ fontSize: '12px' }}>System Admin</label>
                                        <select
                                            className="form-input"
                                            value={activityFilters.admin_id || ''}
                                            onChange={e => setActivityFilters({ ...activityFilters, admin_id: e.target.value })}
                                        >
                                            <option value="">All Admins</option>
                                            {users.filter(u => u.role === 'system_admin').map(u => (
                                                <option key={u.id} value={u.id}>{u.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div style={{ display: 'flex', gap: 8 }}>
                                        <button
                                            className="btn btn-primary"
                                            onClick={applyFilters}
                                            disabled={loadingActivities}
                                        >
                                            {loadingActivities ? 'Loading...' : 'Filter'}
                                        </button>
                                        <button
                                            className="btn"
                                            onClick={resetFilters}
                                        >
                                            Reset
                                        </button>
                                        <button
                                            className="btn"
                                            onClick={loadTodayActivities}
                                        >
                                            Today
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div className="table-container">
                                <table className="data-table">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th>Admin</th>
                                            <th>Action</th>
                                            <th>Description</th>
                                            <th>Entity</th>
                                            <th>Company</th>
                                            <th>Status</th>
                                            <th>IP</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {(filtersApplied ? (filteredActivities || []) : (system_admin_activity || [])).map(a => (
                                            <tr key={a.id}>
                                                <td style={{ whiteSpace: 'nowrap' }}>{a.created_at}</td>
                                                <td>
                                                    <div>{a.actor_name}</div>
                                                    <div style={{ fontSize: '11px', color: 'var(--text-muted)' }}>{a.actor_email}</div>
                                                    <div style={{ fontSize: '10px', color: 'var(--accent-cyan)' }}>{a.actor_role}</div>
                                                </td>
                                                <td>{a.action}</td>
                                                <td>{a.description || '—'}</td>
                                                <td>{a.entity ? `${a.entity}${a.entity_id ? ` #${a.entity_id}` : ''}` : '—'}</td>
                                                <td>{a.company_name || '—'}</td>
                                                <td>
                                                    <span style={{ color: 'var(--status-success)' }}>{a.status || '—'}</span>
                                                </td>
                                                <td style={{ whiteSpace: 'nowrap' }}>{a.ip_address || '—'}</td>
                                            </tr>
                                        ))}
                                        {filtersApplied && filteredActivities.length === 0 && (
                                            <tr><td colSpan={8} style={{ textAlign: 'center', color: 'var(--text-muted)' }}>No System Admin activities match your filter.</td></tr>
                                        )}
                                        {!filtersApplied && (system_admin_activity || []).length === 0 && (
                                            <tr><td colSpan={8} style={{ textAlign: 'center', color: 'var(--text-muted)' }}>No System Admin activities yet.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    )}

                    {activeTab === 'security' && (
                        <section>
                            <SectionTitle>Security Monitoring</SectionTitle>
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '14px', marginBottom: '24px' }}>
                                <StatCard label="Blacklist Hits" value={security.blacklist_hits} color="var(--status-danger)" />
                                <StatCard label="Failed Validations" value={security.failed_validations} color="var(--status-warning)" />
                                <StatCard label="SLA Overdue" value={security.sla_overdue} color="var(--status-warning)" />
                                <StatCard label="Lockdown" value={security.lockdown_active ? 'ACTIVE' : 'Off'} color={security.lockdown_active ? 'var(--status-danger)' : 'var(--status-success)'} />
                                <StatCard label="Open Incidents" value={security.incident_stats.open} color="var(--status-danger)" />
                                <StatCard label="Investigating" value={security.incident_stats.investigating} color="var(--status-warning)" />
                            </div>

                            <SectionTitle>Security Incident Log</SectionTitle>

                            <div className="glass-card" style={{ marginBottom: 20, padding: 14 }}>
                                <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                                    <select className="form-input" style={{ width: 220, fontSize: 12 }} value={incidentCompany} onChange={e => { setIncidentCompany(e.target.value); setIncidentBranch(''); }}>
                                        <option value="">All Companies</option>
                                        {incidentCompanies.map(c => <option key={c.company_id} value={c.company_id}>{c.company_name}</option>)}
                                    </select>
                                    <select className="form-input" style={{ width: 220, fontSize: 12 }} value={incidentBranch} onChange={e => setIncidentBranch(e.target.value)}>
                                        <option value="">All Branches</option>
                                        {incidentBranchOptions.map(b => <option key={b.id} value={b.id}>{b.name}{b.location_name ? ` — ${b.location_name}` : ''}</option>)}
                                    </select>
                                    <select className="form-input" style={{ width: 160, fontSize: 12 }} value={incidentPeriod} onChange={e => setIncidentPeriod(e.target.value)}>
                                        <option value="daily">Daily (Today)</option>
                                        <option value="weekly">Weekly (This Week)</option>
                                        <option value="monthly">Monthly (This Month)</option>
                                        <option value="yearly">Yearly (This Year)</option>
                                    </select>
                                    <button className="btn btn-primary" style={{ fontSize: 12 }} onClick={() => loadIncidents(incidentPeriod, incidentCompany, incidentBranch)} disabled={incidentLoading}>
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
                                    <div className="glass-card" style={{ marginBottom: 20, padding: 14, display: 'flex', flexWrap: 'wrap', gap: 24, alignItems: 'center' }}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                            <AppIcon name="calendar" size={16} />
                                            <span style={{ fontWeight: 700, fontSize: 14 }}>{incidentData.period_label}</span>
                                            <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>({incidentData.range.start} → {incidentData.range.end})</span>
                                        </div>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                            <AppIcon name="building" size={16} />
                                            <span style={{ fontSize: 13 }}>{incidentData.selected_company ? incidentData.selected_company.name : 'All Companies'}{incidentData.selected_branch ? ` / ${incidentData.selected_branch.name}` : ''}</span>
                                        </div>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                            <AppIcon name="file" size={16} />
                                            <span style={{ fontSize: 13, color: 'var(--text-muted)' }}>{incidentData.count} incident(s) in scope</span>
                                        </div>
                                    </div>

                                    <div className="glass-card" style={{ marginBottom: 20, padding: 14 }}>
                                        <div className="stats-grid" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(130px,1fr))' }}>
                                            <StatCard label="Total Incidents" value={incidentData.stats.total} color="default" />
                                            <StatCard label="Logged" value={incidentData.stats.open} color="warning" />
                                            <StatCard label="Investigating" value={incidentData.stats.investigating} color="cyan" />
                                            <StatCard label="Resolved" value={incidentData.stats.resolved} color="emerald" />
                                            <StatCard label="Escalated" value={incidentData.stats.escalated} color="danger" />
                                            <StatCard label="Critical" value={incidentData.stats.critical} color="danger" />
                                        </div>
                                    </div>

                                    <div className="glass-card" style={{ padding: 14 }}>
                                        <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 12 }}>Click a row for full description, corrective action & resolution details.</div>
                                        <div className="table-container">
                                            <table className="data-table">
                                                <thead>
                                                    <tr>
                                                        <th>Incident ID</th>
                                                        <th>Date & Time</th>
                                                        <th>Company</th>
                                                        <th>Branch</th>
                                                        <th>Category</th>
                                                        <th>Severity</th>
                                                        <th>Visitor / Person</th>
                                                        <th>Host Staff</th>
                                                        <th>Security Officer</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {incidentData.incidents.map(inc => {
                                                        const sevColor = { low: 'var(--status-info)', medium: 'var(--status-warning)', high: 'var(--status-danger)', critical: '#dc2626' };
                                                        const statusLabel = { open: 'Logged', investigating: 'Under Investigation', resolved: 'Resolved', closed: 'Closed', escalated: 'Escalated' };
                                                        const statusClass = { open: 'badge-pending', investigating: 'badge-pending', resolved: 'badge-approved', closed: 'badge-approved', escalated: 'badge-rejected' };
                                                        const isExpanded = expandedIncId === inc.id;
                                                        return (
                                                            <React.Fragment key={inc.id}>
                                                                <tr onClick={() => setExpandedIncId(isExpanded ? null : inc.id)} style={{ cursor: 'pointer' }}>
                                                                    <td style={{ fontWeight: 700 }}>#{inc.id}</td>
                                                                    <td style={{ fontSize: 11, whiteSpace: 'nowrap' }}>{inc.created_at}</td>
                                                                    <td style={{ fontSize: 12 }}>{inc.company_name}</td>
                                                                    <td>
                                                                        <div style={{ fontWeight: 600, fontSize: 12 }}>{inc.branch_name}</div>
                                                                        {inc.location_name && <div style={{ fontSize: 10, color: 'var(--text-muted)' }}>{inc.location_name}</div>}
                                                                    </td>
                                                                    <td><span className="badge badge-pending" style={{ fontSize: 10 }}>{inc.category_label || inc.category}</span></td>
                                                                    <td><span style={{ color: sevColor[inc.severity] || 'var(--text-primary)', fontWeight: 700, fontSize: 12 }}>{inc.severity}</span></td>
                                                                    <td>{inc.visitor_name || '—'}</td>
                                                                    <td>{inc.host_name || '—'}</td>
                                                                    <td>{inc.reported_by_name || '—'}</td>
                                                                    <td><span className={`badge ${statusClass[inc.status] || 'badge-pending'}`} style={{ fontSize: 10 }}>{statusLabel[inc.status] || inc.status}</span></td>
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
                        </section>
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
                </div>
            </div>
        </Layout>
    );
}

import React, { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import AppIcon from '../../config/iconMap';

/**
 * Visitor Transfer modal for Company Admin / CEO.
 *
 * Flow: Company -> Branch -> (optional Department filter) -> Host -> Reason -> Review -> Confirm.
 * Eligible users load as soon as a Company is selected (all eligible users of
 * that company). Selecting a Branch narrows the list to that branch's users,
 * and selecting a Department filters it to that department. A Department is
 * never required before users are shown. Everything is fetched from the
 * /visitor-transfer/{id}/options endpoint which validates each selection
 * against the live database for the acting Admin/CEO.
 */
export default function VisitorTransferModal({ visit, onClose }) {
    const { auth } = usePage().props;
    const actor = auth?.user || {};

    const [opts, setOpts] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [sel, setSel] = useState({ company: '', branch: '', dept: '', host: '' });
    const [reason, setReason] = useState('');
    const [step, setStep] = useState('form');
    const [submitting, setSubmitting] = useState(false);
    const loadToken = useRef(0);

    const buildUrl = (v, s) => {
        const qs = [];
        if (s.company) qs.push(`company_id=${encodeURIComponent(s.company)}`);
        if (s.branch) qs.push(`branch_id=${encodeURIComponent(s.branch)}`);
        if (s.dept) qs.push(`department_id=${encodeURIComponent(s.dept)}`);
        const q = qs.length ? `?${qs.join('&')}` : '';
        return `/visitor-transfer/${v.id}/options${q}`;
    };

    const readError = async (res) => {
        try {
            const data = await res.json();
            if (data && data.message) return data.message;
        } catch (_) { /* ignore */ }
        return 'Failed to load transfer options.';
    };

    const load = async (s) => {
        const token = ++loadToken.current;
        setLoading(true);
        setError('');
        try {
            const res = await fetch(buildUrl(visit, s), { headers: { Accept: 'application/json' } });
            if (token !== loadToken.current) return;
            if (!res.ok) throw new Error(await readError(res));
            const data = await res.json();
            setOpts(data);
        } catch (err) {
            if (token !== loadToken.current) return;
            setError(err.message || 'Could not load transfer options.');
        } finally {
            if (token === loadToken.current) setLoading(false);
        }
    };

    useEffect(() => {
        load({ company: '', branch: '', dept: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [visit?.id]);

    const change = async (field, value) => {
        const next = { ...sel, [field]: value };
        if (field === 'company') { next.branch = ''; next.dept = ''; next.host = ''; }
        if (field === 'branch') { next.dept = ''; next.host = ''; }
        if (field === 'dept') { next.host = ''; }
        setSel(next);
        await load(next);
    };

    const review = () => {
        setError('');
        if (!sel.host) { setError('Please select an eligible Host, Admin or CEO.'); return; }
        if (reason && reason.length > 1000) { setError('Transfer reason must not exceed 1000 characters.'); return; }
        setStep('review');
    };

    const confirm = () => {
        setSubmitting(true);
        router.post(`/visitor-transfer/${visit.id}`, {
            new_host_id: sel.host,
            company_id: sel.company,
            branch_id: sel.branch,
            department_id: sel.dept,
            reason,
        }, {
            onFinish: () => setSubmitting(false),
        });
    };

    const selectedRecipient = [...(opts?.managers || []), ...(opts?.hosts || [])].find(r => String(r.id) === String(sel.host));
    const selectedCompany = (opts?.companies || []).find(c => String(c.company_id) === String(sel.company));
    const selectedBranch = (opts?.branches || []).find(b => String(b.id) === String(sel.branch));
    const selectedDept = (opts?.departments || []).find(d => String(d.id) === String(sel.dept));

    const visitorName = visit?.visitor_name || visit?.visitor?.name || visit?.company_name || 'Visitor';
    const currentHostName = visit?.host_name || visit?.host?.name || visit?.assigned_host_name || '—';
    const status = visit?.status || '';

    const fieldStyle = { width: '100%', fontSize: 13 };
    const labelStyle = { fontSize: 12, fontWeight: 700, color: 'var(--text-secondary)', marginBottom: 4, display: 'block' };

    return (
        <div className="modal-overlay" style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 }} onClick={onClose}>
            <div className="glass-card modal-content" style={{ width: 560, maxWidth: '92vw', padding: 24, border: '1px solid rgba(99,102,241,0.3)', maxHeight: '92vh', overflowY: 'auto' }} onClick={e => e.stopPropagation()}>
                <h3 style={{ fontWeight: 700, fontSize: 16, marginBottom: 14, display: 'flex', alignItems: 'center', gap: 8 }}>
                    <AppIcon name="users" size={18} /> Transfer Visitor
                </h3>

                {visit && (
                    <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginBottom: 16, padding: '10px 12px', borderRadius: 8, background: 'rgba(99,102,241,0.06)', border: '1px solid rgba(99,102,241,0.15)' }}>
                        Visitor: <strong>{visitorName}</strong> · Current Host: <strong>{currentHostName}</strong>
                        {status ? <> · Status: <span className={`badge badge-${status}`}>{status.replace(/_/g, ' ')}</span></> : null}
                    </div>
                )}

                {loading && <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Loading transfer options...</p>}
                {error && <p style={{ color: 'var(--status-danger)', fontSize: 13 }}>{error}</p>}

                {opts && !opts.error && step === 'form' && (
                    <form onSubmit={e => { e.preventDefault(); review(); }}>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: 14 }}>
                            <div>
                                <label style={labelStyle}>Company</label>
                                <select className="form-input" style={fieldStyle} value={sel.company} onChange={e => change('company', e.target.value)}>
                                    <option value="">Select company...</option>
                                    {(opts.companies || []).map(c => <option key={c.company_id} value={c.company_id}>{c.company_name}</option>)}
                                </select>
                            </div>
                            <div>
                                <label style={labelStyle}>Branch</label>
                                <select className="form-input" style={fieldStyle} value={sel.branch} onChange={e => change('branch', e.target.value)} disabled={!sel.company}>
                                    <option value="">Select branch...</option>
                                    {(opts.branches || []).map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                    {(opts.branches || []).length === 0 && sel.company && <option value="" disabled>No branches available</option>}
                                </select>
                            </div>
                            <div>
                                <label style={labelStyle}>Department</label>
                                <select className="form-input" style={fieldStyle} value={sel.dept} onChange={e => change('dept', e.target.value)} disabled={!sel.company || loading}>
                                    <option value="">{sel.branch ? 'Select department (this branch)...' : 'Select department (company-level)...'}</option>
                                    {(opts.departments || []).map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                                    {(opts.departments || []).length === 0 && sel.company && !loading && <option value="" disabled>No departments available</option>}
                                </select>
                            </div>
                            <div>
                                <label style={labelStyle}>New Host / Recipient</label>
                                <select className="form-input" style={fieldStyle} value={sel.host} onChange={e => change('host', e.target.value)} disabled={!sel.company || loading}>
                                    <option value="">{loading ? 'Loading recipients...' : (sel.company ? 'Select a Host, Admin or CEO...' : 'Select a company first')}</option>
                                    {(opts.managers || []).length > 0 && (
                                        <optgroup label="Admins / CEO">
                                            {(opts.managers || []).map(m => <option key={m.id} value={m.id}>{m.name}{m.role === 'ceo' ? ' (CEO)' : ' (Admin)'}{m.department ? ` — ${m.department}` : ''}</option>)}
                                        </optgroup>
                                    )}
                                    {(opts.hosts || []).length > 0 && (
                                        <optgroup label="Active Hosts">
                                            {(opts.hosts || []).map(h => <option key={h.id} value={h.id}>{h.name}{h.department ? ` — ${h.department}` : ''}</option>)}
                                        </optgroup>
                                    )}
                                    {!loading && sel.company && (opts.managers || []).length === 0 && (opts.hosts || []).length === 0 && <option value="" disabled>No eligible recipients found</option>}
                                </select>
                            </div>
                        </div>

                        <label style={labelStyle}>Transfer Reason</label>
                        <textarea className="form-input" style={{ width: '100%', fontSize: 13, minHeight: 64, marginBottom: 14 }} placeholder="Reason for transfer (optional)" maxLength={1000} value={reason} onChange={e => setReason(e.target.value)} />

                        <div style={{ padding: '10px 12px', borderRadius: 8, background: 'rgba(99,102,241,0.06)', border: '1px solid rgba(99,102,241,0.15)', marginBottom: 16, fontSize: 11, color: 'var(--text-secondary)' }}>
                            The selected user becomes responsible for this visitor immediately. All visit, approval, property, appointment and history records are preserved. The visitor and the new user are notified.
                        </div>

                        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                            <button type="button" onClick={onClose} className="btn btn-secondary">Cancel</button>
                            <button type="submit" className="btn btn-primary" disabled={!sel.host}>Review Transfer</button>
                        </div>
                    </form>
                )}

                {opts && !opts.error && step === 'review' && (
                    <div>
                        <div style={{ fontSize: 13, fontWeight: 700, marginBottom: 12 }}>Review Transfer</div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 16 }}>
                            {[
                                ['Visitor', visitorName],
                                ['Current Host', currentHostName],
                                ['New Recipient', selectedRecipient ? `${selectedRecipient.name}${selectedRecipient.role === 'ceo' ? ' (CEO)' : selectedRecipient.role === 'admin' ? ' (Admin)' : ''}${selectedRecipient.department ? ` (${selectedRecipient.department})` : ''}` : '—'],
                                ['Company', selectedCompany?.company_name || '—'],
                                ['Branch', selectedBranch?.name || '—'],
                                ['Department', selectedDept?.name || '—'],
                                ['Reason', reason || '—'],
                                ['Transferred By', `${actor.name || ''} (${actor.role === 'ceo' ? 'CEO' : 'Admin'})`],
                            ].map(([k, v]) => (
                                <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '8px 10px', borderRadius: 8, background: 'rgba(255,255,255,0.03)', border: '1px solid rgba(255,255,255,0.06)' }}>
                                    <span style={{ fontSize: 12, color: 'var(--text-secondary)', fontWeight: 600 }}>{k}</span>
                                    <span style={{ fontSize: 12, textAlign: 'right', fontWeight: 600 }}>{v}</span>
                                </div>
                            ))}
                        </div>
                        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                            <button type="button" onClick={() => setStep('form')} className="btn btn-secondary">Back</button>
                            <button type="button" onClick={confirm} className="btn btn-primary" disabled={submitting}>
                                <AppIcon name="check" size={14} /> {submitting ? 'Transferring...' : 'Confirm Transfer'}
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

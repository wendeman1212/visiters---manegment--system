import React from 'react';
import { Link } from '@inertiajs/react';

const statusMeta = {
    pending:     { label: 'Pending',       color: '#f59e0b', bg: 'rgba(245,158,11,0.12)', border: 'rgba(245,158,11,0.35)' },
    approved:    { label: 'Approved',      color: '#10b981', bg: 'rgba(16,185,129,0.12)', border: 'rgba(16,185,129,0.35)' },
    checked_in:  { label: 'Checked In',    color: '#10b981', bg: 'rgba(16,185,129,0.12)', border: 'rgba(16,185,129,0.35)' },
    checked_out: { label: 'Checked Out',   color: '#64748b', bg: 'rgba(100,116,139,0.12)', border: 'rgba(100,116,139,0.35)' },
    rejected:    { label: 'Rejected',      color: '#ef4444', bg: 'rgba(239,68,68,0.12)', border: 'rgba(239,68,68,0.35)' },
    escalated:   { label: 'Escalated',     color: '#ef4444', bg: 'rgba(239,68,68,0.12)', border: 'rgba(239,68,68,0.35)' },
};

const priorityMeta = {
    urgent:   { label: 'Urgent',   color: '#ef4444' },
    high:     { label: 'High',     color: '#f97316' },
    normal:   { label: 'Normal',   color: '#2563eb' },
    low:      { label: 'Low',      color: '#64748b' },
};

const failureCopy = {
    not_found: {
        title: 'QR Code Not Recognized',
        message: 'No visitor record could be found for the scanned visitor number. The code may be invalid, mistyped, or the record may have been deleted.',
    },
    expired: {
        title: 'QR Code Expired',
        message: 'This pass has expired and is no longer valid. Please contact reception or your host for a new pass.',
    },
    revoked: {
        title: 'QR Code Revoked',
        message: 'This pass has been revoked and can no longer be used. Please contact reception or your host.',
    },
    invalid: {
        title: 'QR Code Not Valid',
        message: 'This pass cannot be validated right now. Please contact reception or your host.',
    },
};

function parseParts(value) {
    if (!value) return null;
    const s = String(value).trim();
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{1,2}):(\d{2})(?::(\d{2}))?)?/);
    if (m) return { y: +m[1], mo: +m[2] - 1, d: +m[3], h: +m[4] || 0, mi: +m[5] || 0, utc: true };
    const dt = new Date(s);
    if (!Number.isNaN(dt.getTime()) && String(dt.getFullYear()).length === 4) return { y: dt.getFullYear(), mo: dt.getMonth(), d: dt.getDate(), h: dt.getHours(), mi: dt.getMinutes(), utc: false };
    return null;
}

function formatDate(value) {
    const p = parseParts(value);
    if (!p) return value ? String(value) : '—';
    const opts = { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' };
    if (p.utc) return new Date(Date.UTC(p.y, p.mo, p.d)).toLocaleDateString('en-US', { ...opts, timeZone: 'UTC' });
    return new Date(p.y, p.mo, p.d).toLocaleDateString('en-US', opts);
}

function formatTime(value) {
    const p = parseParts(value);
    if (!p) return value ? String(value) : '—';
    const opts = { hour: 'numeric', minute: '2-digit' };
    if (p.utc) return new Date(Date.UTC(p.y, p.mo, p.d, p.h, p.mi)).toLocaleTimeString('en-US', { ...opts, timeZone: 'UTC' });
    return new Date(p.y, p.mo, p.d, p.h, p.mi).toLocaleTimeString('en-US', opts);
}

function Row({ label, value }) {
    if (!value || value === '—') return null;
    return (
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 12, padding: '9px 0', borderBottom: '1px dashed var(--border-color)' }}>
            <div style={{ flex: '0 0 104px', fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.8px', color: '#94a3b8', fontWeight: 700 }}>{label}</div>
            <div style={{ flex: 1, fontSize: 13.5, fontWeight: 600, color: '#1e293b', wordBreak: 'break-word', textAlign: 'right' }}>{value}</div>
        </div>
    );
}

function Field({ label, value }) {
    if (value === null || value === undefined || value === '') return null;
    return (
        <div style={{ padding: '10px 0', borderBottom: '1px solid var(--border-color)' }}>
            <div style={{ fontSize: 10, textTransform: 'uppercase', letterSpacing: '0.8px', color: 'var(--text-muted)', marginBottom: 3 }}>{label}</div>
            <div style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--text-primary)', wordBreak: 'break-word' }}>{value}</div>
        </div>
    );
}

export default function QrScan({ scan_status, visitor_number, is_exit, visit }) {
    const failed = scan_status !== 'valid';
    const failure = failureCopy[scan_status] || failureCopy.not_found;

    const meta = statusMeta[visit?.status] || { label: visit?.status?.replace(/_/g, ' ') || 'Unknown', color: '#64748b', bg: 'rgba(100,116,139,0.12)', border: 'rgba(100,116,139,0.35)' };
    const priority = priorityMeta[visit?.priority] || { label: visit?.priority || 'normal', color: '#64748b' };

    const passType = is_exit ? 'EXIT PASS' : 'VISITOR PASS';
    const idDocument = visit?.id_document_no ? 'ID: ' + visit.id_document_no : null;

    const rows = [
        ['Date', formatDate(visit?.appointment_date)],
        ['Time', formatTime(visit?.appointment_time)],
        ['Host', visit?.host_name],
        ['Department', visit?.department || visit?.host_department],
        ['Purpose', visit?.purpose],
        ['Location', visit?.branch],
    ];

    return (
        <div className="ai-page" style={{ minHeight: '100vh', display: 'flex', alignItems: 'stretch', justifyContent: 'center', padding: 0 }}>
            <div style={{ width: '100%', maxWidth: 480, display: 'flex', flexDirection: 'column' }}>
                <div style={{ background: 'var(--gradient-primary)', padding: '26px 22px 22px', textAlign: 'center', position: 'relative', overflow: 'hidden' }}>
                    <div style={{ position: 'absolute', inset: 0, opacity: 0.25, background: 'radial-gradient(circle at 80% -20%, rgba(255,255,255,0.45), transparent 55%)' }} />
                    <div style={{ position: 'relative' }}>
                        <div style={{ fontSize: 11, fontWeight: 800, letterSpacing: 2.5, color: '#a5b4fc', marginBottom: 6 }}>VMS ENTERPRISE</div>
                        <div style={{ fontSize: 24, fontWeight: 800, color: '#ffffff', fontFamily: 'var(--font-heading)', letterSpacing: 0.5 }}>
                            {failed ? 'QR Pass Scan' : passType}
                        </div>
                        {visitor_number && (
                            <div style={{ marginTop: 10, display: 'inline-block', padding: '5px 14px', borderRadius: 999, background: 'rgba(255,255,255,0.14)', border: '1px solid rgba(255,255,255,0.28)', fontFamily: 'monospace', fontSize: 12.5, fontWeight: 700, color: '#ffffff', letterSpacing: 0.5 }}>
                                {visitor_number}
                            </div>
                        )}
                    </div>
                </div>

                {failed ? (
                    <div style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 32 }}>
                        <div style={{ width: '100%', textAlign: 'center', background: 'var(--bg-card)', border: '1px solid var(--border-color)', borderRadius: 'var(--radius-lg)', boxShadow: 'var(--shadow-md)', padding: '36px 24px' }}>
                            <div style={{ width: 64, height: 64, margin: '0 auto 18px', borderRadius: '50%', background: 'rgba(239,68,68,0.12)', border: '1px solid rgba(239,68,68,0.35)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 30, color: '#ef4444', fontWeight: 800 }}>!</div>
                            <h2 style={{ margin: 0, fontFamily: 'var(--font-heading)', fontSize: 20, fontWeight: 800, color: 'var(--text-primary)' }}>{failure.title}</h2>
                            <p style={{ margin: '10px 0 0', fontSize: 14, color: 'var(--text-secondary)', lineHeight: 1.6 }}>
                                {failure.message}
                            </p>
                            {visitor_number && (
                                <div style={{ marginTop: 14, fontFamily: 'monospace', fontSize: 12, color: 'var(--text-muted)', wordBreak: 'break-all' }}>{visitor_number}</div>
                            )}
                            <Link href="/" style={{ display: 'inline-block', marginTop: 22, padding: '10px 20px', borderRadius: 'var(--radius-sm)', background: 'var(--gradient-primary)', color: '#fff', fontSize: 13, fontWeight: 700, textDecoration: 'none' }}>Back to Home</Link>
                        </div>
                    </div>
                ) : (
                    <div style={{ flex: 1, padding: 20 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16, padding: '12px 16px', borderRadius: 'var(--radius-sm)', background: 'rgba(16,185,129,0.10)', border: '1px solid rgba(16,185,129,0.35)' }}>
                            <span style={{ width: 28, height: 28, borderRadius: '50%', background: '#10b981', color: '#fff', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontSize: 15, fontWeight: 800, flexShrink: 0 }}>✓</span>
                            <div>
                                <div style={{ fontSize: 13, fontWeight: 800, color: '#10b981' }}>Visitor Record Found</div>
                                <div style={{ fontSize: 11.5, color: 'var(--text-secondary)' }}>{is_exit ? 'Exit pass verified against the visitor register.' : 'Entry pass verified against the visitor register.'}</div>
                            </div>
                        </div>

                        {visit?.group_visit_id && (
                            <div style={{ marginBottom: 16, padding: '12px 16px', borderRadius: 'var(--radius-sm)', background: visit?.is_representative ? 'linear-gradient(135deg, rgba(139,92,246,0.08), rgba(99,102,241,0.05))' : 'linear-gradient(135deg, rgba(16,185,129,0.08), rgba(6,182,212,0.05))', border: `1px solid ${visit?.is_representative ? 'rgba(139,92,246,0.25)' : 'rgba(16,185,129,0.25)'}` }}>
                                <div style={{ fontWeight: 700, fontSize: 13.5, color: 'var(--text-primary)' }}>{visit?.company_name || 'Company / Group Visit'}</div>
                                <div style={{ fontSize: 11.5, color: 'var(--text-secondary)', marginTop: 2 }}>{visit?.is_representative ? 'Company/Group Visit — Representative' : 'Company/Group Visit — Member'}</div>
                                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8 }}>
                                    {visit?.group_visit_id && <span style={{ fontSize: 10, fontWeight: 700, padding: '3px 8px', borderRadius: 6, background: 'rgba(99,102,241,0.12)', color: 'var(--accent-primary)', border: '1px solid rgba(99,102,241,0.25)' }}>Visit #{visit.group_visit_id}</span>}
                                    {visit?.group_members_count > 0 && <span style={{ fontSize: 10, fontWeight: 700, padding: '3px 8px', borderRadius: 6, background: 'rgba(6,182,212,0.12)', color: '#0891b2', border: '1px solid rgba(6,182,212,0.25)' }}>Members: {visit.group_members_count}</span>}
                                    {visit?.representative_name && <span style={{ fontSize: 10, fontWeight: 700, padding: '3px 8px', borderRadius: 6, background: 'rgba(139,92,246,0.12)', color: '#a78bfa', border: '1px solid rgba(139,92,246,0.25)' }}>Rep: {visit.representative_name}</span>}
                                </div>
                            </div>
                        )}

                        {/* Visitor pass card — mirrors the printed badge */}
                        <div style={{ background: 'var(--bg-card)', border: '1px solid var(--border-color)', borderRadius: 'var(--radius-lg)', boxShadow: 'var(--shadow-md)', overflow: 'hidden ' }}>
                            <div style={{ padding: 18, borderBottom: '1px solid var(--border-color)', display: 'flex', alignItems: 'center', gap: 14 }}>
                                <div style={{ width: 64, height: 64, borderRadius: '50%', flexShrink: 0, overflow: 'hidden', background: 'var(--bg-tertiary)', border: '2px solid var(--border-color)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 24, fontWeight: 800, color: 'var(--text-secondary)' }}>
                                    {visit?.photo_url ? (
                                        <img src={visit.photo_url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                                    ) : (
                                        (visit?.name?.charAt(0) || 'V').toUpperCase()
                                    )}
                                </div>
                                <div style={{ minWidth: 0, flex: 1 }}>
                                    <div style={{ fontSize: 21, fontWeight: 800, color: '#0f172a', fontFamily: 'var(--font-heading)', wordBreak: 'break-word', lineHeight: 1.2 }}>{visit?.name}</div>
                                    {idDocument && <div style={{ fontSize: 12, color: '#64748b', marginTop: 4, fontFamily: 'monospace' }}>{idDocument}</div>}
                                    <div style={{ marginTop: 8, display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                                        {visit?.is_vip && <span style={{ fontSize: 10, fontWeight: 800, padding: '3px 9px', borderRadius: 999, background: 'rgba(236,72,153,0.12)', color: '#ec4899', border: '1px solid rgba(236,72,153,0.35)' }}>VIP</span>}
                                        {visit?.is_representative && <span style={{ fontSize: 10, fontWeight: 800, padding: '3px 9px', borderRadius: 999, background: 'rgba(139,92,246,0.12)', color: '#a78bfa', border: '1px solid rgba(139,92,246,0.35)' }}>REPRESENTATIVE</span>}
                                        {visit?.group_visit_id && <span style={{ fontSize: 10, fontWeight: 800, padding: '3px 9px', borderRadius: 999, background: 'rgba(6,182,212,0.12)', color: '#0891b2', border: '1px solid rgba(6,182,212,0.35)' }}>GROUP VISIT</span>}
                                        {visit?.is_group_member && <span style={{ fontSize: 10, fontWeight: 800, padding: '3px 9px', borderRadius: 999, background: 'rgba(16,185,129,0.12)', color: '#10b981', border: '1px solid rgba(16,185,129,0.35)' }}>GROUP MEMBER</span>}
                                        <span style={{ fontSize: 10, fontWeight: 800, padding: '3px 9px', borderRadius: 999, background: 'rgba(6,182,212,0.12)', color: '#0891b2', border: '1px solid rgba(6,182,212,0.35)' }}>{is_exit ? 'EXIT' : 'ENTRY'}</span>
                                    </div>
                                </div>
                            </div>
                            {visit?.company_name && (
                                <div style={{ padding: '12px 18px', borderBottom: '1px solid var(--border-color)', fontSize: 13, fontWeight: 600, color: 'var(--text-secondary)' }}>
                                    {visit.company_name}
                                </div>
                            )}
                            <div style={{ padding: '6px 18px 14px' }}>
                                {rows.map(([label, value]) => (
                                    <Row key={label} label={label} value={value} />
                                ))}
                            </div>

                            {/* Status footer */}
                            <div style={{ padding: '6px 18px 16px', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
                                <div style={{ padding: '12px 14px', borderRadius: 'var(--radius-sm)', background: meta.bg, border: `1px solid ${meta.border}`, textAlign: 'center' }}>
                                    <div style={{ fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.8, color: 'var(--text-muted)', marginBottom: 4 }}>Visit Status</div>
                                    <div style={{ fontSize: 13, fontWeight: 800, color: meta.color }}>{meta.label}</div>
                                </div>
                                <div style={{ padding: '12px 14px', borderRadius: 'var(--radius-sm)', background: 'var(--bg-primary-subtle)', border: '1px solid var(--border-color)', textAlign: 'center' }}>
                                    <div style={{ fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.8, color: 'var(--text-muted)', marginBottom: 4 }}>Priority</div>
                                    <div style={{ fontSize: 13, fontWeight: 800, color: priority.color, textTransform: 'capitalize' }}>{priority.label}</div>
                                </div>
                            </div>

                            {(visit?.check_in_time || visit?.check_out_time) && (
                                <div style={{ padding: '0 18px 16px', display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                                    {visit.check_in_time && <div style={{ fontSize: 11.5, padding: '8px 12px', borderRadius: 'var(--radius-sm)', background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.25)', color: 'var(--text-secondary)' }}><strong style={{ color: '#10b981' }}>Check-in:</strong> {visit.check_in_time}</div>}
                                    {visit.check_out_time && <div style={{ fontSize: 11.5, padding: '8px 12px', borderRadius: 'var(--radius-sm)', background: 'rgba(139,92,246,0.08)', border: '1px solid rgba(139,92,246,0.25)', color: 'var(--text-secondary)' }}><strong style={{ color: '#a78bfa' }}>Check-out:</strong> {visit.check_out_time}</div>}
                                </div>
                            )}

                            {visit?.properties && visit.properties.length > 0 && (
                                <div style={{ padding: '0 18px 16px' }}>
                                    <div style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: 0.8, color: '#f59e0b', fontWeight: 800, marginBottom: 6 }}>Registered Properties</div>
                                    {visit.properties.map((p, i) => (
                                        <div key={i} style={{ display: 'flex', justifyContent: 'space-between', gap: 10, padding: '7px 10px', marginBottom: 6, borderRadius: 'var(--radius-sm)', background: 'rgba(245,158,11,0.07)', border: '1px solid rgba(245,158,11,0.2)', fontSize: 12, color: 'var(--text-secondary)' }}>
                                            <span style={{ fontWeight: 700, color: 'var(--text-primary)' }}>{p.item_name} × {p.quantity}</span>
                                            <span style={{ textAlign: 'right', wordBreak: 'break-word' }}>
                                                {p.serial_number ? `SN: ${p.serial_number} ` : ''}
                                                {p.property_tag ? `Tag: ${p.property_tag} ` : ''}
                                                {p.status ? `(${String(p.status).replace(/_/g, ' ')})` : ''}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Full visitor information */}
                        <div style={{ marginTop: 16, background: 'var(--bg-card)', border: '1px solid var(--border-color)', borderRadius: 'var(--radius-lg)', boxShadow: 'var(--shadow-md)', overflow: 'hidden' }}>
                            <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--border-color)', fontSize: 12.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: 1.2, color: 'var(--text-muted)' }}>Full Visitor Information</div>
                            <div style={{ padding: '4px 18px 16px' }}>
                                <Field label="Visitor Number" value={visit?.visitor_number} />
                                <Field label="Gender" value={visit?.gender} />
                                <Field label="Age" value={visit?.age ? `${visit.age} years` : null} />
                                <Field label="Nationality" value={visit?.nationality} />
                                <Field label="Phone" value={visit?.phone} />
                                <Field label="Email" value={visit?.email} />
                                <Field label="National ID / Passport" value={visit?.id_document_no} />
                                <Field label="Company / Organization" value={visit?.company_name} />
                                <Field label="Company Position" value={visit?.company_position} />
                                <Field label="Host" value={visit?.host_name} />
                                <Field label="Department" value={visit?.department || visit?.host_department} />
                                <Field label="Branch / Location" value={visit?.branch} />
                                <Field label="Visit Purpose" value={visit?.purpose} />
                                <Field label="Appointment Date" value={visit?.appointment_date ? formatDate(visit.appointment_date) : null} />
                                <Field label="Appointment Time" value={visit?.appointment_time ? formatTime(visit.appointment_time) : null} />
                                <Field label="Duration" value={visit?.duration ? `${visit.duration} min` : null} />
                                <Field label="Meeting Room" value={visit?.meeting_room} />
                                <Field label="Building" value={visit?.building} />
                                <Field label="Floor" value={visit?.floor} />
                            </div>
                        </div>

                        <div style={{ textAlign: 'center', margin: '18px 0 24px' }}>
                            <Link href="/" style={{ display: 'inline-block', padding: '10px 20px', borderRadius: 'var(--radius-sm)', border: '1px solid var(--border-color)', color: 'var(--text-secondary)', fontSize: 13, fontWeight: 700, textDecoration: 'none' }}>Back to Home</Link>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
import React, { useState } from 'react';
import Layout from '../Shared/Layout';
import { useForm, usePage, router } from '@inertiajs/react';
import AppIcon from '../../config/iconMap';
import { fmtDateTime, localToServerDatetime, getMinDatetimeLocal } from '../../utils/timezone';

export default function ManagerDashboard({ department_name, capacity_limit, active_visitors_count, escalations, department_visits, hosts, error }) {
    const { app_timezone } = usePage().props;
    const tz = app_timezone || 'Africa/Addis_Ababa';
    const [comments, setComments] = useState('');
    const [reschedTime, setReschedTime] = useState('');
    const [reschedDuration, setReschedDuration] = useState(60);

    const handleEscalationDecision = (id, decision) => {
        if (decision === 'reschedule') {
            if (!reschedTime) {
                alert('Please select a new date and time.');
                return;
            }
            const serverTime = localToServerDatetime(reschedTime, tz);
            router.post(`/visit-requests/${id}/decision`, {
                decision: 'reschedule',
                comments,
                rescheduled_time: serverTime,
                duration: Number(reschedDuration) || 60,
            }, {
                onSuccess: () => { setComments(''); setReschedTime(''); }
            });
        } else {
            router.post(`/visit-requests/${id}/decision`, {
                decision,
                comments
            }, {
                onSuccess: () => setComments('')
            });
        }
    };

    if (error) {
        return (
            <Layout title="Manager Console">
                <div className="glass-card" style={{ textAlign: 'center', padding: '40px' }}>
                    <div style={{ fontSize: '48px', marginBottom: '16px' }}><AppIcon name="alert" size={48} /></div>
                    <p style={{ color: 'var(--text-secondary)', marginTop: '8px' }}>{error}</p>
                </div>
            </Layout>
        );
    }

    return (
        <Layout title="Manager Department Control">
            <div className="dashboard-header">
                <div className="dashboard-title">
                    <h2>Department Portal: {department_name}</h2>
                    <p>Resolve host escalations, monitor active guest loads, and check host response metrics</p>
                </div>
            </div>

            {/* Department stats */}
            <div className="stats-grid">
                <div className="stat-card">
                    <span className="stat-label">Active Visitors</span>
                    <span className="stat-value emerald">{active_visitors_count}</span>
                </div>
                <div className="stat-card">
                    <span className="stat-label">Occupancy Limit</span>
                    <span className="stat-value cyan">{capacity_limit}</span>
                </div>
                <div className="stat-card">
                    <span className="stat-label">Pending Escalations</span>
                    <span className="stat-value warning">{escalations.length}</span>
                </div>
                <div className="stat-card">
                    <span className="stat-label">Active Hosts</span>
                    <span className="stat-value" style={{ color: 'var(--accent-purple)' }}>{hosts.length}</span>
                </div>
            </div>

            {/* Escalations Panel */}
            <div className="glass-card" style={{ marginBottom: '32px' }}>
                <h3 style={{ fontFamily: 'var(--font-heading)', marginBottom: '16px' }}>Escalated Visit Requests</h3>
                {escalations.length === 0 ? (
                    <p style={{ color: 'var(--text-muted)', fontSize: '14px' }}>No escalated requests require department review.</p>
                ) : (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
                        <div style={{ display: 'flex', gap: '16px', flexWrap: 'wrap', alignItems: 'flex-end' }}>
                            <div className="form-group" style={{ marginBottom: 0, flex: 1, minWidth: '220px' }}>
                                <label className="form-label">Decision Note / Rejection Reason</label>
                                <input 
                                    type="text" 
                                    className="form-input" 
                                    placeholder="Enter approval conditions or rejection reasons..."
                                    value={comments}
                                    onChange={e => setComments(e.target.value)}
                                />
                            </div>
                            <div className="form-group" style={{ marginBottom: 0 }}>
                                <label className="form-label">Reschedule To</label>
                                <input
                                    type="datetime-local"
                                    className="form-input"
                                    style={{ width: '210px', padding: '8px' }}
                                    value={reschedTime}
                                    onChange={e => setReschedTime(e.target.value)}
                                    min={getMinDatetimeLocal()}
                                />
                            </div>
                            <div className="form-group" style={{ marginBottom: 0 }}>
                                <label className="form-label">Duration (min)</label>
                                <input
                                    type="number"
                                    className="form-input"
                                    style={{ width: '110px', padding: '8px' }}
                                    value={reschedDuration}
                                    min={15}
                                    max={480}
                                    onChange={e => setReschedDuration(e.target.value)}
                                />
                            </div>
                        </div>
                        <div className="table-container" style={{ marginBottom: 0 }}>
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Visitor</th>
                                        <th>Purpose</th>
                                        <th>Host</th>
                                        <th>Scheduled Time</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {escalations.map((esc) => (
                                        <tr key={esc.id}>
                                            <td>
                                                <div style={{ fontWeight: '600' }}>{esc.visitor.name}</div>
                                                <div style={{ fontSize: '11px', color: 'var(--text-secondary)' }}>{esc.visitor.email}</div>
                                            </td>
                                            <td>{esc.purpose}</td>
                                            <td>{esc.host.name}</td>
                                            <td>{fmtDateTime(esc.requested_time)}</td>
                                            <td>
                                                <div style={{ display: 'flex', gap: '8px' }}>
                                                    <button onClick={() => handleEscalationDecision(esc.id, 'approve')} className="btn btn-primary" style={{ padding: '4px 10px', fontSize: '12px' }}>Approve</button>
                                                    <button onClick={() => handleEscalationDecision(esc.id, 'reject')} className="btn btn-danger" style={{ padding: '4px 10px', fontSize: '12px' }}>Decline</button>
                                                    <button onClick={() => handleEscalationDecision(esc.id, 'reschedule')} className="btn btn-secondary" style={{ padding: '4px 10px', fontSize: '12px' }}>Reschedule</button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>

            {/* Department hosts list */}
            <div className="glass-card" style={{ marginBottom: '32px' }}>
                <h3 style={{ fontFamily: 'var(--font-heading)', marginBottom: '16px' }}>Host Performance Metrics</h3>
                <div className="table-container" style={{ marginBottom: 0 }}>
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Total Handled</th>
                                <th>Approved</th>
                            </tr>
                        </thead>
                        <tbody>
                            {hosts.map((host) => (
                                <tr key={host.id}>
                                    <td>{host.name}</td>
                                    <td>{host.email}</td>
                                    <td><span className="badge badge-approved">{host.status}</span></td>
                                    <td>{host.total_visits}</td>
                                    <td>{host.approved_visits}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* All department visits logs */}
            <div className="glass-card">
                <h3 style={{ fontFamily: 'var(--font-heading)', marginBottom: '16px' }}>Department Visit Log</h3>
                <div className="table-container" style={{ marginBottom: 0 }}>
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Visitor</th>
                                <th>Host</th>
                                <th>Time</th>
                                <th>Purpose</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {department_visits.map((visit) => (
                                <tr key={visit.id}>
                                    <td>{visit.visitor.name}</td>
                                    <td>{visit.host.name}</td>
                                    <td>{fmtDateTime(visit.requested_time)}</td>
                                    <td>{visit.purpose}</td>
                                    <td>
                                        <span className={`badge badge-${visit.status}`}>
                                            {visit.status.replace(/_/g, ' ')}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </Layout>
    );
}

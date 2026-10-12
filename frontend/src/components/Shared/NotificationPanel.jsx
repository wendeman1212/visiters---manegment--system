import React, { useState, useEffect, useRef, useCallback } from 'react';
import AppIcon from '../../config/iconMap';

function getCsrfToken() {
    const match = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
}

async function apiPost(url) {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
    });
    return res.json();
}

async function apiGet(url) {
    const res = await fetch(url, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
    });
    return res.json();
}

const EMPTY_NOTIFICATIONS = [];

export default function NotificationPanel({
    notifications: initialNotifications = EMPTY_NOTIFICATIONS,
    unread_count: initialUnreadCount = 0,
    isVisitor = false,
    pollInterval = null,
}) {
    const [open, setOpen] = useState(false);
    const [notifications, setNotifications] = useState(initialNotifications);
    const [unreadCount, setUnreadCount] = useState(initialUnreadCount);
    const panelRef = useRef(null);
    const bellRef = useRef(null);
    const pollRef = useRef(null);

    const apiBase = isVisitor ? '/api/visitor/notifications' : '/api/notifications';
    // Employees receive CEO emergency broadcasts, so poll frequently so the
    // badge updates without a page refresh. Visitors get a slower cadence.
    const effectivePoll = pollInterval ?? (isVisitor ? 30000 : 5000);

    // Sync with props when they change (Inertia navigation)
    useEffect(() => {
        setNotifications(initialNotifications);
        setUnreadCount(initialUnreadCount);
    }, [initialNotifications, initialUnreadCount]);

    // Close dropdown on outside click
    useEffect(() => {
        function handleClickOutside(e) {
            if (panelRef.current && !panelRef.current.contains(e.target) && bellRef.current && !bellRef.current.contains(e.target)) {
                setOpen(false);
            }
        }
        if (open) {
            document.addEventListener('mousedown', handleClickOutside);
            return () => document.removeEventListener('mousedown', handleClickOutside);
        }
    }, [open]);

    // Poll for new notifications
    const fetchNotifications = useCallback(async () => {
        try {
            const data = await apiGet(apiBase);
            if (data.notifications) {
                setNotifications(data.notifications);
                setUnreadCount(data.unread_count || 0);
            }
        } catch {
            // Silently fail - will retry on next poll
        }
    }, [apiBase]);

    // Fetch immediately on mount (and whenever the API base changes) so the
    // bell badge and dropdown are populated right away, not only after the
    // first poll interval fires.
    useEffect(() => {
        fetchNotifications();
    }, [fetchNotifications]);

    // Refresh the badge the moment the user returns to the tab/window so new
    // emergency notifications show up without waiting for the next poll tick.
    useEffect(() => {
        const onVisible = () => {
            if (document.visibilityState === 'visible') fetchNotifications();
        };
        const onFocus = () => fetchNotifications();
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onFocus);
        return () => {
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('focus', onFocus);
        };
    }, [fetchNotifications]);

    useEffect(() => {
        if (effectivePoll > 0) {
            pollRef.current = setInterval(fetchNotifications, effectivePoll);
            return () => clearInterval(pollRef.current);
        }
    }, [fetchNotifications, effectivePoll]);

    const handleMarkRead = async (id) => {
        try {
            const data = await apiPost(`${apiBase}/${id}/read`);
            if (data.success !== false) {
                setNotifications(prev =>
                    prev.map(n => n.id === id ? { ...n, is_read: true, read_at: new Date().toISOString() } : n)
                );
                setUnreadCount(data.unread_count ?? Math.max(0, unreadCount - 1));
            }
        } catch {
            // Silently fail
        }
    };

    const handleMarkAllRead = async () => {
        try {
            const data = await apiPost(`${apiBase}/mark-all-read`);
            if (data.success !== false) {
                setNotifications(prev =>
                    prev.map(n => ({ ...n, is_read: true, read_at: n.read_at || new Date().toISOString() }))
                );
                setUnreadCount(0);
            }
        } catch {
            // Silently fail
        }
    };

    const handleCancel = async (e, id) => {
        e.stopPropagation();
        try {
            const data = await apiPost(`${apiBase}/${id}/cancel`);
            if (data.success !== false) {
                setNotifications(prev => prev.filter(n => n.id !== id));
                setUnreadCount(data.unread_count ?? Math.max(0, unreadCount - 1));
            }
        } catch {
            // Silently fail
        }
    };

    // The panel is the unread queue: a notification leaves the list the moment
    // it is marked read (individual click or "Mark all read"), so only unread
    // items remain visible and read ones never return.
    const displayNotifications = notifications
        .filter(n => n && (n.id || n.created_at) && !n.is_read)
        .sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));

    const formatTime = (dt) => {
        if (!dt) return '';
        const d = new Date(dt);
        const now = new Date();
        const diffMs = now - d;
        const diffMins = Math.floor(diffMs / 60000);
        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins}m ago`;
        const diffHours = Math.floor(diffMins / 60);
        if (diffHours < 24) return `${diffHours}h ago`;
        const diffDays = Math.floor(diffHours / 24);
        if (diffDays < 7) return `${diffDays}d ago`;
        return d.toLocaleDateString();
    };

    return (
        <div style={{ position: 'relative', display: 'inline-flex' }}>
            {/* Bell Button */}
            <button
                ref={bellRef}
                onClick={() => {
                    const next = !open;
                    setOpen(next);
                    if (next) fetchNotifications();
                }}
                style={{
                    position: 'relative',
                    background: unreadCount > 0 ? 'rgba(245,158,11,0.12)' : 'rgba(255,255,255,0.06)',
                    border: `1px solid ${unreadCount > 0 ? 'rgba(245,158,11,0.35)' : 'rgba(255,255,255,0.1)'}`,
                    borderRadius: 8,
                    width: 38,
                    height: 38,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    cursor: 'pointer',
                    color: unreadCount > 0 ? '#fbbf24' : 'var(--text-secondary)',
                    fontSize: 16,
                    transition: 'all 0.2s',
                    flexShrink: 0,
                }}
                title="Notifications"
            >
                <AppIcon name="bell" size={18} />
                {unreadCount > 0 && (
                    <span style={{
                        position: 'absolute',
                        top: -4,
                        right: -4,
                        background: '#ef4444',
                        color: '#fff',
                        borderRadius: '50%',
                        minWidth: 18,
                        height: 18,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        fontSize: 10,
                        fontWeight: 800,
                        padding: '0 4px',
                        lineHeight: 1,
                    }}>
                        {unreadCount > 99 ? '99+' : unreadCount}
                    </span>
                )}
            </button>

            {/* Dropdown Panel */}
            {open && (
                <div
                    ref={panelRef}
                    style={{
                        position: 'absolute',
                        top: 'calc(100% + 8px)',
                        right: 0,
                        width: 380,
                        maxHeight: 480,
                        background: 'var(--bg-card, #1e1e2e)',
                        border: '1px solid var(--glass-border, rgba(255,255,255,0.1))',
                        borderRadius: 12,
                        boxShadow: '0 8px 32px rgba(0,0,0,0.4)',
                        zIndex: 1000,
                        overflow: 'hidden',
                        display: 'flex',
                        flexDirection: 'column',
                    }}
                >
                    {/* Header */}
                        <div style={{ padding: '14px 16px', borderBottom: '1px solid rgba(255,255,255,0.07)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                            <div style={{ fontSize: 12, fontWeight: 700, color: '#fbbf24' }}>
                                Notifications{unreadCount > 0 && ` (${unreadCount} new)`}
                            </div>
                            {unreadCount > 0 && (
                            <button
                                onClick={handleMarkAllRead}
                                style={{
                                    background: 'none',
                                    border: 'none',
                                    fontSize: 11,
                                    color: 'var(--accent-primary)',
                                    cursor: 'pointer',
                                    fontWeight: 600,
                                    padding: '2px 6px',
                                }}
                            >
                                Mark all read
                            </button>
                        )}
                    </div>

                    {/* Notification List */}
                    <div style={{ flex: 1, overflowY: 'auto', maxHeight: 380 }}>
                        {displayNotifications.length === 0 ? (
                            <div style={{ padding: '32px 16px', textAlign: 'center' }}>
                                <div style={{ fontSize: 28, marginBottom: 8, opacity: 0.4 }}>
                                    <AppIcon name="bell" size={28} />
                                </div>
                                <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>
                                    No notifications yet.
                                </p>
                            </div>
                        ) : (
                            displayNotifications.map(n => (
                                <div
                                    key={n.id}
                                    onClick={() => {
                                        if (!n.is_read) handleMarkRead(n.id);
                                    }}
                                    style={{
                                        padding: '12px 16px',
                                        borderBottom: '1px solid rgba(255,255,255,0.04)',
                                        cursor: n.is_read ? 'default' : 'pointer',
                                        background: n.is_read ? 'transparent' : 'rgba(245,158,11,0.04)',
                                        transition: 'background 0.15s',
                                        display: 'flex',
                                        gap: 10,
                                        alignItems: 'flex-start',
                                    }}
                                    onMouseEnter={e => { e.currentTarget.style.background = n.is_read ? 'rgba(255,255,255,0.03)' : 'rgba(245,158,11,0.08)'; }}
                                    onMouseLeave={e => { e.currentTarget.style.background = n.is_read ? 'transparent' : 'rgba(245,158,11,0.04)'; }}
                                >
                                    {/* Unread indicator */}
                                    <div style={{ width: 8, height: 8, borderRadius: '50%', background: n.is_read ? 'transparent' : '#f59e0b', flexShrink: 0, marginTop: 5 }} />

                                    <div style={{ flex: 1, minWidth: 0 }}>
                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 2 }}>
                                            <span style={{ fontSize: 12, fontWeight: n.is_read ? 600 : 700, color: n.is_read ? 'var(--text-secondary)' : 'var(--text-primary)' }}>
                                                {n.title || n.event}
                                            </span>
                                            <span style={{ fontSize: 10, color: 'var(--text-muted)', whiteSpace: 'nowrap', marginLeft: 8 }}>
                                                {formatTime(n.created_at)}
                                            </span>
                                        </div>
                                        <p style={{ fontSize: 12, color: 'var(--text-secondary)', margin: 0, lineHeight: 1.4, overflow: 'hidden', textOverflow: 'ellipsis', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical' }}>
                                            {n.message}
                                        </p>
                                        {n.event && (
                                            <span style={{ display: 'inline-block', marginTop: 4, fontSize: 10, padding: '1px 6px', borderRadius: 4, background: 'rgba(99,102,241,0.1)', color: 'var(--accent-primary)', fontWeight: 600 }}>
                                                {n.event.replace(/_/g, ' ')}
                                            </span>
                                        )}
                                    </div>
                                    <button
                                        onClick={e => handleCancel(e, n.id)}
                                        title="Cancel notification"
                                        style={{
                                            background: 'none',
                                            border: 'none',
                                            cursor: 'pointer',
                                            color: 'var(--text-muted)',
                                            fontSize: 12,
                                            padding: '2px 4px',
                                            flexShrink: 0,
                                            borderRadius: 4,
                                            marginTop: 2,
                                        }}
                                        onMouseEnter={e => { e.currentTarget.style.color = '#ef4444'; e.currentTarget.style.background = 'rgba(239,68,68,0.1)'; }}
                                        onMouseLeave={e => { e.currentTarget.style.color = 'var(--text-muted)'; e.currentTarget.style.background = 'none'; }}
                                    >
                                        <AppIcon name="close" size={12} />
                                    </button>
                                </div>
                            ))
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

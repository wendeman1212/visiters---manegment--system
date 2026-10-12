import React, { useState, useCallback } from 'react';
import Layout from '../Shared/Layout';
import { router, usePage } from '@inertiajs/react';
import AppIcon from '../../config/iconMap';

export default function Messages({
    inbox = [],
    sent = [],
    recipients = [],
    unread_count = 0,
    folder = 'inbox',
    sender_type,
    sender_id,
    embedded = false,
}) {
    const [activeFolder, setActiveFolder] = useState(folder);
    const [searchTerm, setSearchTerm] = useState('');
    const [showCompose, setShowCompose] = useState(false);
    const [openThread, setOpenThread] = useState(null);
    const [threadData, setThreadData] = useState(null);
    const [loadingThread, setLoadingThread] = useState(false);

    const [composeTo, setComposeTo] = useState('');
    const [composeSubject, setComposeSubject] = useState('');
    const [composeBody, setComposeBody] = useState('');
    const [replyBody, setReplyBody] = useState('');
    const [sending, setSending] = useState(false);
    const [sendSuccess, setSendSuccess] = useState('');
    const [sendError, setSendError] = useState('');

    const baseUrl = sender_type === 'visitor' ? '/visitor' : '';

    const postJson = useCallback(async (url, data) => {
        const csrf = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1] || '';
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(csrf),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(data),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return json;
    }, []);

    const deleteJson = useCallback(async (url) => {
        const csrf = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1] || '';
        const res = await fetch(url, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(csrf),
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        return res.json();
    }, []);

    const getJson = useCallback(async (url) => {
        const res = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        return res.json();
    }, []);

    const fetchThread = useCallback((id) => {
        setLoadingThread(true);
        setOpenThread(id);
        getJson(`${baseUrl}/messages/${id}/thread`)
            .then(data => { setThreadData(data); setLoadingThread(false); })
            .catch(() => setLoadingThread(false));
    }, [baseUrl, getJson]);

    const handleSend = async (e) => {
        e.preventDefault();
        setSending(true);
        setSendError('');
        setSendSuccess('');

        const parts = composeTo.split(':');
        const recipientType = parts[0] || '';
        const recipientId = parseInt(parts[1]) || 0;

        if (!recipientType || !recipientId || (recipientType !== 'user' && recipientType !== 'visitor')) {
            setSendError('Please select a valid recipient.');
            setSending(false);
            return;
        }
        if (!composeBody.trim()) {
            setSendError('Please type a message.');
            setSending(false);
            return;
        }
        if (composeSubject && composeSubject.length > 190) {
            setSendError('Subject must not exceed 190 characters.');
            setSending(false);
            return;
        }
        if (composeBody.length > 5000) {
            setSendError('Message must not exceed 5000 characters.');
            setSending(false);
            return;
        }

        try {
            await postJson(`${baseUrl}/messages/send`, {
                recipient_type: recipientType,
                recipient_id: recipientId,
                subject: composeSubject,
                body: composeBody,
            });
            setSendSuccess('Message sent successfully!');
            setComposeTo('');
            setComposeSubject('');
            setComposeBody('');
            setTimeout(() => {
                setShowCompose(false);
                setSendSuccess('');
                router.reload({ only: ['messages_sent', 'messages_inbox', 'messages_unread_count'] });
            }, 1200);
        } catch (err) {
            if (err && err.errors) {
                setSendError(Object.values(err.errors).flat().join(' '));
            } else if (err && err.message) {
                setSendError(err.message);
            } else {
                setSendError('Failed to send message. Please try again.');
            }
        } finally {
            setSending(false);
        }
    };

    const handleReply = async (e) => {
        e.preventDefault();
        if (!replyBody.trim() || !openThread) return;
        if (replyBody.length > 5000) {
            setSendError('Reply must not exceed 5000 characters.');
            return;
        }

        try {
            await postJson(`${baseUrl}/messages/${openThread}/reply`, { body: replyBody });
            setReplyBody('');
            fetchThread(openThread);
        } catch (err) {
            // Silently fail for replies
        }
    };

    const handleDelete = async (id) => {
        if (!confirm('Delete this message?')) return;
        try {
            await deleteJson(`${baseUrl}/messages/${id}`);
            if (openThread === id) {
                setOpenThread(null);
                setThreadData(null);
            }
            router.reload({ only: ['messages_inbox', 'messages_sent'] });
        } catch (err) {
            // Silently fail
        }
    };

    const messages = activeFolder === 'inbox' ? inbox : sent;
    const filtered = messages.filter(m =>
        (m.subject || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
        (m.body || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
        (m.sender_name || m.recipient_name || '').toLowerCase().includes(searchTerm.toLowerCase())
    );

    const selfFilter = (r) => {
        if (r.type !== sender_type) return true;
        return r.id !== sender_id;
    };

    const content = (
        <div style={{ padding: embedded ? '0' : '24px', maxWidth: '1200px', margin: embedded ? '0' : '0 auto' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '24px' }}>
                <div>
                    <h2 style={{
                        fontFamily: 'var(--font-heading)', fontSize: 22, fontWeight: 700,
                        background: 'var(--gradient-primary)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent',
                    }}>
                        <AppIcon name="mail" /> Messages
                    </h2>
                    <p style={{ color: 'var(--text-secondary)', fontSize: '13px', margin: '4px 0 0' }}>
                        Direct communication between users and visitors in your company and branch
                    </p>
                </div>
                <button
                    onClick={() => { setShowCompose(!showCompose); setOpenThread(null); setThreadData(null); }}
                    className="btn btn-primary"
                    style={{ display: 'flex', alignItems: 'center', gap: '6px' }}
                >
                    <AppIcon name="plus" /> Compose
                </button>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '280px 1fr', gap: '20px', minHeight: '500px' }}>
                <div>
                    <div className="glass-card" style={{ padding: 0, overflow: 'hidden' }}>
                        <div style={{ padding: '12px', borderBottom: '1px solid var(--glass-border)' }}>
                            <input
                                type="text"
                                className="form-input"
                                placeholder="Search messages..."
                                value={searchTerm}
                                onChange={e => setSearchTerm(e.target.value)}
                                style={{ fontSize: '12px', padding: '8px 10px' }}
                            />
                        </div>

                        <button
                            onClick={() => { setActiveFolder('inbox'); setOpenThread(null); setThreadData(null); }}
                            style={{
                                width: '100%', textAlign: 'left', border: 'none', cursor: 'pointer',
                                borderLeft: activeFolder === 'inbox' ? '3px solid #ffffff' : '3px solid transparent',
                                background: activeFolder === 'inbox' ? 'rgba(255,255,255,0.2)' : 'transparent',
                                color: '#ffffff', fontWeight: activeFolder === 'inbox' ? 700 : 500,
                                fontSize: 13, padding: '12px 16px', display: 'flex', justifyContent: 'space-between',
                            }}
                        >
                            <span><AppIcon name="inbox" /> Inbox</span>
                            {unread_count > 0 && (
                                <span style={{ background: '#f59e0b', color: '#fff', borderRadius: 10, padding: '1px 7px', fontSize: 10, fontWeight: 800 }}>
                                    {unread_count}
                                </span>
                            )}
                        </button>
                        <button
                            onClick={() => { setActiveFolder('sent'); setOpenThread(null); setThreadData(null); }}
                            style={{
                                width: '100%', textAlign: 'left', border: 'none', cursor: 'pointer',
                                borderLeft: activeFolder === 'sent' ? '3px solid #ffffff' : '3px solid transparent',
                                background: activeFolder === 'sent' ? 'rgba(255,255,255,0.2)' : 'transparent',
                                color: '#ffffff', fontWeight: activeFolder === 'sent' ? 700 : 500,
                                fontSize: 13, padding: '12px 16px',
                            }}
                        >
                            <span><AppIcon name="send" /> Sent</span>
                        </button>

                        <div style={{ maxHeight: '420px', overflowY: 'auto' }}>
                            {filtered.length === 0 ? (
                                <div style={{ padding: '24px 16px', textAlign: 'center', color: 'var(--text-muted)', fontSize: '13px' }}>
                                    {activeFolder === 'inbox' ? 'No messages in inbox.' : 'No sent messages.'}
                                </div>
                            ) : (
                                filtered.map(msg => {
                                    const isActive = openThread === msg.id;
                                    const otherName = activeFolder === 'inbox' ? msg.sender_name : msg.recipient_name;
                                    return (
                                        <div
                                            key={msg.id}
                                            onClick={() => fetchThread(msg.id)}
                                            style={{
                                                padding: '10px 16px', cursor: 'pointer', borderLeft: isActive ? '3px solid var(--accent-cyan)' : '3px solid transparent',
                                                background: isActive ? 'rgba(0,240,255,0.08)' : (!msg.is_read && activeFolder === 'inbox' ? 'rgba(245,158,11,0.05)' : 'transparent'),
                                                borderBottom: '1px solid rgba(255,255,255,0.04)',
                                                transition: 'all 0.15s',
                                            }}
                                        >
                                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '2px' }}>
                                                <span style={{ fontSize: '12px', fontWeight: !msg.is_read && activeFolder === 'inbox' ? 700 : 500, color: 'var(--text-primary)' }}>
                                                    {otherName}
                                                </span>
                                                {!msg.is_read && activeFolder === 'inbox' && (
                                                    <span style={{ width: 6, height: 6, borderRadius: '50%', background: '#f59e0b', flexShrink: 0 }} />
                                                )}
                                            </div>
                                            {msg.sender_phone && (
                                                <div style={{ fontSize: '10px', color: 'var(--text-muted)', marginBottom: '1px' }}>
                                                    <AppIcon name="phone" size={10} /> {msg.sender_phone}
                                                </div>
                                            )}
                                            {msg.sender_email && (
                                                <div style={{ fontSize: '10px', color: 'var(--text-muted)', marginBottom: '1px' }}>
                                                    <AppIcon name="mail" size={10} /> {msg.sender_email}
                                                </div>
                                            )}
                                            <div style={{ fontSize: '11px', fontWeight: 600, color: 'var(--text-secondary)', marginBottom: '2px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                {msg.subject}
                                            </div>
                                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                                <span style={{ fontSize: '10px', color: 'var(--text-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: '140px' }}>
                                                    {msg.body}
                                                </span>
                                                <span style={{ fontSize: '9px', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{msg.created_at}</span>
                                            </div>
                                            {msg.reply_count > 0 && (
                                                <span style={{ fontSize: '9px', color: 'var(--accent-cyan)', marginTop: '2px', display: 'inline-block' }}>
                                                    {msg.reply_count} {msg.reply_count === 1 ? 'reply' : 'replies'}
                                                </span>
                                            )}
                                        </div>
                                    );
                                })
                            )}
                        </div>
                    </div>
                </div>

                <div>
                    {showCompose && (
                        <div className="glass-card" style={{ marginBottom: '16px', borderColor: 'rgba(99,102,241,0.4)', background: 'rgba(99,102,241,0.03)' }}>
                            <h3 style={{ fontFamily: 'var(--font-heading)', fontSize: '15px', fontWeight: 700, marginBottom: '16px', color: '#818cf8' }}>
                                <AppIcon name="edit" /> Compose New Message
                            </h3>
                            {sendSuccess && (
                                <div style={{ padding: '10px 14px', borderRadius: '8px', background: 'rgba(16,185,129,0.1)', border: '1px solid rgba(16,185,129,0.3)', color: '#6ee7b7', fontSize: '13px', marginBottom: '12px' }}>
                                    {sendSuccess}
                                </div>
                            )}
                            {sendError && (
                                <div style={{ padding: '10px 14px', borderRadius: '8px', background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)', color: '#fca5a5', fontSize: '13px', marginBottom: '12px' }}>
                                    {sendError}
                                </div>
                            )}
                            <form onSubmit={handleSend} style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                                <div>
                                    <label style={{ fontSize: '12px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>To *</label>
                                    <select
                                        className="form-input"
                                        value={composeTo}
                                        onChange={e => setComposeTo(e.target.value)}
                                        required
                                    >
                                        <option value="">Select recipient...</option>
                                        {recipients.filter(selfFilter).map(r => (
                                            <option key={`${r.type}-${r.id}`} value={`${r.type}:${r.id}`}>
                                                {r.name} ({r.role}){r.phone ? ` — ${r.phone}` : ''}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label style={{ fontSize: '12px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Subject</label>
                                    <input
                                        type="text"
                                        className="form-input"
                                        value={composeSubject}
                                        onChange={e => setComposeSubject(e.target.value)}
                                        placeholder="Message subject (optional)"
                                        maxLength={190}
                                    />
                                </div>
                                <div>
                                    <label style={{ fontSize: '12px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Message *</label>
                                    <textarea
                                        className="form-input"
                                        rows={5}
                                        value={composeBody}
                                        onChange={e => setComposeBody(e.target.value)}
                                        placeholder="Type your message here..."
                                        maxLength={5000}
                                        required
                                        style={{ resize: 'vertical' }}
                                    />
                                </div>
                                <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end' }}>
                                    <span style={{ fontSize: '10px', color: 'var(--text-muted)', alignSelf: 'center', marginRight: '8px' }}>
                                        Delivered via email + SMS
                                    </span>
                                    <button type="button" onClick={() => setShowCompose(false)} className="btn btn-secondary" style={{ fontSize: '12px' }}>
                                        Cancel
                                    </button>
                                    <button type="submit" disabled={sending} className="btn btn-primary" style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                                        {sending ? 'Sending...' : <><AppIcon name="send" /> Send</>}
                                    </button>
                                </div>
                            </form>
                        </div>
                    )}

                    {openThread && threadData && (
                        <div className="glass-card" style={{ borderColor: 'rgba(0,240,255,0.3)' }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '16px', paddingBottom: '12px', borderBottom: '1px solid var(--glass-border)' }}>
                                <div>
                                    <h3 style={{ fontFamily: 'var(--font-heading)', fontSize: '16px', fontWeight: 700, margin: 0 }}>
                                        {threadData.subject}
                                    </h3>
                                    <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
                                        From <strong>{threadData.sender_name}</strong> to <strong>{threadData.recipient_name}</strong> · {threadData.created_at}
                                    </div>
                                    {(threadData.sender_phone || threadData.sender_email) && (
                                        <div style={{ fontSize: '10px', color: 'var(--text-muted)', marginTop: '2px' }}>
                                            {threadData.sender_phone && <span><AppIcon name="phone" size={10} /> {threadData.sender_phone} · </span>}
                                            {threadData.sender_email && <span><AppIcon name="mail" size={10} /> {threadData.sender_email}</span>}
                                        </div>
                                    )}
                                    {(threadData.recipient_phone || threadData.recipient_email) && (
                                        <div style={{ fontSize: '10px', color: 'var(--text-muted)', marginTop: '2px' }}>
                                            To: {threadData.recipient_phone && <span><AppIcon name="phone" size={10} /> {threadData.recipient_phone} · </span>}
                                            {threadData.recipient_email && <span><AppIcon name="mail" size={10} /> {threadData.recipient_email}</span>}
                                        </div>
                                    )}
                                </div>
                                <div style={{ display: 'flex', gap: '6px' }}>
                                    <button onClick={() => { setOpenThread(null); setThreadData(null); }} className="btn btn-secondary" style={{ padding: '4px 10px', fontSize: '11px' }}>
                                        Back
                                    </button>
                                    <button onClick={() => handleDelete(threadData.id)} className="btn btn-danger" style={{ padding: '4px 10px', fontSize: '11px' }}>
                                        Delete
                                    </button>
                                </div>
                            </div>

                            <div style={{ background: 'rgba(255,255,255,0.02)', border: '1px solid rgba(255,255,255,0.06)', borderRadius: '8px', padding: '14px', marginBottom: '16px' }}>
                                <div style={{ fontSize: '13px', color: 'var(--text-primary)', lineHeight: 1.6, whiteSpace: 'pre-wrap' }}>
                                    {threadData.body}
                                </div>
                            </div>

                            {threadData.replies && threadData.replies.length > 0 && (
                                <div style={{ marginBottom: '16px' }}>
                                    <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginBottom: '8px', fontWeight: 600 }}>
                                        {threadData.replies.length} {threadData.replies.length === 1 ? 'Reply' : 'Replies'}
                                    </div>
                                    {threadData.replies.map(reply => {
                                        const isMine = reply.sender_type === sender_type && reply.sender_id === sender_id;
                                        return (
                                            <div key={reply.id} style={{
                                                background: isMine ? 'rgba(99,102,241,0.06)' : 'rgba(0,240,255,0.04)',
                                                border: `1px solid ${isMine ? 'rgba(99,102,241,0.2)' : 'rgba(0,240,255,0.15)'}`,
                                                borderRadius: '8px', padding: '12px', marginBottom: '8px',
                                                marginLeft: isMine ? '24px' : '0',
                                                marginRight: isMine ? '0' : '24px',
                                            }}>
                                                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '6px' }}>
                                                    <span style={{ fontSize: '11px', fontWeight: 700, color: isMine ? '#818cf8' : 'var(--accent-cyan)' }}>
                                                        {reply.sender_name} {isMine && '(You)'}
                                                    </span>
                                                    <span style={{ fontSize: '9px', color: 'var(--text-muted)' }}>{reply.created_at}</span>
                                                </div>
                                                <div style={{ fontSize: '13px', color: 'var(--text-primary)', lineHeight: 1.5, whiteSpace: 'pre-wrap' }}>
                                                    {reply.body}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}

                            <form onSubmit={handleReply} style={{ display: 'flex', gap: '8px', marginTop: '12px' }}>
                                <input
                                    type="text"
                                    className="form-input"
                                    value={replyBody}
                                    onChange={e => setReplyBody(e.target.value)}
                                    placeholder="Type your reply..."
                                    style={{ flex: 1, fontSize: '13px' }}
                                />
                                <button type="submit" disabled={!replyBody.trim()} className="btn btn-primary" style={{ display: 'flex', alignItems: 'center', gap: '4px', fontSize: '12px' }}>
                                    <AppIcon name="send" /> Reply
                                </button>
                            </form>
                        </div>
                    )}

                    {loadingThread && (
                        <div className="glass-card" style={{ textAlign: 'center', padding: '40px' }}>
                            <p style={{ color: 'var(--text-muted)', fontSize: '13px' }}>Loading thread...</p>
                        </div>
                    )}

                    {!openThread && !showCompose && !loadingThread && (
                        <div className="glass-card" style={{ textAlign: 'center', padding: '60px 24px' }}>
                            <AppIcon name="mail" />
                            <h3 style={{ fontFamily: 'var(--font-heading)', fontSize: '18px', marginTop: '16px', color: 'var(--text-primary)' }}>
                                {activeFolder === 'inbox' ? 'Your Inbox' : 'Sent Messages'}
                            </h3>
                            <p style={{ color: 'var(--text-muted)', fontSize: '13px', marginTop: '8px', maxWidth: '340px', margin: '8px auto 0' }}>
                                {activeFolder === 'inbox'
                                    ? 'Select a message from the sidebar to read and reply.'
                                    : 'Messages you have sent will appear here.'}
                            </p>
                            <button
                                onClick={() => setShowCompose(true)}
                                className="btn btn-primary"
                                style={{ marginTop: '20px', display: 'inline-flex', alignItems: 'center', gap: '6px' }}
                            >
                                <AppIcon name="plus" /> Compose New Message
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );

    if (embedded) return content;
    return <Layout>{content}</Layout>;
}

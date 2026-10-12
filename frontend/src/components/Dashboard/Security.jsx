import React, { useState, useEffect, useCallback, useRef } from 'react';
import Layout from '../Shared/Layout';
import { useForm, usePage, router, Link } from '@inertiajs/react';
import AppIcon from '../../config/iconMap';
import Messages from './Messages';
import { fmtDateTime, fmtTime } from '../../utils/timezone';

export default function SecurityDashboard({
    stats, today_arrivals, current_visitors, pending_arrivals,
    today_checkins, today_checkouts, denied_entries,
    active_incidents, blacklist, live_count,
    security_alerts, notifications, unread_count, page,
    report_stats, report_visitors, report_incidents, report_alerts, report_period, report_range,
    watchlist, badge_templates, arrivals_with_qr, risk_summary,
    host_change_requests,
    section,
    messages_inbox, messages_sent, messages_recipients, messages_unread_count,
}) {
    const { auth } = usePage().props;
    const validTabsArr = ['dashboard', 'entry_gate', 'live', 'risk', 'incidents', 'reports', 'messages'];
    const [activeTab, setActiveTab] = useState(() => {
        if (page === 'daily_report') return 'reports';
        if (section && validTabsArr.includes(section)) return section;
        const hash = window.location.hash.replace('#', '');
        return validTabsArr.includes(hash) ? hash : 'dashboard';
    });

    useEffect(() => {
        if (page === 'daily_report') setActiveTab('reports');
    }, [page]);
    const [sidebarOpen, setSidebarOpen] = useState(true);
    const [tokenInput, setTokenInput] = useState('');
    const [scanResult, setScanResult] = useState(null);
    const [scanError, setScanError] = useState('');
    const [scanQrStatus, setScanQrStatus] = useState('');
    const [idVerifyInput, setIdVerifyInput] = useState({ visitor_name: '', id_document_no: '' });
    const [idVerifyResult, setIdVerifyResult] = useState(null);
    const [propertyItems, setPropertyItems] = useState([{ item_name: '', quantity: 1, serial_number: '', property_tag: '', custom_name: '' }]);
    const [propertyVisitId, setPropertyVisitId] = useState('');
    const [propertyMsg, setPropertyMsg] = useState(null);
    const [checkoutMode, setCheckoutMode] = useState(false);
    const [verifyReturnVisitId, setVerifyReturnVisitId] = useState('');
    const [verifyReturnResult, setVerifyReturnResult] = useState(null);
    const [exitQrInput, setExitQrInput] = useState('');
    const [exitScanResult, setExitScanResult] = useState(null);
    const [exitScanError, setExitScanError] = useState('');
    const [exitReturnedItems, setExitReturnedItems] = useState({});
    const [exitScanStatus, setExitScanStatus] = useState('');
    const [incidentForm, setIncidentForm] = useState({ type: 'security_alert', severity: 'medium', title: '', description: '', visit_id: '', visitor_id: '' });
    const [recentVisits, setRecentVisits] = useState([]);
    const [recentVisitsLoading, setRecentVisitsLoading] = useState(false);
    const [liveVisitors, setLiveVisitors] = useState([]);
    const [liveCountState, setLiveCountState] = useState(live_count || 0);
    const [reportView, setReportView] = useState('visitors');
    const [reportPeriod, setReportPeriod] = useState(report_period || 'daily');
    const [reportFrom, setReportFrom] = useState(report_range?.from || '');
    const [reportTo, setReportTo] = useState(report_range?.to || '');

    const [incidents, setIncidents] = useState([]);
    const [incidentsLoading, setIncidentsLoading] = useState(false);
    const [incidentsPagination, setIncidentsPagination] = useState({ current_page: 1, per_page: 20, total: 0, last_page: 1 });
    const [incidentsFilters, setIncidentsFilters] = useState({ status: 'open', severity: 'all', type: 'all', search: '' });
    const [incidentsVersion, setIncidentsVersion] = useState(0);
    const [incidentCounts, setIncidentCounts] = useState(null);
    const [countsLoading, setCountsLoading] = useState(false);
    const [selectedIncident, setSelectedIncident] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [actionLoading, setActionLoading] = useState(false);
    const [resolveForm, setResolveForm] = useState({ resolution_reason: '', resolution_action: '', resolution_notes: '' });

    const incidentTypeOptions = [
        { value: 'all', label: 'All Types' },
        { value: 'fake_qr', label: 'Fake/Invalid QR' },
        { value: 'invalid_qr', label: 'Invalid QR' },
        { value: 'expired_qr', label: 'Expired QR' },
        { value: 'revoked_qr', label: 'Revoked QR' },
        { value: 'used_qr', label: 'Already-Used QR' },
        { value: 'unauthorized_entry', label: 'Unauthorized Entry' },
        { value: 'unauthorized_exit', label: 'Unauthorized Exit' },
        { value: 'blacklist_match', label: 'Blacklist Match' },
        { value: 'identity_mismatch', label: 'Identity Mismatch' },
        { value: 'missing_property', label: 'Missing Property' },
        { value: 'damaged_property', label: 'Damaged Property' },
        { value: 'property_owner_mismatch', label: 'Property Owner Mismatch' },
        { value: 'lost_property', label: 'Lost Property' },
        { value: 'security_alert', label: 'Security Alert' },
        { value: 'panic_alarm', label: 'Panic Alarm' },
        { value: 'emergency', label: 'Emergency' },
        { value: 'other', label: 'Other' },
    ];

    const resolutionReasonOptions = [
        { value: 'returned', label: 'Returned' },
        { value: 'replaced', label: 'Replaced' },
        { value: 'recovered', label: 'Recovered' },
        { value: 'accepted', label: 'Accepted' },
        { value: 'closed', label: 'Closed' },
        { value: 'false_positive', label: 'False Positive' },
        { value: 'other', label: 'Other' },
    ];

    const incidentUserRoles = auth?.user?.role;
    const canInvestigate = ['super_admin', 'admin', 'security_manager', 'security_guard', 'manager'].includes(incidentUserRoles);
    const canResolve = ['super_admin', 'admin', 'security_manager'].includes(incidentUserRoles);
    const canDismiss = ['super_admin', 'admin', 'security_manager', 'security_guard'].includes(incidentUserRoles);

    const fetchIncidents = useCallback(async () => {
        setIncidentsLoading(true);
        try {
            const params = new URLSearchParams();
            Object.entries(incidentsFilters).forEach(([k, v]) => {
                if (v && v !== 'all') params.append(k, v);
            });
            params.append('page', String(incidentsPagination.current_page));
            params.append('per_page', String(incidentsPagination.per_page));

            const res = await fetch(`/security/incidents/data?${params.toString()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            });
            const data = await res.json();
            setIncidents(data.incidents || []);
            setIncidentsPagination({
                current_page: data.pagination?.current_page || 1,
                per_page: data.pagination?.per_page || 20,
                total: data.total || data.pagination?.total || 0,
                last_page: data.pagination?.last_page || 1,
            });
        } catch { }
        finally { setIncidentsLoading(false); }
    }, [incidentsFilters, incidentsPagination.current_page, incidentsPagination.per_page, incidentsVersion]);

    const fetchIncidentCounts = useCallback(async () => {
        setCountsLoading(true);
        try {
            const res = await fetch('/security/incidents/counts', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            });
            const data = await res.json();
            setIncidentCounts(data);
        } catch { }
        finally { setCountsLoading(false); }
    }, []);

    const fetchRecentVisits = useCallback(async () => {
        setRecentVisitsLoading(true);
        try {
            const res = await fetch('/security/visits/recent', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            });
            const data = await res.json();
            setRecentVisits(data.visits || []);
        } catch { }
        finally { setRecentVisitsLoading(false); }
    }, []);

    const fetchIncidentDetail = useCallback(async (id) => {
        setDetailLoading(true);
        try {
            const res = await fetch(`/security/incidents/${id}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            });
            const data = await res.json();
            setSelectedIncident(data);
        } catch { }
        finally { setDetailLoading(false); }
    }, []);

    const handleInvestigate = async (incidentId, notes = '') => {
        setActionLoading(true);
        try {
            const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1];
            const res = await fetch(`/security/incidents/${incidentId}/investigate`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ notes }),
            });
            const data = await res.json();
            if (data.incident) {
                setIncidents(prev => prev.map(i => i.id === incidentId ? { ...i, status: 'investigating' } : i));
                if (selectedIncident?.id === incidentId) setSelectedIncident(data.incident);
                fetchIncidentCounts();
            }
        } catch { }
        finally { setActionLoading(false); }
    };

    const handleResolve = async (incidentId, resolution) => {
        setActionLoading(true);
        try {
            const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1];
            const res = await fetch(`/security/incidents/${incidentId}/resolve`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(resolution),
            });
            const data = await res.json();
            if (data.incident) {
                setIncidents(prev => prev.map(i => i.id === incidentId ? { ...i, ...data.incident } : i));
                if (selectedIncident?.id === incidentId) setSelectedIncident(data.incident);
                fetchIncidentCounts();
            }
        } catch { }
        finally { setActionLoading(false); }
    };

    const handleDismiss = async (incidentId, notes = '') => {
        setActionLoading(true);
        try {
            const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1];
            const res = await fetch(`/security/incidents/${incidentId}/dismiss`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ notes }),
            });
            const data = await res.json();
            if (data.incident) {
                setIncidents(prev => prev.filter(i => i.id !== incidentId));
                if (selectedIncident?.id === incidentId) setSelectedIncident(null);
                fetchIncidentCounts();
            }
        } catch { }
        finally { setActionLoading(false); }
    };

    const applyIncidentFilters = () => {
        setIncidentsPagination(p => ({ ...p, current_page: 1 }));
        setIncidentsVersion(v => v + 1);
        fetchIncidents();
    };

    const resetIncidentFilters = () => {
        setIncidentsFilters({ status: 'open', severity: 'all', type: 'all', search: '' });
        setIncidentsPagination(p => ({ ...p, current_page: 1 }));
        setIncidentsVersion(v => v + 1);
    };

    const badgeForm = useForm({ name: '', type: 'visitor', color: '#6366f1', show_photo: false, show_qr: true, is_default: false });

    // Duplicate-scan protection: a scan request already in flight is never
    // re-triggered, and the exact same code scanned again within 2s is ignored
    // (USB scanners frequently fire the same read twice).
    const scanInFlight = useRef(false);
    const lastScanToken = useRef('');
    const lastScanAt = useRef(0);
    const entryScanRef = useRef(null);
    const scanWasExit = useRef(false);
    const exitScanRef = useRef(null);
    const exitScanSuccess = useRef(false);
    const [scanLoading, setScanLoading] = useState(false);
    const [exitScanLoading, setExitScanLoading] = useState(false);
    const checkoutInFlight = useRef(false);
    const [checkInLoading, setCheckInLoading] = useState(false);
    const [checkoutLoading, setCheckoutLoading] = useState(false);

    const canScan = (token) => {
        const now = Date.now();
        if (scanInFlight.current) return false;
        if (token === lastScanToken.current && now - lastScanAt.current < 2000) return false;
        return true;
    };

    const fetchLiveVisitors = useCallback(() => {
        fetch('/security/live-visitors', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        })
            .then(r => r.json())
            .then(data => {
                setLiveVisitors(data.visitors || []);
                setLiveCountState(data.count || 0);
            })
            .catch(() => { });
    }, []);

    // Always poll incident counts (for the sidebar badge) every 15s
    useEffect(() => {
        fetchIncidentCounts();
        const interval = setInterval(fetchIncidentCounts, 15000);
        return () => clearInterval(interval);
    }, [fetchIncidentCounts]);

    useEffect(() => {
        fetchLiveVisitors();
        fetchRecentVisits();
    }, [fetchLiveVisitors, fetchRecentVisits]);

    useEffect(() => {
        if (activeTab !== 'live') return;
        const interval = setInterval(fetchLiveVisitors, 15000);
        return () => clearInterval(interval);
    }, [fetchLiveVisitors, activeTab]);

    // Also poll live visitors when on dashboard so the live count badge stays current
    useEffect(() => {
        if (activeTab !== 'dashboard') return;
        const interval = setInterval(fetchLiveVisitors, 15000);
        return () => clearInterval(interval);
    }, [fetchLiveVisitors, activeTab]);

    useEffect(() => {
        if (activeTab !== 'incidents') return;
        fetchIncidents();
        fetchRecentVisits();
    }, [activeTab, fetchIncidents, fetchRecentVisits, incidentsVersion]);

    // Refresh data when the user returns to the tab (browser focus / visibility change)
    useEffect(() => {
        const onVisible = () => {
            if (!document.hidden) {
                fetchIncidentCounts();
                fetchLiveVisitors();
                if (activeTab === 'incidents') fetchIncidents();
            }
        };
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onVisible);
        return () => {
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('focus', onVisible);
        };
    }, [fetchIncidentCounts, fetchLiveVisitors, fetchIncidents, activeTab]);

    useEffect(() => {
        const onHashChange = () => {
            const hash = window.location.hash.replace('#', '');
            const validTabs = ['dashboard', 'entry_gate', 'live', 'risk', 'incidents', 'reports'];
            if (validTabs.includes(hash)) setActiveTab(hash);
        };
        window.addEventListener('hashchange', onHashChange);
        return () => window.removeEventListener('hashchange', onHashChange);
    }, []);

    // If the exit scan response omits properties, re-fetch the saved records
    // from the database so previously registered property is always displayed.
    const fetchExitPropertiesFallback = async (data) => {
        if (!data || !data.visit_id) return data;
        if (data.properties && data.properties.length > 0) return data;
        const query = data.group_member_id ? `?group_member_id=${data.group_member_id}` : '';
        return fetch(`/security/visit/${data.visit_id}/properties${query}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        })
            .then(r => r.json())
            .then(d => {
                if (d.properties && d.properties.length > 0) {
                    return { ...data, properties: d.properties };
                }
                return data;
            })
            .catch(() => data);
    };

    const handleQRScan = (e) => {
        e.preventDefault();
        const token = (tokenInput || '').trim();
        if (!token || !canScan(token)) return;
        scanInFlight.current = true;
        lastScanToken.current = token;
        lastScanAt.current = Date.now();
        scanWasExit.current = false;
        setScanError(''); setScanResult(null); setScanQrStatus('');
        setScanLoading(true);

        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1];

        fetch('/security/scan', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ token }),
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    setTokenInput('');
                    scanWasExit.current = !!data.is_exit_scan;
                    if (data.is_exit_scan) {
                        fetchExitPropertiesFallback(data).then(resolved => {
                            setExitScanResult(resolved);
                            setExitScanStatus('valid');
                            setVerifyReturnVisitId(resolved.visit_id);
                            const items = {};
                            (resolved.properties || []).forEach(p => { items[p.id] = false; });
                            setExitReturnedItems(items);
                            setActiveTab('entry_gate');
                            setCheckoutMode(true);
                        });
                    } else {
                        const visitData = data.visit || data;
                        setScanResult(visitData);
                        setScanQrStatus(data.qr_status || 'valid');
                        setIdVerifyInput({ visitor_name: visitData.visitor_name || '', id_document_no: visitData.id_document_no || '' });
                        setIdVerifyResult(null);
                        setPropertyMsg(null);
                        setPropertyVisitId(String(visitData.id));
                    }
                } else {
                    if (data.qr_status === 'exit_wrong_status' || data.qr_status === 'exit_expired') {
                        setExitScanError(data.message);
                        setExitScanStatus('invalid');
                        setActiveTab('entry_gate');
                        setCheckoutMode(true);
                    } else {
                        setScanError(data.message);
                        setScanQrStatus(data.qr_status || 'invalid');
                    }
                }
            })
            .catch(() => setScanError('Network error during QR validation.'))
            .finally(() => {
                scanInFlight.current = false;
                setScanLoading(false);
                // Keep focus on the scanner input so consecutive USB scans fire
                // immediately without the guard re-clicking the field.
                if (!scanWasExit.current) entryScanRef.current?.focus();
            });
    };

    const handleIdentityVerify = (e) => {
        e.preventDefault();
        if (!scanResult) return;
        setIdVerifyResult(null);

        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1];

        const body = {
            visit_id: scanResult.id,
            visitor_name: idVerifyInput.visitor_name,
            id_document_no: idVerifyInput.id_document_no,
        };
        if (scanResult.is_group_member && scanResult.group_member_id) {
            body.group_member_id = scanResult.group_member_id;
        }

        fetch('/security/verify-identity', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
        })
            .then(r => r.json())
            .then(data => {
                setIdVerifyResult(data);
            })
            .catch(() => setIdVerifyResult({ success: false, message: 'Network error during identity verification.' }));
    };

    const handlePropertySubmit = (e) => {
        e.preventDefault();
        const visitId = propertyVisitId || scanResult?.id;
        if (!visitId) return;

        const validItems = propertyItems.filter(i => i.item_name.trim());
        if (validItems.length === 0) {
            setPropertyMsg({ type: 'error', text: 'Please add at least one property item before saving.' });
            return;
        }

        for (const item of validItems) {
            if (item.item_name === 'Other' && !(item.custom_name || '').trim()) {
                setPropertyMsg({ type: 'error', text: 'Please specify the item name for the "Other" item.' });
                return;
            }
        }

        const items = validItems.map(i => ({
            item_name: i.item_name === 'Other' ? i.custom_name.trim() : i.item_name,
            quantity: i.quantity,
            serial_number: i.serial_number,
            property_tag: i.property_tag,
        }));

        setPropertyMsg(null);
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1];

        const body = {
            visit_request_id: visitId,
            items: items,
        };
        if (scanResult?.is_group_member && scanResult?.group_member_id) {
            body.visit_group_member_id = scanResult.group_member_id;
        }

        fetch('/security/register-property', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
        })
            .then(async r => {
                const data = await r.json().catch(() => ({}));
                if (!r.ok) {
                    setPropertyMsg({ type: 'error', text: (data && data.message) || `Failed to register property (HTTP ${r.status}).` });
                    return;
                }
                if (data && data.success) {
                    setPropertyItems([{ item_name: '', quantity: 1, serial_number: '', property_tag: '', custom_name: '' }]);
                    if (scanResult) {
                        setScanResult({ ...scanResult, properties: data.properties || [] });
                    }
                    setPropertyMsg({ type: 'success', text: (data && data.message) || 'Property registered successfully.' });
                } else {
                    setPropertyMsg({ type: 'error', text: (data && data.message) || 'Failed to register property.' });
                }
            })
            .catch(() => setPropertyMsg({ type: 'error', text: 'Network error during property registration. Please try again.' }));
    };

    const handleCheckIn = (id) => {
        if (checkInLoading) return;
        setCheckInLoading(true);
        const body = scanResult?.is_group_member && scanResult?.group_member_id
            ? { group_member_id: scanResult.group_member_id }
            : {};
        router.post(`/security/visits/${id}/checkin`, body, {
            onStart: () => setCheckInLoading(true),
            onFinish: () => setCheckInLoading(false),
            onError: () => setCheckInLoading(false),
            onSuccess: () => {
                setCheckInLoading(false);
                setScanResult(null);
                setTokenInput('');
                setIdVerifyResult(null);
            },
        });
    };

    const handleCheckOut = (id) => {
        setVerifyReturnVisitId(id);
        setVerifyReturnResult(null);
        setExitScanResult(null);
        setExitScanError('');
        setExitQrInput('');
        setExitReturnedItems({});
        setExitScanStatus('');
        setActiveTab('entry_gate');
        setCheckoutMode(true);
    };

    const handleExitQrScan = (e) => {
        e.preventDefault();
        const token = (exitQrInput || '').trim();
        if (!token || !canScan(token)) return;
        scanInFlight.current = true;
        lastScanToken.current = token;
        lastScanAt.current = Date.now();
        exitScanSuccess.current = false;
        setExitScanError(''); setExitScanResult(null); setExitScanStatus('');
        setExitScanLoading(true);

        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1];

        fetch('/security/exit-scan', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ token }),
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    exitScanSuccess.current = true;
                    fetchExitPropertiesFallback(data).then(resolved => {
                        setExitScanResult(resolved);
                        setExitScanStatus('valid');
                        setVerifyReturnVisitId(resolved.visit_id);
                        const items = {};
                        (resolved.properties || []).forEach(p => { items[p.id] = false; });
                        setExitReturnedItems(items);
                    });
                } else {
                    setExitScanError(data.message);
                    setExitScanStatus('invalid');
                }
            })
            .catch(() => setExitScanError('Network error during exit QR scan.'))
            .finally(() => {
                scanInFlight.current = false;
                setExitScanLoading(false);
                if (!exitScanSuccess.current) exitScanRef.current?.focus();
            });
    };

    const handleVerifyReturn = (e) => {
        e.preventDefault();
        const visitId = verifyReturnVisitId;
        if (!visitId || checkoutInFlight.current) return;
        checkoutInFlight.current = true;
        setCheckoutLoading(true);

        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1];

        const returnedItems = (exitScanResult?.properties || [])
            .filter(p => exitReturnedItems[p.id])
            .map(p => ({ item_name: p.item_name, serial_number: p.serial_number || '' }));

        // Strict property isolation: when a group member is checking out, the
        // verification must only consider that member's own registered items.
        // Without group_member_id the backend falls back to the representative's
        // property scope and wrongly flags the representative's items as missing.
        const verifyBody = { returned_items: returnedItems };
        if (exitScanResult?.is_group_member && exitScanResult?.group_member_id) {
            verifyBody.group_member_id = exitScanResult.group_member_id;
        }

        fetch(`/security/visits/${visitId}/verify-return`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(verifyBody),
        })
            .then(r => r.json())
            .then(data => {
                setVerifyReturnResult(data);
                if (data.all_returned) {
                    const checkoutBody = {
                        qr_token: exitQrInput || '',
                        returned_items: returnedItems,
                    };
                    if (exitScanResult?.is_group_member && exitScanResult?.group_member_id) {
                        checkoutBody.group_member_id = exitScanResult.group_member_id;
                        checkoutBody.exit_qr_token = exitQrInput || '';
                    }
                    fetch(`/security/visits/${visitId}/checkout`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify(checkoutBody),
                    })
                        .then(r => r.json())
                        .then(d => {
                            if (d && d.success) {
                                resetExitGate();
                            } else {
                                setVerifyReturnResult({ all_returned: false, message: (d && d.message) || 'Checkout failed. Please verify the property return again.' });
                            }
                        })
                        .catch(() => setVerifyReturnResult({ all_returned: false, message: 'Network error during checkout. Property remains registered for this visit.' }));
                }
            })
            .catch(() => { })
            .finally(() => { checkoutInFlight.current = false; setCheckoutLoading(false); });
    };

    const resetExitGate = () => {
        setCheckoutMode(false);
        setVerifyReturnVisitId('');
        setVerifyReturnResult(null);
        setExitScanResult(null);
        setExitScanError('');
        setExitQrInput('');
        setExitReturnedItems({});
        setExitScanStatus('');
    };

    const handleForceCheckout = () => {
        if (!verifyReturnVisitId || checkoutInFlight.current) return;
        checkoutInFlight.current = true;
        setCheckoutLoading(true);
        const csrfToken = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.split('=')[1];
        const body = { force: true, qr_token: exitQrInput || '' };
        if (exitScanResult?.is_group_member && exitScanResult?.group_member_id) {
            body.group_member_id = exitScanResult.group_member_id;
            body.exit_qr_token = exitQrInput || '';
        }
        fetch(`/security/visits/${verifyReturnVisitId}/checkout`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
        })
            .then(r => r.json())
            .then(d => {
                if (d && d.success) {
                    setVerifyReturnResult({
                        all_returned: true,
                        message: (d && d.message) || 'Force checkout completed. Property marked as returned.',
                    });
                    setTimeout(resetExitGate, 2500);
                } else {
                    setVerifyReturnResult({ all_returned: false, message: (d && d.message) || 'Checkout failed. Property remains registered for this visit.' });
                }
            })
            .catch(() => setVerifyReturnResult({ all_returned: false, message: 'Network error during checkout. Property remains registered for this visit.' }))
            .finally(() => { checkoutInFlight.current = false; setCheckoutLoading(false); });
    };

    const handleIncidentSubmit = (e) => {
        e.preventDefault();
        router.post('/security/incidents', incidentForm, {
            onSuccess: () => {
                setIncidentForm({ type: 'security_alert', severity: 'medium', title: '', description: '', visit_id: '', visitor_id: '' });
                fetchIncidents();
                fetchIncidentCounts();
            },
        });
    };

    const handleIncidentUpdate = (id, status) => {
        router.post(`/security/incidents/${id}/update`, { status }, {
            onSuccess: () => {
                fetchIncidents();
                fetchIncidentCounts();
            },
        });
    };

    const generateReport = () => {
        const params = { period: reportPeriod };
        if (reportPeriod === 'range') {
            params.from = reportFrom;
            params.to = reportTo;
        }
        router.get('/security/daily-report', params);
    };

    const periodTitle = { daily: "Today's", weekly: "This Week's", monthly: "This Month's", yearly: "This Year's", range: 'Custom' };

    const tabs = [
        { id: 'dashboard', label: 'Dashboard', icon: 'home' },
        { id: 'entry_gate', label: 'Entry Gate', icon: 'exit' },
        { id: 'live', label: 'Live Visitors', icon: 'location' },
        { id: 'risk', label: 'Risk & Escalations', icon: 'shield' },
        { id: 'incidents', label: 'Alerts & Incidents', icon: 'alert', badge: incidentCounts?.total_open ?? 0 },
        { id: 'reports', label: 'Daily Reports', icon: 'chart' },
        { id: 'messages', label: 'Messages', icon: 'mail' },
    ];

    const getSeverityColor = (s) => {
        const colors = { low: '#6366f1', medium: '#f59e0b', high: '#ef4444', critical: '#dc2626' };
        return colors[s] || '#6366f1';
    };

    const getStatusColor = (s) => {
        const colors = { open: '#ef4444', investigating: '#f59e0b', resolved: '#10b981', dismissed: '#6b7280', closed: '#6b7280' };
        return colors[s] || '#6b7280';
    };

    const TB = ({ active, onClick, children, badge }) => (
        <button onClick={onClick} style={{
            width: '100%', textAlign: 'left', border: 'none',
            borderLeft: active ? '3px solid #1d4ed8' : '3px solid transparent',
            background: active ? '#2563eb' : 'transparent',
            color: '#ffffff',
            fontWeight: active ? 700 : 500, fontSize: 13, padding: '10px 16px',
            cursor: 'pointer', transition: 'all 0.2s',
            display: 'flex', justifyContent: 'space-between', alignItems: 'center',
            borderRadius: active ? '0 8px 8px 0' : '0',
        }} onMouseEnter={e => { if (!active) { e.currentTarget.style.background = 'rgba(255,255,255,0.12)'; e.currentTarget.style.color = '#ffffff'; } }} onMouseLeave={e => { if (!active) { e.currentTarget.style.background = 'transparent'; e.currentTarget.style.color = '#ffffff'; } }}>
            <span>{children}</span>
            {badge > 0 && <span style={{ background: '#ef4444', color: 'white', borderRadius: 10, padding: '1px 7px', fontSize: 10, fontWeight: 800 }}>{badge}</span>}
        </button>
    );

    return (
        <Layout title="Security Operations Center">
            <style>{`
                .sec-panel{display:block;}
                .sec-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;}
                .sec-stat{background:var(--glass-bg);border:1px solid var(--glass-border);border-radius:12px;padding:20px;text-align:center;}
                .sec-stat .val{font-size:32px;font-weight:800;font-family:var(--font-heading);}
                .sec-stat .lbl{font-size:12px;color:var(--text-secondary);margin-top:4px;text-transform:uppercase;letter-spacing:0.5px;}
                .scan-panel{display:grid;grid-template-columns:1fr 1fr;gap:24px;}
                .qr-status-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;}

                .overstay-badge{background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;}
                .sec-dashboard .btn{background:#ffffff !important;background-image:none !important;border:1px solid var(--border-color-strong) !important;color:#1e293b !important;box-shadow:none !important;}
                .sec-dashboard .btn:hover{background:#f8fafc !important;background-image:none !important;color:#1e293b !important;transform:none !important;box-shadow:none !important;}
                @keyframes fadeSlideUp{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
            `}</style>

            <div className="admin-layout sec-dashboard" style={section ? { minHeight: 'calc(100vh - 80px)' } : { display: 'flex', minHeight: 'calc(100vh - 80px)' }}>

                {!section && (
                    <div className="admin-sidebar" style={{ width: sidebarOpen ? 220 : 0, flexShrink: 0, background: '#2563eb', borderRight: sidebarOpen ? '1px solid rgba(255,255,255,0.15)' : 'none', position: 'sticky', top: 68, alignSelf: 'flex-start', maxHeight: 'calc(100vh - 68px)', overflow: sidebarOpen ? 'auto' : 'hidden', borderRadius: '0 0 0 14px', transition: 'width 0.25s ease' }}>
                        <div style={{ padding: '10px 12px', borderBottom: '1px solid rgba(255,255,255,0.15)', display: 'flex', alignItems: 'center', justifyContent: sidebarOpen ? 'flex-end' : 'center' }}>
                            <button onClick={() => setSidebarOpen(p => !p)} style={{ background: 'rgba(255,255,255,0.2)', border: 'none', borderRadius: 8, width: 34, height: 34, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', color: '#fff', fontSize: 16, fontWeight: 700 }}></button>
                        </div>
                        {sidebarOpen && <div style={{ padding: '16px', borderBottom: '1px solid rgba(255,255,255,0.15)' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                <label style={{ width: 38, height: 38, borderRadius: '50%', background: auth.user?.profile_photo ? 'none' : 'rgba(255,255,255,0.2)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 17, color: 'white', flexShrink: 0, overflow: 'hidden', cursor: 'pointer' }} title="Click to change photo">
                                    {auth.user?.profile_photo ? <img src={`/storage/${auth.user.profile_photo}`} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : '️'}
                                    <input type="file" accept="image/*" style={{ display: 'none' }} onChange={e => { const f = e.target.files[0]; if (!f) return; const fd = new FormData(); fd.append('profile_photo', f); fd.append('name', auth.user?.name || ''); router.post('/profile/update', fd, { forceFormData: true, preserveScroll: true }); }} />
                                </label>
                                <div>
                                    <div style={{ fontWeight: 700, fontSize: 13, color: '#ffffff' }}>Security</div>
                                    <div style={{ fontSize: 11, color: 'rgba(255,255,255,0.7)' }}>Operations Center</div>
                                </div>
                            </div>
                        </div>}
                        <ul style={{ listStyle: 'none', padding: sidebarOpen ? '8px' : '6px 0', margin: 0, display: 'flex', flexDirection: 'column', gap: 2 }}>
                            {tabs.map(t => (
                                <li key={t.id}>
                                    <TB active={activeTab === t.id} onClick={() => t.id === 'reports' ? router.get('/security/daily-report') : router.visit(`/security/section/${t.id}`)} badge={t.badge || 0}>
                                        <AppIcon name={t.icon} size={14} /> {sidebarOpen && t.label}
                                    </TB>
                                </li>
                            ))}
                        </ul>
                        <div style={{ borderTop: '1px solid rgba(255,255,255,0.15)', padding: '12px 16px', display: 'flex', flexDirection: 'column', gap: 6 }}>
                            <Link href="/profile" style={{ fontSize: 12, color: 'rgba(255,255,255,0.8)', textDecoration: 'none', display: 'flex', alignItems: 'center', gap: 8, padding: '6px 0', fontWeight: 600 }}><AppIcon name="user" size={14} /> My Profile</Link>
                            <Link href="/" style={{ fontSize: 12, color: 'rgba(255,255,255,0.8)', textDecoration: 'none', display: 'flex', alignItems: 'center', gap: 8, padding: '6px 0' }}><AppIcon name="globe" size={14} /> Public Site</Link>
                            <button onClick={() => router.post('/logout')} style={{ background: 'none', border: 'none', fontSize: 12, color: 'rgba(255,255,255,0.8)', cursor: 'pointer', textAlign: 'left', padding: '6px 0', display: 'flex', alignItems: 'center', gap: 8 }}><AppIcon name="exit" size={14} /> Logout</button>
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

                <div className="dashboard-content" style={{ flex: 1, padding: section ? '0' : '28px 32px', minWidth: 0, overflow: section ? 'visible' : 'hidden' }}>
                    {section && (
                        <div style={{ marginBottom: 24, paddingBottom: 16, borderBottom: '1px solid rgba(99,102,241,0.12)' }}>
                            <Link href="/security/dashboard" style={{ color: 'var(--accent-primary)', fontSize: 14, fontWeight: 600, textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 6 }}>← Back to Security Dashboard</Link>
                        </div>
                    )}

                    {/* ==================== 1. DASHBOARD OVERVIEW ==================== */}
                    {activeTab === 'dashboard' && (
                        <div className="sec-panel active">
                            <div className="sec-stat-grid">
                                <div className="sec-stat" style={{ borderColor: 'rgba(0,240,255,0.3)' }}>
                                    <div className="val" style={{ color: 'var(--accent-cyan)' }}>{liveCountState}</div>
                                    <div className="lbl">Now Inside Building</div>
                                </div>
                                <div className="sec-stat" style={{ borderColor: 'rgba(16,185,129,0.3)' }}>
                                    <div className="val" style={{ color: '#10b981' }}>{stats?.checked_in_today || stats?.total_checkins || 0}</div>
                                    <div className="lbl">Today's Check-ins</div>
                                </div>
                                <div className="sec-stat" style={{ borderColor: 'rgba(99,102,241,0.3)' }}>
                                    <div className="val" style={{ color: '#818cf8' }}>{stats?.total_checkouts || 0}</div>
                                    <div className="lbl">Today's Check-outs</div>
                                </div>
                                <div className="sec-stat" style={{ borderColor: 'rgba(245,158,11,0.3)' }}>
                                    <div className="val" style={{ color: '#fbbf24' }}>{stats?.pending_arrivals || 0}</div>
                                    <div className="lbl">Pending Arrivals</div>
                                </div>
                                <div className="sec-stat" style={{ borderColor: 'rgba(239,68,68,0.3)' }}>
                                    <div className="val" style={{ color: '#f87171' }}>{stats?.denied || 0}</div>
                                    <div className="lbl">Denied Entries</div>
                                </div>
                                <div className="sec-stat" style={{ borderColor: 'rgba(239,68,68,0.3)' }}>
                                    <div className="val" style={{ color: '#ef4444' }}>{stats?.incidents || 0}</div>
                                    <div className="lbl">Active Incidents</div>
                                </div>
                            </div>

                            {/* Risk Quick Summary */}
                            {risk_summary && (risk_summary.escalated_count > 0 || risk_summary.watchlist_count > 0) && (
                                <div style={{ display: 'flex', gap: '12px', marginBottom: '24px', flexWrap: 'wrap' }}>
                                    {risk_summary.escalated_count > 0 && (
                                        <button onClick={() => setActiveTab('risk')} style={{ display: 'flex', alignItems: 'center', gap: '8px', padding: '10px 16px', background: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.25)', borderRadius: '8px', cursor: 'pointer', color: '#f87171', fontSize: '13px', fontWeight: 600 }}>
                                            {risk_summary.escalated_count} Escalated Visit{risk_summary.escalated_count > 1 ? 's' : ''} — View Risk Tab
                                        </button>
                                    )}
                                    {risk_summary.watchlist_count > 0 && (
                                        <button onClick={() => setActiveTab('risk')} style={{ display: 'flex', alignItems: 'center', gap: '8px', padding: '10px 16px', background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.25)', borderRadius: '8px', cursor: 'pointer', color: '#fbbf24', fontSize: '13px', fontWeight: 600 }}>
                                            ️ {risk_summary.watchlist_count} Watchlist {risk_summary.watchlist_count > 1 ? 'Entries' : 'Entry'} — View Risk Tab
                                        </button>
                                    )}
                                </div>
                            )}



                            {/* Today's Arrivals */}
                            <div className="glass-card" style={{ marginBottom: '24px' }}>
                                <h3 style={{ fontFamily: 'var(--font-heading)', marginBottom: '16px' }}> Today's Expected Arrivals</h3>
                                {(!today_arrivals || today_arrivals.length === 0) ? (
                                    <p style={{ color: 'var(--text-muted)', fontSize: '14px' }}>No arrivals expected today.</p>
                                ) : (
                                    <div className="table-container" style={{ marginBottom: 0 }}>
                                        <table className="data-table">
                                            <thead>
                                                <tr>
                                                    <th>Visitor</th>
                                                    <th>Host</th>
                                                    <th>Department</th>
                                                    <th>Scheduled</th>
                                                    <th>Purpose</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {today_arrivals.map(v => (
                                                    <tr key={v.id}>
                                                        <td><strong>{v.visitor?.name}</strong></td>
                                                        <td>{v.host?.name}</td>
                                                        <td>{v.department?.name || v.host?.department?.name || '—'}</td>
                                                        <td>{fmtTime(v.requested_time)}</td>
                                                        <td>{v.purpose}</td>
                                                        <td><span style={{ color: '#10b981', fontWeight: 600, fontSize: '12px' }}>APPROVED</span></td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ==================== 2. ENTRY GATE (QR + ID + PROPERTY) ==================== */}
                    {activeTab === 'entry_gate' && (
                        <div className="sec-panel active">
                            <div className="scan-panel">
                                {/* QR Scan Panel */}
                                <div className="glass-card">
                                    <h3 style={{ fontFamily: 'var(--font-heading)', marginBottom: '16px' }}><AppIcon name="qr-code" /> QR Code Scanner</h3>
                                    <form onSubmit={handleQRScan} style={{ display: 'flex', gap: '12px', marginBottom: '20px' }}>
                                        <input
                                            ref={entryScanRef}
                                            type="text" className="form-input" placeholder="Scan or type QR code (e.g. VMS-XXXXXXXXXXXX-X)..."
                                            value={tokenInput} onChange={e => setTokenInput(e.target.value)}
                                            disabled={scanLoading} autoFocus
                                            style={{ flex: 1 }}
                                        />
                                        <button type="submit" className="btn btn-primary" disabled={scanLoading} style={scanLoading ? { opacity: 0.6, cursor: 'wait' } : {}}>
                                            {scanLoading ? 'Scanning…' : 'Scan QR'}
                                        </button>
                                    </form>

                                    {scanQrStatus && (
                                        <div style={{ marginBottom: '16px' }}>
                                            <span className="qr-status-badge" style={{
                                                background: scanQrStatus === 'valid' ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)',
                                                border: scanQrStatus === 'valid' ? '1px solid rgba(16,185,129,0.4)' : '1px solid rgba(239,68,68,0.4)',
                                                color: scanQrStatus === 'valid' ? '#10b981' : '#f87171',
                                            }}>
                                                    {scanQrStatus === 'valid' ? ' VALID' : scanQrStatus === 'used' ? ' ⊘ ALREADY USED' : scanQrStatus === 'expired' ? ' EXPIRED' : scanQrStatus === 'revoked' ? ' REVOKED' : scanQrStatus === 'not_started' ? ' NOT YET DUE' : scanQrStatus === 'valid_blacklisted' ? ' BLACKLISTED' : scanQrStatus === 'not_found' ? ' NOT FOUND' : ' INVALID'}
                                            </span>
                                        </div>
                                    )}

                                    {scanError && (
                                        <div className="alert-banner alert-error" style={{ fontSize: '13px', marginBottom: '16px' }}>
                                            {scanError}
                                        </div>
                                    )}

                                    {scanResult && (
                                        <div style={{ background: 'rgba(255,255,255,0.02)', border: '1px solid var(--glass-border)', padding: '20px', borderRadius: '8px', display: 'flex', flexDirection: 'column', gap: '14px' }}>
                                            <h4 style={{ color: 'var(--accent-cyan)', fontFamily: 'var(--font-heading)', borderBottom: '1px solid var(--glass-border)', paddingBottom: '8px', display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                                                <AppIcon name="check-circle" /> QR Credential Verified
                                                {scanResult.is_vip && <span style={{ background: 'rgba(236,72,153,0.15)', border: '1px solid rgba(236,72,153,0.3)', color: '#f472b6', padding: '2px 8px', borderRadius: '12px', fontSize: '10px', fontWeight: 800 }}>VIP</span>}
                                                {scanResult.is_representative && <span style={{ background: 'rgba(139,92,246,0.15)', border: '1px solid rgba(139,92,246,0.3)', color: '#a78bfa', padding: '2px 8px', borderRadius: '12px', fontSize: '10px', fontWeight: 800 }}>REPRESENTATIVE</span>}
                                                {scanResult.is_group_visit && <span style={{ background: 'rgba(6,182,212,0.15)', border: '1px solid rgba(6,182,212,0.3)', color: '#22d3ee', padding: '2px 8px', borderRadius: '12px', fontSize: '10px', fontWeight: 800 }}>GROUP VISIT</span>}
                                                {scanResult.is_group_lead && <span style={{ background: 'rgba(99,102,241,0.15)', border: '1px solid rgba(99,102,241,0.3)', color: '#818cf8', padding: '2px 8px', borderRadius: '12px', fontSize: '10px', fontWeight: 800 }}>GROUP LEAD</span>}
                                                {scanResult.is_group_member && <span style={{ background: 'rgba(16,185,129,0.15)', border: '1px solid rgba(16,185,129,0.3)', color: '#10b981', padding: '2px 8px', borderRadius: '12px', fontSize: '10px', fontWeight: 800 }}>GROUP MEMBER</span>}
                                            </h4>
                                            {scanResult.is_group_visit && (
                                                <div style={{ background: 'linear-gradient(135deg, rgba(139,92,246,0.08), rgba(99,102,241,0.05))', border: '1px solid rgba(139,92,246,0.2)', padding: '12px 14px', borderRadius: '8px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
                                                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                                        <span style={{ fontSize: 20 }}><AppIcon name="building" /></span>
                                                        <div>
                                                            <div style={{ fontWeight: 700, fontSize: '14px', color: 'var(--text-primary)' }}>{scanResult.company_name}</div>
                                                            <div style={{ fontSize: 11, color: 'var(--text-secondary)' }}>
                                                                {scanResult.is_representative ? 'Company/Group Visit — Representative' : 'Company/Group Visit — Member'}
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                                        {scanResult.group_visit_id && <span style={{ fontSize: 10, padding: '3px 8px', borderRadius: 6, background: 'rgba(99,102,241,0.12)', color: 'var(--accent-primary)', fontWeight: 700 }}>Visit #{scanResult.group_visit_id}</span>}
                                                        {scanResult.representative_name && <span style={{ fontSize: 10, padding: '3px 8px', borderRadius: 6, background: 'rgba(139,92,246,0.12)', color: '#a78bfa', fontWeight: 700 }}>Rep: {scanResult.representative_name}</span>}
                                                        {scanResult.group_members_count > 0 && <span style={{ fontSize: 10, padding: '3px 8px', borderRadius: 6, background: 'rgba(6,182,212,0.12)', color: 'var(--accent-cyan)', fontWeight: 700 }}>Members: {scanResult.group_members_count}</span>}
                                                    </div>
                                                </div>
                                            )}
                                            {scanResult.is_group_member && !scanResult.is_group_visit && (
                                                <div style={{ background: 'linear-gradient(135deg, rgba(16,185,129,0.08), rgba(6,182,212,0.05))', border: '1px solid rgba(16,185,129,0.2)', padding: '12px 14px', borderRadius: '8px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
                                                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                                        <span style={{ fontSize: 20 }}><AppIcon name="user" /></span>
                                                        <div>
                                                            <div style={{ fontWeight: 700, fontSize: '14px', color: 'var(--text-primary)' }}>{scanResult.company_name}</div>
                                                            <div style={{ fontSize: 11, color: 'var(--text-secondary)' }}>Group Member — Individual Check-in</div>
                                                        </div>
                                                    </div>
                                                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                                        {scanResult.group_visit_id && <span style={{ fontSize: 10, padding: '3px 8px', borderRadius: 6, background: 'rgba(99,102,241,0.12)', color: 'var(--accent-primary)', fontWeight: 700 }}>Visit #{scanResult.group_visit_id}</span>}
                                                        {scanResult.representative_name && <span style={{ fontSize: 10, padding: '3px 8px', borderRadius: 6, background: 'rgba(139,92,246,0.12)', color: '#a78bfa', fontWeight: 700 }}>Rep: {scanResult.representative_name}</span>}
                                                    </div>
                                                </div>
                                            )}
                                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', fontSize: '13px' }}>
                                                <div><span style={{ color: 'var(--text-secondary)' }}>Visitor Name:</span><div style={{ fontWeight: '700', fontSize: '15px', color: 'var(--text-primary)' }}>{scanResult.visitor_name}</div></div>
                                                <div><span style={{ color: 'var(--text-secondary)' }}>ID Document:</span><div style={{ fontWeight: '700', fontSize: '15px' }}>{scanResult.id_document_no}</div></div>
                                                <div><span style={{ color: 'var(--text-secondary)' }}>Host:</span><div style={{ fontWeight: '600' }}>{scanResult.host_name}</div></div>
                                                <div><span style={{ color: 'var(--text-secondary)' }}>Department:</span><div style={{ fontWeight: '600' }}>{scanResult.host_department || '—'}</div></div>
                                                <div><span style={{ color: 'var(--text-secondary)' }}>Purpose:</span><div style={{ fontWeight: '600' }}>{scanResult.purpose}</div></div>
                                                <div><span style={{ color: 'var(--text-secondary)' }}>Scheduled:</span><div style={{ fontWeight: '600' }}>{scanResult.scheduled}</div></div>
                                                <div><span style={{ color: 'var(--text-secondary)' }}>Duration:</span><div style={{ fontWeight: '600' }}>{scanResult.duration} min</div></div>
                                                <div><span style={{ color: 'var(--text-secondary)' }}>Priority:</span><div style={{ fontWeight: '600', textTransform: 'uppercase' }}>{scanResult.priority || 'normal'}</div></div>
                                            </div>

                                            {/* Visit Status & Check-in/out */}
                                            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                                <div style={{ background: 'rgba(0,240,255,0.03)', border: '1px solid rgba(0,240,255,0.15)', padding: '10px 14px', borderRadius: '6px', fontSize: '12px', flex: 1, minWidth: 140 }}>
                                                    <strong style={{ color: 'var(--accent-cyan)' }}>QR Status:</strong> <span style={{ color: '#10b981' }}>Active & Valid</span>
                                                </div>
                                                <div style={{ background: scanResult.status === 'checked_in' ? 'rgba(16,185,129,0.06)' : 'rgba(99,102,241,0.06)', border: `1px solid ${scanResult.status === 'checked_in' ? 'rgba(16,185,129,0.2)' : 'rgba(99,102,241,0.15)'}`, padding: '10px 14px', borderRadius: '6px', fontSize: '12px', flex: 1, minWidth: 140 }}>
                                                    <strong style={{ color: scanResult.status === 'checked_in' ? '#10b981' : 'var(--accent-cyan)' }}>Visit Status:</strong> <span style={{ color: scanResult.status === 'checked_in' ? '#10b981' : 'var(--text-primary)', fontWeight: 600 }}>{(scanResult.status || 'approved').replace(/_/g, ' ')}</span>
                                                </div>
                                            </div>
                                            {(scanResult.check_in_time || scanResult.check_out_time) && (
                                                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                                    {scanResult.check_in_time && <div style={{ background: 'rgba(16,185,129,0.06)', border: '1px solid rgba(16,185,129,0.2)', padding: '8px 12px', borderRadius: '6px', fontSize: '12px' }}><strong style={{ color: '#10b981' }}>Check-in:</strong> {scanResult.check_in_time}</div>}
                                                    {scanResult.check_out_time && <div style={{ background: 'rgba(139,92,246,0.06)', border: '1px solid rgba(139,92,246,0.2)', padding: '8px 12px', borderRadius: '6px', fontSize: '12px' }}><strong style={{ color: '#a78bfa' }}>Check-out:</strong> {scanResult.check_out_time}</div>}
                                                </div>
                                            )}

                                            {/* Registered Properties */}
                                            {scanResult.properties && scanResult.properties.length > 0 && (
                                                <div style={{ background: 'rgba(245,158,11,0.03)', border: '1px solid rgba(245,158,11,0.15)', padding: '12px', borderRadius: '6px' }}>
                                                    <strong style={{ fontSize: '12px', color: '#fbbf24' }}>Registered Properties at Entry:</strong>
                                                    <div style={{ marginTop: '6px', fontSize: '12px' }}>
                                                        {scanResult.properties.map((p, i) => (
                                                            <div key={i} style={{ padding: '4px 0', borderBottom: '1px solid rgba(245,158,11,0.1)' }}>
                                                                {p.item_name} × {p.quantity} {p.serial_number && `(SN: ${p.serial_number})`} {p.property_tag && `[Tag: ${p.property_tag}]`}
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>

                                {/* Identity Verification + Actions */}
                                <div className="glass-card">
                                    <h3 style={{ fontFamily: 'var(--font-heading)', marginBottom: '16px' }}><AppIcon name="shield" /> Identity Verification</h3>
                                    {!scanResult ? (
                                        <p style={{ color: 'var(--text-muted)', fontSize: '14px' }}>Scan a QR code first to begin identity verification.</p>
                                    ) : (
                                        <>
                                            <form onSubmit={handleIdentityVerify} style={{ display: 'flex', flexDirection: 'column', gap: '12px', marginBottom: '16px' }}>
                                                <div>
                                                    <label style={{ fontSize: '12px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Visitor Name (from ID card)</label>
                                                    <input type="text" className="form-input" value={idVerifyInput.visitor_name} onChange={e => setIdVerifyInput({ ...idVerifyInput, visitor_name: e.target.value })} placeholder="Enter name exactly as on ID card" />
                                                </div>
                                                <div>
                                                    <label style={{ fontSize: '12px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>ID Number (from ID card)</label>
                                                    <input type="text" className="form-input" value={idVerifyInput.id_document_no} onChange={e => setIdVerifyInput({ ...idVerifyInput, id_document_no: e.target.value })} placeholder="Enter ID number exactly as on card" />
                                                </div>
                                                <button type="submit" className="btn btn-primary" style={{ width: '100%' }}>Verify Identity Against QR Data</button>
                                            </form>

                                            {idVerifyResult && (
                                                <div style={{
                                                    padding: '14px', borderRadius: '8px', marginBottom: '16px', fontSize: '13px',
                                                    background: idVerifyResult.success ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)',
                                                    border: `1px solid ${idVerifyResult.success ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)'}`,
                                                    color: idVerifyResult.success ? '#6ee7b7' : '#fca5a5',
                                                }}>
                                                    <strong>{idVerifyResult.success ? ' IDENTITY VERIFIED' : ' ENTRY DENIED'}</strong>
                                                    <div style={{ marginTop: '4px' }}>{idVerifyResult.message}</div>
                                                </div>
                                            )}

                                            {/* Property Inspection — integrated into flow after identity verification */}
                                            {idVerifyResult?.success && (
                                                <div style={{ background: 'rgba(245,158,11,0.05)', border: '1px solid rgba(245,158,11,0.2)', borderRadius: '8px', padding: '16px', marginBottom: '16px' }}>
                                                    <h4 style={{ fontSize: '13px', fontWeight: 700, color: '#fbbf24', marginBottom: '12px', fontFamily: 'var(--font-heading)' }}> Property Inspection (Entry)</h4>
                                                    <form onSubmit={handlePropertySubmit}>
                                                        {propertyItems.map((item, idx) => (
                                                            <div key={idx} style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1.5fr 1fr auto', gap: '8px', marginBottom: '10px', alignItems: 'end' }}>
                                                                <div>
                                                                    <label style={{ fontSize: '11px', color: 'var(--text-secondary)' }}>Item Name *</label>
                                                                    <select className="form-input" value={item.item_name} onChange={e => {
                                                                        const updated = [...propertyItems]; updated[idx] = { ...updated[idx], item_name: e.target.value }; setPropertyItems(updated);
                                                                    }} style={{ fontSize: '12px' }}>
                                                                        <option value="">Select item...</option>
                                                                        <option value="Laptop">Laptop</option>
                                                                        <option value="Phone">Phone</option>
                                                                        <option value="Camera">Camera</option>
                                                                        <option value="USB Drive">USB Drive</option>
                                                                        <option value="Bag">Bag</option>
                                                                        <option value="Tablet">Tablet</option>
                                                                        <option value="Other">Other</option>
                                                                    </select>
                                                                    {item.item_name === 'Other' && (
                                                                        <input
                                                                            type="text"
                                                                            className="form-input"
                                                                            value={item.custom_name || ''}
                                                                            onChange={e => {
                                                                                const updated = [...propertyItems]; updated[idx] = { ...updated[idx], custom_name: e.target.value }; setPropertyItems(updated);
                                                                            }}
                                                                            placeholder="Specify item name..."
                                                                            style={{ fontSize: '12px', marginTop: '6px', width: '100%' }}
                                                                        />
                                                                    )}
                                                                </div>
                                                                <div>
                                                                    <label style={{ fontSize: '11px', color: 'var(--text-secondary)' }}>Qty</label>
                                                                    <input type="number" className="form-input" value={item.quantity} min="1" onChange={e => {
                                                                        const updated = [...propertyItems]; updated[idx] = { ...updated[idx], quantity: parseInt(e.target.value) || 1 }; setPropertyItems(updated);
                                                                    }} style={{ fontSize: '12px' }} />
                                                                </div>
                                                                <div>
                                                                    <label style={{ fontSize: '11px', color: 'var(--text-secondary)' }}>Serial Number</label>
                                                                    <input type="text" className="form-input" value={item.serial_number} onChange={e => {
                                                                        const updated = [...propertyItems]; updated[idx] = { ...updated[idx], serial_number: e.target.value }; setPropertyItems(updated);
                                                                    }} placeholder="Optional" style={{ fontSize: '12px' }} />
                                                                </div>
                                                                <div>
                                                                    <label style={{ fontSize: '11px', color: 'var(--text-secondary)' }}>Property Tag</label>
                                                                    <input type="text" className="form-input" value={item.property_tag} onChange={e => {
                                                                        const updated = [...propertyItems]; updated[idx] = { ...updated[idx], property_tag: e.target.value }; setPropertyItems(updated);
                                                                    }} placeholder="Optional" style={{ fontSize: '12px' }} />
                                                                </div>
                                                                {propertyItems.length > 1 && (
                                                                    <button type="button" className="btn btn-danger" style={{ padding: '6px', fontSize: '11px' }} onClick={() => setPropertyItems(propertyItems.filter((_, i) => i !== idx))}></button>
                                                                )}
                                                            </div>
                                                        ))}
                                                        <div style={{ display: 'flex', gap: '8px', marginTop: '8px' }}>
                                                            <button type="button" className="btn btn-secondary" style={{ fontSize: '12px', padding: '6px 12px' }} onClick={() => setPropertyItems([...propertyItems, { item_name: '', quantity: 1, serial_number: '', property_tag: '', custom_name: '' }])}>
                                                                + Add Item
                                                            </button>
                                                            <button type="submit" className="btn btn-primary" style={{ fontSize: '12px', padding: '6px 12px', background: 'linear-gradient(135deg,#d97706,#f59e0b)' }}>
                                                                Save Property
                                                            </button>
                                                        </div>
                                                    </form>
                                                    {propertyMsg && (
                                                        <div style={{
                                                            marginTop: '12px', padding: '10px 12px', borderRadius: '6px', fontSize: '12px', fontWeight: 600,
                                                            background: propertyMsg.type === 'success' ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.12)',
                                                            border: `1px solid ${propertyMsg.type === 'success' ? 'rgba(16,185,129,0.35)' : 'rgba(239,68,68,0.35)'}`,
                                                            color: propertyMsg.type === 'success' ? '#6ee7b7' : '#fca5a5',
                                                        }}>
                                                            {propertyMsg.type === 'success' ? '✓ ' : '⚠ '}{propertyMsg.text}
                                                        </div>
                                                    )}
                                                    {scanResult?.properties && scanResult.properties.length > 0 && (
                                                        <div style={{ marginTop: '10px', fontSize: '11px', color: '#10b981' }}>
                                                            {scanResult.properties.length} property(ies) registered for this visit
                                                        </div>
                                                    )}
                                                </div>
                                            )}

                                            <div style={{ display: 'flex', gap: '10px', flexDirection: 'column' }}>
                                                {idVerifyResult?.success && (
                                                    <button onClick={() => handleCheckIn(scanResult.id)} className="btn btn-primary" style={{ width: '100%', background: 'linear-gradient(135deg,#059669,#10b981)' }} disabled={checkInLoading}>
                                                        {checkInLoading ? 'Checking in…' : 'Confirm Check-In'}
                                                    </button>
                                                )}
                                            </div>
                                        </>
                                    )}

                                    {/* Identity Check Reminder */}
                                    <div style={{ background: 'rgba(245,158,11,0.05)', border: '1px solid rgba(245,158,11,0.2)', padding: '12px', borderRadius: '6px', fontSize: '12px', color: 'var(--text-secondary)', marginTop: '16px' }}>
                                        Verify the visitor's name and photo on their ID card / passport with the QR data shown above. Ensure all fields match before approving entry.
                                    </div>
                                </div>
                            </div>

                        </div>
                    )}

                    {/* ==================== 7. LIVE VISITORS ==================== */}
                    {activeTab === 'live' && (
                        <div className="sec-panel active">
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
                                <h3 style={{ fontFamily: 'var(--font-heading)', margin: 0 }}> Live Visitors — Currently Inside Building</h3>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                                    <span style={{ fontSize: '13px', color: 'var(--text-secondary)' }}>Live Count:</span>
                                    <span style={{ fontSize: '24px', fontWeight: 800, color: 'var(--accent-cyan)' }}>{liveCountState}</span>
                                    <button onClick={fetchLiveVisitors} className="btn btn-secondary" style={{ padding: '6px 12px', fontSize: '12px' }}>Refresh</button>
                                </div>
                            </div>

                            {/* Host Change Alerts for Active Visitors */}
                            {(host_change_requests || []).filter(r => r.status === 'accepted' && r.visit_request?.status === 'checked_in').length > 0 && (
                                <div style={{ marginBottom: 20, padding: '14px 18px', borderRadius: 10, background: 'rgba(99,102,241,0.06)', border: '1px solid rgba(99,102,241,0.25)' }}>
                                    <div style={{ fontWeight: 700, fontSize: 13, marginBottom: 10, color: 'var(--accent-primary)' }}> Visitor Host Updated — Action Required</div>
                                    {(host_change_requests || []).filter(r => r.status === 'accepted' && r.visit_request?.status === 'checked_in').map(r => (
                                        <div key={r.id} style={{ padding: '10px 0', borderBottom: '1px solid rgba(255,255,255,0.06)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                            <div>
                                                <span style={{ fontWeight: 600, fontSize: 13 }}>{r.visit_request?.visitor?.name}</span>
                                                <span style={{ fontSize: 12, color: 'var(--text-secondary)', marginLeft: 8 }}>
                                                    Previous: {r.previous_host?.name} → New: <strong style={{ color: 'var(--status-success)' }}>{r.new_host?.name}</strong>
                                                </span>
                                                <span style={{ fontSize: 11, color: 'var(--accent-primary)', marginLeft: 8 }}>Dept: {r.new_department?.name || 'N/A'}</span>
                                            </div>
                                            <span style={{ fontSize: 10, padding: '2px 8px', borderRadius: 10, background: 'rgba(16,185,129,0.15)', color: '#10b981', fontWeight: 700 }}>ESCORT IF NEEDED</span>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {/* Pending transfer requests (waiting for new host approval) */}
                            {(host_change_requests || []).filter(r => r.status === 'pending').length > 0 && (
                                <div style={{ marginBottom: 20, padding: '14px 18px', borderRadius: 10, background: 'rgba(245,158,11,0.06)', border: '1px solid rgba(245,158,11,0.25)' }}>
                                    <div style={{ fontWeight: 700, fontSize: 13, marginBottom: 10, color: '#f59e0b' }}> Pending Host Transfer Requests ({(host_change_requests || []).filter(r => r.status === 'pending').length})</div>
                                    {(host_change_requests || []).filter(r => r.status === 'pending').map(r => (
                                        <div key={r.id} style={{ padding: '8px 0', borderBottom: '1px solid rgba(255,255,255,0.06)', fontSize: 12 }}>
                                            <span style={{ fontWeight: 600 }}>{r.visit_request?.visitor?.name}</span>
                                            <span style={{ color: 'var(--text-secondary)', marginLeft: 8 }}>{r.previous_host?.name} → {r.new_host?.name}</span>
                                            <span style={{ fontSize: 11, color: 'var(--text-muted)', marginLeft: 8 }}>({r.visit_request?.status})</span>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {liveVisitors.length === 0 ? (
                                <div className="glass-card" style={{ textAlign: 'center', padding: '40px' }}>
                                    <p style={{ color: 'var(--text-muted)', fontSize: '16px' }}>No visitors currently inside the building.</p>
                                </div>
                            ) : (
                                <div className="glass-card" style={{ padding: 0 }}>
                                    <div className="table-container" style={{ marginBottom: 0 }}>
                                        <table className="data-table">
                                            <thead>
                                                <tr>
                                                    <th>Visitor</th>
                                                    <th>ID Doc</th>
                                                    <th>Host</th>
                                                    <th>Department</th>
                                                    <th>Location</th>
                                                    <th>Check-in</th>
                                                    <th>Elapsed</th>
                                                    <th>Properties</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {liveVisitors.map(v => (
                                                    <tr key={v.id} style={v.overstay ? { background: 'rgba(239,68,68,0.03)' } : {}}>
                                                        <td>
                                                            <strong>{v.visitor_name}</strong>
                                                            {v.is_vip && <span style={{ marginLeft: '6px', fontSize: '10px', background: 'rgba(236,72,153,0.15)', border: '1px solid rgba(236,72,153,0.3)', color: '#f472b6', padding: '1px 6px', borderRadius: '8px', fontWeight: 800 }}>VIP</span>}
                                                        </td>
                                                        <td style={{ fontSize: '12px' }}>{v.id_document_no}</td>
                                                        <td>{v.host_name}</td>
                                                        <td style={{ fontSize: '12px' }}>{v.department}</td>
                                                        <td>{[v.building, v.floor, v.meeting_room_id].filter(Boolean).join(' → ') || '—'}</td>
                                                        <td style={{ fontSize: '12px' }}>{v.checkin_time}</td>
                                                        <td style={{ fontSize: '12px' }}>{v.elapsed} min</td>
                                                        <td style={{ fontSize: '12px' }}>{v.properties_count} items</td>
                                                        <td>
                                                            {v.overstay ? (
                                                                <span className="overstay-badge"> OVERSTAY</span>
                                                            ) : (
                                                                <span style={{ color: '#10b981', fontSize: '11px', fontWeight: 600 }}>INSIDE</span>
                                                            )}
                                                        </td>
                                                        <td>
                                                            <button onClick={() => handleCheckOut(v.id)} className="btn btn-secondary" style={{ padding: '4px 10px', fontSize: '11px' }}>
                                                                Check Out
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* ==================== 7b. RISK & ESCALATIONS ==================== */}
                    {activeTab === 'risk' && (
                        <div className="sec-panel active">
                            <h3 style={{ fontFamily: 'var(--font-heading)', marginBottom: '20px' }}>️ Risk Assessment & Escalations</h3>

                            {/* Risk Summary Cards */}
                            <div className="sec-stat-grid" style={{ marginBottom: '24px' }}>
                                <div className="sec-stat" style={{ borderColor: 'rgba(245,158,11,0.4)' }}>
                                    <div className="val" style={{ color: '#f59e0b' }}>{risk_summary?.watchlist_count || 0}</div>
                                    <div className="lbl">Watchlist Entries</div>
                                </div>
                                <div className="sec-stat" style={{ borderColor: 'rgba(239,68,68,0.4)' }}>
                                    <div className="val" style={{ color: '#dc2626' }}>{risk_summary?.blacklist_count || 0}</div>
                                    <div className="lbl">Blacklisted</div>
                                </div>
                                <div className="sec-stat" style={{ borderColor: 'rgba(239,68,68,0.4)' }}>
                                    <div className="val" style={{ color: '#ef4444' }}>{risk_summary?.active_incidents || 0}</div>
                                    <div className="lbl">Active Incidents</div>
                                </div>
                                <div className="sec-stat" style={{ borderColor: 'rgba(245,158,11,0.4)' }}>
                                    <div className="val" style={{ color: '#fbbf24' }}>{risk_summary?.denied_today || 0}</div>
                                    <div className="lbl">Denied Today</div>
                                </div>
                            </div>

                            <div className="grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '24px' }}>
                                {/* Watchlist */}
                                <div className="glass-card">
                                    <h4 style={{ fontFamily: 'var(--font-heading)', marginBottom: '16px', color: '#f59e0b' }}>️ Watchlist ({watchlist?.length || 0})</h4>
                                    {(!watchlist || watchlist.length === 0) ? (
                                        <p style={{ color: 'var(--text-muted)', fontSize: '14px' }}>No watchlist entries.</p>
                                    ) : (
                                        <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', maxHeight: '400px', overflowY: 'auto' }}>
                                            {watchlist.map((w, idx) => {
                                                const levelColors = { caution: '#f59e0b', high: '#ef4444', critical: '#dc2626' };
                                                const levelIcons = { caution: '', high: '', critical: '' };
                                                return (
                                                    <div key={idx} style={{ background: 'rgba(255,255,255,0.02)', border: `1px solid ${levelColors[w.level] || '#f59e0b'}30`, borderRadius: '8px', padding: '12px' }}>
                                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '4px' }}>
                                                            <strong style={{ fontSize: '14px' }}>{levelIcons[w.level] || ''} {w.name}</strong>
                                                            <span style={{ fontSize: '10px', padding: '2px 8px', borderRadius: '10px', background: `${levelColors[w.level] || '#f59e0b'}15`, color: levelColors[w.level] || '#f59e0b', fontWeight: 700, textTransform: 'uppercase' }}>{w.level}</span>
                                                        </div>
                                                        <div style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>
                                                            <div>ID: {w.id_document_no}</div>
                                                            {w.reason && <div style={{ marginTop: '2px' }}>Reason: {w.reason}</div>}
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Security Alerts Feed */}
                            {security_alerts && security_alerts.length > 0 && (
                                <div className="glass-card" style={{ marginTop: '24px' }}>
                                    <h4 style={{ fontFamily: 'var(--font-heading)', marginBottom: '12px' }}> Recent Security Events</h4>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: '6px', maxHeight: '200px', overflowY: 'auto' }}>
                                        {security_alerts.slice(0, 20).map(alert => {
                                            const isNegative = alert.action?.includes('fail') || alert.action?.includes('blacklist') || alert.action?.includes('denied') || alert.action?.includes('fake');
                                            return (
                                                <div key={alert.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 12px', borderRadius: '6px', background: isNegative ? 'rgba(239,68,68,0.03)' : 'rgba(16,185,129,0.03)', fontSize: '12px' }}>
                                                    <div style={{ display: 'flex', gap: '12px', alignItems: 'center' }}>
                                                        <span style={{ color: isNegative ? '#f87171' : '#10b981', fontWeight: 700 }}>{alert.action?.replace(/_/g, ' ')}</span>
                                                        <span style={{ color: 'var(--text-muted)' }}>{alert.entity || ''} {alert.entity_id ? `#${alert.entity_id}` : ''}</span>
                                                    </div>
                                                    <span style={{ color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{fmtTime(alert.created_at)}</span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* ==================== 8. ALERTS & INCIDENTS ==================== */}
                    {activeTab === 'incidents' && (
                        <div className="sec-panel active">
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
                                <h3 style={{ fontFamily: 'var(--font-heading)', margin: 0 }}> Security Incidents</h3>
                                <button onClick={fetchIncidents} disabled={incidentsLoading} className="btn btn-secondary" style={{ padding: '6px 12px', fontSize: '12px' }}>
                                    {incidentsLoading ? 'Refreshing…' : 'Refresh'}
                                </button>
                            </div>

                            {/* Summary count cards */}
                            <div className="sec-stat-grid" style={{ marginBottom: '24px' }}>
                                <div className="sec-stat" style={{ borderColor: 'rgba(239,68,68,0.4)' }}>
                                    <div className="val" style={{ color: '#ef4444' }}>{incidentCounts?.total_open ?? 0}</div>
                                    <div className="lbl">Open Incidents</div>
                                </div>
                                <div className="sec-stat" style={{ borderColor: 'rgba(245,158,11,0.4)' }}>
                                    <div className="val" style={{ color: '#f59e0b' }}>{incidentCounts?.by_status?.investigating ?? 0}</div>
                                    <div className="lbl">Investigating</div>
                                </div>
                                <div className="sec-stat" style={{ borderColor: 'rgba(16,185,129,0.4)' }}>
                                    <div className="val" style={{ color: '#10b981' }}>{incidentCounts?.by_status?.resolved ?? 0}</div>
                                    <div className="lbl">Resolved</div>
                                </div>
                                <div className="sec-stat" style={{ borderColor: 'rgba(231,76,60,0.4)' }}>
                                    <div className="val" style={{ color: '#dc2626' }}>{incidentCounts?.by_severity?.critical ?? 0}</div>
                                    <div className="lbl">Critical</div>
                                </div>
                                <div className="sec-stat" style={{ borderColor: 'rgba(239,68,68,0.4)' }}>
                                    <div className="val" style={{ color: '#ef4444' }}>{incidentCounts?.by_severity?.high ?? 0}</div>
                                    <div className="lbl">High Severity</div>
                                </div>
                            </div>

                            {/* Filters */}
                            <div className="glass-card" style={{ marginBottom: '16px', padding: '14px 16px' }}>
                                <div style={{ display: 'flex', gap: '12px', alignItems: 'end', flexWrap: 'wrap' }}>
                                    <div>
                                        <label style={{ fontSize: '11px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Status</label>
                                        <select className="form-input" value={incidentsFilters.status} onChange={e => setIncidentsFilters({ ...incidentsFilters, status: e.target.value })} style={{ fontSize: '12px', padding: '6px 8px' }}>
                                            <option value="">All</option>
                                            <option value="open">Open</option>
                                            <option value="investigating">Investigating</option>
                                            <option value="resolved">Resolved</option>
                                            <option value="dismissed">Dismissed</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style={{ fontSize: '11px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Severity</label>
                                        <select className="form-input" value={incidentsFilters.severity} onChange={e => setIncidentsFilters({ ...incidentsFilters, severity: e.target.value })} style={{ fontSize: '12px', padding: '6px 8px' }}>
                                            {['all', 'critical', 'high', 'medium', 'low'].map(v => <option key={v} value={v}>{v === 'all' ? 'All' : v}</option>)}
                                        </select>
                                    </div>
                                    <div>
                                        <label style={{ fontSize: '11px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Type</label>
                                        <select className="form-input" value={incidentsFilters.type} onChange={e => setIncidentsFilters({ ...incidentsFilters, type: e.target.value })} style={{ fontSize: '12px', padding: '6px 8px' }}>
                                            {incidentTypeOptions.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
                                        </select>
                                    </div>
                                    <div style={{ flex: 1, minWidth: 180 }}>
                                        <label style={{ fontSize: '11px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Search</label>
                                        <input type="text" className="form-input" value={incidentsFilters.search} onChange={e => setIncidentsFilters({ ...incidentsFilters, search: e.target.value })} onKeyDown={e => e.key === 'Enter' && (setIncidentsPagination(p => ({ ...p, current_page: 1 })), fetchIncidents())} placeholder="Search by title, token, or description..." style={{ fontSize: '12px', padding: '6px 8px' }} />
                                    </div>
                                    <button onClick={applyIncidentFilters} className="btn btn-primary" style={{ padding: '6px 14px', fontSize: '12px' }}>Apply</button>
                                    <button onClick={resetIncidentFilters} className="btn btn-secondary" style={{ padding: '6px 14px', fontSize: '12px' }}>Reset</button>
                                </div>
                            </div>

                            {/* Incident list table */}
                            <div className="glass-card">
                                <div className="table-container" style={{ marginBottom: 0 }}>
                                    <table className="data-table">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Type</th>
                                                <th>Title</th>
                                                <th>Visitor</th>
                                                <th>Severity</th>
                                                <th>Status</th>
                                                <th>Attempts</th>
                                                <th>Reported</th>
                                                <th>Time</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {incidentsLoading && (
                                                <tr><td colSpan="10" style={{ textAlign: 'center', padding: '30px', color: 'var(--text-muted)' }}>Loading incidents…</td></tr>
                                            )}
                                            {!incidentsLoading && incidents.length === 0 && (
                                                <tr><td colSpan="10" style={{ textAlign: 'center', padding: '30px', color: 'var(--text-muted)' }}>No incidents match the current filters.</td></tr>
                                            )}
                                            {incidents.map(inc => (
                                                <tr key={inc.id}>
                                                    <td>#{inc.id}</td>
                                                    <td style={{ textTransform: 'capitalize', fontSize: '11px' }}>{inc.type_label || inc.type?.replace(/_/g, ' ')}</td>
                                                    <td style={{ fontSize: '12px' }}>{inc.title}</td>
                                                    <td style={{ fontSize: '12px' }}>{inc.visitor_name || '—'}</td>
                                                    <td><span style={{ color: inc.severity_color || getSeverityColor(inc.severity), fontWeight: 700, textTransform: 'uppercase', fontSize: '11px' }}>{inc.severity}</span></td>
                                                    <td><span style={{ color: getStatusColor(inc.status), fontWeight: 600, fontSize: '12px' }}>{inc.status_label || inc.status}</span></td>
                                                    <td style={{ fontSize: '12px' }}>{inc.attempt_count > 1 ? `${inc.attempt_count} (deduped)` : inc.attempt_count}</td>
                                                    <td style={{ fontSize: '12px' }}>{inc.reported_by_name || '—'}</td>
                                                    <td style={{ fontSize: '12px' }}>{inc.created_at}</td>
                                                    <td>
                                                        <div style={{ display: 'flex', gap: '4px', flexWrap: 'wrap' }}>
                                                            <button onClick={() => fetchIncidentDetail(inc.id)} className="btn btn-secondary" style={{ padding: '3px 8px', fontSize: '11px' }}>View</button>
                                                            {inc.status === 'open' && canInvestigate && (
                                                                <button onClick={() => handleInvestigate(inc.id)} className="btn btn-secondary" style={{ padding: '3px 8px', fontSize: '11px', background: 'rgba(245,158,11,0.1)', borderColor: 'rgba(245,158,11,0.3)', color: '#f59e0b' }}>Investigate</button>
                                                            )}
                                                            {inc.status === 'investigating' && canResolve && (
                                                                <button onClick={() => { setSelectedIncident({ ...inc, action: 'resolve' }); setResolveForm({ resolution_reason: 'closed', resolution_action: '', resolution_notes: '' }); }} className="btn btn-primary" style={{ padding: '3px 8px', fontSize: '11px', background: 'linear-gradient(135deg,#059669,#10b981)' }}>Resolve</button>
                                                            )}
                                                            {inc.status !== 'resolved' && inc.status !== 'dismissed' && canDismiss && (
                                                                <button onClick={() => handleDismiss(inc.id)} className="btn btn-secondary" style={{ padding: '3px 8px', fontSize: '11px' }}>Dismiss</button>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                {/* Pagination */}
                                {incidentsPagination.last_page > 1 && (
                                    <div style={{ display: 'flex', justifyContent: 'center', gap: '4px', padding: '12px', borderTop: '1px solid var(--glass-border)' }}>
                                        {Array.from({ length: incidentsPagination.last_page }, (_, i) => i + 1).slice(Math.max(0, incidentsPagination.current_page - 3), incidentsPagination.current_page + 3).map(page => (
                                            <button key={page} onClick={() => { setIncidentsPagination(p => ({ ...p, current_page: page })); fetchIncidents(); }} className="btn" style={{ padding: '4px 10px', fontSize: '12px', background: page === incidentsPagination.current_page ? 'var(--accent-primary)' : 'transparent', color: page === incidentsPagination.current_page ? '#fff' : 'var(--text-secondary)' }}>{page}</button>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* Create Incident Form */}
                            <div className="glass-card" style={{ marginTop: '24px' }}>
                                <h3 style={{ fontFamily: 'var(--font-heading)', marginBottom: '16px' }}> Report New Incident</h3>
                                <form onSubmit={handleIncidentSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                                    <div>
                                        <label style={{ fontSize: '12px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Incident Type *</label>
                                        <select className="form-input" value={incidentForm.type} onChange={e => setIncidentForm({ ...incidentForm, type: e.target.value })}>
                                            {incidentTypeOptions.filter(t => t.value !== 'all').map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
                                        </select>
                                    </div>
                                    <div>
                                        <label style={{ fontSize: '12px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Severity *</label>
                                        <select className="form-input" value={incidentForm.severity} onChange={e => setIncidentForm({ ...incidentForm, severity: e.target.value })}>
                                            <option value="low">Low</option>
                                            <option value="medium">Medium</option>
                                            <option value="high">High</option>
                                            <option value="critical">Critical</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style={{ fontSize: '12px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Title *</label>
                                        <input type="text" className="form-input" value={incidentForm.title} onChange={e => setIncidentForm({ ...incidentForm, title: e.target.value })} placeholder="Brief incident title" required />
                                    </div>
                                    <div>
                                        <label style={{ fontSize: '12px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Description</label>
                                        <textarea className="form-input" rows="3" value={incidentForm.description} onChange={e => setIncidentForm({ ...incidentForm, description: e.target.value })} placeholder="Detailed description of the incident..." style={{ resize: 'vertical' }} />
                                    </div>
                                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
                                        <div>
                                            <label style={{ fontSize: '12px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Related Visit</label>
                                            <select
                                                className="form-input"
                                                value={incidentForm.visit_id}
                                                onChange={e => {
                                                    const visitId = e.target.value;
                                                    const visit = recentVisits.find(v => String(v.id) === visitId);
                                                    setIncidentForm({
                                                        ...incidentForm,
                                                        visit_id: visitId ? Number(visitId) : '',
                                                        visitor_id: visit?.visitor_id || ''
                                                    });
                                                }}
                                                style={{ fontSize: '12px', padding: '6px 8px' }}
                                            >
                                                <option value="">Select a visit (optional)</option>
                                                {recentVisitsLoading && <option>Loading…</option>}
                                                {recentVisits.map(v => (
                                                    <option key={v.id} value={v.id}>{v.visitor_name} — {v.host_name} — {v.status} — {v.requested_time}</option>
                                                ))}
                                            </select>
                                            {!incidentForm.visit_id && (
                                                <button type="button" onClick={() => { fetchRecentVisits(); }} className="btn btn-secondary" style={{ padding: '2px 6px', fontSize: '10px', marginTop: '4px' }}>Load Visits</button>
                                            )}
                                        </div>
                                        <div>
                                            <label style={{ fontSize: '12px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Related Visitor ID</label>
                                            <input
                                                type="number"
                                                className="form-input"
                                                value={incidentForm.visitor_id}
                                                onChange={e => setIncidentForm({ ...incidentForm, visitor_id: e.target.value })}
                                                placeholder={incidentForm.visit_id ? 'Auto-filled from visit' : 'Optional'}
                                                disabled={!!incidentForm.visit_id}
                                            />
                                        </div>
                                    </div>
                                    <button type="submit" className="btn btn-primary" style={{ background: 'linear-gradient(135deg,#dc2626,#ef4444)' }}>
                                        Submit Incident Report
                                    </button>
                                </form>
                            </div>

                            {/* Blacklist Quick Reference */}
                            <div className="glass-card" style={{ marginTop: '24px' }}>
                                <h3 style={{ fontFamily: 'var(--font-heading)', marginBottom: '16px' }}> Blacklist Quick Reference</h3>
                                <div className="table-container" style={{ marginBottom: 0 }}>
                                    <table className="data-table">
                                        <thead><tr><th>Name</th><th>ID Document</th><th>Reason</th></tr></thead>
                                        <tbody>
                                            {blacklist && blacklist.map((item, idx) => (
                                                <tr key={idx} style={{ background: 'rgba(239,68,68,0.02)' }}>
                                                    <td style={{ color: '#f87171' }}> {item.name}</td>
                                                    <td>{item.id_document_no}</td>
                                                    <td style={{ fontSize: '12px' }}>{item.reason || '—'}</td>
                                                </tr>
                                            ))}
                                            {(!blacklist || blacklist.length === 0) && (
                                                <tr><td colSpan="3" style={{ color: 'var(--text-muted)', textAlign: 'center' }}>No blacklist entries</td></tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Resolve Modal */}
                    {selectedIncident?.action === 'resolve' && (
                        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.7)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 9999 }}>
                            <div style={{ background: 'var(--glass-bg)', border: '1px solid var(--glass-border)', borderRadius: '12px', padding: '24px', width: '90%', maxWidth: '520px', maxHeight: '80vh', overflowY: 'auto' }}>
                                <h3 style={{ fontFamily: 'var(--font-heading)', margin: 0, marginBottom: '16px' }}>Resolve Incident #{selectedIncident.id}</h3>
                                <div style={{ marginBottom: '12px' }}>
                                    <label style={{ fontSize: '11px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Incident</label>
                                    <div style={{ fontSize: '13px', fontWeight: 600, color: 'var(--text-primary)' }}>{selectedIncident.title}</div>
                                    <div style={{ fontSize: '11px', color: 'var(--text-muted)' }}>{selectedIncident.description}</div>
                                </div>
                                <div style={{ marginBottom: '12px' }}>
                                    <label style={{ fontSize: '11px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Resolution Reason *</label>
                                    <select className="form-input" value={resolveForm.resolution_reason} onChange={e => setResolveForm({ ...resolveForm, resolution_reason: e.target.value })} required>
                                        {resolutionReasonOptions.map(r => <option key={r.value} value={r.value}>{r.label}</option>)}
                                    </select>
                                </div>
                                <div style={{ marginBottom: '12px' }}>
                                    <label style={{ fontSize: '11px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Action Taken</label>
                                    <input type="text" className="form-input" value={resolveForm.resolution_action} onChange={e => setResolveForm({ ...resolveForm, resolution_action: e.target.value })} placeholder="What action was taken?" />
                                </div>
                                <div style={{ marginBottom: '12px' }}>
                                    <label style={{ fontSize: '11px', color: 'var(--text-secondary)', marginBottom: '4px', display: 'block' }}>Resolution Notes</label>
                                    <textarea className="form-input" rows="3" value={resolveForm.resolution_notes} onChange={e => setResolveForm({ ...resolveForm, resolution_notes: e.target.value })} placeholder="Additional notes..." style={{ resize: 'vertical' }} />
                                </div>
                                <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end', marginTop: '16px' }}>
                                    <button onClick={() => setSelectedIncident(null)} className="btn btn-secondary" style={{ padding: '6px 16px', fontSize: '12px' }}>Cancel</button>
                                    <button onClick={() => handleResolve(selectedIncident.id, resolveForm)} className="btn btn-primary" disabled={actionLoading || !resolveForm.resolution_reason} style={{ padding: '6px 16px', fontSize: '12px', background: 'linear-gradient(135deg,#059669,#10b981)' }}>
                                        {actionLoading ? 'Resolving…' : 'Resolve Incident'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Incident Detail Modal */}
                    {selectedIncident && selectedIncident.action !== 'resolve' && (
                        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.7)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 9999, overflowY: 'auto' }}>
                            <div style={{ background: 'var(--glass-bg)', border: '1px solid var(--glass-border)', borderRadius: '12px', padding: '24px', width: '90%', maxWidth: '720px', maxHeight: '85vh', overflowY: 'auto', margin: '20px' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '16px' }}>
                                    <h3 style={{ fontFamily: 'var(--font-heading)', margin: 0, fontSize: '16px' }}>Incident #{selectedIncident.id} — {selectedIncident.title}</h3>
                                    <button onClick={() => setSelectedIncident(null)} className="btn btn-secondary" style={{ padding: '4px 10px', fontSize: '12px' }}><AppIcon name="x" /> Close</button>
                                </div>

                                {detailLoading ? (
                                    <p style={{ color: 'var(--text-muted)' }}>Loading detail…</p>
                                ) : (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: '14px', fontSize: '13px' }}>
                                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px' }}>
                                            <div><span style={{ color: 'var(--text-secondary)' }}>Type:</span> {selectedIncident.type_label}</div>
                                            <div><span style={{ color: 'var(--text-secondary)' }}>Severity:</span> <span style={{ color: selectedIncident.severity_color, fontWeight: 700, textTransform: 'uppercase' }}>{selectedIncident.severity}</span></div>
                                            <div><span style={{ color: 'var(--text-secondary)' }}>Status:</span> <span style={{ color: getStatusColor(selectedIncident.status), fontWeight: 600 }}>{selectedIncident.status_label}</span></div>
                                            <div><span style={{ color: 'var(--text-secondary)' }}>Attempts:</span> {selectedIncident.attempt_count} {selectedIncident.attempt_count > 1 && '(deduplicated)'} {selectedIncident.last_attempt_at && `(last: ${selectedIncident.last_attempt_at})`}</div>
                                            <div><span style={{ color: 'var(--text-secondary)' }}>Visitor:</span> {selectedIncident.visitor_name || '—'} {selectedIncident.visitor_document && `(${selectedIncident.visitor_document})`}</div>
                                            <div><span style={{ color: 'var(--text-secondary)' }}>Checkpoint:</span> {selectedIncident.checkpoint_name || '—'}</div>
                                            <div><span style={{ color: 'var(--text-secondary)' }}>Reported by:</span> {selectedIncident.reported_by_name || 'System'} <span style={{ color: 'var(--text-muted)' }}>({selectedIncident.created_at})</span></div>
                                            {selectedIncident.resolved_by_name && (
                                                <div><span style={{ color: 'var(--text-secondary)' }}>Resolved by:</span> {selectedIncident.resolved_by_name} <span style={{ color: 'var(--text-muted)' }}>({selectedIncident.resolved_at})</span></div>
                                            )}
                                        </div>

                                        <div>
                                            <strong style={{ color: 'var(--text-primary)', display: 'block', marginBottom: '4px' }}>Description</strong>
                                            <div style={{ color: 'var(--text-secondary)', whiteSpace: 'pre-wrap' }}>{selectedIncident.description || '—'}</div>
                                        </div>

                                        {selectedIncident.scan_token && (
                                            <div>
                                                <strong style={{ color: 'var(--text-primary)', display: 'block', marginBottom: '4px' }}>Scan Token</strong>
                                                <code style={{ fontSize: '11px', background: 'rgba(0,0,0,0.2)', padding: '4px 8px', borderRadius: '4px', wordBreak: 'break-all' }}>{selectedIncident.scan_token}</code>
                                            </div>
                                        )}

                                        {selectedIncident.action_taken && (
                                            <div>
                                                <strong style={{ color: 'var(--text-primary)', display: 'block', marginBottom: '4px' }}>Action Taken</strong>
                                                <div style={{ color: 'var(--text-secondary)', whiteSpace: 'pre-wrap' }}>{selectedIncident.action_taken}</div>
                                            </div>
                                        )}

                                        {(selectedIncident.resolution_reason || selectedIncident.resolution_notes) && selectedIncident.status === 'resolved' && (
                                            <div style={{ background: 'rgba(16,185,129,0.05)', border: '1px solid rgba(16,185,129,0.2)', borderRadius: '8px', padding: '12px' }}>
                                                <strong style={{ color: '#10b981', display: 'block', marginBottom: '4px' }}>Resolution</strong>
                                                <div style={{ fontSize: '12px' }}>
                                                    <span style={{ color: 'var(--text-secondary)' }}>Reason:</span> {selectedIncident.resolution_reason_label || selectedIncident.resolution_reason}
                                                    {selectedIncident.resolution_action && <> <span style={{ color: 'var(--text-muted)' }}>| Action:</span> {selectedIncident.resolution_action}</>}
                                                    {selectedIncident.resolution_notes && <> <span style={{ color: 'var(--text-muted)' }}>| Notes:</span> {selectedIncident.resolution_notes}</>}
                                                </div>
                                            </div>
                                        )}

                                        <div>
                                            <strong style={{ color: 'var(--text-primary)', display: 'block', marginBottom: '8px' }}>Audit Trail ({selectedIncident.audit_trail?.length || 0})</strong>
                                            {selectedIncident.audit_trail && selectedIncident.audit_trail.length > 0 ? (
                                                <div style={{ display: 'flex', flexDirection: 'column', gap: '6px', maxHeight: '200px', overflowY: 'auto' }}>
                                                    {selectedIncident.audit_trail.map(a => (
                                                        <div key={a.id} style={{ fontSize: '11px', padding: '6px 10px', background: 'rgba(0,0,0,0.15)', borderRadius: '6px', display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
                                                            <span style={{ color: '#60a5fa' }}>{a.created_at}</span>
                                                            <span style={{ color: '#fbbf24' }}>{a.action}</span>
                                                            {a.from_status && <span style={{ color: 'var(--text-muted)' }}>{a.from_status} → {a.to_status}</span>}
                                                            {a.actor_name && <span style={{ color: 'var(--text-secondary)' }}>by {a.actor_name}</span>}
                                                            {a.notes && <span style={{ color: 'var(--text-muted)', flex: 1, minWidth: '100%' }}>{a.notes}</span>}
                                                        </div>
                                                    ))}
                                                </div>
                                            ) : (
                                                <p style={{ color: 'var(--text-muted)', fontSize: '12px' }}>No audit entries.</p>
                                            )}
                                        </div>
                                    </div>
                                )}

                                <div style={{ display: 'flex', gap: '10px', marginTop: '16px', justifyContent: 'flex-end' }}>
                                    {selectedIncident.status === 'open' && canInvestigate && (
                                        <button onClick={() => handleInvestigate(selectedIncident.id)} className="btn btn-secondary" style={{ padding: '6px 14px', fontSize: '12px' }}><AppIcon name="search" /> Investigate</button>
                                    )}
                                    {selectedIncident.status === 'investigating' && canResolve && (
                                        <button onClick={() => { setSelectedIncident({ ...selectedIncident, action: 'resolve' }); setResolveForm({ resolution_reason: 'closed', resolution_action: '', resolution_notes: '' }); }} className="btn btn-primary" style={{ padding: '6px 14px', fontSize: '12px', background: 'linear-gradient(135deg,#059669,#10b981)' }}><AppIcon name="check" /> Resolve</button>
                                    )}
                                    {selectedIncident.status !== 'resolved' && selectedIncident.status !== 'dismissed' && canDismiss && (
                                        <button onClick={() => handleDismiss(selectedIncident.id)} className="btn btn-secondary" style={{ padding: '6px 14px', fontSize: '12px' }}><AppIcon name="x" /> Dismiss</button>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}
                    {activeTab === 'entry_gate' && checkoutMode && (
                        <div className="glass-card" style={{ marginTop: '24px', borderColor: 'rgba(245,158,11,0.4)', background: 'rgba(245,158,11,0.03)' }}>
                            <h3 style={{ fontFamily: 'var(--font-heading)', marginBottom: '16px', color: '#fbbf24' }}> Exit Gate — Scan QR & Verify Property</h3>

                            {/* Step 1: QR Scan at Exit — only show when no scan result yet */}
                            {!exitScanResult && (
                                <div>
                                    <p style={{ fontSize: '13px', color: 'var(--text-secondary)', marginBottom: '12px' }}>Scan the visitor's QR code to identify them and retrieve registered property.</p>
                                    <form onSubmit={handleExitQrScan} style={{ display: 'flex', gap: '12px', marginBottom: '12px' }}>
                                        <input
                                            ref={exitScanRef}
                                            type="text" className="form-input" placeholder="Scan visitor's QR code for exit..."
                                            value={exitQrInput} onChange={e => setExitQrInput(e.target.value)}
                                            style={{ flex: 1 }}
                                            disabled={exitScanLoading}
                                            autoFocus
                                        />
                                        <button type="submit" className="btn btn-primary" disabled={exitScanLoading} style={exitScanLoading ? { opacity: 0.6, cursor: 'wait' } : {}}>
                                            {exitScanLoading ? 'Scanning…' : 'Scan QR'}
                                        </button>
                                    </form>

                                    {exitScanStatus && (
                                        <div style={{ marginBottom: '12px' }}>
                                            <span className="qr-status-badge" style={{
                                                background: exitScanStatus === 'valid' ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)',
                                                border: exitScanStatus === 'valid' ? '1px solid rgba(16,185,129,0.4)' : '1px solid rgba(239,68,68,0.4)',
                                                color: exitScanStatus === 'valid' ? '#10b981' : '#f87171',
                                            }}>
                                                {exitScanStatus === 'valid' ? ' VISITOR IDENTIFIED' : ' INVALID QR CODE'}
                                            </span>
                                        </div>
                                    )}

                                    {exitScanError && (
                                        <div className="alert-banner alert-error" style={{ fontSize: '13px', marginBottom: '12px' }}>
                                            <AppIcon name="alert" /> {exitScanError}
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* Step 2: Visitor Identified — show when exitScanResult is set */}
                            {exitScanResult && (
                                <div>
                                    <div style={{ background: 'rgba(0,240,255,0.03)', border: '1px solid rgba(0,240,255,0.15)', padding: '14px', borderRadius: '8px', marginBottom: '16px', fontSize: '13px' }}>
                                        <strong style={{ color: 'var(--accent-cyan)' }}><AppIcon name="user" /> {exitScanResult.is_group_member ? 'Group Member Identified' : 'Visitor Identified'}:</strong>
                                        {exitScanResult.owner_verified && (
                                            <span style={{ marginLeft: '8px', fontSize: '11px', fontWeight: 700, padding: '2px 8px', borderRadius: '12px', background: 'rgba(16,185,129,0.15)', border: '1px solid rgba(16,185,129,0.4)', color: '#10b981' }}><AppIcon name="check" /> PROPERTY OWNER VERIFIED</span>
                                        )}
                                        {exitScanResult.is_group_member && (
                                            <span style={{ marginLeft: '8px', fontSize: '11px', fontWeight: 700, padding: '2px 8px', borderRadius: '12px', background: 'rgba(16,185,129,0.15)', border: '1px solid rgba(16,185,129,0.4)', color: '#10b981' }}>GROUP MEMBER EXIT</span>
                                        )}
                                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px', marginTop: '8px' }}>
                                            <div><span style={{ color: 'var(--text-secondary)' }}>Name:</span> <strong>{exitScanResult.visitor_name}</strong></div>
                                            <div><span style={{ color: 'var(--text-secondary)' }}>ID:</span> <strong>{exitScanResult.id_document_no}</strong></div>
                                            <div><span style={{ color: 'var(--text-secondary)' }}>Host:</span> <strong>{exitScanResult.host_name}</strong></div>
                                            <div><span style={{ color: 'var(--text-secondary)' }}>Department:</span> <strong>{exitScanResult.department}</strong></div>
                                        </div>
                                    </div>

                                    {/* Verify Return Result */}
                                    {verifyReturnResult && (
                                        <div style={{
                                            padding: '14px', borderRadius: '8px', marginBottom: '16px', fontSize: '13px',
                                            background: verifyReturnResult.all_returned ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)',
                                            border: `1px solid ${verifyReturnResult.all_returned ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)'}`,
                                            color: verifyReturnResult.all_returned ? '#6ee7b7' : '#fca5a5',
                                        }}>
                                            <strong>{verifyReturnResult.all_returned ? ' ALL PROPERTY VERIFIED — EXIT ALLOWED' : ' MISSING PROPERTY DETECTED'}</strong>
                                            <div style={{ marginTop: '4px' }}>{verifyReturnResult.message}</div>
                                            {verifyReturnResult.missing_items && verifyReturnResult.missing_items.length > 0 && (
                                                <div style={{ marginTop: '8px', fontSize: '12px' }}>
                                                    Missing: {verifyReturnResult.missing_items.join(', ')}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {/* Property Checklist */}
                                    {(!exitScanResult.properties || exitScanResult.properties.length === 0) ? (
                                        <div style={{ padding: '16px', background: 'rgba(255,255,255,0.02)', borderRadius: '8px', marginBottom: '16px' }}>
                                            <p style={{ color: 'var(--text-muted)', fontSize: '13px', margin: 0 }}>No registered properties for this visitor. Proceed to checkout.</p>
                                        </div>
                                    ) : (
                                        <div style={{ marginBottom: '16px' }}>
                                            <strong style={{ fontSize: '13px', color: '#fbbf24' }}><AppIcon name="clipboard" /> Registered Property — Check each item as returned:</strong>
                                            <div style={{ marginTop: '10px', display: 'flex', flexDirection: 'column', gap: '8px' }}>
                                                {exitScanResult.properties.map(p => (
                                                    <label key={p.id} style={{
                                                        display: 'flex', alignItems: 'center', gap: '12px', padding: '12px 16px', borderRadius: '8px', cursor: 'pointer',
                                                        background: exitReturnedItems[p.id] ? 'rgba(16,185,129,0.08)' : 'rgba(255,255,255,0.02)',
                                                        border: `1px solid ${exitReturnedItems[p.id] ? 'rgba(16,185,129,0.3)' : 'rgba(255,255,255,0.06)'}`,
                                                        transition: 'all 0.2s',
                                                    }}>
                                                        <input
                                                            type="checkbox"
                                                            checked={!!exitReturnedItems[p.id]}
                                                            onChange={e => setExitReturnedItems(prev => ({ ...prev, [p.id]: e.target.checked }))}
                                                            style={{ width: 18, height: 18, accentColor: '#10b981', flexShrink: 0 }}
                                                        />
                                                        <div style={{ flex: 1 }}>
                                                            <div style={{ fontWeight: 600, fontSize: '14px' }}>{p.item_name} {p.quantity > 1 && `× ${p.quantity}`}</div>
                                                            <div style={{ fontSize: '12px', color: 'var(--text-secondary)', marginTop: '2px' }}>
                                                                {p.serial_number && <span>SN: {p.serial_number}</span>}
                                                                {p.serial_number && p.property_tag && <span> · </span>}
                                                                {p.property_tag && <span>Tag: {p.property_tag}</span>}
                                                            </div>
                                                        </div>
                                                        <span style={{ fontSize: '12px', fontWeight: 600, color: exitReturnedItems[p.id] ? '#10b981' : 'var(--text-muted)' }}>
                                                            {exitReturnedItems[p.id] ? ' Returned' : ' Pending'}
                                                        </span>
                                                    </label>
                                                ))}
                                            </div>
                                        </div>
                                    )}

                                    {/* Action Buttons */}
                                    <div style={{ display: 'flex', gap: '10px' }}>
                                        <form onSubmit={handleVerifyReturn} style={{ flex: 1 }}>
                                            <button type="submit" className="btn btn-primary" style={{ width: '100%', background: 'linear-gradient(135deg,#059669,#10b981)' }} disabled={checkoutLoading}>
                                                {checkoutLoading ? 'Processing…' : 'Verify All Property Returned'}
                                            </button>
                                        </form>
                                        <button onClick={handleForceCheckout} className="btn btn-danger" style={{ padding: '10px 20px', fontSize: '13px' }} disabled={checkoutLoading}>
                                            {checkoutLoading ? 'Processing…' : 'Force Checkout (Missing Property)'}
                                        </button>
                                        <button onClick={resetExitGate} className="btn btn-secondary" style={{ padding: '10px 20px', fontSize: '13px' }}>
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* ==================== 11. DAILY REPORTS ==================== */}
                    {activeTab === 'reports' && (
                        <div className="sec-panel active">
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px', flexWrap: 'wrap', gap: '10px' }}>
                                <div>
                                    <h3 style={{ fontFamily: 'var(--font-heading)', margin: 0 }}>📊 {periodTitle[reportPeriod] || "Today's"} Security Report</h3>
                                    <div style={{ fontSize: '12px', color: 'var(--text-secondary)', marginTop: '4px' }}>
                                        Reporting period: {report_range?.label || report_stats?.date || '—'}
                                    </div>
                                </div>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' }}>
                                    <select value={reportPeriod} onChange={e => setReportPeriod(e.target.value)} style={{ padding: '8px 12px', borderRadius: 8, border: '1px solid var(--border-color-strong)', background: '#ffffff', color: '#1e293b', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                        <option value="yearly">Yearly</option>
                                        <option value="range">Custom Range</option>
                                    </select>
                                    {reportPeriod === 'range' && (
                                        <>
                                            <input type="date" value={reportFrom} onChange={e => setReportFrom(e.target.value)} style={{ padding: '6px 10px', borderRadius: 8, border: '1px solid var(--border-color-strong)', background: '#ffffff', color: '#1e293b', fontSize: 13 }} />
                                            <span style={{ fontSize: 12, color: 'var(--text-secondary)' }}>to</span>
                                            <input type="date" value={reportTo} onChange={e => setReportTo(e.target.value)} style={{ padding: '6px 10px', borderRadius: 8, border: '1px solid var(--border-color-strong)', background: '#ffffff', color: '#1e293b', fontSize: 13 }} />
                                        </>
                                    )}
                                    <select value={reportView} onChange={e => setReportView(e.target.value)} style={{ padding: '8px 12px', borderRadius: 8, border: '1px solid var(--border-color-strong)', background: '#ffffff', color: '#1e293b', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>
                                        <option value="visitors">Visitors</option>
                                        <option value="incidents">Security Incidents</option>
                                    </select>
                                    <button onClick={generateReport} className="btn btn-primary">Generate Report</button>
                                </div>
                            </div>

                            <div className="sec-stat-grid">
                                {report_stats && Object.entries({
                                    'Total Visitors': { val: report_stats.total_visitors, color: 'var(--accent-cyan)' },
                                    'Checked In': { val: report_stats.checked_in, color: '#10b981' },
                                    'Checked Out': { val: report_stats.checked_out, color: '#818cf8' },
                                    'Denied Entries': { val: report_stats.denied_entries, color: '#f87171' },
                                    'Incidents': { val: report_stats.incidents, color: '#ef4444' },
                                    'Critical': { val: report_stats.critical_incidents, color: '#dc2626' },
                                    'Blacklist Attempts': { val: report_stats.blacklist_attempts, color: '#f59e0b' },
                                    'Property Items': { val: report_stats.property_items, color: '#06b6d4' },
                                }).map(([label, { val, color }]) => (
                                    <div key={label} className="sec-stat" style={{ borderColor: `${color}40` }}>
                                        <div className="val" style={{ color }}>{val}</div>
                                        <div className="lbl">{label}</div>
                                    </div>
                                ))}
                            </div>

                            {/* Report Visitors */}
                            {reportView === 'visitors' && report_visitors && report_visitors.length > 0 && (
                                <div className="glass-card" style={{ marginBottom: '24px' }}>
                                    <h4 style={{ fontFamily: 'var(--font-heading)', marginBottom: '12px' }}>Visitors</h4>
                                    <div className="table-container" style={{ marginBottom: 0 }}>
                                        <table className="data-table">
                                            <thead><tr><th>ID</th><th>Visitor</th><th>Host</th><th>Purpose</th><th>Time</th><th>Status</th></tr></thead>
                                            <tbody>
                                                {report_visitors.map(v => (
                                                    <tr key={v.id}>
                                                        <td>#{v.id}</td><td>{v.visitor}</td><td>{v.host}</td><td>{v.purpose}</td><td>{v.time}</td>
                                                        <td><span style={{ textTransform: 'capitalize', fontSize: '12px', fontWeight: 600, color: v.status === 'checked_in' ? '#10b981' : v.status === 'approved' ? '#10b981' : 'var(--text-secondary)' }}>{v.status.replace(/_/g, ' ')}</span></td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                            {reportView === 'visitors' && (!report_visitors || report_visitors.length === 0) && (
                                <div className="glass-card" style={{ padding: '24px', textAlign: 'center', color: 'var(--text-secondary)' }}>No visitors in the selected period.</div>
                            )}

                            {/* Report Incidents */}
                            {reportView === 'incidents' && report_incidents && report_incidents.length > 0 && (
                                <div className="glass-card">
                                    <h4 style={{ fontFamily: 'var(--font-heading)', marginBottom: '12px' }}>Security Incidents</h4>
                                    <div className="table-container" style={{ marginBottom: 0 }}>
                                        <table className="data-table">
                                            <thead><tr><th>ID</th><th>Type</th><th>Title</th><th>Severity</th><th>Status</th><th>Reporter</th><th>Time</th></tr></thead>
                                            <tbody>
                                                {report_incidents.map(inc => (
                                                    <tr key={inc.id}>
                                                        <td>#{inc.id}</td><td style={{ textTransform: 'capitalize' }}>{inc.type.replace(/_/g, ' ')}</td><td>{inc.title}</td>
                                                        <td><span style={{ color: getSeverityColor(inc.severity), fontWeight: 700, textTransform: 'uppercase', fontSize: '11px' }}>{inc.severity}</span></td>
                                                        <td><span style={{ color: getStatusColor(inc.status), fontWeight: 600, fontSize: '12px' }}>{inc.status}</span></td>
                                                        <td>{inc.reported_by}</td><td>{inc.time}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                            {reportView === 'incidents' && (!report_incidents || report_incidents.length === 0) && (
                                <div className="glass-card" style={{ padding: '24px', textAlign: 'center', color: 'var(--text-secondary)' }}>No incidents in the selected period.</div>
                            )}
                        </div>
                    )}

                    {activeTab === 'messages' && (
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

        </Layout>
    )
}

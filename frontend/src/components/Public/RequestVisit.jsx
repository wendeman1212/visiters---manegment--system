import React, { useState, useMemo } from 'react';
import Layout from '../Shared/Layout';
import { useForm, Link } from '@inertiajs/react';
import { useLang } from '../../LangContext';
import { t } from '../../i18n';
import { validators as v } from '../../utils/validation';

const PURPOSE_OPTIONS = ['Business Meeting', 'Job Interview', 'Delivery', 'Consultation', 'Client Visit', 'Government Affairs', 'Other'];

export default function RequestVisit({ hosts, categories, locations, branches, companies, company_setting, working_hours }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        visitor_name: '', id_document_no: '', visitor_email: '', visitor_phone: '',
        host_id: '', company_id: '', location_id: '', branch_id: '', department_id: '', visitor_category_id: '', purpose: '', requested_time: '', duration: 60, meeting_room_id: '',
        company_name: '', company_email: '', company_phone: '', company_address: '', company_website: '',
        representative_gender: '', representative_nationality: '', representative_position: '',
        visit_type: '', visitors: [],
    });
    const { lang } = useLang();

    const [visitType, setVisitType] = useState(null);
    const [selLocation, setSelLocation] = useState('');
    const [selCompany, setSelCompany] = useState('');
    const [selBranch, setSelBranch] = useState('');
    const [selDepartment, setSelDepartment] = useState('');
    const [endTime, setEndTime] = useState('');
    const [durationError, setDurationError] = useState('');

    const emptyVisitor = () => ({ name: '', id_document_no: '', email: '', phone: '', gender: '', nationality: '', position: '' });
    const [visitors, setVisitors] = useState([emptyVisitor()]);

    const addVisitor = () => setVisitors(prev => [...prev, emptyVisitor()]);
    const removeVisitor = (idx) => setVisitors(prev => prev.filter((_, i) => i !== idx));
    const updateVisitor = (idx, field, value) => setVisitors(prev => prev.map((v, i) => i === idx ? { ...v, [field]: value } : v));

    // Filter branches by selected company
    const selectedCompany = useMemo(() =>
        (companies || []).find(c => String(c.company_id) === String(selCompany)),
        [companies, selCompany]);

    const filteredBranches = useMemo(() => {
        if (!selCompany) return branches || [];
        return (branches || []).filter(b => String(b.company_id) === String(selCompany));
    }, [selCompany, branches]);

    // Build departments — from company when no branch, from branch's own departments when a branch is selected
    const departments = useMemo(() => {
        if (selBranch) {
            const branch = (branches || []).find(b => String(b.id) === String(selBranch));
            return branch?.departments || [];
        }
        if (selCompany) return selectedCompany?.departments || [];
        if (selLocation) {
            const loc = (locations || []).find(l => String(l.id) === String(selLocation));
            return loc?.departments || [];
        }
        return [];
    }, [selBranch, selCompany, selectedCompany, selLocation, locations, branches]);

    // Filter hosts by selected branch AND company (department is not shown to visitors)
    const filteredHosts = useMemo(() => {
        if (!selCompany && !selBranch) return [];
        return (hosts || []).filter(h =>
            (!selCompany || String(h.company_id) === String(selCompany)) &&
            (!selBranch || String(h.branch_id) === String(selBranch))
        );
    }, [selBranch, selCompany, hosts]);

    const now = new Date();
    const [pastError, setPastError] = useState('');
    const [hoursError, setHoursError] = useState('');
    const [fieldErrors, setFieldErrors] = useState({});
    const allErrors = { ...fieldErrors, ...errors };

    const reqText = (val) => val !== undefined && val !== null && String(val).trim() !== '';

    // ── Field validation (mirrors VisitRequestController::store) ──
    const validateForm = () => {
        const e = {};

        if (!reqText(data.visitor_name)) e.visitor_name = t(lang, 'fullName') + ' ' + t(lang, 'required') + '.';
        else if (!v.isName(data.visitor_name)) e.visitor_name = 'Name may only contain letters, spaces, hyphens and apostrophes.';
        else if (!v.maxLen(data.visitor_name, 255)) e.visitor_name = 'Name must not exceed 255 characters.';

        if (!reqText(data.id_document_no)) e.id_document_no = t(lang, 'nationalId') + ' ' + t(lang, 'required') + '.';
        else if (!v.isSixDigitId(data.id_document_no)) e.id_document_no = 'National ID must be a 6-digit number.';

        if (!reqText(data.visitor_email)) e.visitor_email = t(lang, 'emailAddress') + ' ' + t(lang, 'required') + '.';
        else if (!v.isEmail(data.visitor_email)) e.visitor_email = 'Please enter a valid email address.';

        if (!reqText(data.visitor_phone)) e.visitor_phone = t(lang, 'phone') + ' ' + t(lang, 'required') + '.';
        else if (!v.isPhone(data.visitor_phone)) e.visitor_phone = 'Please enter a valid phone number.';

        if (!reqText(data.purpose)) e.purpose = t(lang, 'purpose') + ' ' + t(lang, 'required') + '.';
        else if (!v.maxLen(data.purpose, 255)) e.purpose = 'Purpose must not exceed 255 characters.';

        if (visitType === 'company') {
            if (!reqText(data.company_name)) e.company_name = t(lang, 'companyName') + ' ' + t(lang, 'required') + '.';
            else if (!v.maxLen(data.company_name, 255)) e.company_name = 'Company name must not exceed 255 characters.';

            if (!reqText(data.company_email)) e.company_email = t(lang, 'companyEmail') + ' ' + t(lang, 'required') + '.';
            else if (!v.isEmail(data.company_email)) e.company_email = 'Please enter a valid company email address.';

            if (!reqText(data.company_phone)) e.company_phone = t(lang, 'companyPhone') + ' ' + t(lang, 'required') + '.';
            else if (!v.isPhone(data.company_phone)) e.company_phone = 'Please enter a valid company phone number.';

            if (!reqText(data.company_address)) e.company_address = t(lang, 'companyAddress') + ' ' + t(lang, 'required') + '.';

            if (reqText(data.company_website) && !v.isUrl(data.company_website)) e.company_website = 'Please enter a valid website URL.';

            (visitors || []).forEach((vis, idx) => {
                if (!reqText(vis.name)) e[`visitors.${idx}.name`] = t(lang, 'fullName') + ' ' + t(lang, 'required') + '.';
                else if (!v.isName(vis.name)) e[`visitors.${idx}.name`] = 'Name may only contain letters, spaces, hyphens and apostrophes.';
                if (!reqText(vis.id_document_no)) e[`visitors.${idx}.id_document_no`] = t(lang, 'nationalId') + ' ' + t(lang, 'required') + '.';
                else if (!v.isSixDigitId(vis.id_document_no)) e[`visitors.${idx}.id_document_no`] = 'National ID must be a 6-digit number.';
                if (!reqText(vis.email)) e[`visitors.${idx}.email`] = t(lang, 'emailAddress') + ' ' + t(lang, 'required') + '.';
                else if (!v.isEmail(vis.email)) e[`visitors.${idx}.email`] = 'Please enter a valid email address.';
                if (!reqText(vis.phone)) e[`visitors.${idx}.phone`] = t(lang, 'phone') + ' ' + t(lang, 'required') + '.';
                else if (!v.isPhone(vis.phone)) e[`visitors.${idx}.phone`] = 'Please enter a valid phone number.';
            });
        }

        return e;
    };

    // Admin-configured working hours, keyed by day_of_week (0=Sun..6=Sat)
    const hoursByDay = useMemo(() => {
        const map = {};
        (working_hours || []).forEach(h => { map[String(h.day_of_week)] = h; });
        return map;
    }, [working_hours]);

    const checkHours = (value) => {
        if (!value) return '';
        const d = new Date(value);
        if (isNaN(d.getTime())) return '';
        const wh = hoursByDay[String(d.getDay())];
        if (!wh || !wh.is_working_day) {
            return t(lang, 'notWorkingDay');
        }
        const open = (wh.open_time || '08:00').slice(0, 5);
        const close = (wh.close_time || '18:00').slice(0, 5);
        const mins = d.getHours() * 60 + d.getMinutes();
        const openM = parseInt(open.split(':')[0], 10) * 60 + parseInt(open.split(':')[1], 10);
        const closeM = parseInt(close.split(':')[0], 10) * 60 + parseInt(close.split(':')[1], 10);
        if (mins < openM || mins > closeM) {
            return t(lang, 'outsideWorkingHours');
        }
        return '';
    };

    const workingHoursSummary = useMemo(() => {
        if (!(working_hours || []).length) return null;
        const days = t(lang, 'days') || [];
        const ranges = (working_hours || [])
            .filter(h => h.is_working_day)
            .map(h => `${days[h.day_of_week] || 'Day ' + h.day_of_week} ${(h.open_time || '08:00').slice(0, 5)}–${(h.close_time || '18:00').slice(0, 5)}`);
        return ranges.length ? ranges.join(' · ') : null;
    }, [working_hours, lang]);

    const handleSubmit = (e) => {
        e.preventDefault();
        setPastError('');
        setDurationError('');
        setHoursError('');
        const formErrs = validateForm();
        setFieldErrors(formErrs);
        if (Object.keys(formErrs).length > 0) {
            return;
        }
        if (!data.requested_time) {
            setPastError(t(lang,'pastError'));
            return;
        }
        const selected = new Date(data.requested_time);
        if (isNaN(selected.getTime()) || selected <= new Date()) {
            setPastError(t(lang,'pastError'));
            return;
        }
        const hErr = checkHours(data.requested_time);
        if (hErr) {
            setHoursError(hErr);
            return;
        }
        if (!endTime) {
            setDurationError('Please select an end time for the visit');
            return;
        }
        const end = new Date(endTime);
        if (end <= selected) {
            setDurationError('End time must be after the start time');
            return;
        }
        const diffMin = Math.round((end - selected) / 60000);
        if (diffMin < 15) {
            setDurationError('Duration must be at least 15 minutes');
            return;
        }
        if (diffMin > 480) {
            setDurationError('Duration cannot exceed 8 hours');
            return;
        }
        setData('duration', diffMin);
        if (visitType === 'company') {
            setData('visitors', visitors);
        }
        post('/request-visit', { onSuccess: () => reset() });
    };

    return (
        <Layout title={t(lang,'scheduleVisit')}>
            <section className="ai-page flex justify-center px-6 pt-24 pb-16 sm:px-8 md:pt-28 md:pb-20 lg:px-16">
                <div className="ai-glow ai-glow-blue" style={{ width: '520px', height: '520px', top: '8%', left: '50%', transform: 'translateX(-50%)' }} />

                <div className="ai-card relative z-10 w-full max-w-2xl p-6 md:p-8">
                    <h2 className="text-2xl font-extrabold text-white">
                        {t(lang,'scheduleVisit')}
                    </h2>
                    <p className="mt-1 mb-8 text-sm text-slate-400">
                        {t(lang,'scheduleSubtitle')}
                    </p>

                    {/* Step Progress */}
                    <div className="mb-7 flex items-center gap-3">
                        <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[13px] font-bold ${visitType ? 'border border-emerald-500/40 bg-emerald-500/15 text-emerald-400' : 'bg-blue-600 text-white shadow-[0_0_20px_-4px_rgba(37,99,235,0.8)]'}`}>
                            {visitType ? '' : '1'}
                        </div>
                        <div className="h-0.5 flex-1 rounded-full" style={{ background: visitType ? 'rgba(16,185,129,0.3)' : 'rgba(255,255,255,0.08)' }} />
                        <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[13px] font-bold ${visitType ? 'bg-blue-600 text-white shadow-[0_0_20px_-4px_rgba(37,99,235,0.8)]' : 'bg-white/5 text-slate-500'}`}>
                            2
                        </div>
                    </div>

                    {/* ── STEP 1: Select Visit Type ── */}
                    {!visitType && (
                        <div>
                            <h4 className="ai-section-head">
                                {t(lang,'selectVisitType')}
                            </h4>

                            <div className="flex flex-col gap-4">
                                {/* Individual Visitor */}
                                <label className="ai-option"
                                    onMouseEnter={e => { e.currentTarget.style.borderColor = 'rgba(56,189,248,0.4)'; e.currentTarget.style.background = 'rgba(56,189,248,0.05)'; }}
                                    onMouseLeave={e => { e.currentTarget.style.borderColor = ''; e.currentTarget.style.background = ''; }}
                                >
                                    <input
                                        type="radio"
                                        name="visitType"
                                        value="individual"
                                        onChange={() => { setVisitType('individual'); setData('visit_type', 'individual'); }}
                                        style={{ marginTop: '4px', accentColor: '#2563eb', width: '18px', height: '18px', flexShrink: 0 }}
                                    />
                                    <div>
                                        <div className="mb-1 text-[15px] font-bold text-white">
                                            {t(lang,'individualVisitor')}
                                        </div>
                                        <div className="text-[13px] leading-relaxed text-slate-400">
                                            {t(lang,'individualDesc')}
                                        </div>
                                    </div>
                                </label>

                                {/* Company / Group Visit */}
                                <label className="ai-option"
                                    onMouseEnter={e => { e.currentTarget.style.borderColor = 'rgba(56,189,248,0.4)'; e.currentTarget.style.background = 'rgba(56,189,248,0.05)'; }}
                                    onMouseLeave={e => { e.currentTarget.style.borderColor = ''; e.currentTarget.style.background = ''; }}
                                >
                                    <input
                                        type="radio"
                                        name="visitType"
                                        value="company"
                                        onChange={() => { setVisitType('company'); setData('visit_type', 'company'); }}
                                        style={{ marginTop: '4px', accentColor: '#2563eb', width: '18px', height: '18px', flexShrink: 0 }}
                                    />
                                    <div>
                                        <div className="mb-1 text-[15px] font-bold text-white">
                                            {t(lang,'companyGroupVisit')}
                                        </div>
                                        <div className="text-[13px] leading-relaxed text-slate-400">
                                            {t(lang,'companyGroupDesc')}
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>
                    )}

                    {/* ── STEP 2: Individual Visitor Form ── */}
                    {visitType === 'individual' && (
                        <div>
                            <div className="mb-5 flex items-center gap-3">
                                <button onClick={() => setVisitType(null)} className="flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-300 transition hover:bg-white/10">
                                    ← {t(lang,'back')}
                                </button>
                                <span className="text-xs text-slate-500">{t(lang,'step2of2')}</span>
                            </div>

                    <form onSubmit={handleSubmit}>
                        <h4 className="ai-section-head">
                            {t(lang,'visitorInfo')}
                        </h4>

                        <div className="ai-grid-2">
                            <div className="form-group">
                                <label className="ai-label">{t(lang,'fullName')}</label>
                                <input type="text" className="ai-input" value={data.visitor_name}
                                    onChange={e => setData('visitor_name', e.target.value)} required />
                                {allErrors.visitor_name && <div className="ai-error">{allErrors.visitor_name}</div>}
                            </div>
                            <div className="form-group">
                                <label className="ai-label">{t(lang,'nationalId')}</label>
                                <input type="text" className="ai-input" value={data.id_document_no}
                                    onChange={e => { const v = e.target.value.replace(/\D/g,'').slice(0,6); setData('id_document_no', v); }}
                                    placeholder="6-digit number" maxLength={6} pattern="\d{6}" inputMode="numeric" required />
                                {allErrors.id_document_no && <div className="ai-error">{allErrors.id_document_no}</div>}
                            </div>
                        </div>

                        <div className="ai-grid-2">
                            <div className="form-group">
                                <label className="ai-label">{t(lang,'emailAddress')}</label>
                                <input type="email" className="ai-input" value={data.visitor_email}
                                    onChange={e => setData('visitor_email', e.target.value)} required />
                                {allErrors.visitor_email && <div className="ai-error">{allErrors.visitor_email}</div>}
                            </div>
                            <div className="form-group">
                                <label className="ai-label">{t(lang,'phone')}</label>
                                <input type="tel" className="ai-input" value={data.visitor_phone}
                                    onChange={e => setData('visitor_phone', e.target.value)} required />
                                {allErrors.visitor_phone && <div className="ai-error">{allErrors.visitor_phone}</div>}
                            </div>
                        </div>

                        <h4 className="ai-section-head mt-6">
                            {t(lang,'visitDetails')}
                        </h4>

                        {/* Category */}
                        <div className="form-group">
                            <label className="ai-label">Visit Category</label>
                            <select className="ai-input" value={data.visitor_category_id}
                                onChange={e => setData('visitor_category_id', e.target.value)}>
                                <option value="">— Select Category —</option>
                                {(categories || []).map(c => (
                                    <option key={c.id} value={c.id}>{c.name}</option>
                                ))}
                            </select>
                            {allErrors.visitor_category_id && <div className="ai-error">{allErrors.visitor_category_id}</div>}
                        </div>

                        {/* Company → Branch cascade — Department & Host are auto-assigned from purpose */}
                        {companies?.length > 0 && (
                            <div className="form-group">
                                <label className="ai-label">Company</label>
                                <select className="ai-input" value={selCompany}
                                    onChange={e => { setSelCompany(e.target.value); setSelBranch(''); setData('host_id', ''); setData('company_id', e.target.value); setData('branch_id', ''); setData('location_id', ''); }}>
                                    <option value="">— Select Company —</option>
                                    {companies.map(c => (
                                        <option key={c.company_id} value={c.company_id}>{c.company_name}</option>
                                    ))}
                                </select>
                            </div>
                        )}
                        {filteredBranches?.length > 0 && (
                            <div className="form-group">
                                <label className="ai-label">Branch</label>
                                <select className="ai-input" value={selBranch}
                                    onChange={e => { setSelBranch(e.target.value); setData('host_id', ''); setData('branch_id', e.target.value); const br = branches.find(b => String(b.id) === String(e.target.value)); setData('location_id', br?.location_id || ''); }}>
                                    <option value="">— Select Branch —</option>
                                    {filteredBranches.map(b => (
                                        <option key={b.id} value={b.id}>{b.name}</option>
                                    ))}
                                </select>
                            </div>
                        )}

                        {/* Step 4 — Date & Time */}
                        <div className="form-group">
                            <label className="ai-label">Step 4 — Date & Time</label>
                            <input type="datetime-local" className="ai-input" value={data.requested_time}
                                onChange={e => { setData('requested_time', e.target.value); setPastError(''); setHoursError(checkHours(e.target.value)); }}
                                min={new Date().toISOString().slice(0, 16)} required />
                            {workingHoursSummary && <div className="mt-1 text-[11px] text-slate-500">{t(lang,'workingHoursLabel')}: {workingHoursSummary}</div>}
                            {pastError && <div className="ai-error-box mt-2">{pastError}</div>}
                            {hoursError && <div className="ai-error-box mt-2">{hoursError}</div>}
                            {allErrors.requested_time && <div className="ai-error">{allErrors.requested_time}</div>}
                        </div>

                        {/* Step 5 — Duration */}
                        <div className="form-group">
                            <label className="ai-label">Duration</label>
                            <input type="datetime-local" className="ai-input" value={endTime}
                                min={data.requested_time || new Date().toISOString().slice(0, 16)}
                                onChange={e => {
                                    setEndTime(e.target.value);
                                    setDurationError('');
                                    if (data.requested_time && e.target.value) {
                                        const start = new Date(data.requested_time);
                                        const end = new Date(e.target.value);
                                        const diffMin = Math.round((end - start) / 60000);
                                        if (diffMin < 15) {
                                            setDurationError('Duration must be at least 15 minutes');
                                        } else if (diffMin > 480) {
                                            setDurationError('Duration cannot exceed 8 hours');
                                        } else {
                                            setData('duration', diffMin);
                                        }
                                    }
                                }}
                                required />
                            {durationError && <div className="ai-error">{durationError}</div>}
                        </div>

                        <div className="form-group">
                            <label className="ai-label">{t(lang,'purpose')}</label>
                            <select className="ai-input" value={data.purpose}
                                onChange={e => setData('purpose', e.target.value)} required>
                                <option value="">— {t(lang,'purposePlaceholder')} —</option>
                                {PURPOSE_OPTIONS.map(p => (
                                    <option key={p} value={p}>{p}</option>
                                ))}
                            </select>
                            {allErrors.purpose && <div className="ai-error">{allErrors.purpose}</div>}
                        </div>
                         <h4 className="ai-section-head mt-6">
                              {t(lang,'attachDocuments')}
                         </h4>

                        <div className="form-group" style={{ marginBottom: '12px' }}>
                            <label className="ai-label">{t(lang,'selectDocType')}</label>
                            <select className="ai-input" id="indDocTypeSelect">
                                <option value="">{t(lang,'chooseDocType')}</option>
                                <option value="invitation_letter">{t(lang,'invitationLetter')}</option>
                                <option value="government_letter">{t(lang,'governmentLetter')}</option>
                                <option value="purchase_order">{t(lang,'purchaseOrder')}</option>
                                <option value="nda">{t(lang,'nda')}</option>
                                <option value="other_docs">{t(lang,'otherDocuments')}</option>
                            </select>
                        </div>

                        <div className="form-group" style={{ marginBottom: '12px' }}>
                            <label className="ai-label">{t(lang,'uploadFile')}</label>
                            <input type="file" className="ai-input" id="indDocFileInput" style={{ fontSize: '12px', padding: '8px 12px' }}
                                accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" />
                        </div>

                        <button type="button" onClick={() => {
                            const sel = document.getElementById('indDocTypeSelect');
                            const fileInput = document.getElementById('indDocFileInput');
                            if (!sel.value || !fileInput.files[0]) return;
                            setData(sel.value, fileInput.files[0]);
                            sel.value = '';
                            fileInput.value = '';
                        }} className="ai-btn ai-btn-secondary mb-4 px-4 py-2 text-xs">
                            + {t(lang,'attachFile')}
                        </button>

                        {Object.entries(data).filter(([k]) => ['invitation_letter','government_letter','purchase_order','nda','other_docs'].includes(k) && data[k]).map(([k, file]) => (
                            <div key={k} className="mb-1.5 flex items-center justify-between rounded-md border border-emerald-500/15 bg-emerald-500/5 px-3 py-1.5">
                                <span className="text-xs text-emerald-400"> {file.name}</span>
                                <button type="button" onClick={() => setData(k, null)} className="cursor-pointer border-none bg-transparent text-xs font-bold text-red-400"></button>
                            </div>
                        ))}

                        <div className="mt-8 flex justify-end gap-4">
                            <Link href="/" className="ai-btn ai-btn-secondary">{t(lang,'cancel')}</Link>
                            <button type="submit" className="ai-btn ai-btn-accent" disabled={processing || !!pastError || !!hoursError}>
                                {processing ? t(lang,'checking') : t(lang,'submitRequest')}
                            </button>
                        </div>
                    </form>
                    </div>
                    )}

                    {/* ── STEP 2: Company / Group Visit Form ── */}
                    {visitType === 'company' && (
                        <div>
                            <div className="mb-5 flex items-center gap-3">
                                <button onClick={() => setVisitType(null)} className="flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-300 transition hover:bg-white/10">
                                    ← {t(lang,'back')}
                                </button>
                                <span className="text-xs text-slate-500">{t(lang,'step2of2')}</span>
                            </div>

                            <form onSubmit={handleSubmit}>
                                <h4 className="ai-section-head">
                                    {t(lang,'companyDetails')}
                                </h4>

                                <div className="ai-grid-2">
                                    <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                                        <label className="ai-label">{t(lang,'companyName')}</label>
                                        <input type="text" className="ai-input" value={data.company_name || ''}
                                            onChange={e => setData('company_name', e.target.value)}
                                            placeholder={lang === 'am' ? 'የኩምንያንቱን ስም ያስገቡ' : 'Enter company or organization name'} required />
                                        {allErrors.company_name && <div className="ai-error">{allErrors.company_name}</div>}
                                    </div>
                                </div>

                                <div className="ai-grid-2">
                                    <div className="form-group">
                                        <label className="ai-label">{t(lang,'companyEmail')}</label>
                                        <input type="email" className="ai-input" value={data.company_email || ''}
                                            onChange={e => setData('company_email', e.target.value)}
                                            placeholder={lang === 'am' ? 'የኩምንያንት ኢሜይል' : 'company@example.com'} required />
                                        {allErrors.company_email && <div className="ai-error">{allErrors.company_email}</div>}
                                    </div>
                                    <div className="form-group">
                                        <label className="ai-label">{t(lang,'companyPhone')}</label>
                                        <input type="tel" className="ai-input" value={data.company_phone || ''}
                                            onChange={e => setData('company_phone', e.target.value)}
                                            placeholder={lang === 'am' ? 'የኩምንያንት ስልክ' : '+251 911 234 567'} required />
                                        {allErrors.company_phone && <div className="ai-error">{allErrors.company_phone}</div>}
                                    </div>
                                </div>

                                <div className="form-group">
                                    <label className="ai-label">{t(lang,'companyAddress')}</label>
                                    <input type="text" className="ai-input" value={data.company_address || ''}
                                        onChange={e => setData('company_address', e.target.value)}
                                        placeholder={lang === 'am' ? 'የኩምንያንት አድራሻ' : 'Enter full company address'} required />
                                    {allErrors.company_address && <div className="ai-error">{allErrors.company_address}</div>}
                                </div>

                                <div className="ai-grid-2">
                                    <div className="form-group">
                                        <label className="ai-label">{t(lang,'companyWebsite')}</label>
                                        <input type="url" className="ai-input" value={data.company_website || ''}
                                            onChange={e => setData('company_website', e.target.value)}
                                            placeholder="https://example.com" />
                                    </div>
                                </div>

                                {/* ── Representative Information ── */}
                                <h4 className="ai-section-head mt-6">
                                    {t(lang,'representativeInfo')}
                                </h4>
                                <p className="mb-4 text-xs leading-relaxed text-slate-400">
                                    {t(lang,'representativeInfoDesc')}
                                </p>

                                <div className="ai-grid-2">
                                    <div className="form-group">
                                        <label className="ai-label">{t(lang,'fullName')}</label>
                                        <input type="text" className="ai-input" value={data.visitor_name}
                                            onChange={e => setData('visitor_name', e.target.value)} required />
                                        {allErrors.visitor_name && <div className="ai-error">{allErrors.visitor_name}</div>}
                                    </div>
                                    <div className="form-group">
                                        <label className="ai-label">{t(lang,'nationalId')}</label>
                                        <input type="text" className="ai-input" value={data.id_document_no}
                                            onChange={e => { const v = e.target.value.replace(/\D/g,'').slice(0,6); setData('id_document_no', v); }}
                                            placeholder="6-digit number" maxLength={6} pattern="\d{6}" inputMode="numeric" required />
                                        {allErrors.id_document_no && <div className="ai-error">{allErrors.id_document_no}</div>}
                                    </div>
                                </div>

                                <div className="ai-grid-2">
                                    <div className="form-group">
                                        <label className="ai-label">{t(lang,'emailAddress')}</label>
                                        <input type="email" className="ai-input" value={data.visitor_email}
                                            onChange={e => setData('visitor_email', e.target.value)} required />
                                        {allErrors.visitor_email && <div className="ai-error">{allErrors.visitor_email}</div>}
                                    </div>
                                    <div className="form-group">
                                        <label className="ai-label">{t(lang,'phone')}</label>
                                        <input type="tel" className="ai-input" value={data.visitor_phone}
                                            onChange={e => setData('visitor_phone', e.target.value)} required />
                                        {allErrors.visitor_phone && <div className="ai-error">{allErrors.visitor_phone}</div>}
                                    </div>
                                </div>

                                <div className="ai-grid-3">
                                    <div className="form-group">
                                        <label className="ai-label">{t(lang,'gender')}</label>
                                        <select className="ai-input" value={data.representative_gender || ''}
                                            onChange={e => setData('representative_gender', e.target.value)} required>
                                            <option value="">{t(lang,'selectGender')}</option>
                                            <option value="male">{t(lang,'male')}</option>
                                            <option value="female">{t(lang,'female')}</option>
                                        </select>
                                    </div>
                                    <div className="form-group">
                                        <label className="ai-label">{t(lang,'nationality')}</label>
                                        <input type="text" className="ai-input" value={data.representative_nationality || ''}
                                            onChange={e => setData('representative_nationality', e.target.value)}
                                            placeholder={lang === 'am' ? 'ሀገር' : 'e.g. Ethiopian'} required />
                                    </div>
                                    <div className="form-group">
                                        <label className="ai-label">{t(lang,'companyPosition')}</label>
                                        <input type="text" className="ai-input" value={data.representative_position || ''}
                                            onChange={e => setData('representative_position', e.target.value)}
                                            placeholder={lang === 'am' ? 'አማራጭ' : 'Optional'} />
                                    </div>
                                </div>

                                {/* ── Step 4: Visitor Information ── */}
                                <h4 className="ai-section-head mt-6">
                                    {t(lang,'visitorInformation')}
                                </h4>
                                <p className="mb-4 text-xs leading-relaxed text-slate-400">
                                    {t(lang,'visitorInformationDesc')}
                                </p>

                                {visitors.map((v, idx) => (
                                    <div key={idx} className="ai-visitor-box">
                                        <div className="mb-3 flex items-center justify-between">
                                            <span className="text-[13px] font-bold text-cyan-400">
                                                {t(lang,'visitor')} {idx + 1}
                                            </span>
                                            {visitors.length > 1 && (
                                                <button type="button" onClick={() => removeVisitor(idx)} className="cursor-pointer rounded-md border border-red-500/20 bg-red-500/10 px-2.5 py-1 text-[11px] font-semibold text-red-400 transition hover:bg-red-500/20">
                                                     {t(lang,'remove')}
                                                </button>
                                            )}
                                        </div>

                                        <div className="ai-grid-2">
                                            <div className="form-group">
                                                <label className="ai-label">{t(lang,'fullName')}</label>
                                                <input type="text" className="ai-input" value={v.name}
                                                    onChange={e => updateVisitor(idx, 'name', e.target.value)} required />
                                                {allErrors[`visitors.${idx}.name`] && <div className="ai-error">{allErrors[`visitors.${idx}.name`]}</div>}
                                            </div>
                                            <div className="form-group">
                                                <label className="ai-label">{t(lang,'nationalId')}</label>
                                                <input type="text" className="ai-input" value={v.id_document_no}
                                                    onChange={e => { const val = e.target.value.replace(/\D/g,'').slice(0,6); updateVisitor(idx, 'id_document_no', val); }}
                                                    placeholder="6-digit" maxLength={6} inputMode="numeric" required />
                                                {allErrors[`visitors.${idx}.id_document_no`] && <div className="ai-error">{allErrors[`visitors.${idx}.id_document_no`]}</div>}
                                            </div>
                                        </div>

                                        <div className="ai-grid-2">
                                            <div className="form-group">
                                                <label className="ai-label">{t(lang,'emailAddress')}</label>
                                                <input type="email" className="ai-input" value={v.email}
                                                    onChange={e => updateVisitor(idx, 'email', e.target.value)} required />
                                                {allErrors[`visitors.${idx}.email`] && <div className="ai-error">{allErrors[`visitors.${idx}.email`]}</div>}
                                            </div>
                                            <div className="form-group">
                                                <label className="ai-label">{t(lang,'phone')}</label>
                                                <input type="tel" className="ai-input" value={v.phone}
                                                    onChange={e => updateVisitor(idx, 'phone', e.target.value)} required />
                                                {allErrors[`visitors.${idx}.phone`] && <div className="ai-error">{allErrors[`visitors.${idx}.phone`]}</div>}
                                            </div>
                                        </div>

                                        <div className="ai-grid-3">
                                            <div className="form-group">
                                                <label className="ai-label">{t(lang,'gender')}</label>
                                                <select className="ai-input" value={v.gender}
                                                    onChange={e => updateVisitor(idx, 'gender', e.target.value)} required>
                                                    <option value="">{t(lang,'selectGender')}</option>
                                                    <option value="male">{t(lang,'male')}</option>
                                                    <option value="female">{t(lang,'female')}</option>
                                                </select>
                                            </div>
                                            <div className="form-group">
                                                <label className="ai-label">{t(lang,'nationality')}</label>
                                                <input type="text" className="ai-input" value={v.nationality}
                                                    onChange={e => updateVisitor(idx, 'nationality', e.target.value)}
                                                    placeholder={lang === 'am' ? 'ሀገር' : 'e.g. Ethiopian'} required />
                                            </div>
                                            <div className="form-group">
                                                <label className="ai-label">{t(lang,'companyPosition')}</label>
                                                <input type="text" className="ai-input" value={v.position}
                                                    onChange={e => updateVisitor(idx, 'position', e.target.value)}
                                                    placeholder={lang === 'am' ? 'አማራጭ' : 'Optional'} />
                                            </div>
                                        </div>
                                    </div>
                                ))}

                                <button type="button" onClick={addVisitor} className="mb-2 w-full rounded-xl border-2 border-dashed border-cyan-400/25 bg-cyan-500/5 py-2.5 text-[13px] font-semibold text-cyan-400 transition-all duration-300 hover:border-cyan-400/40 hover:bg-cyan-500/10"
                                    onMouseEnter={e => { e.currentTarget.style.background = 'rgba(6,182,212,0.08)'; e.currentTarget.style.borderColor = 'rgba(6,182,212,0.4)'; }}
                                    onMouseLeave={e => { e.currentTarget.style.background = ''; e.currentTarget.style.borderColor = ''; }}
                                    >
                                    + {t(lang,'addVisitor')}
                                </button>

                                {/* ── Step 5: Attach Documents ── */}
                                <h4 className="ai-section-head mt-6">
                                     {t(lang,'attachDocuments')}
                                </h4>

                                <div className="form-group" style={{ marginBottom: '12px' }}>
                                    <label className="ai-label">{t(lang,'selectDocType')}</label>
                                    <select className="ai-input" id="docTypeSelect">
                                        <option value="">{t(lang,'chooseDocType')}</option>
                                        <option value="invitation_letter">{t(lang,'invitationLetter')}</option>
                                        <option value="visitor_list">{t(lang,'visitorListFile')}</option>
                                        <option value="government_letter">{t(lang,'governmentLetter')}</option>
                                        <option value="purchase_order">{t(lang,'purchaseOrder')}</option>
                                        <option value="nda">{t(lang,'nda')}</option>
                                        <option value="other_docs">{t(lang,'otherDocuments')}</option>
                                    </select>
                                </div>

                                <div className="form-group" style={{ marginBottom: '12px' }}>
                                    <label className="ai-label">{t(lang,'uploadFile')}</label>
                                    <input type="file" className="ai-input" id="docFileInput" style={{ fontSize: '12px', padding: '8px 12px' }}
                                        accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" />
                                </div>

                                <button type="button" onClick={() => {
                                    const sel = document.getElementById('docTypeSelect');
                                    const fileInput = document.getElementById('docFileInput');
                                    if (!sel.value || !fileInput.files[0]) return;
                                    setData(sel.value, fileInput.files[0]);
                                    sel.value = '';
                                    fileInput.value = '';
                                }} className="ai-btn ai-btn-secondary mb-4 px-4 py-2 text-xs">
                                    + {t(lang,'attachFile')}
                                </button>

                                {Object.entries(data).filter(([k]) => ['invitation_letter','visitor_list','government_letter','purchase_order','nda','other_docs'].includes(k) && data[k]).map(([k, file]) => (
                                    <div key={k} className="mb-1.5 flex items-center justify-between rounded-md border border-emerald-500/15 bg-emerald-500/5 px-3 py-1.5">
                                        <span className="text-xs text-emerald-400"> {file.name}</span>
                                        <button type="button" onClick={() => setData(k, null)} className="cursor-pointer border-none bg-transparent text-xs font-bold text-red-400"></button>
                                    </div>
                                ))}

                                {/* Visit Details */}
                                <h4 className="ai-section-head mt-6">
                                    {t(lang,'visitDetails')}
                                </h4>

                                {/* Category */}
                                <div className="form-group">
                                    <label className="ai-label">Visit Category</label>
                                    <select className="ai-input" value={data.visitor_category_id}
                                        onChange={e => setData('visitor_category_id', e.target.value)}>
                                        <option value="">— Select Category —</option>
                                        {(categories || []).map(c => (
                                            <option key={c.id} value={c.id}>{c.name}</option>
                                        ))}
                                    </select>
                                    {allErrors.visitor_category_id && <div className="ai-error">{allErrors.visitor_category_id}</div>}
                                </div>

                                {/* Company → Branch cascade — Department & Host are auto-assigned from purpose */}
                                {companies?.length > 0 && (
                                    <div className="form-group">
                                        <label className="ai-label">Company</label>
                                        <select className="ai-input" value={selCompany}
                                            onChange={e => { setSelCompany(e.target.value); setSelBranch(''); setSelDepartment(''); setData('host_id', ''); setData('company_id', e.target.value); setData('branch_id', ''); setData('department_id', ''); setData('location_id', ''); }}>
                                            <option value="">— Select Company —</option>
                                            {companies.map(c => (
                                                <option key={c.company_id} value={c.company_id}>{c.company_name}</option>
                                            ))}
                                        </select>
                                    </div>
                                )}
                                {filteredBranches?.length > 0 && (
                                    <div className="form-group">
                                        <label className="ai-label">Branch</label>
                                        <select className="ai-input" value={selBranch}
                                            onChange={e => { setSelBranch(e.target.value); setSelDepartment(''); setData('host_id', ''); setData('branch_id', e.target.value); setData('department_id', ''); const br = branches.find(b => String(b.id) === String(e.target.value)); setData('location_id', br?.location_id || ''); }}>
                                            <option value="">— Select Branch —</option>
                                            {filteredBranches.map(b => (
                                                <option key={b.id} value={b.id}>{b.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                )}

                                {/* Step 4 — Date & Time */}
                                <div className="form-group">
                                    <label className="ai-label">Step 4 — Date & Time</label>
                                    <input type="datetime-local" className="ai-input" value={data.requested_time}
                                        onChange={e => { setData('requested_time', e.target.value); setPastError(''); setHoursError(checkHours(e.target.value)); }}
                                        min={new Date().toISOString().slice(0, 16)} required />
                                    {workingHoursSummary && <div className="mt-1 text-[11px] text-slate-500">{t(lang,'workingHoursLabel')}: {workingHoursSummary}</div>}
                                    {pastError && <div className="ai-error-box mt-2">{pastError}</div>}
                                    {hoursError && <div className="ai-error-box mt-2">{hoursError}</div>}
                                    {allErrors.requested_time && <div className="ai-error">{allErrors.requested_time}</div>}
                                </div>

                                {/* Step 5 — Duration */}
                                <div className="form-group">
                                    <label className="ai-label">Duration</label>
                                    <input type="datetime-local" className="ai-input" value={endTime}
                                        min={data.requested_time || new Date().toISOString().slice(0, 16)}
                                        onChange={e => {
                                            setEndTime(e.target.value);
                                            setDurationError('');
                                            if (data.requested_time && e.target.value) {
                                                const start = new Date(data.requested_time);
                                                const end = new Date(e.target.value);
                                                const diffMin = Math.round((end - start) / 60000);
                                                if (diffMin < 15) {
                                                    setDurationError('Duration must be at least 15 minutes');
                                                } else if (diffMin > 480) {
                                                    setDurationError('Duration cannot exceed 8 hours');
                                                } else {
                                                    setData('duration', diffMin);
                                                }
                                            }
                                        }}
                                        required />
                                    {durationError && <div className="ai-error">{durationError}</div>}
                                </div>

                                <div className="form-group">
                                    <label className="ai-label">{t(lang,'purpose')}</label>
                                    <select className="ai-input" value={data.purpose}
                                        onChange={e => setData('purpose', e.target.value)} required>
                                        <option value="">— {t(lang,'purposePlaceholder')} —</option>
                                        {PURPOSE_OPTIONS.map(p => (
                                            <option key={p} value={p}>{p}</option>
                                        ))}
                                    </select>
                                    {allErrors.purpose && <div className="ai-error">{allErrors.purpose}</div>}
                                </div>

                                <div className="mt-8 flex justify-end gap-4">
                                    <Link href="/" className="ai-btn ai-btn-secondary">{t(lang,'cancel')}</Link>
                                    <button type="submit" className="ai-btn ai-btn-accent" disabled={processing || !!pastError || !!hoursError}>
                                        {processing ? t(lang,'checking') : t(lang,'submitRequest')}
                                    </button>
                                </div>
                            </form>
                        </div>
                    )}

                </div>
            </section>
        </Layout>
    );
}

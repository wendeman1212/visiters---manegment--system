import React, { useState } from 'react';
import Layout from '../Shared/Layout';
import { useForm, Link } from '@inertiajs/react';
import { useLang } from '../../LangContext';
import { t } from '../../i18n';
import AppIcon from '../../config/iconMap';
import { FiEye, FiEyeOff } from 'react-icons/fi';
import { validators as v } from '../../utils/validation';

function PwdInput({ value, onChange, placeholder, required }) {
    const [show, setShow] = useState(false);
    return (
        <div className="relative">
            <input type={show ? 'text' : 'password'} className="ai-input"
                value={value} onChange={onChange} placeholder={placeholder} required={required}
                style={{ paddingRight: 40 }} />
            <button type="button" onClick={() => setShow(s => !s)}
                className="absolute right-3 top-1/2 flex -translate-y-1/2 cursor-pointer border-none bg-transparent p-1 leading-none text-slate-500"
                tabIndex={-1}>
                {show ? <FiEyeOff size={18} /> : <FiEye size={18} />}
            </button>
        </div>
    );
}

export default function RegisterRepresentative({ visitRequest, error }) {
    const { lang } = useLang();

    const { data, setData, post, processing, errors } = useForm({
        visit_request_id: visitRequest?.id || '',
        email: visitRequest?.visitor?.email || '',
        phone: visitRequest?.visitor?.phone || '',
        password: '',
        password_confirmation: '',
    });

    const [fieldErrors, setFieldErrors] = useState({});

    const handleSubmit = (e) => {
        e.preventDefault();
        const errs = {};
        if (!data.phone.trim()) errs.phone = 'Phone number is required.';
        else if (!v.isPhone(data.phone)) errs.phone = 'Please enter a valid phone number (e.g. +251911234567).';
        if (!data.password) errs.password = 'Password is required.';
        else if (String(data.password).length < 8) errs.password = 'Password must be at least 8 characters.';
        if (data.password !== data.password_confirmation) errs.password_confirmation = 'Passwords do not match.';
        setFieldErrors(errs);
        if (Object.keys(errs).length > 0) return;
        post('/register/representative');
    };

    const allErrors = { ...fieldErrors, ...errors };

    const formatDate = (dt) => dt ? new Date(dt).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '';
    const formatTime = (dt) => dt ? new Date(dt).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '';

    if (error || !visitRequest) {
        return (
            <Layout title="Representative Registration">
                <section className="ai-page flex justify-center px-6 pt-24 pb-16 sm:px-8 md:pt-28 md:pb-20 lg:px-16">
                    <div className="ai-card relative z-10 w-full max-w-lg text-center p-6 md:p-8">
                        <div className="mb-4 flex justify-center text-red-400">
                            <AppIcon name="warning" size={48} />
                        </div>
                        <h2 className="mb-2 text-xl font-extrabold text-red-400">
                            Registration Not Available
                        </h2>
                        <p className="mb-6 text-sm text-slate-400">
                            {error || 'This visit request is not found or not eligible for representative registration. Please contact your host for assistance.'}
                        </p>
                        <Link href="/login" className="ai-btn ai-btn-primary inline-block">
                            Go to Login
                        </Link>
                    </div>
                </section>
            </Layout>
        );
    }

    return (
        <Layout title="Company Representative Registration">
            <section className="ai-page flex justify-center px-6 pt-24 pb-16 sm:px-8 md:pt-28 md:pb-20 lg:px-16">
                <div className="ai-glow ai-glow-blue" style={{ width: '500px', height: '500px', top: '5%', left: '0' }} />

                <div className="relative z-10 w-full max-w-2xl">
                    {/* Header Card */}
                    <div className="ai-card mb-5 border-indigo-400/30 bg-gradient-to-br from-indigo-500/15 to-purple-500/10 p-6 md:p-8">
                        <div className="mb-4 flex items-center gap-3.5">
                            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-indigo-500 text-white shadow-[0_0_24px_-6px_rgba(99,102,241,0.7)]">
                                <AppIcon name="group" size={24} />
                            </div>
                            <div>
                                <h2 className="text-xl font-extrabold text-white">
                                    Company Representative Registration
                                </h2>
                                <p className="text-[13px] text-slate-400">
                                    Complete your registration to access your visit pass and group member QR codes
                                </p>
                            </div>
                        </div>
                        <div className="rounded-lg border border-emerald-500/25 bg-emerald-500/10 px-3.5 py-2.5 text-xs font-semibold text-emerald-400">
                            Your company/group visit request has been approved. Register now to access your QR pass and manage your group.
                        </div>
                    </div>

                    {/* Visit Details Card */}
                    <div className="ai-card mb-5 p-6 md:p-8">
                        <h3 className="mb-3.5 flex items-center gap-2 text-base font-bold text-white">
                            <AppIcon name="calendar" size={16} color="#38bdf8" /> Appointment Details
                        </h3>
                        <div className="grid grid-cols-2 gap-3">
                            {[
                                ['Company', visitRequest.company_name],
                                ['Host', visitRequest.host?.name],
                                ['Department', visitRequest.department?.name || visitRequest.host?.department?.name],
                                ['Purpose', visitRequest.purpose],
                                ['Date', formatDate(visitRequest.requested_time)],
                                ['Time', formatTime(visitRequest.requested_time)],
                                ['Duration', visitRequest.duration ? `${visitRequest.duration} minutes` : '—'],
                                ['Building', visitRequest.building || '—'],
                            ].map(([label, value]) => (
                                <div key={label} className="rounded-lg border border-white/5 bg-white/5 p-2.5">
                                    <div className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500">{label}</div>
                                    <div className="text-[13px] font-bold text-white">{value || '—'}</div>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Group Members Preview */}
                    {visitRequest.groupMembers && visitRequest.groupMembers.length > 0 && (
                        <div className="ai-card mb-5 p-6 md:p-8">
                            <h3 className="mb-3.5 flex items-center gap-2 text-base font-bold text-white">
                                <AppIcon name="group" size={16} color="#38bdf8" /> Group Members ({visitRequest.groupMembers.length})
                            </h3>
                            <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                                {visitRequest.groupMembers.map((m, idx) => (
                                    <div key={m.id || idx} className="rounded-lg border border-emerald-500/15 bg-emerald-500/5 p-2.5">
                                        <div className="mb-0.5 text-[13px] font-bold text-white">{m.name}</div>
                                        <div className="text-[11px] text-slate-500">{m.position || 'Group Member'} {m.id_document_no ? `· ID: ${m.id_document_no}` : ''}</div>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-3 rounded-md border border-amber-500/20 bg-amber-500/10 px-3 py-2 text-[11px] text-amber-400">
                                After registration, QR codes for all group members will be available in your dashboard.
                            </div>
                        </div>
                    )}

                    {/* Registration Form */}
                    <div className="ai-card p-6">
                        <h3 className="mb-1 text-base font-bold text-white">
                            Create Your Account
                        </h3>
                        <p className="mb-5 text-[13px] text-slate-400">
                            Set a password to access your visitor dashboard. Your information has been pre-filled from the visit request.
                        </p>

                        <form onSubmit={handleSubmit}>
                            <div className="form-group">
                                <label className="ai-label">Email Address</label>
                                <input
                                    type="email"
                                    className="ai-input"
                                    value={data.email}
                                    readOnly
                                />
                                <div className="ai-hint">
                                    This must match the email used in the visit request.
                                </div>
                                {errors.email && <div className="ai-error">{errors.email}</div>}
                            </div>

                            <div className="form-group">
                                <label className="ai-label">Phone Number *</label>
                                <input
                                    type="tel"
                                    className="ai-input"
                                    value={data.phone}
                                    placeholder="+251911234567"
                                    onChange={e => setData('phone', e.target.value)}
                                    required
                                />
                                <div className="ai-hint">
                                    Enter a valid international phone number for SMS notifications and OTP verification.
                                </div>
                                {allErrors.phone && <div className="ai-error">{allErrors.phone}</div>}
                            </div>

                            <div className="ai-grid-2">
                                <div className="form-group">
                                    <label className="ai-label">Password</label>
                                    <PwdInput
                                        value={data.password}
                                        onChange={e => setData('password', e.target.value)}
                                        placeholder="Min 8 characters"
                                        required
                                    />
                                    {allErrors.password && <div className="ai-error">{allErrors.password}</div>}
                                </div>
                                <div className="form-group">
                                    <label className="ai-label">Confirm Password</label>
                                    <PwdInput
                                        value={data.password_confirmation}
                                        onChange={e => setData('password_confirmation', e.target.value)}
                                        placeholder="Repeat password"
                                        required
                                    />
                                    {allErrors.password_confirmation && <div className="ai-error">{allErrors.password_confirmation}</div>}
                                </div>
                            </div>

                            <button type="submit" className="ai-btn ai-btn-accent mt-6 w-full py-3.5 text-sm font-bold" disabled={processing}>
                                {processing ? 'Registering...' : 'Complete Registration & Access Dashboard'}
                            </button>
                        </form>

                        <div className="mt-5 text-center text-[13px] text-slate-400">
                            Already have an account? <Link href="/login" className="ai-link">Log in here</Link>
                        </div>
                    </div>
                </div>
            </section>
        </Layout>
    );
}

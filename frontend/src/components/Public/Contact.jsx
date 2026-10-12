import React, { useState, useEffect } from 'react';
import Layout from '../Shared/Layout';
import AppIcon from '../../config/iconMap';
import FloatingField from '../Shared/FloatingField';
import { useForm, usePage } from '@inertiajs/react';
import Reveal from '../Shared/Reveal';
import { validators as v } from '../../utils/validation';

export default function Contact({ company_setting }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        phone: '',
        message: '',
    });
    const { flash } = usePage().props;
    const [submitted, setSubmitted] = useState(false);

    const c = company_setting || {};

    useEffect(() => {
        if (flash.success) {
            setSubmitted(true);
            reset();
        }
    }, [flash.success]);

    const [fieldErrors, setFieldErrors] = useState({});

    const handleSubmit = (e) => {
        e.preventDefault();
        const errs = {};
        if (!data.name.trim()) errs.name = 'Name is required.';
        else if (!v.isName(data.name)) errs.name = 'Name may only contain letters, spaces, hyphens and apostrophes.';
        else if (!v.maxLen(data.name, 255)) errs.name = 'Name must not exceed 255 characters.';
        if (!data.email.trim()) errs.email = 'Email is required.';
        else if (!v.isEmail(data.email)) errs.email = 'Please enter a valid email address.';
        if (data.phone && !v.isPhone(data.phone)) errs.phone = 'Please enter a valid phone number.';
        if (!data.message.trim()) errs.message = 'Message is required.';
        else if (!v.maxLen(data.message, 5000)) errs.message = 'Message must not exceed 5000 characters.';
        setFieldErrors(errs);
        if (Object.keys(errs).length > 0) return;
        post('/contact', { preserveScroll: true });
    };

    const allErrors = { ...fieldErrors, ...errors };

    return (
        <Layout title="Contact Us">
            <section className="ai-page">
                <div className="ai-glow ai-glow-blue" style={{ width: '480px', height: '480px', top: '-120px', right: '-100px' }} />
                <div className="ai-glow ai-glow-orange" style={{ width: '420px', height: '420px', bottom: '-140px', left: '-90px' }} />

                <div className="relative z-10 grid w-full max-w-7xl grid-cols-1 items-center gap-6 lg:grid-cols-2 md:gap-8">
                    <Reveal direction="left" className="order-2 h-full lg:order-1">
                        <div className="flex h-full flex-col justify-center">
                            <h2 className="ai-title" style={{ color: '#38bdf8' }}>Get In Touch</h2>
                            <p className="ai-sub">
                                Have questions about company? Submit a support inquiry and our administration team will respond.
                            </p>

                            <div className="flex flex-col gap-5">
                                {c.company_name && (
                                    <div>
                                        <div className="flex items-center gap-2 font-bold text-white"><AppIcon name="building" color="#38bdf8" /> {c.company_name}</div>
                                        {c.branch_name && <p className="text-[13px] text-slate-500">Branch: {c.branch_name}</p>}
                                    </div>
                                )}
                                {(c.address) && (
                                    <div>
                                        <div className="flex items-center gap-2 font-bold text-white"><AppIcon name="mappin" color="#38bdf8" /> Address</div>
                                        <p className="text-sm text-slate-400">{c.address}</p>
                                    </div>
                                )}
                                {c.email && (
                                    <div>
                                        <div className="flex items-center gap-2 font-bold text-white"><AppIcon name="mail" color="#38bdf8" /> Email</div>
                                        <p className="text-sm text-slate-400">{c.email}</p>
                                    </div>
                                )}
                                {c.phone && (
                                    <div>
                                        <div className="flex items-center gap-2 font-bold text-white"><AppIcon name="smartphone" color="#38bdf8" /> Phone</div>
                                        <p className="text-sm text-slate-400">{c.phone}</p>
                                    </div>
                                )}
                                {c.website && (
                                    <div>
                                        <div className="flex items-center gap-2 font-bold text-white"><AppIcon name="globe" color="#38bdf8" /> Website</div>
                                        <p className="text-sm text-slate-400">{c.website}</p>
                                    </div>
                                )}

                                {!c.company_name && !c.address && !c.email && !c.phone && (
                                    <div>
                                        <p className="text-sm text-slate-400">Contact the system administrator for company details.</p>
                                    </div>
                                )}
                            </div>
                        </div>
                    </Reveal>

                    <Reveal direction="right" delay={120} className="order-1 h-full lg:order-2">
                        <div className="ai-card h-full">
                        {submitted ? (
                            <div className="text-center">
                                <div className="flex justify-center"><AppIcon name="check" size={48} color="#34d399" /></div>
                                <h3 className="text-xl font-bold text-white">Message Transmitted</h3>
                                <p className="text-sm text-slate-400">
                                    Your message has been sent to the company email. Thank you!
                                </p>
                                <button className="ai-btn ai-btn-secondary" onClick={() => setSubmitted(false)}>
                                    Send Another Message
                                </button>
                            </div>
                        ) : (
                            <form onSubmit={handleSubmit}>
                                <h3 className="text-xl font-bold" style={{ color: '#ff9933' }}>Send Support Ticket</h3>
                                {flash.error && (
                                    <div className="ai-error-box">{flash.error}</div>
                                )}
                                <FloatingField label="Name" value={data.name}>
                                    <input
                                        type="text"
                                        value={data.name}
                                        onChange={e => setData('name', e.target.value)}
                                        required
                                    />
                                </FloatingField>
                                {allErrors.name && <div className="ai-error">{allErrors.name}</div>}
                                <FloatingField label="Email" value={data.email}>
                                    <input
                                        type="email"
                                        value={data.email}
                                        onChange={e => setData('email', e.target.value)}
                                        required
                                    />
                                </FloatingField>
                                {allErrors.email && <div className="ai-error">{allErrors.email}</div>}
                                <FloatingField label="Phone (optional)" value={data.phone}>
                                    <input
                                        type="tel"
                                        value={data.phone}
                                        onChange={e => setData('phone', e.target.value)}
                                    />
                                </FloatingField>
                                {allErrors.phone && <div className="ai-error">{allErrors.phone}</div>}
                                <FloatingField label="Message" value={data.message}>
                                    <textarea
                                        rows="4"
                                        value={data.message}
                                        onChange={e => setData('message', e.target.value)}
                                        required
                                    ></textarea>
                                </FloatingField>
                                {allErrors.message && <div className="ai-error">{allErrors.message}</div>}
                                <button type="submit" className="ai-btn ai-btn-primary w-full" disabled={processing}>
                                    {processing ? 'Sending...' : 'Submit Inquiry'}
                                </button>
                            </form>
                        )}
                        </div>
                    </Reveal>
                </div>
            </section>
        </Layout>
    );
}

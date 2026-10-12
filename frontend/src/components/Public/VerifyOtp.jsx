import React, { useState } from 'react';
import Layout from '../Shared/Layout';
import { useForm, Link, usePage } from '@inertiajs/react';
import { FiEye, FiEyeOff } from 'react-icons/fi';
import { useLang } from '../../LangContext';
import { t } from '../../i18n';
import { validators as v } from '../../utils/validation';

function PasswordInput({ value, onChange, placeholder, required, className }) {
    const [show, setShow] = useState(false);
    return (
        <div style={{ position: 'relative' }}>
            <input
                type={show ? 'text' : 'password'}
                className={className || 'ai-input'}
                value={value}
                onChange={onChange}
                placeholder={placeholder}
                required={required}
                style={{ paddingRight: 40 }}
            />
            <button
                type="button"
                onClick={() => setShow(s => !s)}
                style={{
                    position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)',
                    background: 'none', border: 'none', cursor: 'pointer',
                    color: 'var(--text-muted)', padding: 4, lineHeight: 1,
                }}
                tabIndex={-1}
            >
                {show ? <FiEyeOff size={18} /> : <FiEye size={18} />}
            </button>
        </div>
    );
}

export default function VerifyOtp({ contact, otp }) {
    const { lang } = useLang();
    const { flash } = usePage().props;

    const { data, setData, post, processing, errors } = useForm({
        contact: contact || '',
        otp: '',
        password: '',
        password_confirmation: '',
    });

    const resend = useForm({ contact: contact || '' });

    const steps = [
        t(lang, 'checkEmailSms'),
        t(lang, 'enterOtpStep'),
        t(lang, 'newPassword'),
        t(lang, 'confirmNewPassword'),
        t(lang, 'resetPassword'),
    ];

    const [fieldErrors, setFieldErrors] = useState({});

    const handleSubmit = (e) => {
        e.preventDefault();
        const errs = {};
        if (!data.otp) errs.otp = 'Verification code is required.';
        else if (!v.isOtp(data.otp)) errs.otp = 'Enter the numeric verification code.';
        if (!data.password) errs.password = t(lang, 'password') + ' ' + t(lang, 'required') + '.';
        else if (String(data.password).length < 8) errs.password = 'Password must be at least 8 characters.';
        else if (!v.isStrongPassword(data.password)) errs.password = t(lang, 'passwordHint') || 'Password must contain uppercase, lowercase, number, and special character.';
        if (data.password !== data.password_confirmation) errs.password_confirmation = t(lang, 'passwordsDoNotMatch') || 'Passwords do not match.';
        setFieldErrors(errs);
        if (Object.keys(errs).length > 0) return;
        post('/forgot-password/verify');
    };

    const allErrors = { ...fieldErrors, ...errors };

    const handleResend = (e) => {
        e.preventDefault();
        resend.post('/forgot-password/resend');
    };

    return (
        <Layout title={t(lang, 'verifyOtpTitle')}>
            <section className="ai-page flex justify-center px-6 pt-24 pb-16 sm:px-8 md:pt-28 md:pb-20 lg:px-16">
                <div className="ai-glow ai-glow-blue" style={{ width: '520px', height: '520px', top: '10%', left: '50%', transform: 'translateX(-50%)' }} />

                <div className="ai-card relative z-10 w-full max-w-md p-6 md:p-8">
                    <div className="mb-6 text-center">
                        <span className="ai-pill ai-pill-blue">Verify Code</span>
                        <h2 className="mt-5 text-2xl font-extrabold text-white">
                            {t(lang, 'verifyOtpTitle')}
                        </h2>
                        <p className="mt-2 text-[13px] text-slate-400">
                            {t(lang, 'codeSentTo')} <strong className="text-slate-200">{contact}</strong>. {t(lang, 'enterCodeHint')}
                        </p>
                    </div>

                    <div className="mb-6 space-y-2">
                        {steps.map((step, i) => (
                            <div key={step} className="flex items-center gap-2.5">
                                <span
                                    className={`flex h-[22px] w-[22px] flex-shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white ${
                                        i === 0 ? 'bg-gradient-to-br from-sky-400 to-blue-600' : 'bg-slate-600/40'
                                    }`}
                                >
                                    {i + 1}
                                </span>
                                <span className={i === 0 ? 'text-[13px] font-semibold text-white' : 'text-[13px] font-medium text-slate-400'}>
                                    {step}
                                </span>
                            </div>
                        ))}
                    </div>

                    {(flash?.success) && (
                        <div className="ai-success-box">{flash.success}</div>
                    )}

                    {otp && (
                        <div className="ai-note-box">
                            <strong>DEV</strong> — {t(lang, 'verificationCode')}: <strong style={{ letterSpacing: 2 }}>{otp}</strong>
                        </div>
                    )}

                    <form onSubmit={handleSubmit}>
                        <input type="hidden" value={data.contact} />
                        <div className="form-group">
                            <input
                                type="text"
                                inputMode="numeric"
                                maxLength={6}
                                className="ai-input"
                                value={data.otp}
                                onChange={e => setData('otp', e.target.value.replace(/\D/g, ''))}
                                placeholder={lang === 'am' ? '6 አሃዝ ኮድ' : 'Enter the 6-digit code'}
                                required
                                autoFocus
                                style={{ textAlign: 'center', fontSize: '20px', letterSpacing: '8px' }}
                            />
                            {allErrors.otp && (
                                <div className="ai-error">{allErrors.otp}</div>
                            )}
                        </div>

                        <div className="form-group">
                            <label className="ai-label">{t(lang, 'newPassword')}</label>
                            <PasswordInput
                                value={data.password}
                                onChange={e => setData('password', e.target.value)}
                                placeholder={lang === 'am' ? 'ቢያንስ 8 ቁምፊዎች' : 'Min 8 characters'}
                                required
                            />
                            {allErrors.password && (
                                <div className="ai-error">{allErrors.password}</div>
                            )}
                        </div>

                        <div className="form-group">
                            <label className="ai-label">{t(lang, 'confirmNewPassword')}</label>
                            <PasswordInput
                                value={data.password_confirmation}
                                onChange={e => setData('password_confirmation', e.target.value)}
                                placeholder={lang === 'am' ? 'የይለፍ ቃል ድገም' : 'Repeat password'}
                                required
                            />
                            {allErrors.password_confirmation && (
                                <div className="ai-error">{allErrors.password_confirmation}</div>
                            )}
                        </div>

                        <button
                            type="submit"
                            className="ai-btn ai-btn-primary mt-6 w-full py-3"
                            disabled={processing}
                        >
                            {processing ? t(lang, 'resetting') : t(lang, 'resetPassword')}
                        </button>
                    </form>

                    <div className="mt-4 text-center">
                        <button
                            type="button"
                            className="ai-btn ai-btn-secondary w-full py-2.5"
                            onClick={handleResend}
                            disabled={resend.processing}
                        >
                            {resend.processing ? t(lang, 'resending') : t(lang, 'resendCode')}
                        </button>
                    </div>

                    <div className="mt-5 text-center">
                        <Link href="/login" className="ai-link text-[13px]">{t(lang, 'backToLogin')}</Link>
                    </div>
                </div>
            </section>
        </Layout>
    );
}

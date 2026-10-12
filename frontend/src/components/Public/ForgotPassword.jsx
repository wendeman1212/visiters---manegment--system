import React, { useEffect, useState } from 'react';
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

export default function ForgotPassword() {
    const { lang } = useLang();
    const { flash } = usePage().props;

    const [stage, setStage] = useState('contact');
    const [contact, setContact] = useState('');
    const [sending, setSending] = useState(false);
    const [sendError, setSendError] = useState('');
    const [sentNote, setSentNote] = useState('');

    const { data, setData, post, processing, errors } = useForm({
        contact: '',
        otp: '',
        password: '',
        password_confirmation: '',
    });

    const resend = useForm({ contact: '' });

    useEffect(() => {
        const saved = sessionStorage.getItem('vms_pw_reset_contact');
        const hasVerifyErrors = errors.otp || errors.password || errors.password_confirmation || errors.contact;
        if (hasVerifyErrors || flash?.error || flash?.success) {
            if (saved) {
                setData('contact', saved);
                resend.setData('contact', saved);
            }
            setStage('verify');
        }
    }, []);

    const proceedToVerify = (value, warn) => {
        sessionStorage.setItem('vms_pw_reset_contact', value);
        setData('contact', value);
        resend.setData('contact', value);
        if (warn) {
            setSentNote('We\'re sending your verification code. If you don\'t receive it within a minute, tap "Resend Code".');
        }
        setStage('verify');
    };

    const handleSendOtp = async (e) => {
        e.preventDefault();
        const value = contact.trim();
        if (!value) {
            setSendError('Please enter your email or phone number.');
            return;
        }
        const looksEmail = value.includes('@');
        if (looksEmail ? !v.isEmail(value) : !v.isPhone(value)) {
            setSendError('Please enter a valid email address or phone number.');
            return;
        }
        setSending(true);
        setSendError('');
        setSentNote('');
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 30000);
        try {
            const xsrfMatch = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
            const xsrf = xsrfMatch ? decodeURIComponent(xsrfMatch[1]) : '';
            const res = await fetch('/forgot-password', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'text/html',
                    ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
                },
                body: new URLSearchParams({ contact: value }),
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const finalPath = new URL(res.url).pathname;
            if (res.ok && finalPath === '/forgot-password/verify') {
                proceedToVerify(value, false);
            } else {
                setSendError(res.status === 419
                    ? 'Your session expired. Please refresh the page and try again.'
                    : 'Failed to send the verification code. Please try again.');
            }
        } catch {
            proceedToVerify(value, true);
        } finally {
            clearTimeout(timer);
            setSending(false);
        }
    };

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
        post('/forgot-password/verify', {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const allErrors = { ...fieldErrors, ...errors };

    const handleResend = (e) => {
        e.preventDefault();
        resend.post('/forgot-password/resend', {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <Layout title={t(lang, 'forgotPassword')}>
            <section className="ai-page flex justify-center px-6 pt-24 pb-16 sm:px-8 md:pt-28 md:pb-20 lg:px-16">
                <div className="ai-glow ai-glow-blue" style={{ width: '520px', height: '520px', top: '10%', left: '50%', transform: 'translateX(-50%)' }} />

                <div className="ai-card relative z-10 w-full max-w-md p-6 md:p-8">
                    {stage === 'contact' ? (
                        <>
                            <div className="mb-6 text-center">
                                <span className="ai-pill">Account Recovery</span>
                                <h2 className="mt-5 text-2xl font-extrabold text-white">
                                    {t(lang, 'forgotPassword')}
                                </h2>
                                <p className="mt-2 text-[13px] text-slate-400">
                                    {t(lang, 'enterEmailForReset')}
                                </p>
                            </div>

                            {flash?.success && (
                                <div className="ai-success-box">{flash.success}</div>
                            )}
                            {flash?.error && (
                                <div className="ai-error-box">{flash.error}</div>
                            )}

                            <form onSubmit={handleSendOtp}>
                                <div className="form-group">
                                    <label className="ai-label">{t(lang, 'emailOrPhone')}</label>
                                    <input
                                        type="text"
                                        className="ai-input"
                                        value={contact}
                                        onChange={e => setContact(e.target.value)}
                                        placeholder={lang === 'am' ? 'ኢሜይል ወይም ስልክ ቁጥር' : 'Email or phone number'}
                                        required
                                        autoFocus
                                    />
                                    {sendError && (
                                        <div className="ai-error">{sendError}</div>
                                    )}
                                </div>

                                <button
                                    type="submit"
                                    className="ai-btn ai-btn-primary mt-6 w-full py-3"
                                    disabled={sending}
                                >
                                    {sending ? t(lang, 'sending') : t(lang, 'sendOtp')}
                                </button>
                            </form>

                            <div className="mt-6 text-center">
                                <Link href="/login" className="ai-link text-[13px]">{t(lang, 'backToLogin')}</Link>
                            </div>
                        </>
                    ) : (
                        <>
                            <div className="mb-6 text-center">
                                <span className="ai-pill ai-pill-blue">Verify Code</span>
                                <h2 className="mt-5 text-2xl font-extrabold text-white">
                                    {t(lang, 'verifyOtpTitle')}
                                </h2>
                                <p className="mt-2 text-[13px] text-slate-400">
                                    {t(lang, 'codeSentTo')} <strong className="text-slate-200">{data.contact || contact}</strong>. {t(lang, 'enterCodeHint')}
                                </p>
                            </div>

                            {flash?.success && (
                                <div className="ai-success-box">{flash.success}</div>
                            )}
                            {flash?.error && (
                                <div className="ai-error-box">{flash.error}</div>
                            )}
                            {sentNote && (
                                <div className="ai-note-box">{sentNote}</div>
                            )}

                            <form onSubmit={handleSubmit}>
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
                        </>
                    )}
                </div>
            </section>
        </Layout>
    );
}

import React, { useEffect, useState } from 'react';
import Layout from '../Shared/Layout';
import { useForm, usePage } from '@inertiajs/react';
import { useLang } from '../../LangContext';
import { t } from '../../i18n';
import { validators as v } from '../../utils/validation';

export default function VerifyEmail({ email = '', cooldown_remaining = 0 }) {
    const { lang } = useLang();
    const { flash } = usePage().props;

    const [emailValue, setEmailValue] = useState(email || '');
    const [cooldown, setCooldown] = useState(cooldown_remaining || 0);

    const verify = useForm({ email: emailValue, code: '' });
    const resend = useForm({ email: emailValue });

    useEffect(() => {
        setCooldown(cooldown_remaining || 0);
    }, [cooldown_remaining]);

    useEffect(() => {
        if (cooldown <= 0) return;
        const timer = setTimeout(() => setCooldown(c => c - 1), 1000);
        return () => clearTimeout(timer);
    }, [cooldown]);

    const handleEmailChange = value => {
        setEmailValue(value);
        verify.setData('email', value);
        resend.setData('email', value);
    };

    const [fieldErrors, setFieldErrors] = useState({});

    const handleVerify = e => {
        e.preventDefault();
        const errs = {};
        if (!emailValue.trim()) errs.email = t(lang, 'emailAddress') + ' ' + t(lang, 'required') + '.';
        else if (!v.isEmail(emailValue)) errs.email = t(lang, 'invalidEmail') || 'Please enter a valid email address.';
        if (!verify.data.code) errs.code = 'Verification code is required.';
        else if (!v.isOtp(verify.data.code)) errs.code = 'Enter the numeric verification code.';
        setFieldErrors(errs);
        if (Object.keys(errs).length > 0) return;
        verify.post('/register/verify-code');
    };

    const allErrors = { ...fieldErrors, ...verify.errors };

    const handleResend = e => {
        e.preventDefault();
        resend.setData('email', emailValue);
        resend.post('/register/resend-code');
    };

    return (
        <Layout title={t(lang, 'verifyEmailTitle')}>
            <section className="ai-page flex justify-center px-6 pt-24 pb-16 sm:px-8 md:pt-28 md:pb-20 lg:px-16">
                <div
                    className="ai-glow ai-glow-blue"
                    style={{ width: '520px', height: '520px', top: '10%', left: '50%', transform: 'translateX(-50%)' }}
                />

                <div className="ai-card relative z-10 w-full max-w-md p-6 md:p-8">
                    <div className="mb-6 text-center">
                        <span className="ai-pill">Email Verification</span>
                        <h2 className="mt-5 text-2xl font-extrabold text-white">
                            {t(lang, 'verifyEmailTitle')}
                        </h2>
                        <p className="mt-2 text-[13px] text-slate-400">
                            {t(lang, 'verifyEmailHint')}
                        </p>
                    </div>

                    {flash?.success && <div className="ai-success-box mb-4">{flash.success}</div>}
                    {flash?.error && <div className="ai-error-box mb-4">{flash.error}</div>}

                    <form onSubmit={handleVerify} className="space-y-4">
                        <div className="form-group">
                            <label className="ai-label">{t(lang, 'emailAddress')}</label>
                            <input
                                type="email"
                                className="ai-input"
                                value={emailValue}
                                onChange={e => { handleEmailChange(e.target.value); setFieldErrors(fe => ({ ...fe, email: '' })); }}
                                placeholder="you@example.com"
                                autoComplete="off"
                                required
                            />
                            {allErrors.email && <div className="ai-error">{allErrors.email}</div>}
                            {resend.errors.email && <div className="ai-error">{resend.errors.email}</div>}
                        </div>

                        <div className="form-group">
                            <label className="ai-label">{t(lang, 'verificationCode')}</label>
                            <input
                                type="text"
                                inputMode="numeric"
                                maxLength={6}
                                className="ai-input"
                                value={verify.data.code}
                                onChange={e => { verify.setData('code', e.target.value.replace(/\D/g, '')); setFieldErrors(fe => ({ ...fe, code: '' })); }}
                                placeholder={lang === 'am' ? '6 አሃዝ ኮድ' : '6-digit code'}
                                autoFocus
                                autoComplete="one-time-code"
                                style={{ textAlign: 'center', fontSize: '20px', letterSpacing: '8px' }}
                            />
                            {allErrors.code && <div className="ai-error">{allErrors.code}</div>}
                        </div>

                        <button
                            type="submit"
                            className="ai-btn ai-btn-primary w-full"
                            disabled={verify.processing || verify.data.code.length !== 6}
                        >
                            {verify.processing ? t(lang, 'verifying') : t(lang, 'verifyEmailBtn')}
                        </button>

                        <div className="text-center pt-1">
                            <button
                                type="button"
                                className="ai-link text-sm"
                                onClick={handleResend}
                                disabled={resend.processing || cooldown > 0}
                            >
                                {resend.processing
                                    ? t(lang, 'resending')
                                    : cooldown > 0
                                        ? t(lang, 'resendCooldown').replace('{s}', cooldown)
                                        : t(lang, 'resendCode')}
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </Layout>
    );
}

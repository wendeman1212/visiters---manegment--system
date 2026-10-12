import React, { useState } from 'react';
import Layout from '../Shared/Layout';
import { useForm, Link } from '@inertiajs/react';
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

export default function ResetPassword({ token, email }) {
    const { lang } = useLang();

    const { data, setData, post, processing, errors } = useForm({
        email: email || '',
        token: token || '',
        password: '',
        password_confirmation: '',
    });

    const [fieldErrors, setFieldErrors] = useState({});

    const handleSubmit = (e) => {
        e.preventDefault();
        const errs = {};
        if (!data.password) errs.password = t(lang, 'password') + ' ' + t(lang, 'required') + '.';
        else if (String(data.password).length < 8) errs.password = 'Password must be at least 8 characters.';
        else if (!v.isStrongPassword(data.password)) errs.password = t(lang, 'passwordHint') || 'Password must contain uppercase, lowercase, number, and special character.';
        if (data.password !== data.password_confirmation) errs.password_confirmation = t(lang, 'passwordsDoNotMatch') || 'Passwords do not match.';
        setFieldErrors(errs);
        if (Object.keys(errs).length > 0) return;
        post('/reset-password');
    };

    const allErrors = { ...fieldErrors, ...errors };

    return (
        <Layout title={t(lang, 'resetPassword')}>
            <section className="ai-page flex justify-center px-6 pt-24 pb-16 sm:px-8 md:pt-28 md:pb-20 lg:px-16">
                <div className="ai-glow ai-glow-blue" style={{ width: '520px', height: '520px', top: '10%', left: '50%', transform: 'translateX(-50%)' }} />

                <div className="ai-card relative z-10 w-full max-w-md p-6 md:p-8">
                    <div className="mb-6 text-center">
                        <span className="ai-pill">Secure Reset</span>
                        <h2 className="mt-5 text-2xl font-extrabold text-white">
                            {t(lang, 'resetPassword')}
                        </h2>
                        <p className="mt-2 text-[13px] text-slate-400">
                            {t(lang, 'enterNewPassword')}
                        </p>
                    </div>

                    <form onSubmit={handleSubmit}>
                        <input type="hidden" value={data.token} />
                        <div className="form-group">
                            <label className="ai-label">{t(lang, 'emailAddress')}</label>
                            <input
                                type="email"
                                className="ai-input"
                                value={data.email}
                                readOnly
                                style={{ opacity: 0.6, cursor: 'not-allowed', background: 'rgba(255,255,255,0.03)' }}
                            />
                            {errors.email && (
                                <div className="ai-error">{errors.email}</div>
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

                    <div className="mt-6 text-center">
                        <Link href="/login" className="ai-link text-[13px]">{t(lang, 'backToLogin')}</Link>
                    </div>
                </div>
            </section>
        </Layout>
    );
}

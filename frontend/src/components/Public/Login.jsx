import React, { useState } from 'react';
import Layout from '../Shared/Layout';
import PasswordInput from '../Shared/PasswordInput';
import FloatingField from '../Shared/FloatingField';
import { useForm, Link, usePage } from '@inertiajs/react';
import { useLang } from '../../LangContext';
import { t } from '../../i18n';
import { validators as v } from '../../utils/validation';

export default function Login() {
    const { lang } = useLang();
    const { flash } = usePage().props;

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
    });

    const [fieldErrors, setFieldErrors] = useState({});

    const handleSubmit = (e) => {
        e.preventDefault();
        const errs = {};
        if (!data.email.trim()) errs.email = t(lang, 'emailAddress') + ' ' + t(lang, 'required') + '.';
        else if (!v.isEmail(data.email)) errs.email = t(lang, 'invalidEmail') || 'Please enter a valid email address.';
        if (!data.password) errs.password = t(lang, 'password') + ' ' + t(lang, 'required') + '.';
        setFieldErrors(errs);
        if (Object.keys(errs).length > 0) return;
        post('/login', { onFinish: () => reset('password') });
    };

    const allErrors = { ...fieldErrors, ...errors };

    return (
        <Layout title={t(lang, 'login')}>
            <section className="ai-page flex items-center justify-center px-6 pt-24 pb-16 sm:px-8 md:pt-28 md:pb-20 lg:px-16">
                <div className="ai-glow ai-glow-blue" style={{ width: '520px', height: '520px', top: '10%', left: '50%', transform: 'translateX(-50%)' }} />

                <div className="ai-card relative z-10 w-full max-w-md p-6 md:p-8">
                    <div className="mb-6 text-center">
                        <span className="ai-pill ai-pill-blue">Secure Access</span>
                        <h2 className="mt-5 text-2xl font-extrabold text-white">
                            {t(lang, 'welcomeBack')}
                        </h2>
                        <p className="mt-2 text-[13px] text-slate-400">
                            {t(lang, 'secureGateway')}
                        </p>
                    </div>

                    {(flash?.success) && (
                        <div className="ai-success-box">{flash.success}</div>
                    )}

                    <form onSubmit={handleSubmit}>
                        <FloatingField label={t(lang, 'emailAddress')} value={data.email}>
                            <input
                                type="email"
                                value={data.email}
                                onChange={e => { setData('email', e.target.value); setFieldErrors(fe => ({ ...fe, email: '' })); }}
                                required
                                autoFocus
                            />
                        </FloatingField>
                        {allErrors.email && (
                            <div className="ai-error">{allErrors.email}</div>
                        )}

                        <FloatingField label={t(lang, 'password')} value={data.password}>
                            <PasswordInput
                                value={data.password}
                                onChange={e => { setData('password', e.target.value); setFieldErrors(fe => ({ ...fe, password: '' })); }}
                                required
                            />
                        </FloatingField>
                        {allErrors.password && (
                            <div className="ai-error">{allErrors.password}</div>
                        )}

                        <button
                            type="submit"
                            className="ai-btn ai-btn-primary mt-6 w-full py-3"
                            disabled={processing}
                        >
                            {processing ? t(lang, 'connecting') : t(lang, 'login')}
                        </button>
                    </form>

                    <div className="mt-5 text-center">
                        <Link href="/forgot-password" className="ai-link text-[13px]">
                            {t(lang, 'forgotPassword')}?
                        </Link>
                    </div>
                </div>
            </section>
        </Layout>
    );
}

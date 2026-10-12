import React, { useState } from 'react';
import { useForm, usePage, router } from '@inertiajs/react';
import Layout from '../Shared/Layout';
import AppIcon from '../../config/iconMap';
import PasswordInput from '../Shared/PasswordInput';
import FloatingField from '../Shared/FloatingField';
import { useLang } from '../../LangContext';
import { t } from '../../i18n';
import { validators as v } from '../../utils/validation';

export default function Profile({ user, auth, from }) {
    const currentUser = user || auth?.user;
    const isVisitor = !!auth?.visitor;
    const { flash } = usePage().props;
    const [saved, setSaved] = useState(false);
    const { lang } = useLang();

    // Fallback landing page when there is no valid previous page.
    const dashboardUrl = auth?.user
        ? (auth.user.role === 'system_admin' ? '/system-admin/dashboard'
            : auth.user.role === 'ceo' ? '/ceo/dashboard'
            : auth.user.role === 'admin' ? '/admin/dashboard'
            : auth.user.role === 'security_guard' ? '/security/dashboard'
            : '/host/dashboard')
        : '/visitor/dashboard';

    // Freeze the page we came from so the post-save redirect goes back there.
    const [backUrl] = useState(() => {
        const raw = typeof from === 'string' ? from.trim() : '';
        if (!raw) return null;
        try {
            const u = new URL(raw, window.location.origin);
            if (u.origin !== window.location.origin) return null;
            if (u.pathname === '/profile' || u.pathname.startsWith('/profile')) return null;
            return u.pathname + u.search + u.hash;
        } catch {
            return null;
        }
    });

    const { data, setData, post, processing, errors } = useForm({
        first_name: currentUser?.first_name || '',
        last_name: currentUser?.last_name || '',
        sex: currentUser?.sex || '',
        age: currentUser?.age || '',
        email: currentUser?.email || '',
        phone: currentUser?.phone || '',
        id_document_no: currentUser?.id_document_no || currentUser?.national_id || '',
        company_position: currentUser?.company_position || currentUser?.position || '',
        password: '',
        password_confirmation: '',
        profile_photo: null,
        name: currentUser?.name || '',
    });

    const [photoPreview, setPhotoPreview] = useState(
        currentUser?.profile_photo ? `/storage/${currentUser.profile_photo}` :
        currentUser?.photo_url ? `/storage/${currentUser.photo_url}` : null
    );

    const handlePhotoChange = (e) => {
        const file = e.target.files[0];
        if (file) {
            setData('profile_photo', file);
            setPhotoPreview(URL.createObjectURL(file));
        }
    };

    const [fieldErrors, setFieldErrors] = useState({});
    const allErrors = { ...fieldErrors, ...errors };

    const handleSubmit = (e) => {
        e.preventDefault();
        const errs = {};
        if (!data.first_name.trim()) errs.first_name = t(lang, 'firstName') + ' ' + t(lang, 'required') + '.';
        else if (!v.isName(data.first_name)) errs.first_name = 'First name may only contain letters, spaces, hyphens and apostrophes.';
        if (!data.last_name.trim()) errs.last_name = t(lang, 'lastName') + ' ' + t(lang, 'required') + '.';
        else if (!v.isName(data.last_name)) errs.last_name = 'Last name may only contain letters, spaces, hyphens and apostrophes.';
        if (!data.sex) errs.sex = t(lang, 'gender') + ' ' + t(lang, 'required') + '.';
        else if (!v.inList(data.sex, ['male', 'female', 'other'])) errs.sex = 'Please select a valid gender.';
        if (!data.age) errs.age = t(lang, 'age') + ' ' + t(lang, 'required') + '.';
        else if (!v.isInteger(data.age)) errs.age = 'Age must be a valid number.';
        else if (!v.inRange(data.age, 16, 120)) errs.age = Number(data.age) < 16 ? 'You must be at least 16 years old.' : 'Please enter a valid age.';
        if (!data.email.trim()) errs.email = t(lang, 'emailAddress') + ' ' + t(lang, 'required') + '.';
        else if (!v.isEmail(data.email)) errs.email = t(lang, 'invalidEmail') || 'Please enter a valid email address.';
        if (data.phone && !v.isPhone(data.phone)) errs.phone = 'Please enter a valid phone number.';
        if (!v.maxLen(data.id_document_no, 255)) errs.id_document_no = 'ID document must not exceed 255 characters.';
        if (!v.maxLen(data.company_position, 255)) errs.company_position = 'Company position must not exceed 255 characters.';
        if (data.password) {
            if (String(data.password).length < 8) errs.password = 'Password must be at least 8 characters.';
            else if (data.password !== data.password_confirmation) errs.password_confirmation = t(lang, 'passwordsDoNotMatch') || 'Passwords do not match.';
        }
        setFieldErrors(errs);
        if (Object.keys(errs).length > 0) return;
        setSaved(false);
        const url = isVisitor ? '/visitor/profile/update' : '/profile/update';
        post(url, {
            forceFormData: true,
            preserveScroll: true,
            transform: data => ({
                ...data,
                name: [data.first_name, data.last_name].filter(Boolean).join(' '),
            }),
            onSuccess: () => {
                setData('password', '');
                setData('password_confirmation', '');
                const target = backUrl || dashboardUrl;
                if (target) {
                    router.visit(target);
                } else {
                    setSaved(true);
                    setTimeout(() => setSaved(false), 4000);
                }
            },
        });
    };

    return (
        <Layout title="My Profile">
            <section className="ai-page" style={{ minHeight: 'calc(100vh - 240px)', display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'visible' }}>
                <div className="ai-glow ai-glow-blue" style={{ width: '460px', height: '460px', top: '-60px', right: '-120px' }} />
                <div className="ai-glow ai-glow-orange" style={{ width: '420px', height: '420px', bottom: '-120px', left: '-100px' }} />

                <div className="relative z-10 mx-auto w-full max-w-2xl px-6 py-10 sm:px-8 lg:px-16">
                    <div className="mb-8 text-center">
                        <span className="ai-pill ai-pill-blue">My Account</span>
                        <h2 className="mt-5 text-2xl font-extrabold text-white">My Profile</h2>
                        <p className="mt-2 text-[13px] text-slate-400">
                            Manage your personal information and profile photo.
                        </p>
                    </div>

                    <form onSubmit={handleSubmit} encType="multipart/form-data">
                        {/* Profile Photo */}
                        <div className="ai-card mb-6 p-6 md:p-8">
                            <div className="flex flex-col items-center gap-5 sm:flex-row sm:gap-6">
                                <label className="cursor-pointer text-center">
                                    <div className="flex h-28 w-28 items-center justify-center overflow-hidden rounded-full border-2 border-dashed border-blue-400/40 bg-blue-500/5 transition-all duration-300 hover:border-blue-400/70">
                                        {photoPreview ? (
                                            <img src={photoPreview} alt="Profile" className="h-full w-full object-cover" />
                                        ) : (
                                            <span className="text-slate-500"><AppIcon name="edit" size={42} /></span>
                                        )}
                                    </div>
                                    <div className="mt-2 text-[13px] font-semibold text-blue-400">{t(lang,'uploadPhoto')}</div>
                                    <input type="file" accept="image/*" onChange={handlePhotoChange} className="hidden" />
                                </label>
                                <div className="text-center sm:text-left">
                                    <div className="text-lg font-bold text-white">{currentUser?.name || 'User'}</div>
                                    <div className="mt-1 text-[13px] text-slate-400">
                                        {currentUser?.role ? currentUser.role.charAt(0).toUpperCase() + currentUser.role.slice(1).replace('_', ' ') : 'Visitor'}
                                        {currentUser?.department?.name && ` · ${currentUser.department.name}`}
                                    </div>
                                    <div className="mt-0.5 text-xs text-slate-500">{currentUser?.email}</div>
                                </div>
                            </div>
                        </div>

                        {/* Personal Information */}
                        <div className="ai-card mb-6 p-6 md:p-8">
                            <h3 className="ai-section-head mb-5">Personal Information</h3>

                            {/* First Name + Last Name */}
                            <div className="ai-grid-2">
                                <div>
                                    <FloatingField label={t(lang,'firstName')} value={data.first_name}>
                                        <input type="text" value={data.first_name}
                                            onChange={e => setData('first_name', e.target.value)} required />
                                    </FloatingField>
                                    {allErrors.first_name && <div className="ai-error">{allErrors.first_name}</div>}
                                </div>
                                <div>
                                    <FloatingField label={t(lang,'lastName')} value={data.last_name}>
                                        <input type="text" value={data.last_name}
                                            onChange={e => setData('last_name', e.target.value)} required />
                                    </FloatingField>
                                    {allErrors.last_name && <div className="ai-error">{allErrors.last_name}</div>}
                                </div>
                            </div>

                            {/* Gender + Age */}
                            <div className="ai-grid-2">
                                <div>
                                    <FloatingField label={t(lang,'gender')} value={data.sex}>
                                        <select value={data.sex}
                                            onChange={e => setData('sex', e.target.value)} required>
                                            <option value="">{'\u00A0'}</option>
                                            <option value="male">{t(lang,'male')}</option>
                                            <option value="female">{t(lang,'female')}</option>
                                        </select>
                                    </FloatingField>
                                    {allErrors.sex && <div className="ai-error">{allErrors.sex}</div>}
                                </div>
                                <div>
                                    <FloatingField label={t(lang,'age')} value={data.age}>
                                        <input type="number" value={data.age}
                                            onChange={e => setData('age', e.target.value)}
                                            min={16} max={120} required />
                                    </FloatingField>
                                    {allErrors.age && <div className="ai-error">{allErrors.age}</div>}
                                </div>
                            </div>
                        </div>

                        {/* Contact Information */}
                        <div className="ai-card mb-6 p-6 md:p-8">
                            <h3 className="ai-section-head mb-5">Contact Information</h3>

                            {/* Email + Phone */}
                            <div className="ai-grid-2">
                                <div>
                                    <FloatingField label={t(lang,'emailAddress')} value={data.email}>
                                        <input type="email" value={data.email}
                                            onChange={e => setData('email', e.target.value)} required />
                                    </FloatingField>
                                    {allErrors.email && <div className="ai-error">{allErrors.email}</div>}
                                </div>
                                <div>
                                    <FloatingField label={t(lang,'phone')} value={data.phone}>
                                        <input type="tel" value={data.phone}
                                            onChange={e => setData('phone', e.target.value)} required />
                                    </FloatingField>
                                    {allErrors.phone && <div className="ai-error">{allErrors.phone}</div>}
                                </div>
                            </div>

                            {/* National ID / Passport */}
                            <FloatingField label={t(lang,'nationalId')} value={data.id_document_no}>
                                <input type="text" value={data.id_document_no}
                                    onChange={e => { const v = e.target.value.replace(/\D/g,'').slice(0,6); setData('id_document_no', v); }}
                                    maxLength={6} inputMode="numeric" required />
                            </FloatingField>
                            {allErrors.id_document_no && <div className="ai-error">{allErrors.id_document_no}</div>}

                            {/* Company Position (Optional) */}
                            <FloatingField label={t(lang,'companyPosition')} value={data.company_position}>
                                <input type="text" value={data.company_position}
                                    onChange={e => setData('company_position', e.target.value)} />
                            </FloatingField>
                            {allErrors.company_position && <div className="ai-error">{allErrors.company_position}</div>}
                        </div>

                        {/* Password */}
                        <div className="ai-card mb-6 p-6 md:p-8">
                            <h3 className="ai-section-head mb-5">Password</h3>
                            <div className="ai-grid-2">
                                <div>
                                    <FloatingField label={t(lang,'password')} value={data.password}>
                                        <PasswordInput value={data.password}
                                            onChange={e => setData('password', e.target.value)} placeholder="Leave blank to keep current" />
                                    </FloatingField>
                                    {allErrors.password && <div className="ai-error">{allErrors.password}</div>}
                                </div>
                                <div>
                                    <FloatingField label={t(lang,'confirmPassword')} value={data.password_confirmation}>
                                        <PasswordInput value={data.password_confirmation}
                                            onChange={e => setData('password_confirmation', e.target.value)} placeholder="Leave blank to keep current" />
                                    </FloatingField>
                                </div>
                            </div>
                        </div>

                        {/* Save */}
                        {(saved || flash?.success) && (
                            <div className="ai-success-box"><AppIcon name="check" size={14} /> Profile saved successfully!</div>
                        )}
                        {allErrors.password_confirmation && <div className="ai-error">{allErrors.password_confirmation}</div>}
                        <button type="submit" className="ai-btn ai-btn-primary mt-6 w-full py-3.5" disabled={processing}>
                            {processing ? ' Saving...' : ' Save Profile'}
                        </button>
                    </form>
                </div>
            </section>
        </Layout>
    );
}

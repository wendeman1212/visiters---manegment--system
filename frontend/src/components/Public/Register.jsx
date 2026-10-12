import React, { useEffect, useState } from 'react';
import Layout from '../Shared/Layout';
import PasswordInput from '../Shared/PasswordInput';
import { useForm, Link, usePage } from '@inertiajs/react';
import { useLang } from '../../LangContext';
import { t } from '../../i18n';
import AppIcon from '../../config/iconMap';
import { useRegistrationDraft } from '../../RegistrationDraftContext';
import { validators as v } from '../../utils/validation';

const PHOTO_MAX_SIZE = 2 * 1024 * 1024; // 2 MB
const PHOTO_ALLOWED = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];

function FloatField({ label, htmlFor, value, error, className = '', children }) {
    const hasValue = value !== '' && value != null && String(value).length > 0;

    return (
        <div className={`reg-float${hasValue ? ' is-floated' : ''}${error ? ' is-error' : ''}${className ? ' ' + className : ''}`}>
            <label htmlFor={htmlFor} className="reg-float-label">{label}</label>
            {children}
        </div>
    );
}

export default function Register({ departments, pending_email, email_verified, cooldown_remaining = 0 }) {
    const { lang } = useLang();
    const { flash } = usePage().props;
    const { draft, saveDraft, clearDraft } = useRegistrationDraft();
    const [photoPreview, setPhotoPreview] = useState(null);
    const [photoError, setPhotoError] = useState('');

    const [emailVerifiedState, setEmailVerifiedState] = useState(!!email_verified);
    const [successMessage, setSuccessMessage] = useState(flash?.success ?? '');
    const [cooldown, setCooldown] = useState(() => Number(cooldown_remaining) || 0);

    // A server-verified email always wins over the draft; otherwise the draft
    // (saved right before the verification detour) restores everything the
    // visitor had already typed, including the profile photo File.
    const verifiedEmail = email_verified ? pending_email : null;

    const { data, setData, post, processing, errors, reset } = useForm({
        first_name: draft?.first_name ?? '',
        last_name: draft?.last_name ?? '',
        sex: draft?.sex ?? '',
        age: draft?.age ?? '',
        email: verifiedEmail || draft?.email || pending_email || '',
        phone: draft?.phone ?? '',
        id_document_no: draft?.id_document_no ?? '',
        company_position: draft?.company_position ?? '',
        password: draft?.password ?? '',
        password_confirmation: draft?.password_confirmation ?? '',
        profile_photo: draft?.profile_photo ?? null,
        role: draft?.role ?? 'visitor',
        department_id: draft?.department_id ?? '',
    });

    const verify = useForm({ email: pending_email || '' });

    // The draft is only meant to survive the verification detour; consume it
    // once the form has been restored from it.
    useEffect(() => {
        if (draft) clearDraft();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Rebuild the photo preview from a restored File object.
    useEffect(() => {
        if (photoPreview || !(data.profile_photo instanceof File)) return;
        setPhotoPreview(URL.createObjectURL(data.profile_photo));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        const next = data.email;
        if (verify.data.email !== next) verify.setData('email', next);
    }, [data.email]);

    useEffect(() => {
        setSuccessMessage(flash?.success ?? '');
    }, [flash?.success]);

    useEffect(() => {
        if (!successMessage) return;
        const timer = setTimeout(() => setSuccessMessage(''), 5000);
        return () => clearTimeout(timer);
    }, [successMessage]);

    // Resend countdown, seeded from the server on load and refreshed whenever
    // the backend answers a resend with the seconds that are actually left.
    useEffect(() => {
        if (cooldown <= 0) return;
        const timer = setTimeout(() => setCooldown(c => (c <= 1 ? 0 : c - 1)), 1000);
        return () => clearTimeout(timer);
    }, [cooldown]);

    useEffect(() => {
        const remaining = Number(flash?.cooldown_remaining) || 0;
        if (remaining > cooldown) setCooldown(remaining);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flash?.cooldown_remaining]);

    // The "please wait" notice only means something while the countdown runs.
    // Drop it once the countdown ends so the button looks usable again.
    useEffect(() => {
        if (cooldown > 0) return;
        if (verify.errors.email && /wait a moment/i.test(verify.errors.email)) {
            verify.clearErrors('email');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cooldown]);

    // ── Frontend validation ── (mirrors AuthController::register)
    const validate = () => {
        const newErrors = {};

        if (!data.first_name || !data.first_name.trim()) newErrors.first_name = t(lang, 'firstName') + ' ' + t(lang, 'required') + '.';
        else if (!v.isName(data.first_name)) newErrors.first_name = 'First name may only contain letters, spaces, hyphens and apostrophes.';
        else if (!v.maxLen(data.first_name, 255)) newErrors.first_name = 'First name must not exceed 255 characters.';

        if (!data.last_name || !data.last_name.trim()) newErrors.last_name = t(lang, 'lastName') + ' ' + t(lang, 'required') + '.';
        else if (!v.isName(data.last_name)) newErrors.last_name = 'Last name may only contain letters, spaces, hyphens and apostrophes.';
        else if (!v.maxLen(data.last_name, 255)) newErrors.last_name = 'Last name must not exceed 255 characters.';

        if (!data.age) newErrors.age = t(lang, 'age') + ' ' + t(lang, 'required') + '.';
        else if (!v.isInteger(data.age)) newErrors.age = 'Age must be a valid number.';
        else if (!v.inRange(data.age, 16, 120)) newErrors.age = Number(data.age) < 16 ? 'You must be at least 16 years old.' : 'Please enter a valid age.';

        if (!data.sex) newErrors.sex = t(lang, 'gender') + ' ' + t(lang, 'required') + '.';
        else if (!v.inList(data.sex, ['male', 'female'])) newErrors.sex = 'Please select a valid gender.';

        if (!data.email) newErrors.email = t(lang, 'emailAddress') + ' ' + t(lang, 'required') + '.';
        else if (!v.isEmail(data.email)) newErrors.email = t(lang, 'invalidEmail') || 'Invalid email address.';

        if (!data.phone) newErrors.phone = t(lang, 'phone') + ' ' + t(lang, 'required') + '.';
        else if (!v.isPhone(data.phone)) newErrors.phone = 'Please enter a valid phone number.';

        if (!data.id_document_no || !data.id_document_no.trim()) newErrors.id_document_no = t(lang, 'nationalId') + ' ' + t(lang, 'required') + '.';
        else if (!v.isIdDocument(data.id_document_no)) newErrors.id_document_no = 'ID may only contain letters, numbers, and hyphens.';
        else if (!v.maxLen(data.id_document_no, 50)) newErrors.id_document_no = 'ID document must not exceed 50 characters.';

        if (!data.password) newErrors.password = t(lang, 'password') + ' ' + t(lang, 'required') + '.';
        else if (String(data.password).length < 8) newErrors.password = 'Password must be at least 8 characters.';
        else if (!v.isStrongPassword(data.password)) newErrors.password = t(lang, 'passwordHint') || 'Password must contain uppercase, lowercase, number, and special character.';
        if (data.password !== data.password_confirmation) newErrors.password_confirmation = t(lang, 'passwordsDoNotMatch') || 'Passwords do not match.';

        if (data.company_position && !v.maxLen(data.company_position, 255)) newErrors.company_position = 'Company position must not exceed 255 characters.';

        return newErrors;
    };

    const [frontendErrors, setFrontendErrors] = useState({});

    const runFrontendValidation = () => {
        const e = validate();
        setFrontendErrors(e);
        return Object.keys(e).length === 0;
    };

    const handleSubmit = e => {
        e.preventDefault();
        const fieldErrors = { ...runFrontendValidation(), ...errors };

        if (!emailVerifiedState) {
            fieldErrors.email = t(lang, 'verifyEmailFirst') || 'Please verify your email first.';
        }

        if (Object.keys(fieldErrors).length > 0) {
            setFrontendErrors(fieldErrors);
            return;
        }

        post('/register', { onSuccess: () => { reset(); clearDraft(); } });
    };

    const allErrors = { ...frontendErrors, ...errors };

    const handlePhotoChange = e => {
        const file = e.target.files[0];
        setPhotoError('');

        if (!file) return;

        if (!PHOTO_ALLOWED.includes(file.type)) {
            setPhotoError('Please upload a JPEG, PNG, JPG, or GIF image.');
            setData('profile_photo', null);
            setPhotoPreview(null);
            return;
        }

        if (file.size > PHOTO_MAX_SIZE) {
            setPhotoError('Profile photo must not exceed 2 MB.');
            setData('profile_photo', null);
            setPhotoPreview(null);
            return;
        }

        setData('profile_photo', file);
        setPhotoPreview(URL.createObjectURL(file));
    };

    const handleEmailChange = value => {
        setData('email', value);
        verify.clearErrors('email');
        if (emailVerifiedState) {
            setEmailVerifiedState(false);
        }
    };

    const handleSendCode = e => {
        e.preventDefault();
        if (cooldown > 0 || verify.processing) return;

        if (!data.email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) {
            setFrontendErrors({ email: 'Please enter a valid email address to verify.' });
            return;
        }
        setFrontendErrors({});
        verify.clearErrors();
        saveDraft({ ...data, profile_photo: data.profile_photo ?? null });
        verify.setData('email', data.email);
        verify.post('/register/verify-email', {
            onError: errs => {
                if (errs.email && /wait a moment/i.test(errs.email)) {
                    setCooldown(c => (c > 0 ? c : 60));
                }
            },
        });
    };

    const clearError = field => {
        const newErr = { ...frontendErrors };
        delete newErr[field];
        setFrontendErrors(newErr);
    };

    const formFieldClass = "reg-input";
    const labelClass = "block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1.5";
    const errorClass = "mt-1 text-xs text-red-500";
    const fieldGroupClass = "mb-3";

    return (
        <Layout title={t(lang, 'createAccount')}>
            <section className="register-page ai-page flex justify-center px-6 pt-24 pb-16 sm:px-8 md:pt-28 md:pb-20 lg:px-16">
                <div
                    className="ai-glow ai-glow-orange"
                    style={{
                        width: '500px',
                        height: '500px',
                        top: '5%',
                        right: '0',
                    }}
                />

                <div className="ai-card relative z-10 w-full max-w-2xl p-6 md:p-8">
                    <div className="mb-6 text-center">
                        <span className="ai-pill">Join Us</span>
                        <h2 className="reg-heading mt-5 text-2xl font-extrabold text-white">
                            {t(lang, 'createAccount')}
                        </h2>
                        <p className="reg-sub mt-2 text-[13px] text-slate-400">
                            {t(lang, 'registerToJoin')}
                        </p>
                    </div>

                    {successMessage && <div className="ai-success-box mb-4">{successMessage}</div>}

                    <form onSubmit={handleSubmit} encType="multipart/form-data">
                        {/* ── Profile Photo ── */}
                        <div className={fieldGroupClass}>
                            <label className={labelClass}>{t(lang, 'uploadPhoto')}</label>
                            <div className="flex items-center gap-5">
                                <label className="relative flex h-24 w-24 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-full border-2 border-dashed border-blue-400/40 bg-blue-500/5 transition-all duration-300 hover:border-blue-400/70">
                                    {photoPreview ? (
                                        <img
                                            src={photoPreview}
                                            alt="Preview"
                                            className="h-full w-full object-cover"
                                        />
                                    ) : (
                                        <AppIcon name="upload" size={32} className="text-slate-500" />
                                    )}
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/jpg,image/gif"
                                        onChange={handlePhotoChange}
                                        className="hidden"
                                    />
                                </label>
                                <div className="flex-1">
                                    <div className="text-xs text-slate-500 dark:text-slate-400">
                                        JPEG, PNG, JPG, or GIF. Max 2 MB.
                                    </div>
                                    {photoError && <div className={errorClass}>{photoError}</div>}
                                </div>
                            </div>
                        </div>

                        {/* ── First Name + Last Name ── */}
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className={fieldGroupClass}>
                                <FloatField label={t(lang, 'firstName')} htmlFor="reg-first_name" value={data.first_name} error={!!allErrors.first_name}>
                                    <input
                                        id="reg-first_name"
                                        type="text"
                                        className={formFieldClass}
                                        value={data.first_name}
                                        onChange={e => {
                                            setData('first_name', e.target.value);
                                            clearError('first_name');
                                        }}
                                        maxLength={255}
                                    />
                                </FloatField>
                                {allErrors.first_name && <div className={errorClass}>{allErrors.first_name}</div>}
                            </div>
                            <div className={fieldGroupClass}>
                                <FloatField label={t(lang, 'lastName')} htmlFor="reg-last_name" value={data.last_name} error={!!allErrors.last_name}>
                                    <input
                                        id="reg-last_name"
                                        type="text"
                                        className={formFieldClass}
                                        value={data.last_name}
                                        onChange={e => {
                                            setData('last_name', e.target.value);
                                            clearError('last_name');
                                        }}
                                        maxLength={255}
                                    />
                                </FloatField>
                                {allErrors.last_name && <div className={errorClass}>{allErrors.last_name}</div>}
                            </div>
                        </div>

                        {/* ── Gender + Age ── */}
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className={fieldGroupClass}>
                                <FloatField label={t(lang, 'gender')} htmlFor="reg-sex" value={data.sex} error={!!allErrors.sex}>
                                    <select
                                        id="reg-sex"
                                        className={formFieldClass}
                                        value={data.sex}
                                        onChange={e => {
                                            setData('sex', e.target.value);
                                            clearError('sex');
                                        }}
                                    >
                                        <option value=""></option>
                                        <option value="male">{t(lang, 'male')}</option>
                                        <option value="female">{t(lang, 'female')}</option>
                                    </select>
                                </FloatField>
                                {allErrors.sex && <div className={errorClass}>{allErrors.sex}</div>}
                            </div>
                            <div className={fieldGroupClass}>
                                <FloatField label={t(lang, 'age')} htmlFor="reg-age" value={data.age} error={!!allErrors.age}>
                                    <input
                                        id="reg-age"
                                        type="number"
                                        className={formFieldClass}
                                        value={data.age}
                                        onChange={e => {
                                            setData('age', e.target.value);
                                            clearError('age');
                                        }}
                                        min={16}
                                        max={120}
                                    />
                                </FloatField>
                                {allErrors.age && <div className={errorClass}>{allErrors.age}</div>}
                            </div>
                        </div>

                        {/* ── Email + Phone ── */}
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className={fieldGroupClass}>
                                <FloatField label={t(lang, 'emailAddress')} htmlFor="reg-email" value={data.email} error={!!allErrors.email}>
                                    <input
                                        id="reg-email"
                                        type="email"
                                        className={formFieldClass}
                                        value={data.email}
                                        onChange={e => {
                                            handleEmailChange(e.target.value);
                                            clearError('email');
                                        }}
                                    />
                                </FloatField>
                                {allErrors.email && <div className={errorClass}>{allErrors.email}</div>}
                            </div>
                            <div className={fieldGroupClass}>
                                <FloatField label={t(lang, 'phone')} htmlFor="reg-phone" value={data.phone} error={!!allErrors.phone}>
                                    <input
                                        id="reg-phone"
                                        type="tel"
                                        className={formFieldClass}
                                        value={data.phone}
                                        onChange={e => {
                                            setData('phone', e.target.value);
                                            clearError('phone');
                                        }}
                                        maxLength={30}
                                    />
                                </FloatField>
                                {allErrors.phone && <div className={errorClass}>{allErrors.phone}</div>}
                            </div>
                        </div>

                        {/* ── National ID / Passport ── */}
                        <div className={fieldGroupClass}>
                            <FloatField label={t(lang, 'nationalId')} htmlFor="reg-id_document_no" value={data.id_document_no} error={!!allErrors.id_document_no}>
                                <input
                                    id="reg-id_document_no"
                                    type="text"
                                    className={formFieldClass}
                                    value={data.id_document_no}
                                    onChange={e => {
                                        const v = e.target.value.replace(/[^a-zA-Z0-9\-]/g, '').slice(0, 50);
                                        setData('id_document_no', v);
                                        clearError('id_document_no');
                                    }}
                                    maxLength={50}
                                />
                            </FloatField>
                            {allErrors.id_document_no && <div className={errorClass}>{allErrors.id_document_no}</div>}
                        </div>

                        {/* ── Company Position (Optional) ── */}
                        <div className={fieldGroupClass}>
                            <FloatField label={<>{t(lang, 'companyPosition')} <span className="reg-float-opt">({t(lang, 'optionalLabel')})</span></>} htmlFor="reg-company_position" value={data.company_position} error={!!allErrors.company_position}>
                                <input
                                    id="reg-company_position"
                                    type="text"
                                    className={formFieldClass}
                                    value={data.company_position}
                                    onChange={e => setData('company_position', e.target.value)}
                                    maxLength={255}
                                />
                            </FloatField>
                            {allErrors.company_position && <div className={errorClass}>{allErrors.company_position}</div>}
                        </div>

                        {/* ── Password + Confirm Password ── */}
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className={fieldGroupClass}>
                                <FloatField label={t(lang, 'password')} htmlFor="reg-password" value={data.password} error={!!allErrors.password}>
                                    <PasswordInput
                                        id="reg-password"
                                        value={data.password}
                                        onChange={e => {
                                            setData('password', e.target.value);
                                            clearError('password');
                                            clearError('password_confirmation');
                                        }}
                                        required
                                        className={formFieldClass}
                                    />
                                </FloatField>
                                {allErrors.password && <div className={errorClass}>{allErrors.password}</div>}
                            </div>
                            <div className={fieldGroupClass}>
                                <FloatField label={t(lang, 'confirmPassword')} htmlFor="reg-password_confirmation" value={data.password_confirmation} error={!!allErrors.password_confirmation}>
                                    <PasswordInput
                                        id="reg-password_confirmation"
                                        value={data.password_confirmation}
                                        onChange={e => {
                                            setData('password_confirmation', e.target.value);
                                            clearError('password_confirmation');
                                        }}
                                        required
                                        className={formFieldClass}
                                    />
                                </FloatField>
                                {allErrors.password_confirmation && <div className={errorClass}>{allErrors.password_confirmation}</div>}
                            </div>
                        </div>

                        {/* ── Verify Your Email (sends code, continues on /verify-email) ── */}
                        <div className="mt-4 rounded-xl border border-blue-500/20 bg-blue-500/5 p-5">
                            <div className="flex items-start gap-3">
                                <AppIcon name="mail" size={20} className="mt-0.5 shrink-0 text-blue-400" />
                                <div>
                                    <div className="text-sm font-semibold text-white">
                                        {t(lang, 'verifyEmailTitle')}
                                    </div>
                                    <p className="mt-0.5 text-xs text-slate-400">
                                        {t(lang, 'verifyEmailHint')}
                                    </p>
                                </div>
                            </div>

                            {emailVerifiedState ? (
                                <div className="ai-success-box flex items-center gap-2 mt-3">
                                    <AppIcon name="check" size={16} /> {t(lang, 'emailVerified')}
                                </div>
                            ) : (
                                <>
                                    <button
                                        type="button"
                                        className="ai-btn ai-btn-primary w-full mt-3"
                                        onClick={handleSendCode}
                                        disabled={verify.processing || !data.email || cooldown > 0}
                                    >
                                        {verify.processing
                                            ? t(lang, 'sendingCode')
                                            : cooldown > 0
                                                ? t(lang, 'resendCooldown').replace('{s}', cooldown)
                                                : t(lang, 'sendVerifyCode')}
                                    </button>
                                    {cooldown > 0 ? (
                                        <div className="mt-2 text-xs text-slate-400">
                                            {t(lang, 'codeSentHint')} {data.email}.{' '}
                                            <Link href="/verify-email" className="ai-link">
                                                {t(lang, 'continueVerification')}
                                            </Link>
                                        </div>
                                    ) : (
                                        verify.errors.email && (
                                            <div className={errorClass}>{verify.errors.email}</div>
                                        )
                                    )}
                                </>
                            )}
                        </div>

                        {/* ── Submit ── */}
                        <button
                            type="submit"
                            className="ai-btn ai-btn-accent w-full py-3 mt-10"
                            disabled={processing || !emailVerifiedState}
                        >
                            {processing ? t(lang, 'registering') : t(lang, 'registerAccount')}
                        </button>
                    </form>

                    <div className="reg-footer mt-6 text-center text-sm text-slate-400">
                        {t(lang, 'alreadyHaveAccount')}{' '}
                        <Link href="/login" className="ai-link">
                            {t(lang, 'logInHere')}
                        </Link>
                    </div>
                </div>
            </section>
        </Layout>
    );
}

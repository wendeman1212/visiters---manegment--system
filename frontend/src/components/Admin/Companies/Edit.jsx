import React, { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { FaSave } from 'react-icons/fa';
import { validators as v } from '../../../utils/validation';

export default function Edit({ company, ceo, admin }) {
    const form = useForm({
        company_name: company.company_name,
        industry: company.industry || '',
        phone: company.phone || '',
        email: company.email || '',
        website: company.website || '',
        status: company.status,
        ceo_name: ceo?.name || '',
        ceo_email: ceo?.email || '',
        admin_name: admin?.name || '',
        admin_email: admin?.email || '',
    });

    const [fieldErrors, setFieldErrors] = useState({});
    const allErrors = { ...fieldErrors, ...form.errors };

    const submit = (e) => {
        e.preventDefault();
        const d = form.data;
        const errs = {};
        if (!d.company_name.trim()) errs.company_name = 'Company name is required.';
        else if (!v.maxLen(d.company_name, 200)) errs.company_name = 'Company name must not exceed 200 characters.';
        if (d.industry && !v.maxLen(d.industry, 150)) errs.industry = 'Industry must not exceed 150 characters.';
        if (d.phone && !v.maxLen(d.phone, 30)) errs.phone = 'Phone must not exceed 30 characters.';
        if (d.email && !v.isEmail(d.email)) errs.email = 'Please enter a valid email address.';
        if (d.website && !v.isHttpUrl(d.website)) errs.website = 'Please enter a valid website URL (e.g. https://example.com).';
        if (!v.inList(d.status, ['active', 'inactive'])) errs.status = 'Please select a valid status.';
        if (d.ceo_name && !v.maxLen(d.ceo_name, 255)) errs.ceo_name = 'CEO name must not exceed 255 characters.';
        if (d.ceo_email && !v.isEmail(d.ceo_email)) errs.ceo_email = 'Please enter a valid CEO email address.';
        if (d.admin_name && !v.maxLen(d.admin_name, 255)) errs.admin_name = 'Admin name must not exceed 255 characters.';
        if (d.admin_email && !v.isEmail(d.admin_email)) errs.admin_email = 'Please enter a valid admin email address.';
        setFieldErrors(errs);
        if (Object.keys(errs).length > 0) return;
        form.put(`/admin/companies/${company.company_id}`);
    };

    return (
        <>
            <Head title="Edit Company" />
            <div className="container mx-auto p-6 max-w-xl">
                <h1 className="text-2xl font-bold mb-4">Edit Company</h1>
                <form onSubmit={submit} className="bg-white border rounded p-6 space-y-4">
                    <div className="form-group">
                        <label className="form-label">Company Name *</label>
                        <input type="text" className="form-input" value={form.data.company_name}
                            onChange={(e) => form.setData('company_name', e.target.value)} required />
                        {allErrors.company_name && <div className="text-red-500 text-sm mt-1">{allErrors.company_name}</div>}
                    </div>
                    <div className="form-group">
                        <label className="form-label">Industry</label>
                        <input type="text" className="form-input" value={form.data.industry}
                            onChange={(e) => form.setData('industry', e.target.value)} />
                        {allErrors.industry && <div className="text-red-500 text-sm mt-1">{allErrors.industry}</div>}
                    </div>
                    <div className="form-group">
                        <label className="form-label">Phone</label>
                        <input type="text" className="form-input" value={form.data.phone}
                            onChange={(e) => form.setData('phone', e.target.value)} />
                        {allErrors.phone && <div className="text-red-500 text-sm mt-1">{allErrors.phone}</div>}
                    </div>
                    <div className="form-group">
                        <label className="form-label">Email</label>
                        <input type="email" className="form-input" value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)} />
                        {allErrors.email && <div className="text-red-500 text-sm mt-1">{allErrors.email}</div>}
                    </div>
                    <div className="form-group">
                        <label className="form-label">Website</label>
                        <input type="url" className="form-input" value={form.data.website}
                            onChange={(e) => form.setData('website', e.target.value)} />
                        {allErrors.website && <div className="text-red-500 text-sm mt-1">{allErrors.website}</div>}
                    </div>
                    <div className="form-group">
                        <label className="form-label">Status</label>
                        <select className="form-input" value={form.data.status}
                            onChange={(e) => form.setData('status', e.target.value)}>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <fieldset className="border rounded p-4">
                        <legend className="px-2 font-semibold">Company CEO</legend>
                        <p className="text-sm text-gray-500 mb-2">
                            Assign a CEO for this company. Provide a new email to create the account, or the email of an existing user.
                        </p>
                        <div className="form-group">
                            <label className="form-label">CEO Name</label>
                            <input type="text" className="form-input" value={form.data.ceo_name}
                                onChange={(e) => form.setData('ceo_name', e.target.value)} />
                            {allErrors.ceo_name && <div className="text-red-500 text-sm mt-1">{allErrors.ceo_name}</div>}
                        </div>
                        <div className="form-group">
                            <label className="form-label">CEO Email</label>
                            <input type="email" className="form-input" value={form.data.ceo_email}
                                onChange={(e) => form.setData('ceo_email', e.target.value)} />
                            {allErrors.ceo_email && <div className="text-red-500 text-sm mt-1">{allErrors.ceo_email}</div>}
                        </div>
                    </fieldset>

                    <fieldset className="border rounded p-4">
                        <legend className="px-2 font-semibold">Company Admin</legend>
                        <div className="form-group">
                            <label className="form-label">Admin Name</label>
                            <input type="text" className="form-input" value={form.data.admin_name}
                                onChange={(e) => form.setData('admin_name', e.target.value)} />
                            {allErrors.admin_name && <div className="text-red-500 text-sm mt-1">{allErrors.admin_name}</div>}
                        </div>
                        <div className="form-group">
                            <label className="form-label">Admin Email</label>
                            <input type="email" className="form-input" value={form.data.admin_email}
                                onChange={(e) => form.setData('admin_email', e.target.value)} />
                            {allErrors.admin_email && <div className="text-red-500 text-sm mt-1">{allErrors.admin_email}</div>}
                        </div>
                    </fieldset>

                    <button type="submit" className="btn btn-primary" disabled={form.processing}>
                        <FaSave size={14} className="inline mr-1" /> Update Company
                    </button>
                </form>
            </div>
        </>
    );
}

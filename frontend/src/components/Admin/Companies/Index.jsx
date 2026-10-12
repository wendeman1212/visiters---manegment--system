import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { FaPlus, FaEdit, FaTrash, FaToggleOn, FaToggleOff, FaUserTie } from 'react-icons/fa';

export default function Index({ companies }) {
    const remove = (id, name) => {
        if (!confirm(`Delete company "${name}"?`)) return;
        router.delete(`/admin/companies/${id}`);
    };

    const toggle = (id, name) => {
        if (!confirm(`Toggle status for company "${name}"?`)) return;
        router.post(`/admin/companies/${id}/status`);
    };

    const assign = (company) => {
        const role = prompt(`Assign a CEO or Company Admin for "${company.company_name}"? Type 'ceo' or 'admin':`, 'admin');
        if (!role || (role !== 'ceo' && role !== 'admin')) return;
        const name = prompt('Full name:', '');
        if (!name) return;
        const email = prompt('Email:', '');
        if (!email) return;
        router.post(`/admin/companies/${company.company_id}/assign`, { role, name, email });
    };

    return (
        <>
            <Head title="Companies" />
            <div className="container mx-auto p-6">
                <div className="flex justify-between items-center mb-4">
                    <h1 className="text-2xl font-bold">Companies</h1>
                    <Link href="/admin/companies/create" className="bg-blue-500 text-white px-4 py-2 rounded">
                        <FaPlus className="inline mr-1" /> Add Company
                    </Link>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full bg-white border">
                        <thead>
                            <tr className="bg-gray-100">
                                <th className="px-4 py-2">Name</th>
                                <th className="px-4 py-2">Industry</th>
                                <th className="px-4 py-2">Email</th>
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2">CEO</th>
                                <th className="px-4 py-2">Admin</th>
                                <th className="px-4 py-2">Users</th>
                                <th className="px-4 py-2">Departments</th>
                                <th className="px-4 py-2">Visits</th>
                                <th className="px-4 py-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {companies.map((company) => (
                                <tr key={company.company_id} className="border-t">
                                    <td className="px-4 py-2">{company.company_name}</td>
                                    <td className="px-4 py-2">{company.industry || '—'}</td>
                                    <td className="px-4 py-2">{company.email || '—'}</td>
                                    <td className="px-4 py-2">
                                        <span className={`px-2 py-1 rounded text-white ${company.status === 'active' ? 'bg-green-500' : 'bg-red-500'}`}>
                                            {company.status}
                                        </span>
                                    </td>
                                    <td className="px-4 py-2">{company.ceo_name ? `${company.ceo_name} (${company.ceo_email})` : <span className="text-yellow-500">Unassigned</span>}</td>
                                    <td className="px-4 py-2">{company.admin_name ? `${company.admin_name} (${company.admin_email})` : <span className="text-yellow-500">Unassigned</span>}</td>
                                    <td className="px-4 py-2">{company.users_count}</td>
                                    <td className="px-4 py-2">{company.departments_count}</td>
                                    <td className="px-4 py-2">{company.visit_requests_count}</td>
                                    <td className="px-4 py-2">
                                        <button onClick={() => assign(company)} className="text-purple-500 mr-2" title="Assign CEO / Admin">
                                            <FaUserTie />
                                        </button>
                                        <button onClick={() => toggle(company.company_id, company.company_name)} className="text-green-500 mr-2" title="Activate / Deactivate">
                                            {company.status === 'active' ? <FaToggleOff /> : <FaToggleOn />}
                                        </button>
                                        <Link href={`/admin/companies/${company.company_id}/edit`} className="text-blue-500 mr-2">
                                            <FaEdit />
                                        </Link>
                                        <button onClick={() => remove(company.company_id, company.company_name)} className="text-red-500">
                                            <FaTrash />
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

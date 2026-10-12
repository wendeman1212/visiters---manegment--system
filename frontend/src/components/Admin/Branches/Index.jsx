import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { FaPlus, FaEdit, FaTrash, FaEye } from 'react-icons/fa';

export default function Index({ branches }) {
    return (
        <>
            <Head title="Branches" />
            <div className="container mx-auto p-6">
                <div className="flex justify-between items-center mb-4">
                    <h1 className="text-2xl font-bold">Branches</h1>
                    <Link href={route('admin.branches.create')} className="bg-blue-500 text-white px-4 py-2 rounded">
                        <FaPlus className="inline mr-1" /> Add Branch
                    </Link>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full bg-white border">
                        <thead>
                            <tr className="bg-gray-100">
                                <th className="px-4 py-2">Company</th>
                                <th className="px-4 py-2">Name</th>
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {branches.map((branch) => (
                                <tr key={branch.id} className="border-t">
                                    <td className="px-4 py-2">{branch.company?.company_name || '—'}</td>
                                    <td className="px-4 py-2">{branch.name}</td>
                                    <td className="px-4 py-2">
                                        <span className={`px-2 py-1 rounded text-white ${branch.is_active ? 'bg-green-500' : 'bg-red-500'}`}>
                                            {branch.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </td>
                                    <td className="px-4 py-2">
                                        <Link href={route('admin.branches.activity-logs', branch.id)} className="text-gray-500 mr-2" title="View Logs">
                                            <FaEye />
                                        </Link>
                                        <Link href={route('admin.branches.edit', branch.id)} className="text-blue-500 mr-2">
                                            <FaEdit />
                                        </Link>
                                        <button onClick={() => { /* delete logic */ }} className="text-red-500">
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

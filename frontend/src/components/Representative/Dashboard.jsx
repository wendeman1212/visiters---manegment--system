import React from 'react';
import { Head, usePage } from '@inertiajs/react';
import { FaDownload } from 'react-icons/fa';

export default function Dashboard({ company, representative, appointment, status, members }) {
    return (
        <>
            <Head title="Representative Dashboard" />
            <div className="container mx-auto p-6">
                <h1 className="text-2xl font-bold mb-4">Company Visit Dashboard</h1>
                <div className="bg-white shadow-md rounded-lg p-6 mb-6">
                    <h2 className="text-xl font-semibold">{company}</h2>
                    <p><strong>Representative:</strong> {representative}</p>
                    <p><strong>Appointment:</strong> {appointment}</p>
                    <p><strong>Status:</strong> {status}</p>
                </div>

                <div className="flex justify-end gap-2 mb-4">
                    <button className="bg-blue-500 text-white px-4 py-2 rounded flex items-center gap-2">
                        <FaDownload /> Download All QR Codes
                    </button>
                    <button className="bg-green-500 text-white px-4 py-2 rounded flex items-center gap-2">
                        <FaDownload /> Download All Passes
                    </button>
                </div>

                <div className="overflow-x-auto">
                    <table className="min-w-full bg-white border">
                        <thead>
                            <tr className="bg-gray-100">
                                <th className="px-4 py-2">Name</th>
                                <th className="px-4 py-2">Email</th>
                                <th className="px-4 py-2">Phone</th>
                                <th className="px-4 py-2">QR Code</th>
                                <th className="px-4 py-2">Visitor Pass</th>
                            </tr>
                        </thead>
                        <tbody>
                            {members.map((member, index) => (
                                <tr key={index} className="border-t">
                                    <td className="px-4 py-2">{member.name}</td>
                                    <td className="px-4 py-2">{member.email}</td>
                                    <td className="px-4 py-2">{member.phone}</td>
                                    <td className="px-4 py-2">
                                        {member.qr_code ? (
                                            <a href={member.qr_code} download className="text-blue-500 underline">
                                                Download QR
                                            </a>
                                        ) : 'N/A'}
                                    </td>
                                    <td className="px-4 py-2">
                                        {member.pass_url ? (
                                            <a href={member.pass_url} target="_blank" className="text-blue-500 underline">
                                                View Pass
                                            </a>
                                        ) : 'N/A'}
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

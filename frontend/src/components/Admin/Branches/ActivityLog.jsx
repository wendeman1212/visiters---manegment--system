import React from 'react';
import { Head } from '@inertiajs/react';

export default function ActivityLog({ branch, logs, filters }) {
    return (
        <>
            <Head title={`Activity Log - ${branch.name}`} />
            <div className="container mx-auto p-6">
                <h1 className="text-2xl font-bold mb-4">Activity Log for {branch.name}</h1>
                <div className="overflow-x-auto">
                    <table className="min-w-full bg-white border">
                        <thead>
                            <tr className="bg-gray-100">
                                <th className="px-4 py-2">User</th>
                                <th className="px-4 py-2">Action</th>
                                <th className="px-4 py-2">Details</th>
                                <th className="px-4 py-2">Date/Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.map((log) => (
                                <tr key={log.id} className="border-t">
                                    <td className="px-4 py-2">{log.actor?.name}</td>
                                    <td className="px-4 py-2">{log.action}</td>
                                    <td className="px-4 py-2">{JSON.stringify(log.details)}</td>
                                    <td className="px-4 py-2">{new Date(log.created_at).toLocaleString()}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {logs.links && (
                    <div className="mt-4 flex justify-center">
                        {logs.links.map((link, index) => (
                            <span key={index} dangerouslySetInnerHTML={{ __html: link }} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

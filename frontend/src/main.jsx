import './bootstrap';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import React, { useState, useEffect } from 'react';
import { LangContext } from './LangContext';
import { ThemeContext } from './ThemeContext';

function AppWrapper({ children }) {
    const [lang, setLang] = useState(() => localStorage.getItem('vms_lang') || 'en');
    const changeLang = (l) => { localStorage.setItem('vms_lang', l); setLang(l); };

    const [theme, setTheme] = useState(() => localStorage.getItem('vms_theme') || 'dark');
    const changeTheme = (t) => { localStorage.setItem('vms_theme', t); setTheme(t); };

    useEffect(() => {
        document.documentElement.classList.toggle('dark', theme === 'dark');
    }, [theme]);

    return (
        <LangContext.Provider value={{ lang, setLang: changeLang }}>
            <ThemeContext.Provider value={{ theme, setTheme: changeTheme }}>
                {children}
            </ThemeContext.Provider>
        </LangContext.Provider>
    );
}

function showError(el) {
    const apiUrl = window.location.origin;

    el.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:#0a0e1a;color:#e2e8f0;font-family:'Inter',sans-serif;">
            <div style="text-align:center;max-width:480px;padding:40px;">
                <div style="width:64px;height:64px;border-radius:16px;background:rgba(239,68,68,0.15);display:flex;align-items:center;justify-content:center;margin:0 auto 24px auto;">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </div>
                <div style="font-size:20px;font-weight:800;margin-bottom:12px;font-family:'Outfit',sans-serif;">Unable to Connect to VMS Server</div>
                <div style="font-size:14px;color:#94a3b8;margin-bottom:8px;">The backend server is not reachable.</div>
                <div style="font-size:13px;color:#64748b;margin-bottom:24px;">Ensure the Laravel backend is running at <code style="background:rgba(99,102,241,0.15);padding:2px 8px;border-radius:4px;color:#818cf8;">${apiUrl}</code></div>
                <button onclick="location.reload()" style="padding:10px 24px;border-radius:8px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:white;border:none;font-weight:600;cursor:pointer;font-size:14px;">Retry Connection</button>
            </div>
        </div>`;
}

async function boot() {
    const el = document.getElementById('app');
    const API_URL = window.location.origin;

    if (el.dataset.page) {
        createInertiaApp({
            title: (title) => title ? `${title} - VMS Enterprise` : 'VMS Enterprise',
            resolve: name => {
                const pages = import.meta.glob('./components/**/*.jsx');
                return pages[`./components/${name}.jsx`]().then(m => m.default);
            },
            setup({ el: rootEl, App, props }) {
                createRoot(rootEl).render(
                    <AppWrapper>
                        <App {...props} />
                    </AppWrapper>
                );
            },
        });
        return;
    }

    try {
        const url = window.location.pathname || '/';
        const res = await fetch(url, {
            headers: {
                'X-Inertia': 'true',
                'X-Inertia-Version': '',
                'Accept': 'text/html, application/xhtml+xml',
            },
            credentials: 'same-origin',
        });

        if (!res.ok) throw new Error(`HTTP ${res.status}`);

        const ct = res.headers.get('content-type') || '';
        let data;
        if (ct.includes('application/json')) {
            data = await res.json();
        } else {
            const html = await res.text();
            const m = html.match(/data-page="([^"]+)"/);
            if (!m) throw new Error('Could not extract Inertia page data');
            data = JSON.parse(decodeURIComponent(m[1]));
        }

        createInertiaApp({
            title: (title) => title ? `${title} - VMS Enterprise` : 'VMS Enterprise',
            initialPage: data,
            resolve: name => {
                const pages = import.meta.glob('./components/**/*.jsx');
                return pages[`./components/${name}.jsx`]().then(m => m.default);
            },
            setup({ el: rootEl, App, props }) {
                createRoot(rootEl).render(
                    <AppWrapper>
                        <App {...props} />
                    </AppWrapper>
                );
            },
        });
    } catch (err) {
        console.error('VMS init failed:', err);
        showError(el);
    }
}

boot();

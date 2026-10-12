import './bootstrap';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import React, { useState, useEffect } from 'react';
import { LangContext } from './LangContext';
import { ThemeContext } from './ThemeContext';
import { RegistrationDraftContext } from './RegistrationDraftContext';

function AppWrapper({ children }) {
    const [lang, setLang] = useState(() => localStorage.getItem('vms_lang') || 'en');
    const changeLang = (l) => { localStorage.setItem('vms_lang', l); setLang(l); };

    const [theme, setTheme] = useState(() => localStorage.getItem('vms_theme') || 'light');
    const changeTheme = (t) => { localStorage.setItem('vms_theme', t); setTheme(t); };

    const [draft, setDraft] = useState(null);
    const saveDraft = (data) => setDraft(data);
    const clearDraft = () => setDraft(null);

    useEffect(() => {
        document.documentElement.classList.toggle('dark', theme === 'dark');
    }, [theme]);

    return (
        <LangContext.Provider value={{ lang, setLang: changeLang }}>
            <ThemeContext.Provider value={{ theme, setTheme: changeTheme }}>
                <RegistrationDraftContext.Provider value={{ draft, saveDraft, clearDraft }}>
                    {children}
                </RegistrationDraftContext.Provider>
            </ThemeContext.Provider>
        </LangContext.Provider>
    );
}

createInertiaApp({
    title: (title) => title ? `${title} - VMS Enterprise` : 'VMS Enterprise',
    resolve: name => {
        const pages = import.meta.glob('./components/**/*.jsx');
        return pages[`./components/${name}.jsx`]().then(m => m.default);
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <AppWrapper>
                <App {...props} />
            </AppWrapper>
        );
    },
});

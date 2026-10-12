import { createContext, useContext } from 'react';

export const RegistrationDraftContext = createContext({
    draft: null,
    saveDraft: () => {},
    clearDraft: () => {},
});

export const useRegistrationDraft = () => useContext(RegistrationDraftContext);

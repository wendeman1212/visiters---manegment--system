// Shared, field-purpose-specific validation helpers.
//
// These rules intentionally mirror the Laravel request rules used across the
// VMS controllers (AuthController, VisitRequestController, ManagementController,
// SecurityController, MessageController, ...) so that client-side and
// server-side validation never drift apart.
//
// Usage:
//   import { validators as v, runRules } from '../../utils/validation';
//   const errors = runRules(data, {
//       email: val => (!v.isEmail(val) ? 'Please enter a valid email address.' : null),
//   });

// ── Patterns ──────────────────────────────────────────────────────────────
// Names: unicode letters plus spaces, hyphens, apostrophes and periods.
export const NAME_RE = /^[\p{L}][\p{L}\s'’.\-]*$/u;
export const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
export const PHONE_RE = /^\+?[0-9][0-9\s\-\.\(\)]{7,16}$/;
export const ID_DOC_RE = /^[a-zA-Z0-9\-]+$/;
export const SIX_DIGIT_RE = /^\d{6}$/;
export const OTP_RE = /^\d{4,6}$/;
export const PASSWORD_RE = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$/;
export const INTEGER_RE = /^-?\d+$/;
export const DECIMAL_RE = /^-?\d+(\.\d+)?$/;
export const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
export const DATETIME_LOCAL_RE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/;
export const TIME_RE = /^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/;
export const URL_RE = /^(https?:\/\/)?([\w-]+\.)+[\w-]{2,}(\/[^\s]*)?$/i;
// Laravel's `url` rule requires an explicit scheme (PHP FILTER_VALIDATE_URL).
export const HTTP_URL_RE = /^https?:\/\/[^\s]+$/i;

// ── Primitives ────────────────────────────────────────────────────────────
export const isBlank = v =>
    v === undefined || v === null || (typeof v === 'string' && v.trim() === '');

export const strLen = v => (v === undefined || v === null ? 0 : String(v).trim().length);

export const isName = v => NAME_RE.test(String(v).trim());
export const isEmail = v => EMAIL_RE.test(String(v).trim());
export const isPhone = v => PHONE_RE.test(String(v).trim());
export const isIdDocument = v => ID_DOC_RE.test(String(v).trim());
export const isSixDigitId = v => SIX_DIGIT_RE.test(String(v).trim());
export const isOtp = v => OTP_RE.test(String(v).trim());
export const isStrongPassword = v => PASSWORD_RE.test(String(v));
export const isInteger = v => INTEGER_RE.test(String(v).trim());
export const isDecimal = v => DECIMAL_RE.test(String(v).trim());
export const isDate = v => DATE_RE.test(String(v).trim());
export const isDateTime = v => DATETIME_LOCAL_RE.test(String(v).trim());
export const isTime = v => TIME_RE.test(String(v).trim());
export const isUrl = v => URL_RE.test(String(v).trim());
export const isHttpUrl = v => HTTP_URL_RE.test(String(v).trim());

export const maxLen = (v, n) => strLen(v) <= n;
export const minLen = (v, n) => strLen(v) >= n;

export const inRange = (v, min, max) => {
    const n = Number(v);
    return !Number.isNaN(n) && n >= min && n <= max;
};

export const isNumeric = v => !isBlank(v) && !Number.isNaN(Number(v));

export const inList = (v, list) => list.map(String).includes(String(v));

// ── Aggregate helper ──────────────────────────────────────────────────────
// rules: { field: (value, allValues) => string | null | undefined }
// A rule should return an error message or a falsy value when valid.
export function runRules(values, rules) {
    const errors = {};
    for (const field of Object.keys(rules)) {
        let message = null;
        try {
            message = rules[field](values[field], values);
        } catch (e) {
            message = 'Invalid value.';
        }
        if (message) errors[field] = message;
    }
    return errors;
}

export const validators = {
    isName,
    isEmail,
    isPhone,
    isIdDocument,
    isSixDigitId,
    isOtp,
    isStrongPassword,
    isInteger,
    isDecimal,
    isDate,
    isDateTime,
    isTime,
    isUrl,
    isHttpUrl,
    isNumeric,
    maxLen,
    minLen,
    inRange,
    inList,
};

export default validators;

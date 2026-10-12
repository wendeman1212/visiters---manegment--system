/**
 * Timezone utilities for the VMS.
 *
 * The backend stores datetimes in the company timezone (e.g. Africa/Addis_Ababa).
 * Carbon serializes them to ISO 8601 with Z (UTC) suffix.
 * JavaScript's `new Date()` parses these correctly.
 *
 * This module provides helpers for:
 *  1. Display: ISO → user's local timezone (via toLocaleString)
 *  2. datetime-local inputs: ISO → local datetime-local format
 *  3. min attribute: current time in local datetime-local format
 *  4. Submission: local datetime-local → ISO string in company timezone
 */

const pad = (n) => String(n).padStart(2, '0');

/**
 * Format an ISO datetime string for display in the user's local timezone.
 */
export function fmtDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? String(iso) : d.toLocaleString();
}

/**
 * Format an ISO datetime string as a date in the user's local timezone.
 */
export function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? String(iso) : d.toLocaleDateString();
}

/**
 * Format an ISO datetime string as time (HH:MM) in the user's local timezone.
 */
export function fmtTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? String(iso) : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

/**
 * Convert an ISO datetime string to a datetime-local input value (YYYY-MM-DDTHH:MM)
 * in the user's local timezone.
 */
export function toLocalDatetimeInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/**
 * Get the current time in datetime-local input format (YYYY-MM-DDTHH:MM)
 * in the user's LOCAL timezone. Used for the `min` attribute on datetime-local inputs
 * to prevent scheduling in the past.
 */
export function getMinDatetimeLocal() {
    const now = new Date();
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
}

/**
 * Convert a datetime-local input value (in the user's local timezone) to an ISO string
 * in the company timezone for backend storage.
 *
 * @param {string} localValue - datetime-local value like "2026-07-26T10:00"
 * @param {string} companyTimezone - company timezone like "Africa/Addis_Ababa"
 * @returns {string} ISO string with timezone info for the backend
 */
export function localToServerDatetime(localValue, companyTimezone) {
    if (!localValue) return '';
    // Parse the local datetime-local value as a Date in the browser's timezone
    const localDate = new Date(localValue);
    // Format it in the company timezone to get the correct server time
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: companyTimezone,
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        hour12: false,
    }).formatToParts(localDate);

    const get = (type) => parts.find(p => p.type === type)?.value || '';
    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

/**
 * Convert an ISO datetime string to a datetime-local input value
 * in the company timezone (for editing).
 *
 * This is used when the user edits a time — we show it in the company timezone
 * so that when they submit it, the backend interprets it correctly.
 */
export function toServerDatetimeInput(iso, companyTimezone) {
    if (!iso) return '';
    const d = new Date(iso);
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: companyTimezone,
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit',
        hour12: false,
    }).formatToParts(d);

    const get = (type) => parts.find(p => p.type === type)?.value || '';
    return `${get('year')}-${get('month')}-${get('day')}T${pad(parseInt(get('hour')) % 24)}:${get('minute')}`;
}

/**
 * Check if a datetime (ISO string) is in the past, compared to now.
 * Uses the browser's local time for comparison.
 */
export function isPast(iso) {
    if (!iso) return false;
    return new Date(iso) <= new Date();
}

/**
 * Check if a datetime (ISO string) is in the future.
 */
export function isFuture(iso) {
    if (!iso) return false;
    return new Date(iso) > new Date();
}

/**
 * Get a Date object representing when the meeting ends.
 * @param {string} requestedTime - ISO datetime string
 * @param {number} duration - duration in minutes
 * @returns {Date}
 */
export function getMeetingEnd(requestedTime, duration) {
    return new Date(new Date(requestedTime).getTime() + (duration || 60) * 60000);
}

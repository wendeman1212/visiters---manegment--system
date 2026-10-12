import React, { useState } from 'react';
import { FiEye, FiEyeOff } from 'react-icons/fi';

export default function PasswordInput({ value, onChange, placeholder, required, className, readOnly, ...props }) {
    const [show, setShow] = useState(false);
    return (
        <div style={{ position: 'relative' }}>
            <input
                type={show ? 'text' : 'password'}
                className={className || 'form-input'}
                value={value}
                onChange={onChange}
                placeholder={placeholder}
                required={required}
                readOnly={readOnly}
                style={{ paddingRight: 40, ...(readOnly ? { opacity: 0.6, cursor: 'not-allowed', background: 'rgba(255,255,255,0.03)' } : {}) }}
                {...props}
            />
            <button
                type="button"
                onClick={() => setShow(s => !s)}
                style={{
                    position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)',
                    background: 'none', border: 'none', cursor: 'pointer',
                    color: 'var(--text-muted)', padding: 4, lineHeight: 1, display: 'flex',
                }}
                tabIndex={-1}
                aria-label={show ? 'Hide password' : 'Show password'}
            >
                {show ? <FiEyeOff size={18} /> : <FiEye size={18} />}
            </button>
        </div>
    );
}

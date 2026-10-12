import React, { useId, useState } from 'react';

export default function FloatingField({ label, value, children, className = '' }) {
    const [focused, setFocused] = useState(false);
    const id = useId();
    const hasValue = value !== undefined && value !== null && String(value).length > 0;
    const floating = focused || hasValue;

    const child = React.isValidElement(children)
        ? React.cloneElement(children, {
              id,
              className: `${children.props.className || ''} ai-input ai-field-input`.trim(),
              onFocus: (e) => {
                  setFocused(true);
                  children.props.onFocus?.(e);
              },
              onBlur: (e) => {
                  setFocused(false);
                  children.props.onBlur?.(e);
              },
          })
        : children;

    return (
        <div className={`ai-field ${floating ? 'is-floating' : ''} ${className}`.trim()}>
            {child}
            <label className="ai-field-label" htmlFor={id}>{label}</label>
        </div>
    );
}

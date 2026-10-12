import React, { useEffect, useRef } from 'react';

export default function Reveal({ children, delay = 0, direction = 'up', className = '', ...rest }) {
    const ref = useRef(null);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;

        if (!('IntersectionObserver' in window)) {
            el.classList.add('rv-show');
            return;
        }

        const io = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        el.classList.add('rv-show');
                        io.unobserve(el);
                    }
                });
            },
            { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
        );
        io.observe(el);
        return () => io.disconnect();
    }, []);

    const dir = direction === 'left' ? 'rv-l' : direction === 'right' ? 'rv-r' : direction === 'scale' ? 'rv-s' : '';

    return (
        <div ref={ref} className={`rv ${dir} ${className}`} style={{ transitionDelay: `${delay}ms` }} {...rest}>
            {children}
        </div>
    );
}

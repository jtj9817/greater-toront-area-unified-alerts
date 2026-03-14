import React from 'react';

interface BrandIconProps {
    className?: string;
    style?: React.CSSProperties;
}

export const BrandIcon: React.FC<BrandIconProps> = ({
    className = '',
    style,
}) => (
    <svg
        viewBox="0 0 24 24"
        fill="currentColor"
        xmlns="http://www.w3.org/2000/svg"
        className={className}
        style={style}
        aria-hidden="true"
    >
        {/* Center dot */}
        <circle cx="12" cy="12" r="2.5" />
        {/* Inner arcs */}
        <path
            d="M8.46 15.54a5 5 0 0 1 0-7.08"
            stroke="currentColor"
            strokeWidth="2"
            fill="none"
            strokeLinecap="round"
        />
        <path
            d="M15.54 8.46a5 5 0 0 1 0 7.08"
            stroke="currentColor"
            strokeWidth="2"
            fill="none"
            strokeLinecap="round"
        />
        {/* Outer arcs */}
        <path
            d="M5.64 18.36a9 9 0 0 1 0-12.72"
            stroke="currentColor"
            strokeWidth="2"
            fill="none"
            strokeLinecap="round"
        />
        <path
            d="M18.36 5.64a9 9 0 0 1 0 12.72"
            stroke="currentColor"
            strokeWidth="2"
            fill="none"
            strokeLinecap="round"
        />
    </svg>
);

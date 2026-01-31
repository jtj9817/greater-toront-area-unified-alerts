import React from 'react';

interface IconProps {
  name: string;
  className?: string;
  fill?: boolean;
  style?: React.CSSProperties;
}

export const Icon: React.FC<IconProps> = ({ name, className = "", fill = false, style }) => {
  // material-symbols-outlined defaults to outline.
  // Add 'fill' class variation if needed, though usually controlled by font-variation-settings in CSS.
  // For standard Google Fonts Material Symbols, 'fill-current' usually just sets SVG fill,
  // but for the font version, we rely on the class.
  return (
    <span 
      className={`material-symbols-outlined ${className}`}
      style={{
        ...(fill ? { fontVariationSettings: "'FILL' 1" } : {}),
        ...style
      }}
    >
      {name}
    </span>
  );
};
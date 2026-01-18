import type { ReactNode } from 'react';
import BaseCard, { type CardTheme } from './BaseCard';

const InfoIcon = () => (
  <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
    <path
      fill="currentColor"
      d="M12 2.8c5 0 9.2 4.1 9.2 9.2 0 5-4.1 9.2-9.2 9.2-5 0-9.2-4.1-9.2-9.2 0-5 4.1-9.2 9.2-9.2zm0 4.4a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2zm-1 4.3a1 1 0 0 0 0 2h.6v4a1 1 0 0 0 2 0v-5a1 1 0 0 0-1-1H11z"
    />
  </svg>
);

/**
 * Informational card with optional subtitle and custom content.
 *
 * @returns {JSX.Element}
 */
export interface InfoCardProps {
  title?: ReactNode;
  subtitle?: ReactNode;
  children?: ReactNode;
  theme?: CardTheme;
}

export default function InfoCard({ title, subtitle, children, theme = 'auto' }: InfoCardProps) {
  return (
    <BaseCard title={title} subtitle={subtitle} icon={<InfoIcon />} variant="info" theme={theme}>
      {children}
    </BaseCard>
  );
}

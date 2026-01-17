import { useId, type ReactNode } from 'react';
import './cards.css';

export type CardVariant = 'info' | 'danger' | 'success' | 'error' | 'chart';
export type CardTheme = 'auto' | 'light' | 'dark';

/**
 * Base card layout for AgentWP response cards.
 *
 * @returns {JSX.Element}
 */
export interface BaseCardProps {
  title?: ReactNode;
  subtitle?: ReactNode;
  icon?: ReactNode;
  actions?: ReactNode;
  children?: ReactNode;
  variant?: CardVariant;
  accent?: boolean;
  theme?: CardTheme;
  className?: string;
}

export default function BaseCard({
  title,
  subtitle,
  icon,
  actions,
  children,
  variant = 'info',
  accent = false,
  theme = 'auto',
  className = '',
}: BaseCardProps) {
  const titleId = useId();
  const resolvedTheme = theme === 'light' || theme === 'dark' ? theme : undefined;
  const cardClasses = [
    'agentwp-card',
    `agentwp-card--${variant}`,
    accent ? 'agentwp-card--accent' : '',
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <section
      className={cardClasses}
      data-theme={resolvedTheme}
      aria-labelledby={title ? titleId : undefined}
    >
      {(title || subtitle || icon) && (
        <div className="agentwp-card__header">
          {icon && <div className="agentwp-card__icon">{icon}</div>}
          <div>
            {title && (
              <h3 id={titleId} className="agentwp-card__title">
                {title}
              </h3>
            )}
            {subtitle && <p className="agentwp-card__subtitle">{subtitle}</p>}
          </div>
        </div>
      )}

      {children && <div className="agentwp-card__body">{children}</div>}

      {actions && <div className="agentwp-card__actions">{actions}</div>}
    </section>
  );
}

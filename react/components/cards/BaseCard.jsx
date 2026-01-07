import { useId } from 'react';
import './cards.css';

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
}) {
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
      role="region"
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

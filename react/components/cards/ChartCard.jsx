import BaseCard from './BaseCard.jsx';

const ChartIcon = () => (
  <svg
    viewBox="0 0 24 24"
    width="20"
    height="20"
    aria-hidden="true"
    focusable="false"
  >
    <path
      fill="currentColor"
      d="M4.8 20.2a1 1 0 0 1-1-1V4.8a1 1 0 0 1 2 0v13.4h13.4a1 1 0 0 1 0 2H4.8zm4.2-4.9 3-4.2a1 1 0 0 1 1.6-.1l2 2.6 3-4a1 1 0 0 1 1.6 1.2l-3.8 5a1 1 0 0 1-1.6 0l-2.1-2.7-2.2 3.1a1 1 0 1 1-1.5-.9z"
    />
  </svg>
);

export default function ChartCard({
  title = 'Performance snapshot',
  subtitle,
  metric,
  trend,
  chart,
  footer,
  theme = 'dark',
}) {
  return (
    <BaseCard
      title={title}
      subtitle={subtitle}
      icon={<ChartIcon />}
      variant="chart"
      accent
      theme={theme}
    >
      {(metric || trend) && (
        <div className="agentwp-card__metric">
          {metric && <span>{metric}</span>}
          {trend && <span className="agentwp-card__trend">{trend}</span>}
        </div>
      )}
      <div className="agentwp-card__chart">
        {chart || (
          <div className="agentwp-card__chart-placeholder" aria-hidden="true">
            <div className="agentwp-card__chart-bar" style={{ height: '55%' }} />
            <div className="agentwp-card__chart-bar" style={{ height: '80%' }} />
            <div className="agentwp-card__chart-bar" style={{ height: '45%' }} />
            <div className="agentwp-card__chart-bar" style={{ height: '90%' }} />
            <div className="agentwp-card__chart-bar" style={{ height: '60%' }} />
            <div className="agentwp-card__chart-bar" style={{ height: '75%' }} />
          </div>
        )}
      </div>
      {footer && <div className="agentwp-card__muted">{footer}</div>}
    </BaseCard>
  );
}

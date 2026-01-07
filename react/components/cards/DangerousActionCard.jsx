import BaseCard from './BaseCard.jsx';

const WarningIcon = () => (
  <svg
    viewBox="0 0 24 24"
    width="20"
    height="20"
    aria-hidden="true"
    focusable="false"
  >
    <path
      fill="currentColor"
      d="M12 3.2c.4 0 .8.2 1 .6l8.4 14.2c.4.7-.1 1.5-1 1.5H3.6c-.8 0-1.3-.8-.9-1.5L11 3.8c.2-.4.6-.6 1-.6zm0 5.1a.9.9 0 0 0-.9.9v5.1a.9.9 0 0 0 1.8 0V9.2a.9.9 0 0 0-.9-.9zm0 9.1a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4z"
    />
  </svg>
);

export default function DangerousActionCard({
  title = 'Confirm action',
  details,
  executeLabel = 'Execute',
  cancelLabel = 'Cancel',
  onExecute,
  onCancel,
  theme = 'auto',
}) {
  return (
    <BaseCard
      title={title}
      icon={<WarningIcon />}
      variant="danger"
      accent
      theme={theme}
      actions={
        <>
          <button
            type="button"
            className="agentwp-card__button agentwp-card__button--danger"
            onClick={onExecute}
          >
            {executeLabel}
          </button>
          <button
            type="button"
            className="agentwp-card__button agentwp-card__button--muted"
            onClick={onCancel}
          >
            {cancelLabel}
          </button>
        </>
      }
    >
      {details && <p className="agentwp-card__text">{details}</p>}
    </BaseCard>
  );
}

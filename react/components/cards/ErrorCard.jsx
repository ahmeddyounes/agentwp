import BaseCard from './BaseCard.jsx';

const ErrorIcon = () => (
  <svg
    viewBox="0 0 24 24"
    width="20"
    height="20"
    aria-hidden="true"
    focusable="false"
  >
    <path
      fill="currentColor"
      d="M12 2.8c5 0 9.2 4.1 9.2 9.2 0 5-4.1 9.2-9.2 9.2-5 0-9.2-4.1-9.2-9.2 0-5 4.1-9.2 9.2-9.2zm0 5.1a.9.9 0 0 0-.9.9v4.8a.9.9 0 0 0 1.8 0V8.8a.9.9 0 0 0-.9-.9zm0 8a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2z"
    />
  </svg>
);

export default function ErrorCard({
  title = 'Something went wrong',
  message,
  retryLabel = 'Retry',
  reportLabel = 'Report Issue',
  onRetry,
  onReport,
  reportHref,
  theme = 'auto',
}) {
  const retryAction = onRetry ? (
    <button type="button" className="agentwp-card__button" onClick={onRetry}>
      {retryLabel}
    </button>
  ) : null;
  const reportAction = onReport ? (
    <button type="button" className="agentwp-card__link" onClick={onReport}>
      {reportLabel}
    </button>
  ) : reportHref ? (
    <a className="agentwp-card__link" href={reportHref}>
      {reportLabel}
    </a>
  ) : null;

  const actions = retryAction || reportAction ? (
    <>
      {retryAction}
      {reportAction}
    </>
  ) : null;

  return (
    <BaseCard
      title={title}
      icon={<ErrorIcon />}
      variant="error"
      theme={theme}
      actions={actions}
    >
      {message && <p className="agentwp-card__text">{message}</p>}
    </BaseCard>
  );
}

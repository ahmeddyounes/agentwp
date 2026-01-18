import BaseCard, { type CardTheme } from './BaseCard';

const CheckIcon = () => (
  <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
    <path
      fill="currentColor"
      d="M9.2 16.6 4.8 12.2a1 1 0 0 1 1.4-1.4l3 3 8-8a1 1 0 1 1 1.4 1.4l-9.4 9.4a1 1 0 0 1-1.4 0z"
    />
  </svg>
);

/**
 * Success feedback card for completed actions.
 *
 * @returns {JSX.Element}
 */
export interface SuccessCardProps {
  title?: string;
  summary?: string;
  undoLabel?: string;
  onUndo?: (() => void) | undefined;
  undoHref?: string | undefined;
  theme?: CardTheme;
  onStar?: (() => void) | undefined;
  isStarred?: boolean;
  starLabel?: string;
}

export default function SuccessCard({
  title = 'Action completed',
  summary,
  undoLabel = 'Undo',
  onUndo,
  undoHref,
  theme = 'auto',
  onStar,
  isStarred = false,
  starLabel = 'Star',
}: SuccessCardProps) {
  const undoAction = onUndo ? (
    <button type="button" className="agentwp-card__link" onClick={onUndo}>
      {undoLabel}
    </button>
  ) : undoHref ? (
    <a className="agentwp-card__link" href={undoHref}>
      {undoLabel}
    </a>
  ) : null;

  const starAction = onStar ? (
    <button type="button" className="agentwp-card__link" onClick={onStar} aria-pressed={isStarred}>
      {isStarred ? 'Starred' : starLabel}
    </button>
  ) : null;

  const actions =
    undoAction || starAction ? (
      <>
        {undoAction}
        {starAction}
      </>
    ) : null;

  return (
    <BaseCard
      title={title}
      icon={<CheckIcon />}
      variant="success"
      accent
      theme={theme}
      actions={actions}
    >
      {summary && <p className="agentwp-card__text">{summary}</p>}
    </BaseCard>
  );
}

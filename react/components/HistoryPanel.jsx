import { useMemo, useRef } from 'react';

const StarIcon = ({ filled }) => (
  <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4">
    <path
      d="M12 3.4l2.4 4.9 5.4.8-3.9 3.8.9 5.3-4.8-2.5-4.8 2.5.9-5.3-3.9-3.8 5.4-.8L12 3.4z"
      fill={filled ? 'currentColor' : 'none'}
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinejoin="round"
    />
  </svg>
);

const TrashIcon = () => (
  <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4">
    <path
      d="M5 7h14M10 7V5h4v2M8.5 7v11m3-11v11m3-11v11M6.5 7l.7 12a1 1 0 0 0 1 .9h7.6a1 1 0 0 0 1-.9l.7-12"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </svg>
);

const buildCommandKey = (entry) => `${entry?.raw_input || ''}::${entry?.parsed_intent || ''}`;

const formatTime = (timestamp) => {
  const date = new Date(timestamp);
  if (Number.isNaN(date.getTime())) {
    return '';
  }
  return date.toLocaleTimeString(undefined, {
    hour: 'numeric',
    minute: '2-digit',
  });
};

const formatDayLabel = (timestamp, now) => {
  const date = new Date(timestamp);
  if (Number.isNaN(date.getTime())) {
    return 'Unknown date';
  }
  const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const startOfDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());
  const diffDays = Math.round((startOfToday - startOfDate) / 86400000);

  if (diffDays === 0) {
    return 'Today';
  }
  if (diffDays === 1) {
    return 'Yesterday';
  }
  return date.toLocaleDateString(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
};

const groupHistory = (entries, now) => {
  const groups = [];
  entries.forEach((entry) => {
    const label = formatDayLabel(entry.timestamp, now);
    const existing = groups.find((group) => group.label === label);
    if (existing) {
      existing.items.push(entry);
    } else {
      groups.push({ label, items: [entry] });
    }
  });
  return groups;
};

/**
 * Command history and favorites panel.
 *
 * @param {object} props Component props.
 * @param {Array} props.history History entries.
 * @param {number} [props.historyCount] Optional total count.
 * @param {Array} props.favorites Favorite entries.
 * @param {Array} props.mostUsed Most-used entries.
 * @param {Function} props.onRun Callback when a command is rerun.
 * @param {Function} props.onDelete Callback when a command is deleted.
 * @param {Function} props.onToggleFavorite Callback when a favorite is toggled.
 * @param {Function} props.onClearHistory Callback when history is cleared.
 * @param {Function} props.isFavorited Optional predicate for favorites.
 * @returns {JSX.Element}
 */
export default function HistoryPanel({
  history = [],
  historyCount,
  favorites = [],
  mostUsed = [],
  onRun,
  onDelete,
  onToggleFavorite,
  onClearHistory,
  isFavorited,
}) {
  const totalHistory = typeof historyCount === 'number' ? historyCount : history.length;
  const now = useMemo(() => new Date(), []);
  const groupedHistory = useMemo(() => groupHistory(history, now), [history, now]);
  const favoriteKeys = useMemo(
    () => new Set(favorites.map((entry) => buildCommandKey(entry))),
    [favorites]
  );
  const touchStartRef = useRef({});

  const handleTouchStart = (entryId) => (event) => {
    const touch = event.touches[0];
    touchStartRef.current[entryId] = {
      x: touch.clientX,
      y: touch.clientY,
    };
  };

  const handleTouchEnd = (entry) => (event) => {
    const start = touchStartRef.current[entry.id];
    if (!start) {
      return;
    }
    delete touchStartRef.current[entry.id];
    const touch = event.changedTouches[0];
    const deltaX = touch.clientX - start.x;
    const deltaY = touch.clientY - start.y;
    if (deltaX < -60 && Math.abs(deltaY) < 40) {
      onDelete?.(entry);
    }
  };

  const renderCommandRow = (entry, { showDelete }) => {
    const isStarred = isFavorited ? isFavorited(entry) : favoriteKeys.has(buildCommandKey(entry));
    const canFavorite = entry.was_successful || isStarred;
    const timeLabel = formatTime(entry.timestamp);
    const statusLabel = entry.was_successful ? 'Successful' : 'Failed';
    const statusColor = entry.was_successful ? 'bg-emerald-400/70' : 'bg-rose-400/70';

    return (
      <div
        key={entry.id}
        className="flex items-start gap-2 rounded-xl border border-slate-800/80 bg-slate-950/40 px-3 py-2 transition hover:border-slate-600/80"
        onTouchStart={showDelete ? handleTouchStart(entry.id) : undefined}
        onTouchEnd={showDelete ? handleTouchEnd(entry) : undefined}
      >
        <button
          type="button"
          onClick={() => onRun?.(entry.raw_input)}
          className="flex-1 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400"
        >
          <span className="block text-sm font-semibold text-slate-100">{entry.raw_input}</span>
          <span className="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
            {entry.parsed_intent ? <span>{entry.parsed_intent}</span> : null}
            {timeLabel ? <span>{timeLabel}</span> : null}
            <span className="flex items-center gap-1">
              <span className={`h-1.5 w-1.5 rounded-full ${statusColor}`} />
              {statusLabel}
            </span>
          </span>
        </button>
        <button
          type="button"
          onClick={() => onToggleFavorite?.(entry)}
          aria-pressed={isStarred}
          disabled={!canFavorite}
          className={`inline-flex h-8 w-8 items-center justify-center rounded-full border text-xs transition focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300 ${
            isStarred
              ? 'border-amber-400/70 text-amber-200'
              : canFavorite
                ? 'border-slate-700/70 text-slate-400 hover:border-slate-500/80 hover:text-slate-200'
                : 'cursor-not-allowed border-slate-800/60 text-slate-600'
          }`}
          aria-label={isStarred ? 'Remove favorite' : 'Add favorite'}
        >
          <StarIcon filled={isStarred} />
        </button>
        {showDelete ? (
          <button
            type="button"
            onClick={() => onDelete?.(entry)}
            className="inline-flex h-8 w-8 items-center justify-center rounded-full border border-slate-700/70 text-slate-400 transition hover:border-slate-500/80 hover:text-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-300"
            aria-label="Remove from history"
          >
            <TrashIcon />
          </button>
        ) : null}
      </div>
    );
  };

  return (
    <div className="space-y-4" onMouseDown={(event) => event.preventDefault()}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.3em] text-slate-500">
            Command history
          </p>
          <p className="text-sm text-slate-300">Re-run recent commands or pin favorites.</p>
        </div>
        <button
          type="button"
          onClick={() => onClearHistory?.()}
          disabled={!totalHistory}
          className={`inline-flex items-center justify-center rounded-full border px-3 py-1.5 text-xs font-semibold uppercase tracking-widest transition focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 ${
            totalHistory
              ? 'border-slate-600/70 bg-slate-900/70 text-white hover:border-slate-400/80 hover:bg-slate-900'
              : 'cursor-not-allowed border-slate-700/60 bg-slate-900/50 text-slate-500'
          }`}
        >
          Clear history
        </button>
      </div>

      <div className="space-y-2">
        <p className="text-[11px] font-semibold uppercase tracking-[0.3em] text-slate-500">
          Favorites
        </p>
        {favorites.length ? (
          <div className="space-y-2">
            {favorites.map((entry) => renderCommandRow(entry, { showDelete: false }))}
          </div>
        ) : (
          <p className="text-xs text-slate-500">
            No favorites yet. Star a successful command to keep it here.
          </p>
        )}
      </div>

      {mostUsed.length ? (
        <div className="space-y-2">
          <p className="text-[11px] font-semibold uppercase tracking-[0.3em] text-slate-500">
            Most used
          </p>
          <div className="flex flex-wrap gap-2">
            {mostUsed.map((entry) => (
              <button
                key={entry.raw_input}
                type="button"
                onClick={() => onRun?.(entry.raw_input)}
                className="inline-flex max-w-full items-center gap-2 rounded-full border border-slate-700/70 bg-slate-950/40 px-3 py-1 text-xs text-slate-200 transition hover:border-slate-500/80 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400"
              >
                <span className="max-w-[200px] truncate">{entry.raw_input}</span>
                <span className="text-[10px] text-slate-500">{entry.count}x</span>
              </button>
            ))}
          </div>
        </div>
      ) : null}

      <div className="space-y-2">
        <p className="text-[11px] font-semibold uppercase tracking-[0.3em] text-slate-500">
          Recent commands
        </p>
        {history.length ? (
          <div className="space-y-3">
            {groupedHistory.map((group) => (
              <div key={group.label} className="space-y-2">
                <p className="text-xs font-semibold text-slate-400">{group.label}</p>
                <div className="space-y-2">
                  {group.items.map((entry) => renderCommandRow(entry, { showDelete: true }))}
                </div>
              </div>
            ))}
          </div>
        ) : (
          <p className="text-xs text-slate-500">
            {totalHistory
              ? 'All recent commands are already favorited.'
              : 'No commands yet. Ask AgentWP to get started.'}
          </p>
        )}
      </div>
    </div>
  );
}

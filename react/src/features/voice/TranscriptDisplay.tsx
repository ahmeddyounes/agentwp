interface TranscriptDisplayProps {
  interimTranscript: string;
  finalTranscript: string;
  error?: string;
  isListening: boolean;
}

export function TranscriptDisplay({
  interimTranscript,
  finalTranscript,
  error,
  isListening,
}: TranscriptDisplayProps) {
  const hasContent = interimTranscript || finalTranscript || error;

  if (!hasContent && !isListening) {
    return null;
  }

  if (error) {
    return (
      <div className="rounded-lg border border-red-500/30 bg-red-500/10 p-3" role="alert">
        <div className="flex items-center gap-2 text-sm text-red-300">
          <ErrorIcon />
          <span>{error}</span>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-1">
      {finalTranscript && <p className="text-sm text-white">{finalTranscript}</p>}
      {interimTranscript && <p className="text-sm italic text-slate-400">{interimTranscript}</p>}
      {isListening && !interimTranscript && !finalTranscript && (
        <div className="flex items-center gap-2 text-sm text-slate-400">
          <ListeningIndicator />
          <span>Listening...</span>
        </div>
      )}
    </div>
  );
}

function ErrorIcon() {
  return (
    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" className="h-4 w-4">
      <path
        fillRule="evenodd"
        d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z"
        clipRule="evenodd"
      />
    </svg>
  );
}

function ListeningIndicator() {
  return (
    <div className="flex items-center gap-0.5">
      {[0, 1, 2].map((i) => (
        <span
          key={i}
          className="h-2 w-0.5 animate-pulse rounded-full bg-indigo-400"
          style={{
            animationDelay: `${i * 150}ms`,
            animationDuration: '1s',
          }}
        />
      ))}
    </div>
  );
}

import { useRef, useEffect } from 'react';
import ReactMarkdown from 'react-markdown';

interface ResponseAreaProps {
  content: string;
  loading?: boolean;
  error?: string | null;
  onRetry?: () => void;
}

export function ResponseArea({
  content,
  loading = false,
  error = null,
  onRetry,
}: ResponseAreaProps) {
  const containerRef = useRef<HTMLDivElement>(null);

  // Auto-scroll to bottom when content changes
  useEffect(() => {
    if (containerRef.current && content) {
      containerRef.current.scrollTop = containerRef.current.scrollHeight;
    }
  }, [content]);

  if (loading && !content) {
    return (
      <div className="flex items-center justify-center py-8">
        <div className="flex items-center gap-2 text-slate-400">
          <LoadingSpinner />
          <span>Thinking...</span>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="rounded-lg border border-red-500/30 bg-red-500/10 p-4" role="alert">
        <div className="flex items-start gap-3">
          <ErrorIcon />
          <div className="flex-1">
            <p className="text-sm font-medium text-red-400">Error</p>
            <p className="mt-1 text-sm text-red-300">{error}</p>
            {onRetry && (
              <button
                onClick={onRetry}
                className="mt-3 rounded-md bg-red-500/20 px-3 py-1.5 text-sm font-medium text-red-300 transition-colors hover:bg-red-500/30"
              >
                Try Again
              </button>
            )}
          </div>
        </div>
      </div>
    );
  }

  if (!content) {
    return null;
  }

  return (
    <div
      ref={containerRef}
      className="prose prose-invert prose-sm max-w-none overflow-y-auto"
      style={{ maxHeight: '400px' }}
    >
      <ReactMarkdown>{content}</ReactMarkdown>
      {loading && <span className="inline-block h-4 w-2 animate-pulse bg-slate-400" />}
    </div>
  );
}

function LoadingSpinner() {
  return (
    <svg
      className="h-5 w-5 animate-spin"
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
      aria-hidden="true"
    >
      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
      <path
        className="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
      />
    </svg>
  );
}

function ErrorIcon() {
  return (
    <svg
      className="h-5 w-5 text-red-400"
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 20 20"
      fill="currentColor"
      aria-hidden="true"
    >
      <path
        fillRule="evenodd"
        d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z"
        clipRule="evenodd"
      />
    </svg>
  );
}

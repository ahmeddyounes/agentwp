import { forwardRef, type KeyboardEvent, type ChangeEvent } from 'react';

interface PromptInputProps {
  value: string;
  onChange: (value: string) => void;
  onSubmit: () => void;
  onKeyDown?: (event: KeyboardEvent<HTMLTextAreaElement>) => void;
  onFocus?: () => void;
  onBlur?: () => void;
  placeholder?: string;
  disabled?: boolean;
  loading?: boolean;
  rows?: number;
}

export const PromptInput = forwardRef<HTMLTextAreaElement, PromptInputProps>(function PromptInput(
  {
    value,
    onChange,
    onSubmit,
    onKeyDown,
    onFocus,
    onBlur,
    placeholder = 'Ask me anything...',
    disabled = false,
    loading = false,
    rows = 1,
  },
  ref,
) {
  const handleChange = (event: ChangeEvent<HTMLTextAreaElement>) => {
    onChange(event.target.value);
  };

  const handleKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
    // Submit on Enter (without Shift)
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      if (!disabled && !loading && value.trim()) {
        onSubmit();
      }
    }

    // Forward other key events
    onKeyDown?.(event);
  };

  return (
    <div className="relative flex-1">
      <textarea
        ref={ref}
        value={value}
        onChange={handleChange}
        onKeyDown={handleKeyDown}
        onFocus={onFocus}
        onBlur={onBlur}
        placeholder={placeholder}
        disabled={disabled || loading}
        rows={rows}
        className="w-full resize-none rounded-lg border border-slate-700/60 bg-slate-900/60 px-4 py-3 text-sm text-white placeholder-slate-500 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
        aria-label="Enter your prompt"
        aria-describedby={loading ? 'prompt-loading' : undefined}
      />
      {loading && (
        <span id="prompt-loading" className="sr-only">
          Processing your request...
        </span>
      )}
    </div>
  );
});

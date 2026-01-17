interface OfflineBannerProps {
  text?: string;
}

export function OfflineBanner({ text = 'Agent Offline' }: OfflineBannerProps) {
  return (
    <div
      className="flex items-center gap-2 rounded-lg bg-amber-500/10 px-3 py-2 text-sm text-amber-400"
      role="status"
      aria-live="polite"
    >
      <span className="h-2 w-2 rounded-full bg-amber-500" aria-hidden="true" />
      <span>{text}</span>
    </div>
  );
}

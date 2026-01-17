interface VoiceControlsProps {
  isSupported: boolean;
  isListening: boolean;
  isSpeaking: boolean;
  ttsSupported: boolean;
  onStartListening: () => void;
  onStopListening: () => void;
  onSpeak: () => void;
  onStopSpeaking: () => void;
  disabled?: boolean;
  hasResponse?: boolean;
}

export function VoiceControls({
  isSupported,
  isListening,
  isSpeaking,
  ttsSupported,
  onStartListening,
  onStopListening,
  onSpeak,
  onStopSpeaking,
  disabled = false,
  hasResponse = false,
}: VoiceControlsProps) {
  if (!isSupported && !ttsSupported) {
    return null;
  }

  return (
    <div className="flex items-center gap-2">
      {isSupported && (
        <button
          onClick={isListening ? onStopListening : onStartListening}
          disabled={disabled}
          className={`rounded-lg p-2 transition-colors ${
            isListening
              ? 'bg-red-500/20 text-red-400 hover:bg-red-500/30'
              : 'bg-slate-800/60 text-slate-400 hover:bg-slate-800 hover:text-slate-200'
          } disabled:cursor-not-allowed disabled:opacity-50`}
          aria-label={isListening ? 'Stop listening' : 'Start voice input'}
          aria-pressed={isListening}
        >
          {isListening ? <MicActiveIcon /> : <MicIcon />}
        </button>
      )}

      {ttsSupported && hasResponse && (
        <button
          onClick={isSpeaking ? onStopSpeaking : onSpeak}
          disabled={disabled}
          className={`rounded-lg p-2 transition-colors ${
            isSpeaking
              ? 'bg-indigo-500/20 text-indigo-400 hover:bg-indigo-500/30'
              : 'bg-slate-800/60 text-slate-400 hover:bg-slate-800 hover:text-slate-200'
          } disabled:cursor-not-allowed disabled:opacity-50`}
          aria-label={isSpeaking ? 'Stop speaking' : 'Read response aloud'}
          aria-pressed={isSpeaking}
        >
          {isSpeaking ? <SpeakerActiveIcon /> : <SpeakerIcon />}
        </button>
      )}
    </div>
  );
}

function MicIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" className="h-5 w-5">
      <path
        d="M12 2a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <path
        d="M19 10v1a7 7 0 0 1-14 0v-1M12 18v4M8 22h8"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function MicActiveIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" className="h-5 w-5 animate-pulse">
      <path
        d="M12 2a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"
        fill="currentColor"
        stroke="currentColor"
        strokeWidth="1.8"
      />
      <path
        d="M19 10v1a7 7 0 0 1-14 0v-1M12 18v4M8 22h8"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function SpeakerIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" className="h-5 w-5">
      <path
        d="M11 5L6 9H2v6h4l5 4V5z"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <path
        d="M15.54 8.46a5 5 0 0 1 0 7.07M19.07 4.93a10 10 0 0 1 0 14.14"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function SpeakerActiveIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" className="h-5 w-5">
      <path
        d="M11 5L6 9H2v6h4l5 4V5z"
        fill="currentColor"
        stroke="currentColor"
        strokeWidth="1.8"
      />
      <path
        d="M15.54 8.46a5 5 0 0 1 0 7.07M19.07 4.93a10 10 0 0 1 0 14.14"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
        className="animate-pulse"
      />
    </svg>
  );
}

import { useEffect, useRef, useCallback } from 'react';
import { useVoiceStore } from '../stores/useVoiceStore';
import { getSpeechErrorMessage } from '../utils/speech';

// Web Speech API type definitions
interface SpeechRecognitionAlternative {
  readonly transcript: string;
  readonly confidence: number;
}

interface SpeechRecognitionResult {
  readonly isFinal: boolean;
  readonly length: number;
  [index: number]: SpeechRecognitionAlternative;
}

interface SpeechRecognitionResultList {
  readonly length: number;
  [index: number]: SpeechRecognitionResult;
}

interface SpeechRecognitionEventResult extends Event {
  readonly resultIndex: number;
  readonly results: SpeechRecognitionResultList;
}

interface SpeechRecognitionErrorEventResult extends Event {
  readonly error: string;
  readonly message: string;
}

interface SpeechRecognitionInstance extends EventTarget {
  lang: string;
  continuous: boolean;
  interimResults: boolean;
  maxAlternatives: number;
  onaudiostart: ((ev: Event) => void) | null;
  onaudioend: ((ev: Event) => void) | null;
  onend: ((ev: Event) => void) | null;
  onerror: ((ev: SpeechRecognitionErrorEventResult) => void) | null;
  onresult: ((ev: SpeechRecognitionEventResult) => void) | null;
  onstart: ((ev: Event) => void) | null;
  abort(): void;
  start(): void;
  stop(): void;
}

interface SpeechRecognitionCtor {
  new (): SpeechRecognitionInstance;
}

/**
 * Speech Recognition hook options
 */
export interface UseSpeechRecognitionOptions {
  lang?: string;
  continuous?: boolean;
  interimResults?: boolean;
  wakeWord?: string;
  wakeWordEnabled?: boolean;
  autoRestart?: boolean;
  onWakeWord?: (data: { wakeWord: string; transcript: string }) => void;
}

// Get the SpeechRecognition constructor
const getSpeechRecognitionConstructor = (): SpeechRecognitionCtor | null => {
  if (typeof window === 'undefined') return null;
  const win = window as Window & {
    SpeechRecognition?: SpeechRecognitionCtor;
    webkitSpeechRecognition?: SpeechRecognitionCtor;
  };
  return win.SpeechRecognition ?? win.webkitSpeechRecognition ?? null;
};

// Normalize wake word
const normalizeWakeWord = (wakeWord: string | undefined): string => {
  if (typeof wakeWord !== 'string') return '';
  return wakeWord.trim().toLowerCase();
};

// Append transcript helper
const appendTranscript = (base: string, addition: string): string => {
  const trimmed = addition?.trim() || '';
  if (!trimmed) return base || '';
  if (!base) return trimmed;
  return `${base} ${trimmed}`.trim();
};

/**
 * React hook for speech recognition with wake word support.
 * Integrates with useVoiceStore for state management.
 */
export function useSpeechRecognition(options: UseSpeechRecognitionOptions = {}) {
  const {
    lang = 'en-US',
    continuous = true,
    interimResults = true,
    wakeWord = 'hey agent',
    wakeWordEnabled = false,
    autoRestart = false,
    onWakeWord,
  } = options;

  // Refs for instance and state
  const recognitionRef = useRef<SpeechRecognitionInstance | null>(null);
  const shouldRestartRef = useRef(false);
  const commandActiveRef = useRef(!wakeWordEnabled);
  const finalTranscriptRef = useRef('');
  const restartTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Refs for callbacks to avoid recreating recognition instance
  const onWakeWordRef = useRef(onWakeWord);
  const wakeWordRef = useRef(wakeWord);
  const wakeWordEnabledRef = useRef(wakeWordEnabled);
  const autoRestartRef = useRef(autoRestart);

  // Keep refs in sync with props
  useEffect(() => {
    onWakeWordRef.current = onWakeWord;
  }, [onWakeWord]);

  useEffect(() => {
    wakeWordRef.current = wakeWord;
  }, [wakeWord]);

  useEffect(() => {
    wakeWordEnabledRef.current = wakeWordEnabled;
    commandActiveRef.current = !wakeWordEnabled;
  }, [wakeWordEnabled]);

  useEffect(() => {
    autoRestartRef.current = autoRestart;
  }, [autoRestart]);

  const {
    isSupported,
    isListening,
    setIsListening,
    setError,
    setInterimTranscript,
    setFinalTranscript,
    setWakeWordDetected,
    clearTranscripts,
  } = useVoiceStore();

  // Clear restart timeout helper
  const clearRestartTimeout = useCallback(() => {
    if (restartTimeoutRef.current) {
      clearTimeout(restartTimeoutRef.current);
      restartTimeoutRef.current = null;
    }
  }, []);

  // Initialize recognition - only depends on config that requires new instance
  useEffect(() => {
    const SpeechRecognitionCtor = getSpeechRecognitionConstructor();
    if (!SpeechRecognitionCtor) return;

    const recognition = new SpeechRecognitionCtor();
    recognition.lang = lang;
    recognition.continuous = continuous;
    recognition.interimResults = interimResults;
    recognition.maxAlternatives = 1;

    // Handle start
    recognition.onstart = () => {
      setIsListening(true);
    };

    // Handle end - uses refs to avoid recreation
    recognition.onend = () => {
      // Check if we should auto-restart before setting isListening to false
      const shouldAutoRestart =
        shouldRestartRef.current && autoRestartRef.current && recognitionRef.current;

      if (shouldAutoRestart) {
        // Don't set isListening to false during restart to avoid UI flicker
        clearRestartTimeout();
        restartTimeoutRef.current = setTimeout(() => {
          if (shouldRestartRef.current && recognitionRef.current) {
            try {
              recognitionRef.current.start();
            } catch {
              // Restart failed, now set isListening to false
              setIsListening(false);
            }
          } else {
            // Conditions changed, set isListening to false
            setIsListening(false);
          }
        }, 250);
      } else {
        setIsListening(false);
      }
    };

    // Handle error - uses refs to avoid recreation
    recognition.onerror = (event: SpeechRecognitionErrorEventResult) => {
      // Don't show 'aborted' error - it's expected when calling abort()
      if (event.error !== 'aborted') {
        const message = getSpeechErrorMessage(event.error);
        setError(message);
      }

      // Don't auto-restart on certain errors
      if (['not-allowed', 'audio-capture', 'service-not-allowed'].includes(event.error)) {
        shouldRestartRef.current = false;
      }
    };

    // Handle result - uses refs to avoid recreation
    recognition.onresult = (event: SpeechRecognitionEventResult) => {
      if (!event?.results) return;

      const currentWakeWord = wakeWordRef.current;
      const currentWakeWordEnabled = wakeWordEnabledRef.current;
      const currentOnWakeWord = onWakeWordRef.current;

      const wakeWordNormalized = normalizeWakeWord(currentWakeWord);
      let interim = '';
      let finalText = '';
      let wakeDetected = false;

      for (let i = event.resultIndex || 0; i < event.results.length; i++) {
        const result = event.results[i];
        if (!result?.[0]) continue;

        const transcript = (result[0].transcript || '').trim();
        if (!transcript) continue;

        if (result.isFinal) {
          // Check for wake word if enabled
          if (currentWakeWordEnabled && !commandActiveRef.current) {
            if (!wakeWordNormalized) {
              commandActiveRef.current = true;
            } else {
              const lower = transcript.toLowerCase();
              const wakeIndex = lower.indexOf(wakeWordNormalized);
              if (wakeIndex === -1) continue;

              wakeDetected = true;
              commandActiveRef.current = true;
              finalTranscriptRef.current = '';
              setWakeWordDetected(true);

              const afterWake = transcript.slice(wakeIndex + wakeWordNormalized.length).trim();
              if (afterWake) {
                finalText = appendTranscript(finalText, afterWake);
              }
              continue;
            }
          }
          finalText = appendTranscript(finalText, transcript);
        } else if (!currentWakeWordEnabled || commandActiveRef.current) {
          interim = appendTranscript(interim, transcript);
        }
      }

      // Fire wake word callback
      if (wakeDetected && currentOnWakeWord) {
        currentOnWakeWord({ wakeWord: currentWakeWord, transcript: finalText });
      }

      // Update interim transcript (always update to clear stale values)
      setInterimTranscript(interim);

      // Update final transcript
      if (finalText) {
        finalTranscriptRef.current = appendTranscript(finalTranscriptRef.current, finalText);
        setFinalTranscript(finalTranscriptRef.current);
      }
    };

    recognitionRef.current = recognition;

    return () => {
      shouldRestartRef.current = false;
      clearRestartTimeout();
      recognition.onstart = null;
      recognition.onend = null;
      recognition.onerror = null;
      recognition.onresult = null;
      try {
        recognition.stop();
      } catch {
        // Ignore cleanup errors
      }
      recognitionRef.current = null;
    };
  }, [
    lang,
    continuous,
    interimResults,
    setIsListening,
    setError,
    setInterimTranscript,
    setFinalTranscript,
    setWakeWordDetected,
    clearRestartTimeout,
  ]);

  // Start listening
  const start = useCallback(() => {
    if (!recognitionRef.current || !isSupported) return false;

    // Guard against double-start
    if (isListening) return false;

    shouldRestartRef.current = true;
    commandActiveRef.current = !wakeWordEnabledRef.current;
    finalTranscriptRef.current = '';
    clearTranscripts();
    setError('');
    setWakeWordDetected(false);

    try {
      recognitionRef.current.start();
      return true;
    } catch (err) {
      const message = getSpeechErrorMessage(err as Error);
      setError(message);
      return false;
    }
  }, [isSupported, isListening, clearTranscripts, setError, setWakeWordDetected]);

  // Stop listening
  const stop = useCallback(() => {
    if (!recognitionRef.current) return;

    shouldRestartRef.current = false;
    clearRestartTimeout();
    setInterimTranscript('');
    try {
      recognitionRef.current.stop();
    } catch {
      // Ignore stop errors
    }
  }, [clearRestartTimeout, setInterimTranscript]);

  // Abort listening (immediate stop)
  const abort = useCallback(() => {
    if (!recognitionRef.current) return;

    shouldRestartRef.current = false;
    clearRestartTimeout();
    setInterimTranscript('');
    try {
      recognitionRef.current.abort();
    } catch {
      // Ignore abort errors
    }
    setIsListening(false);
  }, [setIsListening, clearRestartTimeout, setInterimTranscript]);

  // Reset transcripts
  const resetTranscripts = useCallback(() => {
    finalTranscriptRef.current = '';
    clearTranscripts();
  }, [clearTranscripts]);

  return {
    isSupported,
    isListening,
    start,
    stop,
    abort,
    resetTranscripts,
  };
}

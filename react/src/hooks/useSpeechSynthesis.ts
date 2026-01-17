import { useCallback, useEffect, useState } from 'react';
import { useVoiceStore } from '../stores/useVoiceStore';

/**
 * Speech Synthesis hook options
 */
export interface UseSpeechSynthesisOptions {
  lang?: string;
  rate?: number;
  pitch?: number;
  volume?: number;
  voiceName?: string;
}

/**
 * React hook for text-to-speech synthesis.
 * Integrates with useVoiceStore for state management.
 */
export function useSpeechSynthesis(options: UseSpeechSynthesisOptions = {}) {
  const { lang = 'en-US', rate = 1, pitch = 1, volume = 1, voiceName = '' } = options;

  const [voices, setVoices] = useState<SpeechSynthesisVoice[]>([]);

  const { ttsSupported, isSpeaking, setIsSpeaking, setError } = useVoiceStore();

  // Load available voices
  useEffect(() => {
    if (!ttsSupported || typeof window === 'undefined') return;

    const loadVoices = () => {
      const availableVoices = window.speechSynthesis.getVoices();
      setVoices(availableVoices);
    };

    // Load voices immediately if available
    loadVoices();

    // Also listen for voiceschanged event (Chrome loads voices async)
    window.speechSynthesis.addEventListener('voiceschanged', loadVoices);

    return () => {
      window.speechSynthesis.removeEventListener('voiceschanged', loadVoices);
    };
  }, [ttsSupported]);

  // Resolve voice by name
  const resolveVoice = useCallback(
    (name: string): SpeechSynthesisVoice | null => {
      if (!name) return null;
      return voices.find((v) => v.name === name) || null;
    },
    [voices],
  );

  // Speak text
  const speak = useCallback(
    (text: string, overrides: Partial<UseSpeechSynthesisOptions> = {}): boolean => {
      if (!ttsSupported || typeof window === 'undefined') return false;

      const trimmedText = text?.trim();
      if (!trimmedText) return false;

      // Clear any previous error
      setError('');

      // Cancel any ongoing speech and set speaking state synchronously
      // to avoid flicker (cancel triggers onend which sets false, then onstart sets true)
      window.speechSynthesis.cancel();
      setIsSpeaking(true);

      const utterance = new SpeechSynthesisUtterance(trimmedText);

      // Apply options with overrides
      utterance.lang = overrides.lang ?? lang;
      utterance.rate = overrides.rate ?? rate;
      utterance.pitch = overrides.pitch ?? pitch;
      utterance.volume = overrides.volume ?? volume;

      // Set voice if specified
      const voice = resolveVoice(overrides.voiceName ?? voiceName);
      if (voice) {
        utterance.voice = voice;
      }

      // Event handlers
      utterance.onstart = () => {
        setIsSpeaking(true);
      };

      utterance.onend = () => {
        setIsSpeaking(false);
      };

      utterance.onerror = (event) => {
        setIsSpeaking(false);
        if (event.error !== 'canceled') {
          setError(`Speech synthesis error: ${event.error}`);
        }
      };

      window.speechSynthesis.speak(utterance);
      return true;
    },
    [ttsSupported, lang, rate, pitch, volume, voiceName, resolveVoice, setIsSpeaking, setError],
  );

  // Stop speaking
  const stop = useCallback(() => {
    if (typeof window === 'undefined') return;
    window.speechSynthesis.cancel();
    setIsSpeaking(false);
  }, [setIsSpeaking]);

  // Pause speaking
  const pause = useCallback(() => {
    if (typeof window === 'undefined') return;
    window.speechSynthesis.pause();
  }, []);

  // Resume speaking
  const resume = useCallback(() => {
    if (typeof window === 'undefined') return;
    window.speechSynthesis.resume();
  }, []);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      if (typeof window !== 'undefined') {
        window.speechSynthesis.cancel();
      }
    };
  }, []);

  return {
    isSupported: ttsSupported,
    isSpeaking,
    voices,
    speak,
    stop,
    pause,
    resume,
  };
}

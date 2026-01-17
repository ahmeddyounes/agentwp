import { useCallback } from 'react';
import { useVoiceStore } from '../stores/useVoiceStore';
import { useSpeechRecognition, type UseSpeechRecognitionOptions } from './useSpeechRecognition';
import { useSpeechSynthesis, type UseSpeechSynthesisOptions } from './useSpeechSynthesis';

/**
 * Combined voice hook options
 */
export interface UseVoiceOptions {
  recognition?: UseSpeechRecognitionOptions;
  synthesis?: UseSpeechSynthesisOptions;
}

/**
 * Unified voice hook that combines speech recognition and synthesis.
 * Provides a simple interface for voice input and text-to-speech output.
 */
export function useVoice(options: UseVoiceOptions = {}) {
  const { recognition: recognitionOptions = {}, synthesis: synthesisOptions = {} } = options;

  // Get store state
  const {
    isSupported: sttSupported,
    ttsSupported,
    isListening,
    isSpeaking,
    error,
    interimTranscript,
    finalTranscript,
    wakeWordEnabled,
    wakeWordDetected,
    setWakeWordEnabled,
    reset,
  } = useVoiceStore();

  // Speech recognition
  const {
    start: startListening,
    stop: stopListening,
    abort: abortListening,
    resetTranscripts,
  } = useSpeechRecognition(recognitionOptions);

  // Speech synthesis
  const {
    voices,
    speak,
    stop: stopSpeaking,
    pause: pauseSpeaking,
    resume: resumeSpeaking,
  } = useSpeechSynthesis(synthesisOptions);

  // Toggle listening state
  const toggleListening = useCallback(() => {
    if (isListening) {
      stopListening();
    } else {
      startListening();
    }
  }, [isListening, startListening, stopListening]);

  // Speak response (convenience method)
  const speakResponse = useCallback(
    (text: string) => {
      if (!text?.trim()) return false;
      return speak(text);
    },
    [speak],
  );

  // Toggle speaking state
  const toggleSpeaking = useCallback(
    (text: string) => {
      if (isSpeaking) {
        stopSpeaking();
      } else {
        speakResponse(text);
      }
    },
    [isSpeaking, stopSpeaking, speakResponse],
  );

  // Reset all voice state
  const resetAll = useCallback(() => {
    stopListening();
    stopSpeaking();
    resetTranscripts();
    reset();
  }, [stopListening, stopSpeaking, resetTranscripts, reset]);

  return {
    // Support flags
    sttSupported,
    ttsSupported,

    // State
    isListening,
    isSpeaking,
    error,
    interimTranscript,
    finalTranscript,
    wakeWordEnabled,
    wakeWordDetected,

    // Recognition controls
    startListening,
    stopListening,
    abortListening,
    toggleListening,
    resetTranscripts,

    // Synthesis controls
    voices,
    speak,
    speakResponse,
    stopSpeaking,
    pauseSpeaking,
    resumeSpeaking,
    toggleSpeaking,

    // Wake word
    setWakeWordEnabled,

    // Reset
    resetAll,
  };
}

// Re-export individual hooks for granular use
export { useSpeechRecognition, type UseSpeechRecognitionOptions } from './useSpeechRecognition';
export { useSpeechSynthesis, type UseSpeechSynthesisOptions } from './useSpeechSynthesis';

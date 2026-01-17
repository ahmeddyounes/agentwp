import { create } from 'zustand';

interface VoiceState {
  isSupported: boolean;
  ttsSupported: boolean;
  isListening: boolean;
  isSpeaking: boolean;
  error: string;
  interimTranscript: string;
  finalTranscript: string;
  wakeWordEnabled: boolean;
  wakeWordDetected: boolean;
}

interface VoiceActions {
  setIsListening: (listening: boolean) => void;
  setIsSpeaking: (speaking: boolean) => void;
  setError: (error: string) => void;
  setInterimTranscript: (transcript: string) => void;
  setFinalTranscript: (transcript: string) => void;
  setWakeWordEnabled: (enabled: boolean) => void;
  setWakeWordDetected: (detected: boolean) => void;
  clearTranscripts: () => void;
  reset: () => void;
}

// Lazy check functions - called when store is first accessed, not at module load
const checkSpeechRecognitionSupport = (): boolean => {
  if (typeof window === 'undefined') return false;
  const win = window as Window & {
    SpeechRecognition?: unknown;
    webkitSpeechRecognition?: unknown;
  };
  return !!(win.SpeechRecognition || win.webkitSpeechRecognition);
};

const checkSpeechSynthesisSupport = (): boolean => {
  if (typeof window === 'undefined') return false;
  return 'speechSynthesis' in window;
};

// Create store with lazy initialization for SSR safety
export const useVoiceStore = create<VoiceState & VoiceActions>((set) => ({
  // Lazy evaluate support - computed when store is first accessed (client-side)
  isSupported: checkSpeechRecognitionSupport(),
  ttsSupported: checkSpeechSynthesisSupport(),
  isListening: false,
  isSpeaking: false,
  error: '',
  interimTranscript: '',
  finalTranscript: '',
  wakeWordEnabled: false,
  wakeWordDetected: false,

  setIsListening: (isListening) => set({ isListening }),

  setIsSpeaking: (isSpeaking) => set({ isSpeaking }),

  setError: (error) => set({ error }),

  setInterimTranscript: (interimTranscript) => set({ interimTranscript }),

  setFinalTranscript: (finalTranscript) => set({ finalTranscript }),

  setWakeWordEnabled: (wakeWordEnabled) => set({ wakeWordEnabled }),

  setWakeWordDetected: (wakeWordDetected) => set({ wakeWordDetected }),

  clearTranscripts: () => set({ interimTranscript: '', finalTranscript: '' }),

  reset: () =>
    set({
      isListening: false,
      isSpeaking: false,
      error: '',
      interimTranscript: '',
      finalTranscript: '',
      wakeWordDetected: false,
    }),
}));

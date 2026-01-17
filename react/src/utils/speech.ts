/**
 * Speech recognition error utilities.
 */

export const SPEECH_ERROR_MESSAGES: Record<string, string> = {
  'not-allowed': 'Microphone access is blocked. Check browser permissions.',
  'audio-capture': 'No microphone was found. Connect a mic and try again.',
  'no-speech': 'No speech detected. Try again and speak a little louder.',
  aborted: 'Voice capture stopped.',
  network: 'Speech recognition network error. Try again.',
  'service-not-allowed': 'Speech recognition is disabled by the browser.',
  'language-not-supported': 'Speech recognition language not supported.',
};

interface SpeechError {
  error?: string;
  name?: string;
  message?: string;
}

export const getSpeechErrorMessage = (error: string | SpeechError | null | undefined): string => {
  if (!error) {
    return '';
  }
  if (typeof error === 'string') {
    return SPEECH_ERROR_MESSAGES[error] || error;
  }
  const code = `${error.error || error.name || ''}`.toLowerCase();
  return SPEECH_ERROR_MESSAGES[code] || error.message || 'Speech recognition error.';
};

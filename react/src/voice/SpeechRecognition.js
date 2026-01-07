const getSpeechRecognitionConstructor = () => {
  if (typeof window === 'undefined') {
    return null;
  }
  return window.SpeechRecognition || window.webkitSpeechRecognition || null;
};

const normalizeWakeWord = (wakeWord) => {
  if (typeof wakeWord !== 'string') {
    return '';
  }
  return wakeWord.trim();
};

const appendTranscript = (base, addition) => {
  if (!addition) {
    return base || '';
  }
  const trimmed = addition.trim();
  if (!trimmed) {
    return base || '';
  }
  if (!base) {
    return trimmed;
  }
  return `${base} ${trimmed}`.trim();
};

export class SpeechRecognition {
  static isSupported() {
    return Boolean(getSpeechRecognitionConstructor());
  }

  constructor(options = {}) {
    this.options = {
      lang: 'en-US',
      interimResults: true,
      continuous: true,
      maxAlternatives: 1,
      wakeWord: 'hey agent',
      wakeWordEnabled: false,
      autoRestart: false,
      onStart: null,
      onEnd: null,
      onError: null,
      onResult: null,
      onInterim: null,
      onFinal: null,
      onWakeWord: null,
      ...options,
    };
    this.recognition = null;
    this.isListening = false;
    this.commandActive = !this.options.wakeWordEnabled;
    this.finalTranscript = '';
    this.interimTranscript = '';
    this.lastError = null;
    this._shouldRestart = false;
    this._setup();
  }

  _setup() {
    const RecognitionCtor = getSpeechRecognitionConstructor();
    if (!RecognitionCtor) {
      return;
    }
    const recognition = new RecognitionCtor();
    recognition.lang = this.options.lang;
    recognition.interimResults = Boolean(this.options.interimResults);
    recognition.continuous = Boolean(this.options.continuous);
    recognition.maxAlternatives = Number.isFinite(this.options.maxAlternatives)
      ? this.options.maxAlternatives
      : 1;
    recognition.onstart = this._handleStart.bind(this);
    recognition.onend = this._handleEnd.bind(this);
    recognition.onerror = this._handleError.bind(this);
    recognition.onresult = this._handleResult.bind(this);
    this.recognition = recognition;
  }

  _handleStart() {
    this.isListening = true;
    if (typeof this.options.onStart === 'function') {
      this.options.onStart();
    }
  }

  _handleEnd() {
    this.isListening = false;
    if (typeof this.options.onEnd === 'function') {
      this.options.onEnd();
    }
    if (this._shouldRestart && this.options.autoRestart) {
      this._restart();
    }
  }

  _handleError(event) {
    this.lastError = event;
    if (typeof this.options.onError === 'function') {
      this.options.onError(event);
    }
  }

  _handleResult(event) {
    if (!event || !event.results) {
      return;
    }
    const wakeWord = normalizeWakeWord(this.options.wakeWord);
    const wakeWordLower = wakeWord.toLowerCase();
    let interim = '';
    let finalText = '';
    let wakeWordDetected = false;

    for (let index = event.resultIndex || 0; index < event.results.length; index += 1) {
      const result = event.results[index];
      if (!result || !result[0]) {
        continue;
      }
      const transcript = `${result[0].transcript || ''}`.trim();
      if (!transcript) {
        continue;
      }

      if (result.isFinal) {
        if (this.options.wakeWordEnabled && !this.commandActive) {
          if (!wakeWordLower) {
            this.commandActive = true;
          } else {
            const lower = transcript.toLowerCase();
            const wakeIndex = lower.indexOf(wakeWordLower);
            if (wakeIndex === -1) {
              continue;
            }
            wakeWordDetected = true;
            this.commandActive = true;
            this.finalTranscript = '';
            const afterWake = transcript.slice(wakeIndex + wakeWordLower.length).trim();
            if (afterWake) {
              finalText = appendTranscript(finalText, afterWake);
            }
            continue;
          }
        }
        finalText = appendTranscript(finalText, transcript);
      } else if (!this.options.wakeWordEnabled || this.commandActive) {
        interim = appendTranscript(interim, transcript);
      }
    }

    if (wakeWordDetected && typeof this.options.onWakeWord === 'function') {
      this.options.onWakeWord({ wakeWord, transcript: finalText });
    }

    if (interim) {
      this.interimTranscript = interim;
      if (typeof this.options.onInterim === 'function') {
        this.options.onInterim(interim);
      }
    }

    if (finalText) {
      this.finalTranscript = appendTranscript(this.finalTranscript, finalText);
      this.interimTranscript = '';
      if (typeof this.options.onFinal === 'function') {
        this.options.onFinal(finalText, {
          transcript: this.finalTranscript,
          wakeWordDetected,
        });
      }
    }

    if (interim || finalText) {
      if (typeof this.options.onResult === 'function') {
        this.options.onResult({
          interim,
          final: finalText,
          transcript: this.finalTranscript,
          wakeWordDetected,
          commandActive: this.commandActive,
        });
      }
    }
  }

  _restart() {
    if (!this.recognition || typeof window === 'undefined') {
      return;
    }
    window.setTimeout(() => {
      if (!this._shouldRestart || !this.recognition) {
        return;
      }
      try {
        this.recognition.start();
      } catch (error) {
        if (typeof this.options.onError === 'function') {
          this.options.onError(error);
        }
      }
    }, 250);
  }

  start() {
    if (!this.recognition) {
      return false;
    }
    this._shouldRestart = true;
    try {
      this.recognition.start();
      return true;
    } catch (error) {
      if (typeof this.options.onError === 'function') {
        this.options.onError(error);
      }
      return false;
    }
  }

  stop() {
    if (!this.recognition) {
      return;
    }
    this._shouldRestart = false;
    try {
      this.recognition.stop();
    } catch (error) {
      if (typeof this.options.onError === 'function') {
        this.options.onError(error);
      }
    }
  }

  abort() {
    if (!this.recognition) {
      return;
    }
    this._shouldRestart = false;
    try {
      this.recognition.abort();
    } catch (error) {
      if (typeof this.options.onError === 'function') {
        this.options.onError(error);
      }
    }
  }

  resetTranscripts() {
    this.finalTranscript = '';
    this.interimTranscript = '';
  }

  resetCommandState() {
    this.commandActive = !this.options.wakeWordEnabled;
  }

  setWakeWordEnabled(enabled) {
    this.options.wakeWordEnabled = Boolean(enabled);
    this.resetCommandState();
  }

  setWakeWord(wakeWord) {
    this.options.wakeWord = normalizeWakeWord(wakeWord);
  }

  destroy() {
    if (!this.recognition) {
      return;
    }
    this._shouldRestart = false;
    this.recognition.onstart = null;
    this.recognition.onend = null;
    this.recognition.onerror = null;
    this.recognition.onresult = null;
    this.stop();
    this.recognition = null;
  }
}

export const attachSpeechRecognitionNamespace = (root = null) => {
  if (typeof window === 'undefined') {
    return;
  }
  const container = root || (window.AgentWP = window.AgentWP || {});
  if (!container.Voice) {
    container.Voice = {};
  }
  container.Voice.SpeechRecognition = SpeechRecognition;
};

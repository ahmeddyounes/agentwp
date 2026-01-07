const getSpeechSynthesis = () => {
  if (typeof window === 'undefined') {
    return null;
  }
  return window.speechSynthesis || null;
};

const getUtteranceConstructor = () => {
  if (typeof window === 'undefined') {
    return null;
  }
  return window.SpeechSynthesisUtterance || null;
};

export class SpeechSynthesis {
  static isSupported() {
    return Boolean(getSpeechSynthesis() && getUtteranceConstructor());
  }

  constructor(options = {}) {
    this.options = {
      lang: 'en-US',
      rate: 1,
      pitch: 1,
      volume: 1,
      voiceName: '',
      onStart: null,
      onEnd: null,
      onError: null,
      ...options,
    };
    this.synth = getSpeechSynthesis();
    this.utterance = null;
    this.isSpeaking = false;
  }

  getVoices() {
    if (!this.synth || typeof this.synth.getVoices !== 'function') {
      return [];
    }
    return this.synth.getVoices();
  }

  _resolveVoice(voiceName) {
    if (!voiceName) {
      return null;
    }
    const voices = this.getVoices();
    return voices.find((voice) => voice.name === voiceName) || null;
  }

  speak(text, overrides = {}) {
    if (!this.synth) {
      return false;
    }
    const Utterance = getUtteranceConstructor();
    if (!Utterance) {
      return false;
    }
    const value = typeof text === 'string' ? text.trim() : '';
    if (!value) {
      return false;
    }
    const config = { ...this.options, ...overrides };
    const utterance = new Utterance(value);
    utterance.lang = config.lang || 'en-US';
    utterance.rate = Number.isFinite(config.rate) ? config.rate : 1;
    utterance.pitch = Number.isFinite(config.pitch) ? config.pitch : 1;
    utterance.volume = Number.isFinite(config.volume) ? config.volume : 1;
    const voice = this._resolveVoice(config.voiceName);
    if (voice) {
      utterance.voice = voice;
    }
    utterance.onstart = () => {
      this.isSpeaking = true;
      if (typeof config.onStart === 'function') {
        config.onStart();
      }
    };
    utterance.onend = () => {
      this.isSpeaking = false;
      if (typeof config.onEnd === 'function') {
        config.onEnd();
      }
    };
    utterance.onerror = (event) => {
      this.isSpeaking = false;
      if (typeof config.onError === 'function') {
        config.onError(event);
      }
    };
    this.synth.cancel();
    this.utterance = utterance;
    this.synth.speak(utterance);
    return true;
  }

  stop() {
    if (!this.synth) {
      return;
    }
    this.synth.cancel();
    this.isSpeaking = false;
  }
}

export const attachSpeechSynthesisNamespace = (root = null) => {
  if (typeof window === 'undefined') {
    return;
  }
  const container = root || (window.AgentWP = window.AgentWP || {});
  if (!container.Voice) {
    container.Voice = {};
  }
  container.Voice.SpeechSynthesis = SpeechSynthesis;
};

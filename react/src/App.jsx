import { useCallback, useEffect, useRef, useState } from 'react';
import ReactMarkdown from 'react-markdown';

const OPEN_STATE_KEY = 'agentwp-command-deck-open';
const ADMIN_TRIGGER_SELECTORS = [
  '#wp-admin-bar-agentwp',
  '[data-agentwp-command-deck]',
  '#agentwp-command-deck',
];
const REST_PATH = '/agentwp/v1/intent';
const FOCUSABLE_SELECTORS = [
  'a[href]',
  'button:not([disabled])',
  'textarea:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
];

const getInitialOpenState = () => {
  if (typeof window === 'undefined') {
    return false;
  }
  try {
    return window.sessionStorage.getItem(OPEN_STATE_KEY) === 'true';
  } catch (error) {
    return false;
  }
};

const isEditableTarget = (target) => {
  if (!(target instanceof HTMLElement)) {
    return false;
  }
  const tagName = target.tagName.toLowerCase();
  if (['input', 'textarea', 'select'].includes(tagName)) {
    return true;
  }
  if (target.isContentEditable) {
    return true;
  }
  return Boolean(target.closest('[contenteditable="true"]'));
};

const getRestEndpoint = () => {
  if (typeof window === 'undefined') {
    return REST_PATH;
  }
  const root = window.agentwpSettings?.root || window.wpApiSettings?.root;
  if (!root) {
    return REST_PATH;
  }
  return `${root.replace(/\/$/, '')}${REST_PATH}`;
};

const getRestNonce = () => {
  if (typeof window === 'undefined') {
    return null;
  }
  return window.agentwpSettings?.nonce || window.wpApiSettings?.nonce || null;
};

const getNow = () => {
  if (typeof performance !== 'undefined' && typeof performance.now === 'function') {
    return performance.now();
  }
  return Date.now();
};

const getFocusableElements = (container) => {
  if (!container) {
    return [];
  }
  return Array.from(container.querySelectorAll(FOCUSABLE_SELECTORS.join(','))).filter(
    (element) => !element.hasAttribute('disabled') && element.tabIndex !== -1
  );
};

export default function App() {
  const [isOpen, setIsOpen] = useState(getInitialOpenState);
  const [prompt, setPrompt] = useState('');
  const [loading, setLoading] = useState(false);
  const [response, setResponse] = useState('');
  const [errorMessage, setErrorMessage] = useState('');
  const [metrics, setMetrics] = useState({ latencyMs: null, tokenCost: null });
  const inputRef = useRef(null);
  const modalRef = useRef(null);
  const lastActiveRef = useRef(null);
  const requestControllerRef = useRef(null);

  const abortActiveRequest = useCallback(() => {
    if (requestControllerRef.current) {
      requestControllerRef.current.abort();
      requestControllerRef.current = null;
    }
  }, []);

  const openModal = useCallback(() => {
    if (typeof document !== 'undefined') {
      lastActiveRef.current = document.activeElement;
    }
    setIsOpen(true);
  }, []);

  const closeModal = useCallback(() => {
    abortActiveRequest();
    setIsOpen(false);
  }, [abortActiveRequest]);

  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }
    try {
      if (isOpen) {
        window.sessionStorage.setItem(OPEN_STATE_KEY, 'true');
      } else {
        window.sessionStorage.removeItem(OPEN_STATE_KEY);
      }
    } catch (error) {
      // Ignore storage failures (private mode, strict policies).
    }
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) {
      return undefined;
    }
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = previousOverflow;
    };
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) {
      return undefined;
    }
    const focusTimer = window.setTimeout(() => {
      inputRef.current?.focus();
    }, 0);
    return () => window.clearTimeout(focusTimer);
  }, [isOpen]);

  useEffect(() => {
    if (isOpen) {
      return;
    }
    const lastActive = lastActiveRef.current;
    if (lastActive && typeof lastActive.focus === 'function') {
      lastActive.focus();
    }
  }, [isOpen]);

  useEffect(() => {
    const handleHotkey = (event) => {
      const isMac =
        typeof navigator !== 'undefined' && /Mac|iPod|iPhone|iPad/.test(navigator.platform);
      const requiresMeta = isMac;
      const modifierPressed = requiresMeta ? event.metaKey : event.ctrlKey;
      if (!modifierPressed || event.shiftKey || event.altKey) {
        return;
      }
      if (event.repeat) {
        return;
      }
      if (event.key.toLowerCase() !== 'k') {
        return;
      }
      if (event.defaultPrevented || isEditableTarget(event.target)) {
        return;
      }
      event.preventDefault();
      if (isOpen) {
        closeModal();
      } else {
        openModal();
      }
    };

    window.addEventListener('keydown', handleHotkey);
    return () => window.removeEventListener('keydown', handleHotkey);
  }, [closeModal, isOpen, openModal]);

  useEffect(() => {
    const handleAdminTrigger = (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }
      const isTrigger = ADMIN_TRIGGER_SELECTORS.some((selector) => target.closest(selector));
      if (!isTrigger) {
        return;
      }
      event.preventDefault();
      openModal();
    };

    document.addEventListener('click', handleAdminTrigger);
    return () => document.removeEventListener('click', handleAdminTrigger);
  }, [openModal]);

  useEffect(() => {
    if (!isOpen) {
      return undefined;
    }
    const handleKeydown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeModal();
        return;
      }
      if (event.key !== 'Tab') {
        return;
      }
      const focusable = getFocusableElements(modalRef.current);
      if (!focusable.length) {
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      const activeElement = document.activeElement;
      if (!modalRef.current?.contains(activeElement)) {
        event.preventDefault();
        if (event.shiftKey) {
          last.focus();
        } else {
          first.focus();
        }
        return;
      }
      if (event.shiftKey) {
        if (activeElement === first) {
          event.preventDefault();
          last.focus();
        }
      } else if (activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', handleKeydown);
    return () => document.removeEventListener('keydown', handleKeydown);
  }, [closeModal, isOpen]);

  useEffect(() => {
    return () => {
      abortActiveRequest();
    };
  }, [abortActiveRequest]);

  const handleSubmit = async (event) => {
    event.preventDefault();
    const trimmedPrompt = prompt.trim();
    if (!trimmedPrompt || loading) {
      return;
    }

    abortActiveRequest();
    const controller = new AbortController();
    requestControllerRef.current = controller;
    setLoading(true);
    setErrorMessage('');
    setResponse('');
    setMetrics({ latencyMs: null, tokenCost: null });

    const startTime = getNow();

    try {
    const headers = {
      'Content-Type': 'application/json',
    };
    const restEndpoint = getRestEndpoint();
    const restNonce = getRestNonce();
    if (restNonce) {
      headers['X-WP-Nonce'] = restNonce;
    }

      const response = await fetch(restEndpoint, {
        method: 'POST',
        headers,
        body: JSON.stringify({ prompt: trimmedPrompt }),
        credentials: 'same-origin',
        signal: controller.signal,
      });

      const data = await response.json().catch(() => null);
      const latencyMs = Math.round(getNow() - startTime);

      if (!response.ok || data?.success === false) {
        const errorMessage = data?.error?.message || data?.message || 'Unable to reach AgentWP.';
        throw new Error(errorMessage);
      }

      const message =
        data?.data?.message ||
        data?.message ||
        'Intent received. AgentWP is preparing the next step.';
      const tokenCost = data?.data?.token_cost ?? null;

      setResponse(message);
      setMetrics({ latencyMs, tokenCost });
    } catch (error) {
      if (error?.name === 'AbortError') {
        return;
      }
      const latencyMs = Math.round(getNow() - startTime);
      setErrorMessage(error?.message || 'Unable to reach AgentWP.');
      setMetrics((prev) => ({ ...prev, latencyMs }));
    } finally {
      if (requestControllerRef.current === controller) {
        requestControllerRef.current = null;
      }
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen text-slate-100">
      <main
        className="relative mx-auto flex min-h-screen max-w-5xl flex-col px-6 py-16 animate-fade-in motion-reduce:animate-none"
        aria-hidden={isOpen}
      >
        <header className="max-w-2xl space-y-4">
          <p className="text-xs font-semibold uppercase tracking-[0.4em] text-slate-400">
            AgentWP
          </p>
          <h1 className="text-4xl font-semibold text-white sm:text-5xl">
            Command Deck: instant actions for your store.
          </h1>
          <p className="text-base text-slate-300 sm:text-lg">
            Invoke the Command Deck with Cmd+K / Ctrl+K or the admin bar button. Responses render
            as markdown, with latency and token cost tracking for quick feedback.
          </p>
        </header>

        <section className="mt-10 grid gap-6 sm:grid-cols-2">
          <div className="rounded-2xl border border-deck-border bg-deck-surface/80 p-6 shadow-deck">
            <h2 className="text-lg font-semibold text-white">Try a sample prompt</h2>
            <p className="mt-2 text-sm text-slate-300">
              &ldquo;Summarize today&apos;s pending orders and draft a response for the two longest
              open tickets.&rdquo;
            </p>
            <button
              type="button"
              onClick={openModal}
              className="mt-6 inline-flex items-center justify-center gap-2 rounded-full border border-slate-600/60 bg-slate-900/60 px-4 py-2 text-sm font-semibold text-white transition hover:border-slate-400/80 hover:bg-slate-900/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400"
            >
              Open Command Deck
              <span className="rounded-full border border-slate-600/80 bg-slate-950/70 px-2 py-1 text-[11px] text-slate-300">
                {typeof navigator !== 'undefined' && /Mac|iPod|iPhone|iPad/.test(navigator.platform)
                  ? 'Cmd'
                  : 'Ctrl'}
                +K
              </span>
            </button>
          </div>

          <div className="rounded-2xl border border-deck-border bg-deck-surface/60 p-6 text-sm text-slate-300 shadow-deck">
            <h2 className="text-lg font-semibold text-white">Command Deck status</h2>
            <ul className="mt-3 space-y-2 text-sm">
              <li>Modal state is persisted in session storage.</li>
              <li>Focus is trapped for keyboard-only navigation.</li>
              <li>Responses render markdown with accessible contrast.</li>
            </ul>
          </div>
        </section>
      </main>

      {isOpen && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4 py-10 backdrop-blur-sm animate-fade-in motion-reduce:animate-none"
          role="presentation"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) {
              closeModal();
            }
          }}
        >
          <div
            ref={modalRef}
            role="dialog"
            aria-modal="true"
            aria-labelledby="agentwp-command-deck-title"
            className="w-full max-w-[600px] rounded-3xl border border-deck-border bg-slate-900/90 shadow-deck backdrop-blur-xl animate-deck-in motion-reduce:animate-none"
          >
            <div className="flex items-center justify-between border-b border-slate-700/50 px-6 py-5">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                  AgentWP
                </p>
                <h2 id="agentwp-command-deck-title" className="text-lg font-semibold text-white">
                  Command Deck
                </h2>
              </div>
              <button
                type="button"
                onClick={closeModal}
                className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-700/70 text-slate-200 transition hover:border-slate-500/80 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400"
                aria-label="Close Command Deck"
              >
                <span aria-hidden="true">&times;</span>
              </button>
            </div>

            <div className="space-y-4 px-6 py-5">
              <form onSubmit={handleSubmit}>
                <label htmlFor="agentwp-prompt" className="sr-only">
                  Ask AgentWP anything
                </label>
                <div className="flex items-center gap-3 rounded-2xl border border-slate-700/70 bg-slate-950/40 px-4 py-3 focus-within:border-sky-400/80">
                  <input
                    id="agentwp-prompt"
                    ref={inputRef}
                    type="text"
                    value={prompt}
                    onChange={(event) => setPrompt(event.target.value)}
                    placeholder="Ask AgentWP anything..."
                    className="flex-1 bg-transparent text-sm text-slate-100 placeholder:text-slate-500 focus:outline-none"
                  />
                  <button
                    type="submit"
                    disabled={loading || !prompt.trim()}
                    className="inline-flex items-center justify-center rounded-full border border-slate-600/70 bg-slate-900/80 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition hover:border-slate-400/80 hover:bg-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    {loading ? 'Sending' : 'Send'}
                  </button>
                </div>
              </form>

              <div className="rounded-2xl border border-slate-800/80 bg-slate-950/40 px-4 py-4">
                <div
                  className="min-h-[140px] max-h-64 overflow-y-auto pr-1"
                  aria-live="polite"
                  aria-busy={loading ? 'true' : 'false'}
                >
                  {loading ? (
                    <div className="space-y-3 animate-pulse">
                      <div className="h-3 w-3/4 rounded-full bg-slate-700/60" />
                      <div className="h-3 w-5/6 rounded-full bg-slate-700/50" />
                      <div className="h-3 w-2/3 rounded-full bg-slate-700/40" />
                    </div>
                  ) : errorMessage ? (
                    <p className="text-sm text-rose-300">{errorMessage}</p>
                  ) : response ? (
                    <ReactMarkdown
                      className="agentwp-markdown"
                      components={{
                        a: ({ ...props }) => (
                          <a {...props} target="_blank" rel="noreferrer" />
                        ),
                      }}
                    >
                      {response}
                    </ReactMarkdown>
                  ) : (
                    <div className="space-y-2 text-sm text-slate-400">
                      <p>Describe a task and AgentWP will coordinate the next action.</p>
                      <p className="text-xs text-slate-500">Press Enter to send. Esc to close.</p>
                    </div>
                  )}
                </div>
              </div>

              <div className="flex items-center justify-between text-xs text-slate-400">
                <span>
                  Latency: {metrics.latencyMs !== null ? `${metrics.latencyMs} ms` : '--'}
                </span>
                <span>Token cost: {metrics.tokenCost ?? '--'}</span>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

import { useCallback, useEffect, useRef, useState } from 'react';
import html2canvas from 'html2canvas';
import ReactMarkdown from 'react-markdown';

const OPEN_STATE_KEY = 'agentwp-command-deck-open';
const DRAFT_HISTORY_KEY = 'agentwp-draft-history';
const MAX_DRAFT_HISTORY = 10;
const COPY_FEEDBACK_MS = 2000;
const EXPORT_FEEDBACK_MS = 2000;
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

const stripMarkdownToPlainText = (markdown) => {
  if (!markdown) {
    return '';
  }
  let text = markdown;
  text = text.replace(/```[\s\S]*?```/g, (block) => block.replace(/```/g, '').trim());
  text = text.replace(/`([^`]+)`/g, '$1');
  text = text.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, '$1');
  text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '$1');
  text = text.replace(/^#{1,6}\s+/gm, '');
  text = text.replace(/^>\s?/gm, '');
  text = text.replace(/^\s*[-*+]\s+/gm, '- ');
  text = text.replace(/\*\*([^*]+)\*\*/g, '$1');
  text = text.replace(/\*([^*]+)\*/g, '$1');
  text = text.replace(/~~([^~]+)~~/g, '$1');
  return text.replace(/\n{3,}/g, '\n\n').trim();
};

const escapeHtml = (value) =>
  value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

const formatPlainTextAsHtml = (text) => {
  if (!text) {
    return '';
  }
  const safeText = escapeHtml(text);
  const paragraphs = safeText.split(/\n{2,}/).filter(Boolean);
  return paragraphs
    .map((paragraph) => `<p>${paragraph.replace(/\n/g, '<br />')}</p>`)
    .join('');
};

const buildMailtoLink = (subject, body) => {
  const parts = [];
  if (subject) {
    parts.push(`subject=${encodeURIComponent(subject)}`);
  }
  if (body) {
    const normalizedBody = body.replace(/\r?\n/g, '\r\n');
    parts.push(`body=${encodeURIComponent(normalizedBody)}`);
  }
  return `mailto:${parts.length ? `?${parts.join('&')}` : ''}`;
};

const fallbackCopyToClipboard = ({ text, html }) => {
  if (typeof document === 'undefined') {
    return false;
  }
  const activeElement = document.activeElement;
  const selection = typeof window !== 'undefined' ? window.getSelection() : null;

  const restoreSelection = () => {
    selection?.removeAllRanges();
    if (activeElement && typeof activeElement.focus === 'function') {
      activeElement.focus();
    }
  };

  if (html && selection && typeof document.createRange === 'function') {
    const container = document.createElement('div');
    container.innerHTML = html;
    container.setAttribute('contenteditable', 'true');
    container.style.position = 'fixed';
    container.style.left = '-9999px';
    container.style.opacity = '0';
    container.style.pointerEvents = 'none';
    document.body.appendChild(container);

    const range = document.createRange();
    range.selectNodeContents(container);
    selection.removeAllRanges();
    selection.addRange(range);

    const htmlSuccess = document.execCommand('copy');
    document.body.removeChild(container);
    restoreSelection();
    if (htmlSuccess) {
      return true;
    }
  }

  if (text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);
    const textSuccess = document.execCommand('copy');
    document.body.removeChild(textarea);
    restoreSelection();
    return textSuccess;
  }

  restoreSelection();
  return false;
};

const copyToClipboard = async ({ text, html }) => {
  if (typeof navigator !== 'undefined' && navigator.clipboard) {
    try {
      if (html && typeof window !== 'undefined' && window.ClipboardItem && navigator.clipboard.write) {
        const clipboardItem = new window.ClipboardItem({
          'text/plain': new Blob([text ?? ''], { type: 'text/plain' }),
          'text/html': new Blob([html], { type: 'text/html' }),
        });
        await navigator.clipboard.write([clipboardItem]);
        return true;
      }
      if (text && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
        return true;
      }
    } catch (error) {
      return fallbackCopyToClipboard({ text, html });
    }
  }
  return fallbackCopyToClipboard({ text, html });
};

const ClipboardButton = ({ label, getPayload, disabled }) => {
  const [status, setStatus] = useState('idle');
  const timerRef = useRef(null);

  useEffect(() => {
    return () => {
      if (timerRef.current) {
        window.clearTimeout(timerRef.current);
      }
    };
  }, []);

  const handleCopy = async () => {
    if (disabled) {
      return;
    }
    const payload = await getPayload();
    if (!payload) {
      return;
    }
    try {
      const success = await copyToClipboard(payload);
      if (!success) {
        return;
      }
      setStatus('copied');
      if (timerRef.current) {
        window.clearTimeout(timerRef.current);
      }
      timerRef.current = window.setTimeout(() => {
        setStatus('idle');
      }, COPY_FEEDBACK_MS);
    } catch (error) {
      // Ignore clipboard errors; button stays in default state.
    }
  };

  const showCopied = status === 'copied';

  return (
    <button
      type="button"
      onClick={handleCopy}
      disabled={disabled}
      className={`inline-flex items-center justify-center rounded-full border px-4 py-2 text-xs font-semibold uppercase tracking-widest transition focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 ${
        disabled
          ? 'cursor-not-allowed border-slate-700/60 bg-slate-900/50 text-slate-500'
          : 'border-slate-600/70 bg-slate-900/80 text-white hover:border-slate-400/80 hover:bg-slate-900'
      } ${showCopied ? 'copy-feedback border-emerald-400/70 bg-emerald-500/20 text-emerald-100' : ''}`}
    >
      {showCopied ? 'Copied!' : label}
    </button>
  );
};

export default function App() {
  const [isOpen, setIsOpen] = useState(getInitialOpenState);
  const [prompt, setPrompt] = useState('');
  const [loading, setLoading] = useState(false);
  const [response, setResponse] = useState('');
  const [errorMessage, setErrorMessage] = useState('');
  const [metrics, setMetrics] = useState({ latencyMs: null, tokenCost: null });
  const [draftSubject, setDraftSubject] = useState('');
  const [draftBody, setDraftBody] = useState('');
  const [draftHistory, setDraftHistory] = useState([]);
  const [exportStatus, setExportStatus] = useState('idle');
  const inputRef = useRef(null);
  const modalRef = useRef(null);
  const responseHtmlRef = useRef(null);
  const lastActiveRef = useRef(null);
  const requestControllerRef = useRef(null);
  const chartRef = useRef(null);
  const exportTimerRef = useRef(null);

  const abortActiveRequest = useCallback(() => {
    if (requestControllerRef.current) {
      requestControllerRef.current.abort();
      requestControllerRef.current = null;
    }
  }, []);

  const addDraftToHistory = useCallback((draft) => {
    if (!draft?.markdown) {
      return;
    }
    setDraftHistory((prev) => {
      const nextEntry = {
        id: `draft-${Date.now()}-${Math.random().toString(16).slice(2)}`,
        prompt: draft.prompt || '',
        subject: draft.subject || '',
        markdown: draft.markdown,
        plainText: draft.plainText || '',
        createdAt: new Date().toISOString(),
      };
      const filtered = prev.filter(
        (item) =>
          item.markdown !== nextEntry.markdown ||
          item.subject !== nextEntry.subject ||
          item.plainText !== nextEntry.plainText
      );
      return [nextEntry, ...filtered].slice(0, MAX_DRAFT_HISTORY);
    });
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

  const handleRestoreDraft = useCallback(
    (draft) => {
      if (!draft) {
        return;
      }
      abortActiveRequest();
      setPrompt(draft.prompt || '');
      setResponse(draft.markdown || '');
      setDraftSubject(draft.subject || draft.prompt || 'AgentWP Draft');
      setDraftBody(draft.plainText || stripMarkdownToPlainText(draft.markdown || ''));
      setErrorMessage('');
      setMetrics({ latencyMs: null, tokenCost: null });
      setLoading(false);
    },
    [abortActiveRequest]
  );

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
    if (typeof window === 'undefined') {
      return;
    }
    try {
      const stored = window.localStorage.getItem(DRAFT_HISTORY_KEY);
      if (!stored) {
        return;
      }
      const parsed = JSON.parse(stored);
      if (Array.isArray(parsed)) {
        setDraftHistory(parsed.slice(0, MAX_DRAFT_HISTORY));
      }
    } catch (error) {
      // Ignore storage failures (private mode, strict policies).
    }
  }, []);

  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }
    try {
      window.localStorage.setItem(DRAFT_HISTORY_KEY, JSON.stringify(draftHistory));
    } catch (error) {
      // Ignore storage failures (private mode, strict policies).
    }
  }, [draftHistory]);

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

  useEffect(() => {
    return () => {
      if (exportTimerRef.current) {
        window.clearTimeout(exportTimerRef.current);
      }
    };
  }, []);

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
      const subjectLine = trimmedPrompt || 'AgentWP Draft';
      const plainTextDraft = stripMarkdownToPlainText(message);

      setResponse(message);
      setDraftSubject(subjectLine);
      setDraftBody(plainTextDraft);
      setMetrics({ latencyMs, tokenCost });
      addDraftToHistory({
        prompt: trimmedPrompt,
        subject: subjectLine,
        markdown: message,
        plainText: plainTextDraft,
      });
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

  const handleExportChart = useCallback(async () => {
    if (!chartRef.current || exportStatus === 'exporting') {
      return;
    }
    setExportStatus('exporting');
    try {
      const canvas = await html2canvas(chartRef.current, {
        scale: 2,
        backgroundColor: '#0a1120',
      });
      const dataUrl = canvas.toDataURL('image/png');
      const link = document.createElement('a');
      link.href = dataUrl;
      link.download = 'agentwp-analytics.png';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      setExportStatus('exported');
      if (exportTimerRef.current) {
        window.clearTimeout(exportTimerRef.current);
      }
      exportTimerRef.current = window.setTimeout(() => {
        setExportStatus('idle');
      }, EXPORT_FEEDBACK_MS);
    } catch (error) {
      setExportStatus('idle');
    }
  }, [exportStatus]);

  const hasDraft = Boolean(response && !errorMessage);
  const resolvedBody = draftBody || stripMarkdownToPlainText(response);
  const mailtoHref = hasDraft ? buildMailtoLink(draftSubject, resolvedBody) : '#';
  const exportLabel =
    exportStatus === 'exporting' ? 'Exporting...' : exportStatus === 'exported' ? 'Exported' : 'Export PNG';
  const chartBars = [72, 98, 84, 124, 96, 112, 78];
  const chartLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

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

        <section className="mt-10">
          <div className="rounded-2xl border border-deck-border bg-deck-surface/70 p-6 shadow-deck">
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                  Analytics snapshot
                </p>
                <h2 className="mt-2 text-lg font-semibold text-white">Weekly revenue trend</h2>
                <p className="mt-1 text-sm text-slate-300">
                  Track orders and response momentum before drafting outreach.
                </p>
              </div>
              <button
                type="button"
                onClick={handleExportChart}
                disabled={exportStatus === 'exporting'}
                className={`inline-flex items-center justify-center rounded-full border px-4 py-2 text-xs font-semibold uppercase tracking-widest transition focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 ${
                  exportStatus === 'exporting'
                    ? 'cursor-not-allowed border-slate-700/60 bg-slate-900/50 text-slate-500'
                    : 'border-slate-600/70 bg-slate-900/80 text-white hover:border-slate-400/80 hover:bg-slate-900'
                } ${exportStatus === 'exported' ? 'copy-feedback border-emerald-400/70 bg-emerald-500/20 text-emerald-100' : ''}`}
              >
                {exportLabel}
              </button>
            </div>

            <div
              ref={chartRef}
              className="mt-6 rounded-2xl border border-slate-800/80 bg-slate-950/50 p-5"
            >
              <div className="flex items-center justify-between text-xs text-slate-400">
                <span>Revenue</span>
                <span>Last 7 days</span>
              </div>
              <div className="mt-4 grid grid-cols-7 items-end gap-2">
                {chartBars.map((value, index) => (
                  <div
                    key={chartLabels[index]}
                    className="flex h-40 flex-col items-center justify-end gap-2"
                  >
                    <div
                      className="w-6 rounded-full bg-gradient-to-b from-sky-400/80 via-sky-500/60 to-sky-700/80"
                      style={{ height: `${value}px` }}
                    />
                    <span className="text-[11px] text-slate-500">{chartLabels[index]}</span>
                  </div>
                ))}
              </div>
              <div className="mt-6 grid gap-3 sm:grid-cols-3">
                <div className="rounded-xl border border-slate-800/80 bg-slate-950/40 px-3 py-3">
                  <p className="text-xs text-slate-400">Orders</p>
                  <p className="text-lg font-semibold text-white">184</p>
                  <p className="text-xs text-emerald-300">+12% week over week</p>
                </div>
                <div className="rounded-xl border border-slate-800/80 bg-slate-950/40 px-3 py-3">
                  <p className="text-xs text-slate-400">Drafts sent</p>
                  <p className="text-lg font-semibold text-white">42</p>
                  <p className="text-xs text-sky-300">Avg. 3.2 hrs response</p>
                </div>
                <div className="rounded-xl border border-slate-800/80 bg-slate-950/40 px-3 py-3">
                  <p className="text-xs text-slate-400">Refund rate</p>
                  <p className="text-lg font-semibold text-white">1.4%</p>
                  <p className="text-xs text-amber-300">-0.3% from last week</p>
                </div>
              </div>
            </div>
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
                    <div ref={responseHtmlRef} className="agentwp-markdown">
                      <ReactMarkdown
                        components={{
                          a: ({ ...props }) => (
                            <a {...props} target="_blank" rel="noreferrer" />
                          ),
                        }}
                      >
                        {response}
                      </ReactMarkdown>
                    </div>
                  ) : (
                    <div className="space-y-2 text-sm text-slate-400">
                      <p>Describe a task and AgentWP will coordinate the next action.</p>
                      <p className="text-xs text-slate-500">Press Enter to send. Esc to close.</p>
                    </div>
                  )}
                </div>
              </div>

              <div className="rounded-2xl border border-slate-800/80 bg-slate-950/40 px-4 py-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.3em] text-slate-500">
                      Draft actions
                    </p>
                    <p className="text-sm text-slate-300">
                      Copy the latest draft or open it in your email client.
                    </p>
                  </div>
                  <div className="min-w-[200px]">
                    <label
                      htmlFor="agentwp-draft-subject"
                      className="block text-[11px] font-semibold uppercase tracking-[0.3em] text-slate-500"
                    >
                      Email subject
                    </label>
                    <input
                      id="agentwp-draft-subject"
                      type="text"
                      value={draftSubject}
                      onChange={(event) => setDraftSubject(event.target.value)}
                      disabled={!hasDraft}
                      placeholder="Subject line"
                      className="mt-2 w-full rounded-xl border border-slate-700/70 bg-slate-950/40 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 disabled:cursor-not-allowed disabled:opacity-60"
                    />
                  </div>
                </div>
                <div className="mt-4 flex flex-wrap gap-2">
                  <ClipboardButton
                    label="Copy plain text"
                    disabled={!hasDraft}
                    getPayload={() => ({ text: resolvedBody })}
                  />
                  <ClipboardButton
                    label="Copy HTML"
                    disabled={!hasDraft}
                    getPayload={() => ({
                      text: resolvedBody,
                      html:
                        responseHtmlRef.current?.innerHTML ||
                        formatPlainTextAsHtml(resolvedBody),
                    })}
                  />
                  <ClipboardButton
                    label="Copy Markdown"
                    disabled={!hasDraft}
                    getPayload={() => ({ text: response })}
                  />
                  <a
                    href={mailtoHref}
                    onClick={(event) => {
                      if (!hasDraft) {
                        event.preventDefault();
                      }
                    }}
                    aria-disabled={!hasDraft}
                    tabIndex={hasDraft ? 0 : -1}
                    className={`inline-flex items-center justify-center rounded-full border px-4 py-2 text-xs font-semibold uppercase tracking-widest transition focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 ${
                      hasDraft
                        ? 'border-slate-600/70 bg-slate-900/80 text-white hover:border-slate-400/80 hover:bg-slate-900'
                        : 'pointer-events-none border-slate-700/60 bg-slate-900/50 text-slate-500'
                    }`}
                  >
                    Open in Mail
                  </a>
                </div>
              </div>

              <details className="rounded-2xl border border-slate-800/80 bg-slate-950/40 px-4 py-3 text-sm text-slate-300">
                <summary className="cursor-pointer text-xs font-semibold uppercase tracking-[0.3em] text-slate-500">
                  Draft history (last 10)
                </summary>
                <div className="mt-3 space-y-2">
                  {draftHistory.length ? (
                    draftHistory.map((draft) => {
                      const title = draft.subject || draft.prompt || 'Untitled draft';
                      const timestamp = draft.createdAt
                        ? new Date(draft.createdAt).toLocaleString()
                        : '';
                      return (
                        <button
                          key={draft.id}
                          type="button"
                          onClick={() => handleRestoreDraft(draft)}
                          className="flex w-full flex-col rounded-xl border border-slate-800/80 bg-slate-950/30 px-3 py-2 text-left text-sm text-slate-200 transition hover:border-slate-600/80 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400"
                        >
                          <span className="font-semibold">{title}</span>
                          <span className="text-xs text-slate-500">{timestamp}</span>
                        </button>
                      );
                    })
                  ) : (
                    <p className="text-xs text-slate-500">No drafts yet. Send a prompt to save one.</p>
                  )}
                </div>
              </details>

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

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import html2canvas from 'html2canvas';
import ReactMarkdown from 'react-markdown';
import { ChartCard } from '../components/cards';

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
const SEARCH_PATH = '/agentwp/v1/search';
const FOCUSABLE_SELECTORS = [
  'a[href]',
  'button:not([disabled])',
  'textarea:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
];
const PERIOD_OPTIONS = [
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
  { value: '90d', label: 'Last 90 days' },
];
const SEARCH_TYPES = ['products', 'orders', 'customers'];
const TYPEAHEAD_CONFIG = {
  products: {
    label: 'Products',
    icon: (
      <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4">
        <path
          d="M4 5h9l7 7-8 8-8-8V5z"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.7"
          strokeLinejoin="round"
        />
        <circle cx="8.5" cy="9.5" r="1.3" fill="currentColor" />
      </svg>
    ),
  },
  orders: {
    label: 'Orders',
    icon: (
      <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4">
        <path
          d="M7 4h10v16l-3-2-2 2-2-2-3 2z"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.7"
          strokeLinejoin="round"
        />
        <path
          d="M9 9h6M9 12h6"
          stroke="currentColor"
          strokeWidth="1.6"
          strokeLinecap="round"
        />
      </svg>
    ),
  },
  customers: {
    label: 'Customers',
    icon: (
      <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4">
        <circle
          cx="12"
          cy="8"
          r="3.4"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.7"
        />
        <path
          d="M4 20c1.8-3.6 5-5.4 8-5.4s6.2 1.8 8 5.4"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.7"
          strokeLinecap="round"
        />
      </svg>
    ),
  },
};
const getEmptySearchResults = () =>
  SEARCH_TYPES.reduce((accumulator, type) => {
    accumulator[type] = [];
    return accumulator;
  }, {});

const currencyFormatter = new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD',
  maximumFractionDigits: 0,
});

const formatCurrencyValue = (value) => {
  if (typeof value !== 'number' || Number.isNaN(value)) {
    return value?.toString() ?? '';
  }
  return currencyFormatter.format(value);
};

const buildDayLabels = (days, prefix = 'Day') =>
  Array.from({ length: days }, (_, index) => `${prefix} ${index + 1}`);

const buildRevenueSeries = (days, base, trend, variance, phase = 0) =>
  Array.from({ length: days }, (_, index) =>
    Math.round(base + index * trend + Math.sin(index * 0.45 + phase) * variance)
  );

const hexToRgba = (hex, alpha) => {
  const cleanHex = hex.replace('#', '');
  if (cleanHex.length !== 6) {
    return hex;
  }
  const r = parseInt(cleanHex.slice(0, 2), 16);
  const g = parseInt(cleanHex.slice(2, 4), 16);
  const b = parseInt(cleanHex.slice(4, 6), 16);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
};

const ANALYTICS_DATA = {
  '7d': {
    label: 'Last 7 days',
    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    current: buildRevenueSeries(7, 4200, 160, 420, 0.2),
    previous: buildRevenueSeries(7, 3900, 120, 360, 1.1),
    metrics: {
      labels: ['Revenue', 'Shipping', 'Discounts', 'Returns'],
      current: [128000, 18200, 9600, 4100],
      previous: [116500, 16750, 10100, 4600],
    },
    categories: {
      labels: ['Accessories', 'Home', 'Wellness', 'Apparel'],
      values: [45200, 38200, 29600, 15000],
    },
  },
  '30d': {
    label: 'Last 30 days',
    labels: buildDayLabels(30),
    current: buildRevenueSeries(30, 3800, 55, 520, 0.3),
    previous: buildRevenueSeries(30, 3600, 45, 480, 1.2),
    metrics: {
      labels: ['Revenue', 'Shipping', 'Discounts', 'Returns'],
      current: [540000, 61200, 45200, 12300],
      previous: [498000, 58500, 47600, 13800],
    },
    categories: {
      labels: ['Accessories', 'Home', 'Wellness', 'Apparel'],
      values: [188000, 162000, 115000, 75000],
    },
  },
  '90d': {
    label: 'Last 90 days',
    labels: buildDayLabels(90, 'D'),
    current: buildRevenueSeries(90, 3500, 22, 620, 0.4),
    previous: buildRevenueSeries(90, 3300, 18, 560, 1.4),
    metrics: {
      labels: ['Revenue', 'Shipping', 'Discounts', 'Returns'],
      current: [1480000, 166500, 128000, 44200],
      previous: [1375000, 158000, 141000, 46800],
    },
    categories: {
      labels: ['Accessories', 'Home', 'Wellness', 'Apparel'],
      values: [510000, 436000, 318000, 216000],
    },
  },
};

const usePrefersDark = (fallback = true) => {
  const [prefersDark, setPrefersDark] = useState(() => {
    if (typeof window === 'undefined' || !window.matchMedia) {
      return fallback;
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
  });

  useEffect(() => {
    if (!window.matchMedia) {
      return undefined;
    }
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const handleChange = (event) => {
      setPrefersDark(event.matches);
    };
    if (media.addEventListener) {
      media.addEventListener('change', handleChange);
    } else {
      media.addListener(handleChange);
    }
    return () => {
      if (media.removeEventListener) {
        media.removeEventListener('change', handleChange);
      } else {
        media.removeListener(handleChange);
      }
    };
  }, []);

  return prefersDark;
};

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

const getSearchEndpoint = () => {
  if (typeof window === 'undefined') {
    return SEARCH_PATH;
  }
  const root = window.agentwpSettings?.root || window.wpApiSettings?.root;
  if (!root) {
    return SEARCH_PATH;
  }
  return `${root.replace(/\/$/, '')}${SEARCH_PATH}`;
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

const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const getHighlightTokens = (query) => {
  if (!query) {
    return [];
  }
  return query
    .trim()
    .toLowerCase()
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 6);
};

const renderHighlightedText = (text, query) => {
  if (!text) {
    return '';
  }
  const tokens = getHighlightTokens(query);
  if (!tokens.length) {
    return text;
  }
  const pattern = new RegExp(`(${tokens.map(escapeRegExp).join('|')})`, 'ig');
  return text.split(pattern).map((part, index) => {
    if (tokens.includes(part.toLowerCase())) {
      return (
        <mark
          key={`${part}-${index}`}
          className="rounded bg-sky-500/30 px-1 text-sky-100"
        >
          {part}
        </mark>
      );
    }
    return part;
  });
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
  const [searchResults, setSearchResults] = useState(getEmptySearchResults);
  const [isTypeaheadOpen, setIsTypeaheadOpen] = useState(false);
  const [isTypeaheadLoading, setIsTypeaheadLoading] = useState(false);
  const [activeSuggestionIndex, setActiveSuggestionIndex] = useState(-1);
  const [isPromptFocused, setIsPromptFocused] = useState(false);
  const [loading, setLoading] = useState(false);
  const [response, setResponse] = useState('');
  const [errorMessage, setErrorMessage] = useState('');
  const [metrics, setMetrics] = useState({ latencyMs: null, tokenCost: null });
  const [draftSubject, setDraftSubject] = useState('');
  const [draftBody, setDraftBody] = useState('');
  const [draftHistory, setDraftHistory] = useState([]);
  const [exportStatus, setExportStatus] = useState('idle');
  const [selectedPeriod, setSelectedPeriod] = useState('7d');
  const inputRef = useRef(null);
  const modalRef = useRef(null);
  const responseHtmlRef = useRef(null);
  const lastActiveRef = useRef(null);
  const requestControllerRef = useRef(null);
  const searchControllerRef = useRef(null);
  const searchTimeoutRef = useRef(null);
  const promptBlurTimeoutRef = useRef(null);
  const suppressSearchRef = useRef(false);
  const chartRef = useRef(null);
  const exportTimerRef = useRef(null);
  const prefersDark = usePrefersDark();
  const flatSuggestions = useMemo(() => {
    const items = [];
    SEARCH_TYPES.forEach((type) => {
      const list = Array.isArray(searchResults[type]) ? searchResults[type] : [];
      list.forEach((item) => {
        items.push({ ...item, type });
      });
    });
    return items;
  }, [searchResults]);
  const suggestionGroups = useMemo(() => {
    let cursor = 0;
    return SEARCH_TYPES.map((type) => {
      const list = Array.isArray(searchResults[type]) ? searchResults[type] : [];
      const items = list.map((item) => ({ ...item, type, _index: cursor++ }));
      return { type, items };
    });
  }, [searchResults]);
  const hasSuggestions = flatSuggestions.length > 0;

  const abortActiveRequest = useCallback(() => {
    if (requestControllerRef.current) {
      requestControllerRef.current.abort();
      requestControllerRef.current = null;
    }
  }, []);

  const abortSearchRequest = useCallback(() => {
    if (searchControllerRef.current) {
      searchControllerRef.current.abort();
      searchControllerRef.current = null;
    }
  }, []);

  const resetTypeahead = useCallback(() => {
    setSearchResults(getEmptySearchResults());
    setIsTypeaheadLoading(false);
    setActiveSuggestionIndex(-1);
  }, []);

  const handleSuggestionSelect = useCallback(
    (suggestion) => {
      if (!suggestion) {
        return;
      }
      suppressSearchRef.current = true;
      abortSearchRequest();
      setIsTypeaheadLoading(false);
      if (searchTimeoutRef.current) {
        window.clearTimeout(searchTimeoutRef.current);
      }
      const nextValue = suggestion.query || suggestion.primary || '';
      setPrompt(nextValue);
      setIsTypeaheadOpen(false);
      setActiveSuggestionIndex(-1);
    },
    [abortSearchRequest]
  );

  const handlePromptFocus = useCallback(() => {
    if (promptBlurTimeoutRef.current) {
      window.clearTimeout(promptBlurTimeoutRef.current);
    }
    setIsPromptFocused(true);
    if (prompt.trim()) {
      setIsTypeaheadOpen(true);
    }
  }, [prompt]);

  const handlePromptBlur = useCallback(() => {
    if (promptBlurTimeoutRef.current) {
      window.clearTimeout(promptBlurTimeoutRef.current);
    }
    promptBlurTimeoutRef.current = window.setTimeout(() => {
      setIsPromptFocused(false);
      setIsTypeaheadOpen(false);
      setActiveSuggestionIndex(-1);
    }, 120);
  }, []);

  const handlePromptChange = useCallback((event) => {
    const nextValue = event.target.value;
    setPrompt(nextValue);
    if (nextValue.trim()) {
      setIsTypeaheadOpen(true);
    }
  }, []);

  const handlePromptKeyDown = useCallback(
    (event) => {
      if (!isTypeaheadOpen || !hasSuggestions) {
        return;
      }

      if (event.key === 'ArrowDown') {
        event.preventDefault();
        setActiveSuggestionIndex((prev) => {
          const next = prev + 1;
          if (next >= flatSuggestions.length) {
            return 0;
          }
          return next;
        });
        return;
      }

      if (event.key === 'ArrowUp') {
        event.preventDefault();
        setActiveSuggestionIndex((prev) => {
          const next = prev - 1;
          if (next < 0) {
            return flatSuggestions.length - 1;
          }
          return next;
        });
        return;
      }

      if (event.key === 'Enter' && activeSuggestionIndex >= 0) {
        event.preventDefault();
        handleSuggestionSelect(flatSuggestions[activeSuggestionIndex]);
        return;
      }

      if (event.key === 'Escape') {
        event.stopPropagation();
        setIsTypeaheadOpen(false);
        setActiveSuggestionIndex(-1);
      }
    },
    [
      activeSuggestionIndex,
      flatSuggestions,
      handleSuggestionSelect,
      hasSuggestions,
      isTypeaheadOpen,
    ]
  );

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
    const trimmedPrompt = prompt.trim();

    if (suppressSearchRef.current) {
      suppressSearchRef.current = false;
      return;
    }

    if (!trimmedPrompt || !isPromptFocused) {
      abortSearchRequest();
      resetTypeahead();
      setIsTypeaheadOpen(false);
      return;
    }

    if (searchTimeoutRef.current) {
      window.clearTimeout(searchTimeoutRef.current);
    }

    setIsTypeaheadLoading(true);
    searchTimeoutRef.current = window.setTimeout(async () => {
      abortSearchRequest();
      const controller = new AbortController();
      searchControllerRef.current = controller;

      try {
        const headers = {};
        const restNonce = getRestNonce();
        if (restNonce) {
          headers['X-WP-Nonce'] = restNonce;
        }

        const endpoint = getSearchEndpoint();
        const url = new URL(endpoint, window.location.origin);
        url.searchParams.set('q', trimmedPrompt);
        url.searchParams.set('types', SEARCH_TYPES.join(','));

        const response = await fetch(url.toString(), {
          method: 'GET',
          headers,
          credentials: 'same-origin',
          signal: controller.signal,
        });

        const data = await response.json().catch(() => null);
        if (!response.ok || data?.success === false) {
          throw new Error(data?.error?.message || data?.message || 'Search unavailable.');
        }

        const payload = data?.data?.results || {};
        const nextResults = getEmptySearchResults();
        SEARCH_TYPES.forEach((type) => {
          nextResults[type] = Array.isArray(payload[type]) ? payload[type] : [];
        });

        setSearchResults(nextResults);
        setActiveSuggestionIndex(-1);
        setIsTypeaheadOpen(true);
      } catch (error) {
        if (error?.name === 'AbortError') {
          return;
        }
        setSearchResults(getEmptySearchResults());
      } finally {
        setIsTypeaheadLoading(false);
      }
    }, 150);

    return () => {
      if (searchTimeoutRef.current) {
        window.clearTimeout(searchTimeoutRef.current);
      }
    };
  }, [abortSearchRequest, isPromptFocused, prompt, resetTypeahead]);

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
      abortSearchRequest();
      if (searchTimeoutRef.current) {
        window.clearTimeout(searchTimeoutRef.current);
      }
      if (promptBlurTimeoutRef.current) {
        window.clearTimeout(promptBlurTimeoutRef.current);
      }
    };
  }, [abortSearchRequest]);

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

    setIsTypeaheadOpen(false);
    setActiveSuggestionIndex(-1);
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
  const showTypeahead =
    isTypeaheadOpen &&
    isPromptFocused &&
    prompt.trim().length > 0 &&
    (isTypeaheadLoading || hasSuggestions);
  const activeSuggestionId =
    activeSuggestionIndex >= 0 ? `agentwp-suggestion-${activeSuggestionIndex}` : undefined;
  const exportLabel =
    exportStatus === 'exporting' ? 'Exporting...' : exportStatus === 'exported' ? 'Exported' : 'Export PNG';
  const periodData = useMemo(
    () => ANALYTICS_DATA[selectedPeriod] || ANALYTICS_DATA['7d'],
    [selectedPeriod]
  );
  const chartPalette = useMemo(
    () =>
      prefersDark
        ? {
            primary: '#38bdf8',
            secondary: '#a78bfa',
            barPrimary: '#38bdf8',
            barSecondary: '#64748b',
            doughnut: ['#38bdf8', '#22c55e', '#f59e0b', '#f97316'],
            canvas: '#111827',
          }
        : {
            primary: '#0284c7',
            secondary: '#6366f1',
            barPrimary: '#0ea5e9',
            barSecondary: '#94a3b8',
            doughnut: ['#0ea5e9', '#22c55e', '#f59e0b', '#ef4444'],
            canvas: '#f1f5f9',
          },
    [prefersDark]
  );
  const trendChartData = useMemo(
    () => ({
      labels: periodData.labels,
      datasets: [
        {
          label: 'This period',
          data: periodData.current,
          borderColor: chartPalette.primary,
          backgroundColor: hexToRgba(chartPalette.primary, 0.25),
          tension: 0.35,
          borderWidth: 2,
          pointRadius: 3,
          pointHoverRadius: 4,
          fill: true,
        },
        {
          label: 'Previous period',
          data: periodData.previous,
          borderColor: chartPalette.secondary,
          backgroundColor: hexToRgba(chartPalette.secondary, 0.12),
          tension: 0.35,
          borderWidth: 2,
          borderDash: [6, 4],
          pointRadius: 2,
          pointHoverRadius: 3,
          fill: false,
        },
      ],
    }),
    [chartPalette, periodData]
  );
  const comparisonChartData = useMemo(
    () => ({
      labels: periodData.metrics.labels,
      datasets: [
        {
          label: 'This period',
          data: periodData.metrics.current,
          backgroundColor: chartPalette.barPrimary,
          borderRadius: 6,
          borderSkipped: false,
        },
        {
          label: 'Last period',
          data: periodData.metrics.previous,
          backgroundColor: chartPalette.barSecondary,
          borderRadius: 6,
          borderSkipped: false,
        },
      ],
    }),
    [chartPalette, periodData]
  );
  const categoryChartData = useMemo(
    () => ({
      labels: periodData.categories.labels,
      datasets: [
        {
          label: 'Revenue',
          data: periodData.categories.values,
          backgroundColor: chartPalette.doughnut,
          borderColor: chartPalette.canvas,
          borderWidth: 2,
        },
      ],
    }),
    [chartPalette, periodData]
  );
  const trendTable = useMemo(
    () => ({
      caption: `Daily revenue for ${periodData.label}`,
      headers: ['Day', 'This period', 'Previous period'],
      rows: periodData.labels.map((label, index) => ({
        id: `trend-${selectedPeriod}-${label}`,
        cells: [
          label,
          formatCurrencyValue(periodData.current[index]),
          formatCurrencyValue(periodData.previous[index]),
        ],
      })),
    }),
    [periodData, selectedPeriod]
  );
  const comparisonTable = useMemo(
    () => ({
      caption: `Period comparison metrics for ${periodData.label}`,
      headers: ['Metric', 'This period', 'Last period'],
      rows: periodData.metrics.labels.map((label, index) => ({
        id: `metric-${selectedPeriod}-${label}`,
        cells: [
          label,
          formatCurrencyValue(periodData.metrics.current[index]),
          formatCurrencyValue(periodData.metrics.previous[index]),
        ],
      })),
    }),
    [periodData, selectedPeriod]
  );
  const categoryTable = useMemo(
    () => ({
      caption: `Revenue by product category for ${periodData.label}`,
      headers: ['Category', 'Revenue'],
      rows: periodData.categories.labels.map((label, index) => ({
        id: `category-${selectedPeriod}-${label}`,
        cells: [label, formatCurrencyValue(periodData.categories.values[index])],
      })),
    }),
    [periodData, selectedPeriod]
  );
  const trendChartOptions = useMemo(
    () => ({
      plugins: {
        legend: {
          position: 'top',
        },
      },
      scales: {
        x: {
          ticks: {
            maxTicksLimit: periodData.labels.length > 14 ? 8 : 7,
          },
        },
      },
    }),
    [periodData.labels.length]
  );
  const comparisonChartOptions = useMemo(
    () => ({
      plugins: {
        legend: {
          position: 'bottom',
        },
      },
      scales: {
        x: {
          grid: {
            display: false,
          },
        },
      },
    }),
    []
  );
  const categoryChartOptions = useMemo(
    () => ({
      plugins: {
        legend: {
          position: 'bottom',
        },
      },
      cutout: '62%',
    }),
    []
  );
  const totalRevenue = useMemo(
    () => periodData.current.reduce((sum, value) => sum + value, 0),
    [periodData]
  );
  const previousRevenue = useMemo(
    () => periodData.previous.reduce((sum, value) => sum + value, 0),
    [periodData]
  );
  const revenueDelta = previousRevenue
    ? (totalRevenue - previousRevenue) / previousRevenue
    : 0;
  const trendLabel = `${revenueDelta >= 0 ? '+' : ''}${(revenueDelta * 100).toFixed(1)}% vs last period`;
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
                <div className="relative">
                  <div className="flex items-center gap-3 rounded-2xl border border-slate-700/70 bg-slate-950/40 px-4 py-3 focus-within:border-sky-400/80">
                    <input
                      id="agentwp-prompt"
                      ref={inputRef}
                      type="text"
                      value={prompt}
                      onChange={handlePromptChange}
                      onKeyDown={handlePromptKeyDown}
                      onFocus={handlePromptFocus}
                      onBlur={handlePromptBlur}
                      aria-expanded={showTypeahead}
                      aria-controls="agentwp-typeahead"
                      aria-activedescendant={activeSuggestionId}
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

                  {showTypeahead && (
                    <div
                      id="agentwp-typeahead"
                      role="listbox"
                      className="absolute left-0 right-0 z-30 mt-2 max-h-80 overflow-y-auto rounded-2xl border border-slate-700/70 bg-slate-950/95 p-3 shadow-xl backdrop-blur"
                    >
                      {isTypeaheadLoading && (
                        <div className="px-2 pb-2 text-xs uppercase tracking-[0.3em] text-slate-500">
                          Searching...
                        </div>
                      )}
                      {suggestionGroups.map(({ type, items }) => {
                        if (!items.length) {
                          return null;
                        }
                        const config = TYPEAHEAD_CONFIG[type];
                        return (
                          <div key={type} className="pb-3 last:pb-0">
                            <div className="flex items-center gap-2 px-2 text-[11px] font-semibold uppercase tracking-[0.3em] text-slate-500">
                              <span className="text-slate-400">{config?.label || type}</span>
                            </div>
                            <div className="mt-2 space-y-1">
                              {items.map((item) => {
                                const isActive = item._index === activeSuggestionIndex;
                                return (
                                  <button
                                    key={`${item.type}-${item.id}-${item._index}`}
                                    id={`agentwp-suggestion-${item._index}`}
                                    type="button"
                                    role="option"
                                    aria-selected={isActive}
                                    onMouseDown={(event) => event.preventDefault()}
                                    onMouseEnter={() => setActiveSuggestionIndex(item._index)}
                                    onClick={() => handleSuggestionSelect(item)}
                                    className={`flex w-full items-start gap-3 rounded-xl border px-3 py-2 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 ${
                                      isActive
                                        ? 'border-sky-400/70 bg-slate-900/80 text-white'
                                        : 'border-transparent bg-slate-950/30 text-slate-200 hover:border-slate-600/60 hover:bg-slate-900/70'
                                    }`}
                                  >
                                    <span className="mt-1 text-slate-400">{config?.icon}</span>
                                    <span className="flex-1">
                                      <span className="block text-sm font-semibold text-slate-100">
                                        {renderHighlightedText(item.primary || '', prompt)}
                                      </span>
                                      {item.secondary ? (
                                        <span className="block text-xs text-slate-400">
                                          {renderHighlightedText(item.secondary, prompt)}
                                        </span>
                                      ) : null}
                                    </span>
                                  </button>
                                );
                              })}
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  )}
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

              <div className="rounded-2xl border border-slate-800/80 bg-slate-950/40 px-4 py-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.3em] text-slate-500">
                      Analytics charts
                    </p>
                    <p className="text-sm text-slate-300">
                      Visualize revenue trends and period performance inside the Command Deck.
                    </p>
                  </div>
                  <div className="min-w-[200px]">
                    <label
                      htmlFor="agentwp-analytics-period"
                      className="block text-[11px] font-semibold uppercase tracking-[0.3em] text-slate-500"
                    >
                      Period
                    </label>
                    <select
                      id="agentwp-analytics-period"
                      value={selectedPeriod}
                      onChange={(event) => setSelectedPeriod(event.target.value)}
                      className="mt-2 w-full rounded-xl border border-slate-700/70 bg-slate-950/40 px-3 py-2 text-sm text-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400"
                    >
                      {PERIOD_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
                <div className="mt-4 grid gap-4">
                  <ChartCard
                    title="Sales trend"
                    subtitle="Daily revenue with previous period overlay"
                    metric={formatCurrencyValue(totalRevenue)}
                    trend={trendLabel}
                    theme="auto"
                    type="line"
                    data={trendChartData}
                    options={trendChartOptions}
                    meta={
                      <>
                        <span>Revenue</span>
                        <span>{periodData.label}</span>
                      </>
                    }
                    table={trendTable}
                    exportFilename={`agentwp-sales-trend-${selectedPeriod}.png`}
                    valueFormatter={formatCurrencyValue}
                    height={240}
                  />
                  <ChartCard
                    title="Period comparison"
                    subtitle="This period vs last period metrics"
                    theme="auto"
                    type="bar"
                    data={comparisonChartData}
                    options={comparisonChartOptions}
                    meta={
                      <>
                        <span>Totals</span>
                        <span>{periodData.label}</span>
                      </>
                    }
                    table={comparisonTable}
                    exportFilename={`agentwp-comparison-${selectedPeriod}.png`}
                    valueFormatter={formatCurrencyValue}
                    height={200}
                  />
                  <ChartCard
                    title="Category breakdown"
                    subtitle="Revenue by product category"
                    theme="auto"
                    type="doughnut"
                    data={categoryChartData}
                    options={categoryChartOptions}
                    meta={
                      <>
                        <span>Revenue mix</span>
                        <span>{periodData.label}</span>
                      </>
                    }
                    table={categoryTable}
                    exportFilename={`agentwp-category-${selectedPeriod}.png`}
                    valueFormatter={formatCurrencyValue}
                    height={220}
                  />
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

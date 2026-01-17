import { useRef, useCallback, useState, useEffect, useLayoutEffect } from 'react';
import { CommandDeckHeader } from './CommandDeckHeader';
import { PromptInput } from './PromptInput';
import { ResponseArea } from './ResponseArea';
import { TypeaheadDropdown } from './TypeaheadDropdown';
import { OfflineBanner } from './OfflineBanner';
import { VoiceControls } from '../voice';
import { useModalStore } from '../../stores/useModalStore';
import { useThemeStore } from '../../stores/useThemeStore';
import { useFocusTrap } from '../../hooks/useFocusTrap';
import { useIsOnline } from '../../hooks/useHealthCheck';
import { useDebouncedSearch } from '../../hooks/useSearch';
import { useVoice } from '../../hooks/useVoice';
import { buildErrorState } from '../../utils/error';
import agentwpClient from '../../api/AgentWPClient';
import type { SearchResult } from '../../types';

interface CommandDeckProps {
  onClose?: () => void;
}

type IntentResponseData = {
  response?: string;
  message?: string;
};

export function CommandDeck({ onClose }: CommandDeckProps) {
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const blurTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [showTypeahead, setShowTypeahead] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);

  // Focus trap for accessibility
  const { containerRef: modalRef } = useFocusTrap<HTMLDivElement>({
    enabled: true,
    autoFocus: true,
    restoreFocus: true,
  });

  // Stores
  const {
    loading,
    prompt,
    response,
    errorState,
    setPrompt,
    setLoading,
    setResponse,
    setError,
    close,
  } = useModalStore();

  const { resolved: theme, preference: themePreference, toggle: toggleTheme } = useThemeStore();

  // Voice recognition and synthesis
  const {
    sttSupported: voiceSupported,
    ttsSupported,
    isListening,
    isSpeaking,
    finalTranscript,
    error: voiceError,
    startListening,
    stopListening,
    speakResponse,
    stopSpeaking,
    resetTranscripts,
    resetAll: resetVoice,
  } = useVoice();

  // Hooks
  const isOnline = useIsOnline();
  const {
    setQuery: setSearchQuery,
    results: searchResults,
    isLoading: searchLoading,
    hasResults: hasSearchResults,
  } = useDebouncedSearch();

  const handleClose = useCallback(() => {
    // Stop any ongoing voice activity and clear error
    resetVoice();
    close();
    onClose?.();
  }, [resetVoice, close, onClose]);

  const handlePromptChange = useCallback(
    (value: string) => {
      setPrompt(value);
      setSearchQuery(value);
      setShowTypeahead(value.length >= 2);
      setActiveIndex(0);
    },
    [setPrompt, setSearchQuery],
  );

  const handleSubmit = useCallback(async () => {
    if (!prompt.trim() || loading) return;

    setShowTypeahead(false);
    setLoading(true);
    setError(null);
    setResponse('');

    try {
      const result = await agentwpClient.processIntent<IntentResponseData>(prompt);

      if (result.success) {
        const responseText = result.data.response || result.data.message || '';
        setResponse(responseText);
        return;
      }

      const errorState = buildErrorState({
        message: result.error.message,
        code: result.error.code,
        status: result.error.status,
        meta: result.error.meta,
      });
      setError({
        message: errorState.message,
        code: errorState.code,
        retryable: errorState.retryable,
      });
    } catch (err) {
      const errorState = buildErrorState({
        message: err instanceof Error ? err.message : undefined,
        status: 0, // Network error
      });
      setError({
        message: errorState.message,
        code: errorState.code,
        retryable: errorState.retryable,
      });
    } finally {
      setLoading(false);
    }
  }, [prompt, loading, setLoading, setError, setResponse]);

  const handleRetry = useCallback(() => {
    handleSubmit();
  }, [handleSubmit]);

  const handleSelectResult = useCallback(
    (result: SearchResult) => {
      setPrompt(result.title);
      setShowTypeahead(false);
      inputRef.current?.focus();
    },
    [setPrompt],
  );

  const handleKeyDown = useCallback(
    (event: React.KeyboardEvent) => {
      if (!showTypeahead || !hasSearchResults) return;

      const allResults = [
        ...searchResults.products,
        ...searchResults.orders,
        ...searchResults.customers,
      ];

      switch (event.key) {
        case 'ArrowDown':
          event.preventDefault();
          setActiveIndex((prev) => (prev + 1) % allResults.length);
          break;
        case 'ArrowUp':
          event.preventDefault();
          setActiveIndex((prev) => (prev - 1 + allResults.length) % allResults.length);
          break;
        case 'Enter':
          if (allResults[activeIndex]) {
            event.preventDefault();
            handleSelectResult(allResults[activeIndex]);
          }
          break;
        case 'Escape':
          setShowTypeahead(false);
          break;
      }
    },
    [showTypeahead, hasSearchResults, searchResults, activeIndex, handleSelectResult],
  );

  // Sync voice transcript to prompt
  useEffect(() => {
    if (finalTranscript) {
      setPrompt(finalTranscript);
      setSearchQuery(finalTranscript);
    }
  }, [finalTranscript, setPrompt, setSearchQuery]);

  // Cleanup blur timeout on unmount
  useLayoutEffect(() => {
    return () => {
      if (blurTimeoutRef.current) {
        clearTimeout(blurTimeoutRef.current);
      }
    };
  }, []);

  const handleStartListening = useCallback(() => {
    resetTranscripts();
    startListening();
  }, [resetTranscripts, startListening]);

  const handleStopListening = useCallback(() => {
    stopListening();
  }, [stopListening]);

  const handleSpeak = useCallback(() => {
    if (response) {
      speakResponse(response);
    }
  }, [response, speakResponse]);

  const handleStopSpeaking = useCallback(() => {
    stopSpeaking();
  }, [stopSpeaking]);

  const handleBackdropClick = useCallback(
    (event: React.MouseEvent) => {
      if (event.target === event.currentTarget) {
        handleClose();
      }
    },
    [handleClose],
  );

  return (
    <div
      className="fixed inset-0 z-50 flex animate-fade-in items-center justify-center bg-slate-950/70 px-4 py-10 backdrop-blur-sm motion-reduce:animate-none"
      role="presentation"
      onMouseDown={handleBackdropClick}
    >
      <div
        ref={modalRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="command-deck-title"
        className="w-full max-w-2xl animate-deck-in rounded-2xl border border-slate-700/60 bg-slate-900/95 shadow-2xl backdrop-blur-xl motion-reduce:animate-none"
      >
        <CommandDeckHeader
          theme={theme}
          themePreference={themePreference}
          onThemeToggle={toggleTheme}
          onClose={handleClose}
          isOffline={!isOnline}
        />

        {!isOnline && <OfflineBanner />}

        <div className="p-4">
          <div className="relative">
            <div className="flex gap-2">
              <PromptInput
                ref={inputRef}
                value={prompt}
                onChange={handlePromptChange}
                onSubmit={handleSubmit}
                onKeyDown={handleKeyDown}
                onFocus={() => {
                  // Clear any pending blur timeout to prevent flicker
                  if (blurTimeoutRef.current) {
                    clearTimeout(blurTimeoutRef.current);
                    blurTimeoutRef.current = null;
                  }
                  if (prompt.length >= 2) {
                    setShowTypeahead(true);
                  }
                }}
                onBlur={() => {
                  blurTimeoutRef.current = setTimeout(() => setShowTypeahead(false), 150);
                }}
                loading={loading}
                disabled={!isOnline}
                placeholder={isOnline ? 'Ask me anything...' : 'Reconnecting...'}
              />

              <VoiceControls
                isSupported={voiceSupported}
                isListening={isListening}
                isSpeaking={isSpeaking}
                ttsSupported={ttsSupported}
                onStartListening={handleStartListening}
                onStopListening={handleStopListening}
                onSpeak={handleSpeak}
                onStopSpeaking={handleStopSpeaking}
                disabled={loading || !isOnline}
                hasResponse={!!response}
              />

              <button
                type="button"
                onClick={handleSubmit}
                disabled={loading || !prompt.trim() || !isOnline}
                className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-slate-900 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {loading ? 'Sending...' : 'Send'}
              </button>
            </div>

            <TypeaheadDropdown
              results={searchResults}
              query={prompt}
              isOpen={showTypeahead && hasSearchResults}
              isLoading={searchLoading}
              activeIndex={activeIndex}
              onSelect={handleSelectResult}
            />
          </div>

          {voiceError && (
            <p className="mt-2 text-xs text-red-400" role="alert">
              {voiceError}
            </p>
          )}

          {(response || loading || errorState) && (
            <div className="mt-4 rounded-lg border border-slate-700/60 bg-slate-800/50 p-4">
              <ResponseArea
                content={response}
                loading={loading}
                error={errorState?.message}
                onRetry={errorState?.retryable ? handleRetry : undefined}
              />
            </div>
          )}
        </div>

        <div className="flex items-center justify-between border-t border-slate-700/50 px-4 py-3 text-xs text-slate-500">
          <span>Press Esc to close</span>
          <span>↑↓ to navigate • Enter to select</span>
        </div>
      </div>
    </div>
  );
}

import { useCallback, useEffect, useRef } from 'react';
import { getFocusableElements as getFocusableElementsFromDom } from '../utils/dom';

interface UseFocusTrapOptions {
  enabled?: boolean;
  autoFocus?: boolean;
  restoreFocus?: boolean;
}

export function useFocusTrap<T extends HTMLElement>(options: UseFocusTrapOptions = {}) {
  const { enabled = true, autoFocus = true, restoreFocus = true } = options;
  const containerRef = useRef<T | null>(null);
  const previousActiveElementRef = useRef<HTMLElement | null>(null);

  const getFocusableElements = useCallback((): HTMLElement[] => {
    return getFocusableElementsFromDom(containerRef.current);
  }, []);

  const focusFirst = useCallback(() => {
    const elements = getFocusableElements();
    const firstElement = elements[0];
    if (firstElement) {
      firstElement.focus();
    }
  }, [getFocusableElements]);

  const focusLast = useCallback(() => {
    const elements = getFocusableElements();
    const lastElement = elements[elements.length - 1];
    if (lastElement) {
      lastElement.focus();
    }
  }, [getFocusableElements]);

  const handleKeyDown = useCallback(
    (event: KeyboardEvent) => {
      if (!enabled || event.key !== 'Tab') return;

      const elements = getFocusableElements();
      if (elements.length === 0) return;

      const firstElement = elements[0];
      const lastElement = elements[elements.length - 1];
      if (!firstElement || !lastElement) return;

      const activeElement = document.activeElement;

      if (event.shiftKey) {
        // Shift + Tab: wrap to last element
        if (activeElement === firstElement) {
          event.preventDefault();
          lastElement.focus();
        }
      } else {
        // Tab: wrap to first element
        if (activeElement === lastElement) {
          event.preventDefault();
          firstElement.focus();
        }
      }
    },
    [enabled, getFocusableElements],
  );

  // Store previous active element and auto-focus
  useEffect(() => {
    if (!enabled) return;

    previousActiveElementRef.current = document.activeElement as HTMLElement;

    if (autoFocus) {
      // Delay focus to allow animation
      const timeoutId = setTimeout(focusFirst, 50);
      return () => clearTimeout(timeoutId);
    }
  }, [enabled, autoFocus, focusFirst]);

  // Restore focus on cleanup
  useEffect(() => {
    if (!enabled || !restoreFocus) return;

    return () => {
      const previousElement = previousActiveElementRef.current;
      if (previousElement && typeof previousElement.focus === 'function') {
        previousElement.focus();
      }
    };
  }, [enabled, restoreFocus]);

  // Add keydown listener
  useEffect(() => {
    if (!enabled) return;

    const container = containerRef.current;
    if (!container) return;

    container.addEventListener('keydown', handleKeyDown);

    return () => {
      container.removeEventListener('keydown', handleKeyDown);
    };
  }, [enabled, handleKeyDown]);

  return {
    containerRef,
    focusFirst,
    focusLast,
    getFocusableElements,
  };
}

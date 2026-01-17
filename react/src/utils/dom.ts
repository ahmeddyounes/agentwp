/**
 * DOM utility functions.
 */

export const FOCUSABLE_SELECTORS = [
  'a[href]',
  'button:not([disabled])',
  'textarea:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
];

export const isEditableTarget = (target: EventTarget | null): boolean => {
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

export const getComposedTarget = (event: Event | null): EventTarget | null => {
  if (!event) {
    return null;
  }
  if (typeof event.composedPath === 'function') {
    const path = event.composedPath();
    if (path && path.length > 0) {
      return path[0] ?? null;
    }
  }
  return event.target;
};

export const getActiveElement = (shadowRoot?: ShadowRoot | null): Element | null => {
  if (shadowRoot && shadowRoot.activeElement) {
    return shadowRoot.activeElement;
  }
  if (typeof document === 'undefined') {
    return null;
  }
  return document.activeElement;
};

export const getNow = (): number => {
  if (typeof performance !== 'undefined' && typeof performance.now === 'function') {
    return performance.now();
  }
  return Date.now();
};

export const getFocusableElements = (container: HTMLElement | null): HTMLElement[] => {
  if (!container) {
    return [];
  }
  return Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTORS.join(','))).filter(
    (element) => !element.hasAttribute('disabled') && element.tabIndex !== -1,
  );
};

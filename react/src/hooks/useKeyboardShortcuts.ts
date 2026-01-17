import { useCallback, useEffect } from 'react';
import { useModalStore } from '../stores';
import { isEditableTarget, getComposedTarget } from '../utils/dom';

interface UseKeyboardShortcutsOptions {
  shadowRoot?: ShadowRoot | null;
  onToggle?: () => void;
  onClose?: () => void;
}

export function useKeyboardShortcuts(options: UseKeyboardShortcutsOptions = {}) {
  const { shadowRoot, onToggle, onClose } = options;
  const { isOpen, toggle, close } = useModalStore();

  const handleKeyDown = useCallback(
    (event: KeyboardEvent) => {
      const target = getComposedTarget(event);

      // Cmd/Ctrl + K to toggle
      if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
        event.preventDefault();
        event.stopPropagation();
        toggle();
        onToggle?.();
        return;
      }

      // Escape to close (only when open and not in editable field)
      if (event.key === 'Escape' && isOpen) {
        // Allow Escape in editable fields if needed for other purposes
        if (!isEditableTarget(target)) {
          event.preventDefault();
          close();
          onClose?.();
        }
      }
    },
    [isOpen, toggle, close, onToggle, onClose],
  );

  useEffect(() => {
    // Listen on document for global shortcuts
    document.addEventListener('keydown', handleKeyDown, true);

    // Also listen on shadow root if provided
    if (shadowRoot) {
      shadowRoot.addEventListener('keydown', handleKeyDown as EventListener, true);
    }

    return () => {
      document.removeEventListener('keydown', handleKeyDown, true);
      if (shadowRoot) {
        shadowRoot.removeEventListener('keydown', handleKeyDown as EventListener, true);
      }
    };
  }, [handleKeyDown, shadowRoot]);

  return { isOpen, toggle, close };
}

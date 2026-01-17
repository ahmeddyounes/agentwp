/**
 * Clipboard utilities for copying text and HTML content.
 */

interface ClipboardPayload {
  text?: string;
  html?: string;
}

export const fallbackCopyToClipboard = ({ text, html }: ClipboardPayload): boolean => {
  if (typeof document === 'undefined') {
    return false;
  }
  const activeElement = document.activeElement as HTMLElement | null;
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

export const copyToClipboard = async ({ text, html }: ClipboardPayload): Promise<boolean> => {
  if (typeof navigator !== 'undefined' && navigator.clipboard) {
    try {
      if (
        html &&
        typeof window !== 'undefined' &&
        window.ClipboardItem &&
        navigator.clipboard.write
      ) {
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
    } catch {
      return fallbackCopyToClipboard({ text, html });
    }
  }
  return fallbackCopyToClipboard({ text, html });
};

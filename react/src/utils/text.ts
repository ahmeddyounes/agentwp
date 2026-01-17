import { createElement } from 'react';
import type { ReactNode } from 'react';

/**
 * Text manipulation utilities.
 */

export const stripMarkdownToPlainText = (markdown: string): string => {
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

export const escapeHtml = (value: string): string =>
  value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

export const formatPlainTextAsHtml = (text: string): string => {
  if (!text) {
    return '';
  }
  const safeText = escapeHtml(text);
  const paragraphs = safeText.split(/\n{2,}/).filter(Boolean);
  return paragraphs.map((paragraph) => `<p>${paragraph.replace(/\n/g, '<br />')}</p>`).join('');
};

export const truncateText = (value: string, limit = 140): string => {
  if (!value) {
    return '';
  }
  const trimmed = value.trim();
  if (trimmed.length <= limit) {
    return trimmed;
  }
  return `${trimmed.slice(0, Math.max(1, limit - 3))}...`;
};

const escapeRegExp = (value: string): string => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const getHighlightTokens = (query: string): string[] => {
  if (!query) {
    return [];
  }
  return query.trim().toLowerCase().split(/\s+/).filter(Boolean).slice(0, 6);
};

export const renderHighlightedText = (text: string, query: string): ReactNode => {
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
      return createElement(
        'mark',
        {
          key: `${part}-${index}`,
          className: 'rounded bg-sky-500/30 px-1 text-sky-100',
        },
        part,
      );
    }
    return part;
  });
};

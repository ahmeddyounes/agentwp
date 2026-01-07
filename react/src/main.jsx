import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App.jsx';
import ErrorBoundary from '../components/ErrorBoundary.jsx';
import styles from './index.css?inline';
import { applyTheme, getInitialThemePreference, getSystemTheme, resolveTheme } from './theme.js';

const getHostElement = () => {
  if (typeof document === 'undefined') {
    return null;
  }
  return document.getElementById('agentwp-root') || document.getElementById('root');
};

const ensureShadowNode = (shadowRoot, selector, createNode) => {
  const existing = shadowRoot.querySelector(selector);
  if (existing) {
    return existing;
  }
  const node = createNode();
  shadowRoot.appendChild(node);
  return node;
};

const mountShadowApp = (hostElement) => {
  const shadowRoot = hostElement.shadowRoot || hostElement.attachShadow({ mode: 'open' });
  const styleNode = ensureShadowNode(shadowRoot, 'style[data-agentwp-shadow]', () => {
    const style = document.createElement('style');
    style.setAttribute('data-agentwp-shadow', 'true');
    return style;
  });
  styleNode.textContent = styles;
  const appRoot = ensureShadowNode(shadowRoot, '#agentwp-shadow-root', () => {
    const container = document.createElement('div');
    container.id = 'agentwp-shadow-root';
    return container;
  });
  const portalRoot = ensureShadowNode(shadowRoot, '#agentwp-portal-root', () => {
    const container = document.createElement('div');
    container.id = 'agentwp-portal-root';
    return container;
  });
  return { shadowRoot, appRoot, portalRoot };
};

const hostElement = getHostElement();
if (hostElement) {
  const { shadowRoot, appRoot, portalRoot } = mountShadowApp(hostElement);
  const initialPreference = getInitialThemePreference();
  const resolvedTheme = resolveTheme(initialPreference, getSystemTheme() === 'dark');
  applyTheme(resolvedTheme, hostElement);

  ReactDOM.createRoot(appRoot).render(
    <React.StrictMode>
      <ErrorBoundary>
        <App shadowRoot={shadowRoot} portalRoot={portalRoot} themeTarget={hostElement} />
      </ErrorBoundary>
    </React.StrictMode>
  );
}

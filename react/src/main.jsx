const OPEN_STATE_KEY = 'agentwp-command-deck-open';
const ADMIN_TRIGGER_SELECTORS = [
  '#wp-admin-bar-agentwp',
  '[data-agentwp-command-deck]',
  '#agentwp-command-deck',
];

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

const mountShadowApp = (hostElement, styles) => {
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

const getComposedTarget = (event) => {
  if (!event) {
    return null;
  }
  if (typeof event.composedPath === 'function') {
    const path = event.composedPath();
    if (path && path.length > 0) {
      return path[0];
    }
  }
  return event.target;
};

const markOpenState = (isOpen) => {
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
};

const shouldAutoLoad = () => {
  if (typeof window === 'undefined') {
    return false;
  }
  try {
    return window.sessionStorage.getItem(OPEN_STATE_KEY) === 'true';
  } catch (error) {
    return false;
  }
};

let appPromise = null;
let appLoaded = false;

const loadCommandDeck = async (openOnLoad = false) => {
  if (openOnLoad) {
    markOpenState(true);
  }

  if (appLoaded) {
    return null;
  }

  if (appPromise) {
    return appPromise;
  }

  const hostElement = getHostElement();
  if (!hostElement) {
    return null;
  }

  appPromise = (async () => {
    const [
      ReactModule,
      ReactDOM,
      AppModule,
      ErrorBoundaryModule,
      stylesModule,
      themeModule,
    ] = await Promise.all([
      import('react'),
      import('react-dom/client'),
      import('./App.jsx'),
      import('../components/ErrorBoundary.jsx'),
      import('./index.css?inline'),
      import('./theme.js'),
    ]);

    const { StrictMode } = ReactModule;
    const App = AppModule.default;
    const ErrorBoundary = ErrorBoundaryModule.default;
    const styles = stylesModule.default || '';
    const { applyTheme, getInitialThemePreference, getSystemTheme, resolveTheme } = themeModule;

    const { shadowRoot, appRoot, portalRoot } = mountShadowApp(hostElement, styles);
    const initialPreference = getInitialThemePreference();
    const resolvedTheme = resolveTheme(initialPreference, getSystemTheme() === 'dark');
    applyTheme(resolvedTheme, hostElement);

    ReactDOM.createRoot(appRoot).render(
      <StrictMode>
        <ErrorBoundary>
          <App shadowRoot={shadowRoot} portalRoot={portalRoot} themeTarget={hostElement} />
        </ErrorBoundary>
      </StrictMode>
    );

    appLoaded = true;
    return null;
  })().finally(() => {
    appPromise = null;
  });

  return appPromise;
};

const handleHotkey = (event) => {
  if (appLoaded) {
    return;
  }
  const isMac = typeof navigator !== 'undefined' && /Mac|iPod|iPhone|iPad/.test(navigator.platform);
  const modifierPressed = isMac ? event.metaKey : event.ctrlKey;
  if (!modifierPressed || event.shiftKey || event.altKey || event.repeat) {
    return;
  }
  if (event.key.toLowerCase() !== 'k') {
    return;
  }
  const target = getComposedTarget(event);
  if (event.defaultPrevented || isEditableTarget(target)) {
    return;
  }
  event.preventDefault();
  loadCommandDeck(true);
};

const handleAdminTrigger = (event) => {
  if (appLoaded) {
    return;
  }
  const target = event.target;
  if (!(target instanceof HTMLElement)) {
    return;
  }
  const isTrigger = ADMIN_TRIGGER_SELECTORS.some((selector) => target.closest(selector));
  if (!isTrigger) {
    return;
  }
  event.preventDefault();
  loadCommandDeck(true);
};

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
  window.addEventListener('keydown', handleHotkey);
  document.addEventListener('click', handleAdminTrigger);

  if (shouldAutoLoad()) {
    loadCommandDeck(true);
  }
}

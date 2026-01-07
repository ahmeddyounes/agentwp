import '@testing-library/jest-dom';

beforeAll(() => {
  globalThis.__IMPORT_META__ = { env: { DEV: false } };
});

if (!window.matchMedia) {
  window.matchMedia = (query) => ({
    matches: false,
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
    addListener: () => {},
    removeListener: () => {},
    onchange: null,
    dispatchEvent: () => false,
  });
}

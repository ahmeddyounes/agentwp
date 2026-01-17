import { render, screen } from '@testing-library/react';
import { vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import App from '../src/App';

// MSW is set up in setupTests.ts and handles API mocking automatically
// No need for manual globalThis.fetch mocking

const createTestQueryClient = () =>
  new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
        gcTime: 0,
      },
    },
  });

const renderWithQueryClient = (ui: ReactElement) => {
  const queryClient = createTestQueryClient();
  return render(<QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>);
};

// Mock shepherd.js for demo tour
vi.mock('shepherd.js', () => ({
  __esModule: true,
  default: class Shepherd {
    static activeTour = null;
    steps: unknown[] = [];
    constructor() {
      this.steps = [];
    }
    addStep() {
      return this;
    }
    start() {}
    complete() {}
    cancel() {}
    on() {}
    off() {}
  },
}));

vi.mock('shepherd.js/dist/css/shepherd.css', () => ({}));

describe('App', () => {
  let portalRoot: HTMLDivElement;

  beforeEach(() => {
    portalRoot = document.createElement('div');
    portalRoot.id = 'portal-root';
    document.body.appendChild(portalRoot);
  });

  afterEach(() => {
    portalRoot.remove();
  });

  it('renders the landing page with Open Command Deck button', async () => {
    const { unmount } = renderWithQueryClient(<App portalRoot={portalRoot} />);

    // Verify the Open Command Deck button is rendered
    const button = screen.getByRole('button', { name: /Open Command Deck/ });
    expect(button).toBeInTheDocument();

    unmount();
  });
});

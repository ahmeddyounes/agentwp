import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import App from '../src/App.jsx';

jest.mock('../components/cards', () => ({
  ChartCard: ({ title }) => <div data-testid="chart-card">{title}</div>,
  ErrorCard: ({ title }) => <div data-testid="error-card">{title}</div>,
}));

jest.mock('react-markdown', () => ({
  __esModule: true,
  default: ({ children }) => <div>{children}</div>,
}));

jest.mock('html2canvas', () => ({
  __esModule: true,
  default: jest.fn(() => Promise.resolve({})),
}));

describe('App', () => {
  it('opens the command deck modal', async () => {
    const user = userEvent.setup();
    const portalRoot = document.createElement('div');
    document.body.appendChild(portalRoot);

    global.fetch = jest.fn(async () => ({
      ok: true,
      json: async () => ({ success: true, data: {} }),
    }));

    const { unmount } = render(<App portalRoot={portalRoot} />);

    await user.click(screen.getByRole('button', { name: 'Open Command Deck' }));

    const dialog = await screen.findByRole('dialog', { name: 'Command Deck' });
    expect(dialog).toHaveAttribute('aria-modal', 'true');

    unmount();
    portalRoot.remove();
  });
});

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';
import ErrorBoundary from '../../src/components/ErrorBoundary';

const Thrower = (): JSX.Element => {
  throw new Error('Boom');
};

describe('ErrorBoundary', () => {
  it('renders fallback UI and reloads', async () => {
    const user = userEvent.setup();
    const onError = vi.fn();
    const reload = vi.fn();
    const originalLocation = window.location;

    Object.defineProperty(window, 'location', {
      value: { ...originalLocation, reload },
      writable: true,
    });

    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

    render(
      <ErrorBoundary onError={onError}>
        <Thrower />
      </ErrorBoundary>,
    );

    expect(screen.getByText('AgentWP ran into a problem')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Reload' }));

    expect(onError).toHaveBeenCalledTimes(1);
    expect(reload).toHaveBeenCalledTimes(1);

    consoleSpy.mockRestore();
    Object.defineProperty(window, 'location', { value: originalLocation });
  });
});

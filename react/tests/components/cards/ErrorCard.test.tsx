import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';
import ErrorCard from '../../../components/cards/ErrorCard';

describe('ErrorCard', () => {
  it('renders retry and report actions', async () => {
    const user = userEvent.setup();
    const onRetry = vi.fn();
    const onReport = vi.fn();

    render(
      <ErrorCard
        title="Failure"
        message="Something broke"
        retryLabel="Try again"
        reportLabel="Report"
        onRetry={onRetry}
        onReport={onReport}
      />,
    );

    expect(screen.getByText('Something broke')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Try again' }));
    await user.click(screen.getByRole('button', { name: 'Report' }));

    expect(onRetry).toHaveBeenCalledTimes(1);
    expect(onReport).toHaveBeenCalledTimes(1);
  });

  it('renders report link when provided', () => {
    render(<ErrorCard reportHref="https://example.com" reportLabel="Report issue" />);

    const link = screen.getByRole('link', { name: 'Report issue' });
    expect(link).toHaveAttribute('href', 'https://example.com');
  });
});

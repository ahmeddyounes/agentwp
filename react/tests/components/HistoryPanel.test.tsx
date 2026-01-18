import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';
import HistoryPanel, { type CommandHistoryEntry } from '../../src/components/HistoryPanel';

describe('HistoryPanel', () => {
  const entry: CommandHistoryEntry = {
    id: '1',
    raw_input: 'Check order',
    parsed_intent: 'order_status',
    timestamp: Date.now(),
    was_successful: true,
  };

  it('renders history and handles interactions', async () => {
    const user = userEvent.setup();
    const onRun = vi.fn();
    const onDelete = vi.fn();
    const onToggleFavorite = vi.fn();
    const onClearHistory = vi.fn();

    render(
      <HistoryPanel
        history={[entry]}
        historyCount={1}
        favorites={[entry]}
        mostUsed={[{ raw_input: 'Refund order', count: 2 }]}
        onRun={onRun}
        onDelete={onDelete}
        onToggleFavorite={onToggleFavorite}
        onClearHistory={onClearHistory}
      />,
    );

    // Click the command button (entry appears in both favorites and history, get first)
    const checkOrderButtons = screen.getAllByRole('button', { name: /Check order/ });
    expect(checkOrderButtons).toHaveLength(2);
    await user.click(checkOrderButtons[0]!);
    // Remove favorite button exists only in favorites section
    const removeFavoriteButtons = screen.getAllByRole('button', { name: 'Remove favorite' });
    expect(removeFavoriteButtons.length).toBeGreaterThan(0);
    await user.click(removeFavoriteButtons[0]!);
    await user.click(screen.getByRole('button', { name: 'Remove from history' }));
    await user.click(screen.getByRole('button', { name: 'Clear history' }));
    await user.click(screen.getByRole('button', { name: /Refund order/ }));

    expect(onRun).toHaveBeenCalledWith('Check order');
    expect(onToggleFavorite).toHaveBeenCalledWith(entry);
    expect(onDelete).toHaveBeenCalledWith(entry);
    expect(onClearHistory).toHaveBeenCalledTimes(1);
    expect(onRun).toHaveBeenCalledWith('Refund order');
  });
});

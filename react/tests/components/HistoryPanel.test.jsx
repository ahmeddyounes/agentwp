import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import HistoryPanel from '../../components/HistoryPanel.jsx';

describe('HistoryPanel', () => {
  const entry = {
    id: '1',
    raw_input: 'Check order',
    parsed_intent: 'order_status',
    timestamp: Date.now(),
    was_successful: true,
  };

  it('renders history and handles interactions', async () => {
    const user = userEvent.setup();
    const onRun = jest.fn();
    const onDelete = jest.fn();
    const onToggleFavorite = jest.fn();
    const onClearHistory = jest.fn();

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
      />
    );

    await user.click(screen.getByRole('button', { name: 'Check order' }));
    await user.click(screen.getByRole('button', { name: 'Remove favorite' }));
    await user.click(screen.getByRole('button', { name: 'Remove from history' }));
    await user.click(screen.getByRole('button', { name: 'Clear history' }));
    await user.click(screen.getByRole('button', { name: 'Refund order' }));

    expect(onRun).toHaveBeenCalledWith('Check order');
    expect(onToggleFavorite).toHaveBeenCalledWith(entry);
    expect(onDelete).toHaveBeenCalledWith(entry);
    expect(onClearHistory).toHaveBeenCalledTimes(1);
    expect(onRun).toHaveBeenCalledWith('Refund order');
  });
});

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';
import DangerousActionCard from '../../../components/cards/DangerousActionCard';

vi.mock('react', async () => {
  const actual = await vi.importActual<typeof import('react')>('react');
  return { ...actual, useId: () => 'test-id' };
});

describe('DangerousActionCard', () => {
  it('fires execute and cancel handlers', async () => {
    const user = userEvent.setup();
    const onExecute = vi.fn();
    const onCancel = vi.fn();

    render(
      <DangerousActionCard
        title="Confirm"
        details="Delete item"
        executeLabel="Execute"
        cancelLabel="Cancel"
        onExecute={onExecute}
        onCancel={onCancel}
      />,
    );

    await user.click(screen.getByRole('button', { name: 'Execute' }));
    await user.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(onExecute).toHaveBeenCalledTimes(1);
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it('matches snapshot for action card', () => {
    const { container } = render(<DangerousActionCard title="Confirm" details="Delete item" />);

    expect(container).toMatchSnapshot();
  });
});

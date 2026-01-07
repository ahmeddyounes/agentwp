import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import DangerousActionCard from '../../../components/cards/DangerousActionCard.jsx';

describe('DangerousActionCard', () => {
  it('fires execute and cancel handlers', async () => {
    const user = userEvent.setup();
    const onExecute = jest.fn();
    const onCancel = jest.fn();

    render(
      <DangerousActionCard
        title="Confirm"
        details="Delete item"
        executeLabel="Execute"
        cancelLabel="Cancel"
        onExecute={onExecute}
        onCancel={onCancel}
      />
    );

    await user.click(screen.getByRole('button', { name: 'Execute' }));
    await user.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(onExecute).toHaveBeenCalledTimes(1);
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it('matches snapshot for action card', () => {
    const idSpy = jest.spyOn(React, 'useId').mockReturnValue('test-id');
    const { container } = render(
      <DangerousActionCard title="Confirm" details="Delete item" />
    );

    expect(container).toMatchSnapshot();
    idSpy.mockRestore();
  });
});

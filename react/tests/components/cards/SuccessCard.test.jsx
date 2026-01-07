import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import SuccessCard from '../../../components/cards/SuccessCard.jsx';

describe('SuccessCard', () => {
  it('renders undo and star actions', async () => {
    const user = userEvent.setup();
    const onUndo = jest.fn();
    const onStar = jest.fn();

    render(
      <SuccessCard summary="Completed" onUndo={onUndo} onStar={onStar} isStarred={false} />
    );

    await user.click(screen.getByRole('button', { name: 'Undo' }));
    await user.click(screen.getByRole('button', { name: 'Star' }));

    expect(onUndo).toHaveBeenCalledTimes(1);
    expect(onStar).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Star' })).toHaveAttribute('aria-pressed', 'false');
  });

  it('matches snapshot for action state', () => {
    const idSpy = jest.spyOn(React, 'useId').mockReturnValue('test-id');
    const { container } = render(
      <SuccessCard summary="Done" onUndo={() => {}} onStar={() => {}} isStarred />
    );

    expect(container).toMatchSnapshot();
    idSpy.mockRestore();
  });
});

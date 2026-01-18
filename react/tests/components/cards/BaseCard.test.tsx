import { render, screen } from '@testing-library/react';
import BaseCard from '../../../src/components/cards/BaseCard';

describe('BaseCard', () => {
  it('renders header, body, and actions with accessible labeling', () => {
    render(
      <BaseCard
        title="Card title"
        subtitle="Card subtitle"
        actions={<button>Action</button>}
        theme="dark"
      >
        <p>Body content</p>
      </BaseCard>,
    );

    const region = screen.getByRole('region');
    const title = screen.getByRole('heading', { name: 'Card title' });

    expect(region).toHaveAttribute('aria-labelledby', title.getAttribute('id'));
    expect(region).toHaveAttribute('data-theme', 'dark');
    expect(screen.getByText('Card subtitle')).toBeInTheDocument();
    expect(screen.getByText('Body content')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Action' })).toBeInTheDocument();
  });
});

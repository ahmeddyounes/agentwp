import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import DataTableCard from '../../../components/cards/DataTableCard';

describe('DataTableCard', () => {
  const columns = [
    { key: 'name', label: 'Name' },
    { key: 'count', label: 'Count' },
  ];
  const rows = [
    { id: 1, name: 'Bravo', count: 2 },
    { id: 2, name: 'Alpha', count: 1 },
  ];

  it('sorts columns and paginates results', async () => {
    const user = userEvent.setup();
    render(<DataTableCard columns={columns} rows={rows} pageSize={1} />);

    expect(screen.getByText('Showing 1-1 of 2')).toBeInTheDocument();
    const table = screen.getByRole('table');
    let bodyRows = within(table).getAllByRole('row');
    expect(bodyRows).toHaveLength(2);
    expect(within(bodyRows[1]!).getByText('Bravo')).toBeInTheDocument();

    await act(async () => {
      await user.click(screen.getByRole('button', { name: 'Sort by Name' }));
    });

    bodyRows = within(table).getAllByRole('row');
    expect(bodyRows).toHaveLength(2);
    expect(within(bodyRows[1]!).getByText('Alpha')).toBeInTheDocument();
    const headers = screen.getAllByRole('columnheader');
    expect(headers[0]).toHaveAttribute('aria-sort', 'ascending');

    await act(async () => {
      await user.click(screen.getByRole('button', { name: 'Next' }));
    });
    expect(screen.getByText('Showing 2-2 of 2')).toBeInTheDocument();
  });

  it('renders empty state', () => {
    render(<DataTableCard columns={columns} rows={[]} />);

    expect(screen.getByText('No data available.')).toBeInTheDocument();
  });
});

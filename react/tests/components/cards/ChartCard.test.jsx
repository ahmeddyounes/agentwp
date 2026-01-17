import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';
import ChartCard from '../../../components/cards/ChartCard.jsx';

vi.mock('chart.js', () => ({
  Chart: { register: vi.fn() },
  CategoryScale: {},
  LinearScale: {},
  PointElement: {},
  LineElement: {},
  BarElement: {},
  ArcElement: {},
  Tooltip: {},
  Legend: {},
  Filler: {},
}));

vi.mock('react-chartjs-2', async () => {
  const ReactLocal = await import('react');
  const MockChart = ReactLocal.forwardRef((props, ref) => {
    ReactLocal.useImperativeHandle(ref, () => ({
      toBase64Image: () => 'data:image/png;base64,mock',
    }));
    return (
      <div
        data-testid="chart"
        role={props.role}
        aria-label={props['aria-label']}
        aria-describedby={props['aria-describedby']}
      />
    );
  });

  return {
    Line: MockChart,
    Bar: MockChart,
    Doughnut: MockChart,
  };
});

describe('ChartCard', () => {
  it('renders chart and exports image', async () => {
    const user = userEvent.setup();
    render(
      <ChartCard
        title="Sales"
        type="line"
        data={{ labels: ['A'], datasets: [{ label: 'X', data: [1] }] }}
      />,
    );

    expect(screen.getByRole('img', { name: 'Sales' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Export PNG' }));

    expect(await screen.findByRole('button', { name: 'Exported' })).toBeInTheDocument();
  });
});

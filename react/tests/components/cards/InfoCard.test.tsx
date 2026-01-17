import { render, screen } from '@testing-library/react';
import InfoCard from '../../../components/cards/InfoCard';

describe('InfoCard', () => {
  it('renders title, subtitle, and children', () => {
    render(
      <InfoCard title="Info title" subtitle="Info subtitle">
        <p>Info body</p>
      </InfoCard>,
    );

    expect(screen.getByRole('heading', { name: 'Info title' })).toBeInTheDocument();
    expect(screen.getByText('Info subtitle')).toBeInTheDocument();
    expect(screen.getByText('Info body')).toBeInTheDocument();
  });
});

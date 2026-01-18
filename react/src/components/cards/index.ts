import { lazy } from 'react';

export { default as BaseCard } from './BaseCard';
export { default as DangerousActionCard } from './DangerousActionCard';
export { default as SuccessCard } from './SuccessCard';
export { default as ErrorCard } from './ErrorCard';
export { default as InfoCard } from './InfoCard';
export const ChartCard = lazy(() => import('./ChartCard'));
export { default as DataTableCard } from './DataTableCard';

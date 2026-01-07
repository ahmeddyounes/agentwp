import { useEffect, useId, useMemo, useRef, useState } from 'react';
import {
  ArcElement,
  BarElement,
  CategoryScale,
  Chart as ChartJS,
  Filler,
  Legend,
  LineElement,
  LinearScale,
  PointElement,
  Tooltip,
} from 'chart.js';
import { Bar, Doughnut, Line } from 'react-chartjs-2';
import BaseCard from './BaseCard.jsx';

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  ArcElement,
  Tooltip,
  Legend,
  Filler
);

const ChartIcon = () => (
  <svg
    viewBox="0 0 24 24"
    width="20"
    height="20"
    aria-hidden="true"
    focusable="false"
  >
    <path
      fill="currentColor"
      d="M4.8 20.2a1 1 0 0 1-1-1V4.8a1 1 0 0 1 2 0v13.4h13.4a1 1 0 0 1 0 2H4.8zm4.2-4.9 3-4.2a1 1 0 0 1 1.6-.1l2 2.6 3-4a1 1 0 0 1 1.6 1.2l-3.8 5a1 1 0 0 1-1.6 0l-2.1-2.7-2.2 3.1a1 1 0 1 1-1.5-.9z"
    />
  </svg>
);

const CHART_COMPONENTS = {
  line: Line,
  bar: Bar,
  doughnut: Doughnut,
};

const currencyFormatter = new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD',
  maximumFractionDigits: 0,
});

const formatCurrency = (value) => {
  if (typeof value !== 'number' || Number.isNaN(value)) {
    return value?.toString() ?? '';
  }
  return currencyFormatter.format(value);
};

const resolveTooltipValue = (context) => {
  if (typeof context.parsed === 'number') {
    return context.parsed;
  }
  if (context.parsed?.y !== undefined) {
    return context.parsed.y;
  }
  if (context.parsed?.r !== undefined) {
    return context.parsed.r;
  }
  return context.raw;
};

const usePrefersDark = (fallback = false) => {
  const [prefersDark, setPrefersDark] = useState(() => {
    if (typeof window === 'undefined' || !window.matchMedia) {
      return fallback;
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
  });

  useEffect(() => {
    if (!window.matchMedia) {
      return undefined;
    }
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const handleChange = (event) => {
      setPrefersDark(event.matches);
    };
    if (media.addEventListener) {
      media.addEventListener('change', handleChange);
    } else {
      media.addListener(handleChange);
    }
    return () => {
      if (media.removeEventListener) {
        media.removeEventListener('change', handleChange);
      } else {
        media.removeListener(handleChange);
      }
    };
  }, []);

  return prefersDark;
};

const buildChartPalette = (theme) => {
  if (theme === 'light') {
    return {
      text: '#0f172a',
      muted: '#475569',
      grid: 'rgba(148, 163, 184, 0.35)',
      tooltipBg: '#ffffff',
      tooltipBorder: 'rgba(148, 163, 184, 0.6)',
      tooltipText: '#0f172a',
      canvas: '#f1f5f9',
    };
  }
  return {
    text: '#e2e8f0',
    muted: '#94a3b8',
    grid: 'rgba(148, 163, 184, 0.2)',
    tooltipBg: '#0f172a',
    tooltipBorder: 'rgba(148, 163, 184, 0.4)',
    tooltipText: '#e2e8f0',
    canvas: '#111827',
  };
};

export default function ChartCard({
  title = 'Performance snapshot',
  subtitle,
  metric,
  trend,
  footer,
  theme = 'auto',
  type = 'line',
  data,
  options,
  meta,
  table,
  height = 220,
  exportFilename = 'agentwp-chart.png',
  exportLabel = 'Export PNG',
  valueFormatter = formatCurrency,
  yAxisFormatter,
  className = '',
}) {
  const prefersDark = usePrefersDark();
  const resolvedTheme = theme === 'auto' ? (prefersDark ? 'dark' : 'light') : theme;
  const palette = useMemo(() => buildChartPalette(resolvedTheme), [resolvedTheme]);
  const ChartComponent = CHART_COMPONENTS[type] || Line;
  const chartRef = useRef(null);
  const exportTimerRef = useRef(null);
  const [exportStatus, setExportStatus] = useState('idle');
  const tableId = useId();
  const chartId = useId();

  useEffect(() => {
    return () => {
      if (exportTimerRef.current) {
        window.clearTimeout(exportTimerRef.current);
      }
    };
  }, []);

  const chartPlugins = useMemo(
    () => [
      {
        id: 'agentwpChartBackground',
        beforeDraw: (chartInstance) => {
          const { ctx, width, height } = chartInstance;
          ctx.save();
          ctx.fillStyle = palette.canvas;
          ctx.fillRect(0, 0, width, height);
          ctx.restore();
        },
      },
    ],
    [palette.canvas]
  );

  const tooltipCallbacks = useMemo(
    () => ({
      label: (context) => {
        const label = context.dataset?.label ? `${context.dataset.label}: ` : '';
        const value = resolveTooltipValue(context);
        return `${label}${valueFormatter(value)}`;
      },
    }),
    [valueFormatter]
  );

  const mergedOptions = useMemo(() => {
    const hasScales = type !== 'doughnut';
    const baseOptions = {
      responsive: true,
      maintainAspectRatio: false,
      devicePixelRatio: 2,
      animation: {
        duration: 280,
        easing: 'easeOutQuart',
      },
      interaction: {
        mode: 'index',
        intersect: false,
      },
      plugins: {
        legend: {
          labels: {
            color: palette.text,
            font: {
              family: '"Space Grotesk", "Manrope", system-ui, sans-serif',
              size: 11,
              weight: 500,
            },
            usePointStyle: true,
            boxWidth: 8,
          },
        },
        tooltip: {
          backgroundColor: palette.tooltipBg,
          borderColor: palette.tooltipBorder,
          borderWidth: 1,
          titleColor: palette.tooltipText,
          bodyColor: palette.tooltipText,
          callbacks: tooltipCallbacks,
        },
      },
      scales: hasScales
        ? {
            x: {
              ticks: {
                color: palette.muted,
              },
              grid: {
                color: palette.grid,
              },
            },
            y: {
              ticks: {
                color: palette.muted,
                callback: yAxisFormatter || valueFormatter,
              },
              grid: {
                color: palette.grid,
              },
            },
          }
        : undefined,
    };

    if (!options) {
      return baseOptions;
    }

    return {
      ...baseOptions,
      ...options,
      plugins: {
        ...baseOptions.plugins,
        ...options.plugins,
        legend: {
          ...baseOptions.plugins?.legend,
          ...options.plugins?.legend,
          labels: {
            ...baseOptions.plugins?.legend?.labels,
            ...options.plugins?.legend?.labels,
          },
        },
        tooltip: {
          ...baseOptions.plugins?.tooltip,
          ...options.plugins?.tooltip,
          callbacks: {
            ...baseOptions.plugins?.tooltip?.callbacks,
            ...options.plugins?.tooltip?.callbacks,
          },
        },
      },
      scales: hasScales
        ? {
            ...baseOptions.scales,
            ...options.scales,
            x: {
              ...baseOptions.scales?.x,
              ...options.scales?.x,
            },
            y: {
              ...baseOptions.scales?.y,
              ...options.scales?.y,
            },
          }
        : options.scales,
    };
  }, [options, palette, tooltipCallbacks, type, valueFormatter, yAxisFormatter]);

  const handleExport = () => {
    if (!chartRef.current || exportStatus === 'exporting') {
      return;
    }
    setExportStatus('exporting');
    try {
      const dataUrl = chartRef.current.toBase64Image('image/png', 1);
      const link = document.createElement('a');
      link.href = dataUrl;
      link.download = exportFilename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      setExportStatus('exported');
      if (exportTimerRef.current) {
        window.clearTimeout(exportTimerRef.current);
      }
      exportTimerRef.current = window.setTimeout(() => {
        setExportStatus('idle');
      }, 2000);
    } catch (error) {
      setExportStatus('idle');
    }
  };

  const resolvedExportLabel =
    exportStatus === 'exporting'
      ? 'Exporting...'
      : exportStatus === 'exported'
        ? 'Exported'
        : exportLabel;

  return (
    <BaseCard
      title={title}
      subtitle={subtitle}
      icon={<ChartIcon />}
      variant="chart"
      accent
      theme={resolvedTheme}
      className={className}
      actions={
        <button
          type="button"
          onClick={handleExport}
          disabled={exportStatus === 'exporting'}
          className={`agentwp-card__button ${exportStatus === 'exported' ? 'agentwp-card__button--muted' : ''}`}
        >
          {resolvedExportLabel}
        </button>
      }
    >
      {(metric || trend) && (
        <div className="agentwp-card__metric">
          {metric && <span>{metric}</span>}
          {trend && <span className="agentwp-card__trend">{trend}</span>}
        </div>
      )}
      <div className="agentwp-card__chart">
        {meta && <div className="agentwp-card__chart-meta">{meta}</div>}
        <div className="agentwp-card__chart-canvas" style={{ height }}>
          <ChartComponent
            id={chartId}
            ref={chartRef}
            data={data}
            options={mergedOptions}
            plugins={chartPlugins}
            role="img"
            aria-label={title}
            aria-describedby={table ? tableId : undefined}
          />
        </div>
      </div>
      {footer && <div className="agentwp-card__muted">{footer}</div>}
      {table && (
        <div className="agentwp-sr-only">
          <table id={tableId} className="agentwp-card__table">
            {table.caption && <caption>{table.caption}</caption>}
            <thead>
              <tr>
                {table.headers.map((header) => (
                  <th key={header} scope="col">
                    {header}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {table.rows.map((row) => (
                <tr key={row.id}>
                  {row.cells.map((cell, index) => (
                    <td key={`${row.id}-${index}`}>{cell}</td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </BaseCard>
  );
}

import { isValidElement, useEffect, useMemo, useState, type ReactNode } from 'react';
import BaseCard, { type CardTheme } from './BaseCard';

export interface DataTableColumn<RowType> {
  key: string;
  label?: string;
  align?: 'left' | 'center' | 'right';
  width?: string | number;
  sortable?: boolean;
  render?: (row: RowType) => ReactNode;
  sortValue?: (row: RowType) => unknown;
}

const getSortValue = <RowType extends Record<string, unknown>>(
  row: RowType,
  column: DataTableColumn<RowType>,
): unknown => {
  if (column.sortValue) {
    return column.sortValue(row);
  }
  return row[column.key];
};

const compareValues = (aValue: unknown, bValue: unknown): number => {
  if (aValue == null && bValue == null) {
    return 0;
  }
  if (aValue == null) {
    return 1;
  }
  if (bValue == null) {
    return -1;
  }
  if (typeof aValue === 'number' && typeof bValue === 'number') {
    return aValue - bValue;
  }
  const aString = String(aValue).toLowerCase();
  const bString = String(bValue).toLowerCase();
  return aString.localeCompare(bString);
};

/**
 * Data table card with sorting and pagination.
 *
 * @returns {JSX.Element}
 */
export interface DataTableCardProps<RowType extends Record<string, unknown>> {
  title?: string;
  subtitle?: ReactNode;
  columns?: DataTableColumn<RowType>[];
  rows?: RowType[];
  pageSize?: number;
  getRowId?: (row: RowType, rowIndex: number) => string | number;
  emptyMessage?: string;
  theme?: CardTheme;
}

export default function DataTableCard<RowType extends Record<string, unknown>>({
  title = 'Data table',
  subtitle,
  columns,
  rows,
  pageSize = 10,
  getRowId,
  emptyMessage = 'No data available.',
  theme = 'auto',
}: DataTableCardProps<RowType>) {
  const [sortConfig, setSortConfig] = useState<{ key: string | null; direction: 'asc' | 'desc' }>({
    key: null,
    direction: 'asc',
  });
  const [page, setPage] = useState(1);

  const normalizedColumns = useMemo(() => (Array.isArray(columns) ? columns : []), [columns]);
  const normalizedRows = useMemo(() => (Array.isArray(rows) ? rows : []), [rows]);
  const columnCount = Math.max(1, normalizedColumns.length);

  const sortedRows = useMemo(() => {
    if (!sortConfig.key) {
      return normalizedRows;
    }
    const sortColumn = normalizedColumns.find((column) => column.key === sortConfig.key);
    if (!sortColumn) {
      return normalizedRows;
    }
    const sorted = [...normalizedRows].sort((rowA, rowB) => {
      const aValue = getSortValue(rowA, sortColumn);
      const bValue = getSortValue(rowB, sortColumn);
      return compareValues(aValue, bValue);
    });
    if (sortConfig.direction === 'desc') {
      sorted.reverse();
    }
    return sorted;
  }, [normalizedColumns, normalizedRows, sortConfig]);

  const pageCount = Math.max(1, Math.ceil(sortedRows.length / pageSize));
  const safePage = Math.min(page, pageCount);

  useEffect(() => {
    if (page !== safePage) {
      setPage(safePage);
    }
  }, [page, safePage]);

  const startIndex = (safePage - 1) * pageSize;
  const endIndex = Math.min(startIndex + pageSize, sortedRows.length);
  const pagedRows = sortedRows.slice(startIndex, endIndex);

  const handleSort = (column: DataTableColumn<RowType>) => {
    if (column.sortable === false) {
      return;
    }
    setPage(1);
    setSortConfig((prev) => {
      if (prev.key === column.key) {
        if (prev.direction === 'asc') {
          return { key: column.key, direction: 'desc' };
        }
        return { key: null, direction: 'asc' };
      }
      return { key: column.key, direction: 'asc' };
    });
  };

  const paginationVisible = sortedRows.length > pageSize;

  const renderCellValue = (value: unknown): ReactNode => {
    if (value == null) {
      return '--';
    }
    if (typeof value === 'string' || typeof value === 'number') {
      return value;
    }
    if (typeof value === 'boolean') {
      return value ? 'true' : 'false';
    }
    if (isValidElement(value)) {
      return value;
    }
    if (Array.isArray(value)) {
      return value as ReactNode;
    }
    if (value instanceof Date) {
      return value.toISOString();
    }
    try {
      return JSON.stringify(value);
    } catch {
      return String(value);
    }
  };

  return (
    <BaseCard title={title} subtitle={subtitle} variant="info" theme={theme}>
      <div className="agentwp-card__table-wrapper">
        <table className="agentwp-card__table">
          <thead>
            <tr>
              {normalizedColumns.map((column) => {
                const isSorted = sortConfig.key === column.key;
                const ariaSort = isSorted
                  ? sortConfig.direction === 'asc'
                    ? 'ascending'
                    : 'descending'
                  : 'none';
                const align = column.align || 'left';
                const isSortable = column.sortable !== false;
                const label = column.label || column.key || 'Column';

                return (
                  <th
                    key={column.key || column.label || label}
                    scope="col"
                    aria-sort={isSortable ? ariaSort : undefined}
                    style={{ textAlign: align, width: column.width }}
                  >
                    {isSortable ? (
                      <button
                        type="button"
                        className="agentwp-card__sort-button"
                        onClick={() => handleSort(column)}
                        aria-label={`Sort by ${label}`}
                      >
                        <span>{label}</span>
                        <span className="agentwp-card__sort-indicator">
                          {isSorted ? (sortConfig.direction === 'asc' ? '^' : 'v') : '-'}
                        </span>
                      </button>
                    ) : (
                      column.label
                    )}
                  </th>
                );
              })}
            </tr>
          </thead>
          <tbody>
            {pagedRows.length === 0 ? (
              <tr>
                <td colSpan={columnCount} className="agentwp-card__muted">
                  {emptyMessage}
                </td>
              </tr>
            ) : (
              pagedRows.map((row, rowIndex) => {
                const rowKey = getRowId
                  ? getRowId(row, rowIndex)
                  : (() => {
                      const candidate = row['id'];
                      if (typeof candidate === 'string' || typeof candidate === 'number') {
                        return candidate;
                      }
                      return rowIndex;
                    })();
                return (
                  <tr key={rowKey}>
                    {normalizedColumns.map((column) => {
                      const label = column.label || column.key || 'Column';
                      const value = column.render ? column.render(row) : row[column.key];
                      return (
                        <td
                          key={column.key || column.label || label}
                          style={{ textAlign: column.align || 'left' }}
                        >
                          {renderCellValue(value)}
                        </td>
                      );
                    })}
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>

      {paginationVisible && (
        <div className="agentwp-card__pagination">
          <span>
            Showing {sortedRows.length === 0 ? 0 : startIndex + 1}-{endIndex} of {sortedRows.length}
          </span>
          <div className="agentwp-card__pagination-controls">
            <button
              type="button"
              className="agentwp-card__pagination-button"
              onClick={() => setPage((prev) => Math.max(prev - 1, 1))}
              disabled={safePage === 1}
            >
              Previous
            </button>
            <button
              type="button"
              className="agentwp-card__pagination-button"
              onClick={() => setPage((prev) => Math.min(prev + 1, pageCount))}
              disabled={safePage === pageCount}
            >
              Next
            </button>
          </div>
        </div>
      )}
    </BaseCard>
  );
}

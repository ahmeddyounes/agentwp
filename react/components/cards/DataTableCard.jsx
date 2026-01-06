import { useEffect, useMemo, useState } from 'react';
import BaseCard from './BaseCard.jsx';

const getSortValue = (row, column) => {
  if (column.sortValue) {
    return column.sortValue(row);
  }
  return row?.[column.key];
};

const compareValues = (aValue, bValue) => {
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

export default function DataTableCard({
  title = 'Data table',
  subtitle,
  columns,
  rows,
  pageSize = 10,
  getRowId,
  emptyMessage = 'No data available.',
  theme = 'dark',
}) {
  const [sortConfig, setSortConfig] = useState({ key: null, direction: 'asc' });
  const [page, setPage] = useState(1);

  const normalizedColumns = Array.isArray(columns) ? columns : [];
  const normalizedRows = Array.isArray(rows) ? rows : [];
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

  const handleSort = (column) => {
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
                const rowKey = getRowId ? getRowId(row, rowIndex) : row.id ?? rowIndex;
                return (
                  <tr key={rowKey}>
                    {normalizedColumns.map((column) => {
                      const label = column.label || column.key || 'Column';
                      const value = column.render
                        ? column.render(row)
                        : row?.[column.key] ?? '--';
                      return (
                        <td
                          key={column.key || column.label || label}
                          style={{ textAlign: column.align || 'left' }}
                        >
                          {value}
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
            Showing {sortedRows.length === 0 ? 0 : startIndex + 1}-{endIndex} of{' '}
            {sortedRows.length}
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

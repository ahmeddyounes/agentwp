import type { SearchResults, SearchResult } from '../../types';
import { renderHighlightedText } from '../../utils/text';

interface TypeaheadConfig {
  label: string;
  icon: React.ReactNode;
}

const TYPEAHEAD_CONFIG: Record<keyof SearchResults, TypeaheadConfig> = {
  products: {
    label: 'Products',
    icon: (
      <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4">
        <path
          d="M4 5h9l7 7-8 8-8-8V5z"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.7"
          strokeLinejoin="round"
        />
        <circle cx="8.5" cy="9.5" r="1.3" fill="currentColor" />
      </svg>
    ),
  },
  orders: {
    label: 'Orders',
    icon: (
      <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4">
        <path
          d="M7 4h10v16l-3-2-2 2-2-2-3 2z"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.7"
          strokeLinejoin="round"
        />
        <path d="M9 9h6M9 12h6" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
      </svg>
    ),
  },
  customers: {
    label: 'Customers',
    icon: (
      <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4">
        <circle cx="12" cy="8" r="3.4" fill="none" stroke="currentColor" strokeWidth="1.7" />
        <path
          d="M4 20c1.8-3.6 5-5.4 8-5.4s6.2 1.8 8 5.4"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.7"
          strokeLinecap="round"
        />
      </svg>
    ),
  },
};

interface TypeaheadDropdownProps {
  results: SearchResults;
  query: string;
  isOpen: boolean;
  isLoading: boolean;
  activeIndex: number;
  onSelect: (result: SearchResult) => void;
}

export function TypeaheadDropdown({
  results,
  query,
  isOpen,
  isLoading,
  activeIndex,
  onSelect,
}: TypeaheadDropdownProps) {
  if (!isOpen) return null;

  const allResults: SearchResult[] = [...results.products, ...results.orders, ...results.customers];

  const hasResults = allResults.length > 0;

  return (
    <div
      className="absolute left-0 right-0 top-full z-50 mt-1 max-h-80 overflow-y-auto rounded-lg border border-slate-700/60 bg-slate-900/95 shadow-xl backdrop-blur"
      role="listbox"
      aria-label="Search suggestions"
      aria-live="polite"
      aria-relevant="additions removals"
    >
      {isLoading && !hasResults && (
        <div className="flex items-center gap-2 p-3 text-sm text-slate-400">
          <LoadingSpinner />
          <span>Searching...</span>
        </div>
      )}

      {!isLoading && !hasResults && (
        <div className="p-3 text-sm text-slate-500">No results found</div>
      )}

      {hasResults && (
        <>
          {(['products', 'orders', 'customers'] as const).map((type) => {
            const items = results[type];
            if (items.length === 0) return null;

            const config = TYPEAHEAD_CONFIG[type];
            const startIndex =
              type === 'products'
                ? 0
                : type === 'orders'
                  ? results.products.length
                  : results.products.length + results.orders.length;

            return (
              <div key={type}>
                <div className="flex items-center gap-2 bg-slate-800/50 px-3 py-2 text-xs font-medium uppercase tracking-wider text-slate-400">
                  {config.icon}
                  <span>{config.label}</span>
                  <span className="ml-auto text-slate-500">{items.length}</span>
                </div>
                <div role="group" aria-label={config.label}>
                  {items.map((item, index) => {
                    const globalIndex = startIndex + index;
                    const isActive = globalIndex === activeIndex;

                    return (
                      <div
                        key={`${type}-${item.id}`}
                        role="option"
                        tabIndex={isActive ? 0 : -1}
                        aria-selected={isActive}
                        className={`cursor-pointer px-3 py-2 transition-colors ${
                          isActive
                            ? 'bg-indigo-500/20 text-white'
                            : 'text-slate-300 hover:bg-slate-800/50'
                        }`}
                        onClick={() => onSelect(item)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            onSelect(item);
                          }
                        }}
                      >
                        <div className="text-sm font-medium">
                          {renderHighlightedText(item.title, query)}
                        </div>
                        {item.subtitle && (
                          <div className="text-xs text-slate-500">
                            {renderHighlightedText(item.subtitle, query)}
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </>
      )}
    </div>
  );
}

function LoadingSpinner() {
  return (
    <svg
      className="h-4 w-4 animate-spin"
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
      aria-hidden="true"
    >
      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
      <path
        className="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
      />
    </svg>
  );
}

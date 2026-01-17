import * as React from 'react';
import { ErrorCard } from './cards';

const IS_DEV = import.meta.env?.DEV ?? false;

/**
 * Error boundary for the AgentWP admin UI.
 */
export interface ErrorBoundaryProps {
  children?: React.ReactNode;
  onError?: (error: unknown, info: React.ErrorInfo) => void;
}

interface ErrorBoundaryState {
  hasError: boolean;
}

export default class ErrorBoundary extends React.Component<ErrorBoundaryProps, ErrorBoundaryState> {
  constructor(props: ErrorBoundaryProps) {
    super(props);
    this.state = { hasError: false };
    this.handleReload = this.handleReload.bind(this);
  }

  static getDerivedStateFromError() {
    return { hasError: true };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    if (IS_DEV && typeof console !== 'undefined') {
      console.error('[AgentWP] Render error', error, info);
    }
    if (typeof this.props.onError === 'function') {
      this.props.onError(error, info);
    }
  }

  handleReload() {
    if (typeof window === 'undefined') {
      return;
    }
    window.location.reload();
  }

  render() {
    const { hasError } = this.state;
    if (hasError) {
      return (
        <div className="p-6">
          <ErrorCard
            title="AgentWP ran into a problem"
            message="Please refresh the page to try again."
            retryLabel="Reload"
            onRetry={this.handleReload}
          />
        </div>
      );
    }
    return this.props.children;
  }
}

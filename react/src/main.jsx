import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App.jsx';
import ErrorBoundary from '../components/ErrorBoundary.jsx';
import './index.css';
import { applyTheme, getInitialThemePreference, getSystemTheme, resolveTheme } from './theme.js';

const initialPreference = getInitialThemePreference();
const resolvedTheme = resolveTheme(initialPreference, getSystemTheme() === 'dark');
applyTheme(resolvedTheme);

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <ErrorBoundary>
      <App />
    </ErrorBoundary>
  </React.StrictMode>
);

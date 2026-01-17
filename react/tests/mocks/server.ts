import { setupServer } from 'msw/node';
import { handlers } from './handlers';

// Create MSW server with default handlers
export const server = setupServer(...handlers);

// Helper to reset handlers to default state
export const resetHandlers = () => {
  server.resetHandlers();
};

// Helper to add custom handlers for specific tests
export const use = server.use.bind(server);

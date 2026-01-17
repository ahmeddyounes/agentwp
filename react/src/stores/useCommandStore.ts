import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { CommandEntry } from '../types';
import {
  COMMAND_HISTORY_KEY,
  MAX_COMMAND_HISTORY,
  MAX_COMMAND_FAVORITES,
} from '../utils/constants';

interface CommandUsage {
  [prompt: string]: number;
}

interface CommandState {
  history: CommandEntry[];
  favorites: CommandEntry[];
  usage: CommandUsage;
  lastEntry: CommandEntry | null;
}

interface CommandActions {
  addToHistory: (entry: Omit<CommandEntry, 'id' | 'timestamp'>) => void;
  removeFromHistory: (id: string) => void;
  clearHistory: () => void;
  addToFavorites: (entry: CommandEntry) => void;
  removeFromFavorites: (id: string) => void;
  isFavorite: (id: string) => boolean;
  incrementUsage: (prompt: string) => void;
  getUsageCount: (prompt: string) => number;
  getMostUsed: (limit?: number) => Array<{ prompt: string; count: number }>;
}

const generateId = (): string => {
  return `${Date.now()}-${Math.random().toString(36).substring(2, 9)}`;
};

export const useCommandStore = create<CommandState & CommandActions>()(
  persist(
    (set, get) => ({
      history: [],
      favorites: [],
      usage: {},
      lastEntry: null,

      addToHistory: (entry) => {
        const newEntry: CommandEntry = {
          ...entry,
          id: generateId(),
          timestamp: Date.now(),
        };

        set((state) => ({
          history: [newEntry, ...state.history].slice(0, MAX_COMMAND_HISTORY),
          lastEntry: newEntry,
        }));
      },

      removeFromHistory: (id) =>
        set((state) => ({
          history: state.history.filter((entry) => entry.id !== id),
        })),

      clearHistory: () => set({ history: [], lastEntry: null }),

      addToFavorites: (entry) =>
        set((state) => {
          if (state.favorites.some((f) => f.id === entry.id)) {
            return state;
          }
          return {
            favorites: [entry, ...state.favorites].slice(0, MAX_COMMAND_FAVORITES),
          };
        }),

      removeFromFavorites: (id) =>
        set((state) => ({
          favorites: state.favorites.filter((entry) => entry.id !== id),
        })),

      isFavorite: (id) => get().favorites.some((entry) => entry.id === id),

      incrementUsage: (prompt) =>
        set((state) => ({
          usage: {
            ...state.usage,
            [prompt]: (state.usage[prompt] || 0) + 1,
          },
        })),

      getUsageCount: (prompt) => get().usage[prompt] || 0,

      getMostUsed: (limit = 10) => {
        const { usage } = get();
        return Object.entries(usage)
          .map(([prompt, count]) => ({ prompt, count }))
          .sort((a, b) => b.count - a.count)
          .slice(0, limit);
      },
    }),
    {
      name: COMMAND_HISTORY_KEY,
      partialize: (state) => ({
        history: state.history,
        favorites: state.favorites,
        usage: state.usage,
      }),
      merge: (persistedState, currentState) => ({
        ...currentState,
        ...(persistedState as Partial<CommandState>),
      }),
      onRehydrateStorage: () => (_state, error) => {
        if (error) {
          console.warn('Failed to rehydrate command store:', error);
        }
      },
    },
  ),
);

import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { DraftEntry } from '../types';
import { DRAFT_HISTORY_KEY, MAX_DRAFT_HISTORY } from '../utils/constants';

interface DraftState {
  subject: string;
  body: string;
  history: DraftEntry[];
}

interface DraftActions {
  setSubject: (subject: string) => void;
  setBody: (body: string) => void;
  saveDraft: () => void;
  loadDraft: (id: string) => void;
  removeDraft: (id: string) => void;
  clearCurrent: () => void;
  clearHistory: () => void;
}

const generateId = (): string => {
  return `${Date.now()}-${Math.random().toString(36).substring(2, 9)}`;
};

export const useDraftStore = create<DraftState & DraftActions>()(
  persist(
    (set, get) => ({
      subject: '',
      body: '',
      history: [],

      setSubject: (subject) => set({ subject }),

      setBody: (body) => set({ body }),

      saveDraft: () => {
        const { subject, body, history } = get();
        if (!subject.trim() && !body.trim()) return;

        const newDraft: DraftEntry = {
          id: generateId(),
          subject: subject.trim(),
          body: body.trim(),
          timestamp: Date.now(),
        };

        set({
          history: [newDraft, ...history].slice(0, MAX_DRAFT_HISTORY),
          subject: '',
          body: '',
        });
      },

      loadDraft: (id) => {
        const { history } = get();
        const draft = history.find((d) => d.id === id);
        if (draft) {
          set({ subject: draft.subject, body: draft.body });
        }
      },

      removeDraft: (id) =>
        set((state) => ({
          history: state.history.filter((d) => d.id !== id),
        })),

      clearCurrent: () => set({ subject: '', body: '' }),

      clearHistory: () => set({ history: [] }),
    }),
    {
      name: DRAFT_HISTORY_KEY,
      partialize: (state) => ({ history: state.history }),
      onRehydrateStorage: () => (_state, error) => {
        if (error) {
          console.warn('Failed to rehydrate draft store:', error);
        }
      },
    },
  ),
);

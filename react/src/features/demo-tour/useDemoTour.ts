import { useCallback, useEffect, useRef, useState } from 'react';
import Shepherd from 'shepherd.js';
import type { Tour } from 'shepherd.js';
import {
  DEMO_TOUR_SEEN_KEY,
  DEMO_TOUR_START_DELAY_MS,
  getInitialTourSeen,
} from '../../utils/constants';

interface UseDemoTourOptions {
  demoMode: boolean;
  resolvedTheme: 'light' | 'dark';
  shadowRoot?: ShadowRoot | null;
}

interface UseDemoTourReturn {
  tourSeen: boolean;
  startTour: () => void;
  cancelTour: () => void;
}

const TOUR_STEPS = [
  {
    id: 'hero',
    title: 'Welcome to AgentWP',
    text: 'Your command deck turns plain-language prompts into store actions.',
    attachTo: { element: '[data-tour="hero"]', on: 'bottom' as const },
  },
  {
    id: 'sample',
    title: 'Sample prompts',
    text: 'Use starter prompts to see how AgentWP drafts responses.',
    attachTo: { element: '[data-tour="sample-prompt"]', on: 'right' as const },
  },
  {
    id: 'launch',
    title: 'Launch the command deck',
    text: 'Open the deck anytime from this quick action button.',
    attachTo: { element: '[data-tour="open-command-deck"]', on: 'bottom' as const },
  },
  {
    id: 'status',
    title: 'Live status checks',
    text: 'AgentWP surfaces connectivity and status indicators for confidence.',
    attachTo: { element: '[data-tour="status-card"]', on: 'left' as const },
  },
  {
    id: 'analytics',
    title: 'Analytics snapshot',
    text: 'Keep an eye on revenue, order volume, and response momentum.',
    attachTo: { element: '[data-tour="analytics"]', on: 'top' as const },
  },
];

export function useDemoTour({
  demoMode,
  resolvedTheme,
  shadowRoot,
}: UseDemoTourOptions): UseDemoTourReturn {
  const [tourSeen, setTourSeen] = useState(getInitialTourSeen);
  const tourRef = useRef<Tour | null>(null);

  const markSeen = useCallback(() => {
    try {
      window.localStorage.setItem(DEMO_TOUR_SEEN_KEY, '1');
    } catch {
      // Ignore storage failures.
    }
    setTourSeen(true);
  }, []);

  const startTour = useCallback(() => {
    if (typeof window === 'undefined' || !demoMode) {
      return;
    }

    if (tourRef.current) {
      tourRef.current.cancel();
      tourRef.current = null;
    }

    const tourClasses =
      resolvedTheme === 'dark' ? 'agentwp-tour agentwp-tour--dark' : 'agentwp-tour';
    const tour = new Shepherd.Tour({
      useModalOverlay: true,
      defaultStepOptions: {
        classes: tourClasses,
        cancelIcon: {
          enabled: true,
        },
        scrollTo: {
          behavior: 'smooth',
          block: 'center',
        },
      },
    });

    const addButtons = (isLast: boolean) => [
      {
        text: 'Skip tour',
        action: tour.cancel,
        classes: 'shepherd-button-secondary',
      },
      {
        text: isLast ? 'Finish' : 'Next',
        action: isLast ? tour.complete : tour.next,
      },
    ];

    const root = shadowRoot || document;

    TOUR_STEPS.forEach((step, index) => {
      const target = root.querySelector(step.attachTo.element);
      if (!target) {
        return;
      }
      tour.addStep({
        ...step,
        buttons: addButtons(index === TOUR_STEPS.length - 1),
      });
    });

    if (!tour.steps || tour.steps.length === 0) {
      return;
    }

    tour.on('complete', markSeen);
    tour.on('cancel', markSeen);
    tour.start();
    tourRef.current = tour;
  }, [demoMode, resolvedTheme, shadowRoot, markSeen]);

  const cancelTour = useCallback(() => {
    if (tourRef.current) {
      tourRef.current.cancel();
      tourRef.current = null;
    }
  }, []);

  // Auto-start tour on first visit in demo mode
  useEffect(() => {
    if (!demoMode || tourSeen || typeof window === 'undefined') {
      return undefined;
    }
    const timeoutId = window.setTimeout(() => {
      startTour();
    }, DEMO_TOUR_START_DELAY_MS);
    return () => {
      window.clearTimeout(timeoutId);
    };
  }, [demoMode, startTour, tourSeen]);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      if (tourRef.current) {
        tourRef.current.cancel();
        tourRef.current = null;
      }
    };
  }, []);

  return { tourSeen, startTour, cancelTour };
}

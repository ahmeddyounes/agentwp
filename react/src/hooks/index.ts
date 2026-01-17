// API hooks (React Query)
export { useHealthCheck, useIsOnline } from './useHealthCheck';
export { useAnalytics, useAnalyticsData } from './useAnalytics';
export { useUsage, useUsageData } from './useUsage';
export { useSearchQuery, useDebouncedSearch } from './useSearch';

// UI hooks
export { useKeyboardShortcuts } from './useKeyboardShortcuts';
export { useFocusTrap } from './useFocusTrap';
export { usePrefersDark } from './usePrefersDark';

// Voice hooks
export {
  useVoice,
  useSpeechRecognition,
  useSpeechSynthesis,
  type UseVoiceOptions,
  type UseSpeechRecognitionOptions,
  type UseSpeechSynthesisOptions,
} from './useVoice';

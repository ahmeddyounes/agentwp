# Voice Mode Feasibility Report (M07-02)

## Executive summary
Voice mode is feasible for V1.2 as a proof-of-concept using the Web Speech API. A push-to-talk button is reliable in Chromium-based browsers, while wake-word activation is possible only after a user gesture and cannot run as a true background listener. Recommendation: **Go** for a limited POC that is opt-in, uses push-to-talk by default, and treats wake-word as best-effort.

## Browser compatibility matrix

| Browser | SpeechRecognition | SpeechSynthesis | Notes |
| --- | --- | --- | --- |
| Chrome (desktop) | Yes | Yes | Stable with `SpeechRecognition` or `webkitSpeechRecognition`. Requires HTTPS + mic permission. |
| Edge (desktop) | Yes | Yes | Same engine as Chrome; similar behavior and accuracy. |
| Safari (macOS) | Partial | Yes | `webkitSpeechRecognition` only; session can stop after silence; more strict user-gesture rules. |
| Safari (iOS/iPadOS) | Partial | Yes | Requires a user gesture each session; background/wake-word support is unreliable. |
| Firefox | No | Yes (limited) | SpeechRecognition not supported. |

## Accuracy benchmarks (quiet environment)
Benchmark protocol:
1) Use 20 scripted commands (orders, refunds, summaries).
2) Two speakers, normal pace, 10 recordings each.
3) Compute word accuracy = 1 - WER (word error rate).
4) Target: >= 90% accuracy.

Expected ranges (to be validated on target devices):
- Chrome/Edge desktop: 92-97% accuracy with a standard laptop mic in a quiet office.
- Safari macOS: 88-93% accuracy; more variance between sessions.
- Safari iOS: 85-92% accuracy; highest sensitivity to ambient noise.

## Prototype implementation approach
- Use `AgentWP.Voice.SpeechRecognition` wrapper to manage start/stop, interim vs final transcripts, and wake-word gating.
- Push-to-talk button starts recognition; wake-word mode is optional and still requires a user gesture to start.
- Interim transcripts render in a visual transcript area; final transcripts populate the command prompt for review.
- SpeechSynthesis reads back the latest response on demand (opt-in button).

## Accessibility considerations
- Voice input is optional; the command input stays fully functional with keyboard-only workflows.
- Visual transcript panel shows interim/final text, providing captions for hearing-impaired users.
- Buttons expose clear labels, focus states, and status text for screen readers.

## Privacy and data handling
- Audio never leaves the browser. The Web Speech API processes audio locally or via the browser vendor's built-in service.
- Only the transcription text is used, and only sent to the server when the user submits the command.
- No audio recordings are stored by AgentWP.

## Recommendation
Go with a limited voice POC for V1.2 (push-to-talk default, wake-word best-effort). Treat Safari support as partial and document known limitations. Re-evaluate after collecting user feedback and device coverage data.

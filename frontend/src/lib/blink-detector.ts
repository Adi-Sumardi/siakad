/**
 * Blink detection over MediaPipe's per-frame blink score (docs/09-ABSEN-WAJAH-GERBANG.md).
 * Pure logic, no DOM, so it can be checked with synthetic numbers.
 *
 * MediaPipe Face Landmarker reports `eyeBlinkLeft` / `eyeBlinkRight` from 0
 * (open) to 1 (shut), from a model trained on expressions - far steadier
 * than eye-landmark geometry. The page feeds the average of both eyes.
 *
 * Thresholds follow the person: "open" is the median of their recent open
 * frames (narrow eyes or glasses can sit at 0.2-0.3 instead of ~0.05), and
 * a blink is the score rising CLOSE_DELTA above that, then falling back
 * within REOPEN_DELTA of it. At 20-30 frames a second even a quick blink
 * spans several frames, so one blink is one detection.
 */

const CLOSE_DELTA = 0.25;
const MIN_CLOSE = 0.35;
const REOPEN_DELTA = 0.12;
const HISTORY = 15;
const MIN_HISTORY = 3;
/** Closed longer than this many frames (~2-3 s) is squinting or a bad baseline, not a blink. */
const MAX_CLOSED_FRAMES = 60;

function median(values: number[]): number {
  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
}

export type BlinkDetector = {
  /** Feeds one frame's blink score (0 open .. 1 shut); true exactly on the frame a blink completes. */
  push: (score: number) => boolean;
  /** Forget everything - face lost, or a new attempt. */
  reset: () => void;
  /** True while the eyes currently count as closed (for UI feedback). */
  isClosed: () => boolean;
};

export function createBlinkDetector(): BlinkDetector {
  let history: number[] = [];
  let closed = false;
  let closedFrames = 0;

  return {
    push(score: number): boolean {
      if (!Number.isFinite(score) || score < 0) return false;

      if (history.length < MIN_HISTORY) {
        // Do not learn "open" from a frame that already looks shut.
        if (score < MIN_CLOSE) history.push(score);
        return false;
      }

      const open = median(history);
      const closeAt = Math.max(MIN_CLOSE, open + CLOSE_DELTA);

      if (!closed) {
        if (score >= closeAt) {
          closed = true;
          closedFrames = 1;
          return false;
        }
        history.push(score);
        if (history.length > HISTORY) history = history.slice(-HISTORY);
        return false;
      }

      if (score <= open + REOPEN_DELTA) {
        closed = false;
        closedFrames = 0;
        return true;
      }

      closedFrames++;
      if (closedFrames > MAX_CLOSED_FRAMES) {
        history = [];
        closed = false;
        closedFrames = 0;
      }
      return false;
    },

    reset() {
      history = [];
      closed = false;
      closedFrames = 0;
    },

    isClosed: () => closed,
  };
}

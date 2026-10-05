"use client";

import { useEffect, useId, useRef, useState } from "react";
import { createPortal } from "react-dom";
import type { LucideIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useShellChrome } from "@/components/layout/shell-chrome";
import { cn } from "@/lib/utils";

/** The dim around the spotlight hole - navy, the splash's own night shade. */
const DIM_SHADOW = "0 0 0 9999px rgba(12,27,77,0.62)";
/** Space between spotlight box and target, and tooltip and spotlight. */
const PAD = 8;
const GAP = 12;

export type TourStep = {
  /** data-tour value of the highlighted element; absent = centered intro. */
  target?: string;
  title: string;
  body: string;
  icon: LucideIcon;
  /** Preferred tooltip side; auto-falls back when there is no room. */
  placement?: "right" | "below";
  /** Silently skip this step when its target is not on screen (the wali bell
   * renders nothing at all for a parent with no news). */
  optional?: boolean;
  /** Target lives in the mobile drawer - the tour opens it on a phone. */
  nav?: boolean;
};

type Box = { top: number; left: number; width: number; height: number };
type Geom = { box: Box; tip: { x: number; y: number }; found: boolean };

const clamp = (value: number, min: number, max: number) => Math.min(Math.max(value, min), max);

function reducedMotion(): boolean {
  return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}

/**
 * The one data-tour anchor to highlight right now. Nav items exist twice
 * (mobile drawer + desktop sidebar) and the hidden instance is still in the
 * DOM: a closed drawer sits fully off-canvas and a `hidden` sidebar collapses
 * to 0x0, so "intersects the viewport" picks the live one. When neither
 * instance intersects - the staff sidebar's nav list scrolls internally, so on
 * a short viewport deep items sit below its fold, clipped but alive - fall
 * back to the instance belonging to the current viewport's shell: on a phone
 * that is the drawer (its aside carries md:hidden), on desktop the sidebar.
 * Content anchors merely page-scrolled away land on the first match; the
 * per-step scrollIntoView then brings whatever it is back into view.
 */
function resolveTarget(step: TourStep): HTMLElement | null {
  if (!step.target) return null;
  const matches = Array.from(document.querySelectorAll<HTMLElement>(`[data-tour="${step.target}"]`));

  for (const el of matches) {
    const r = el.getBoundingClientRect();
    const onScreen = r.width > 0 && r.height > 0 && r.right > 0 && r.bottom > 0 && r.left < window.innerWidth && r.top < window.innerHeight;
    if (onScreen) return el;
  }

  const wantsDrawer = window.matchMedia("(max-width: 767px)").matches;
  return (
    matches.find((el) => (el.closest("aside")?.classList.contains("md:hidden") ?? false) === wantsDrawer) ??
    matches[0] ??
    null
  );
}

/** Tooltip position for a spotlight box (or a bottom sheet when targetless). */
function placeTooltip(box: Box, tipW: number, tipH: number, prefer: "right" | "below", vw: number, vh: number) {
  // No target to point at: park the card at the bottom, driver.js style.
  if (box.width === 0 && box.height === 0) {
    return { x: clamp(vw / 2 - tipW / 2, GAP, Math.max(GAP, vw - tipW - GAP)), y: vh - tipH - 24 };
  }

  const centerX = clamp(box.left + box.width / 2 - tipW / 2, GAP, Math.max(GAP, vw - tipW - GAP));
  const fitsRight = prefer === "right" && box.left + box.width + GAP + tipW <= vw - GAP;
  const fitsBelow = box.top + box.height + GAP + tipH <= vh - GAP;

  if (fitsRight) {
    return { x: box.left + box.width + GAP, y: clamp(box.top, GAP, Math.max(GAP, vh - tipH - GAP)) };
  }
  if (fitsBelow) {
    return { x: centerX, y: box.top + box.height + GAP };
  }
  // Mirror above the target when there is no room below.
  return { x: centerX, y: Math.max(GAP, box.top - tipH - GAP) };
}

/**
 * The "Panduan Fitur" spotlight tour: a hand-rolled driver.js. A fixed box is
 * parked over the live target and its enormous box-shadow dims everything
 * else; a card next to it explains the feature. One rAF loop re-measures
 * every frame, which quietly covers the drawer's 300ms slide, window resizes,
 * sticky headers and late-arriving content - no listener bookkeeping.
 *
 * Role-agnostic engine: each portal passes its own steps (wali, guru, admin
 * unit) and mounts this inside its shell so the tour can reach the drawer
 * levers through ShellChromeContext. `steps` keys the per-step effects, so it
 * must be a module-level constant - a stable identity, never rebuilt in
 * render.
 *
 * onFinish fires for Selesai AND for Lewati/Escape: a skipped tour still
 * counts as seen, the replay button is how anyone comes back to it.
 */
export function FeatureTour({
  open,
  steps,
  onFinish,
}: {
  open: boolean;
  steps: TourStep[];
  onFinish: () => void;
}) {
  const chrome = useShellChrome();
  const [index, setIndex] = useState(0);
  const [geom, setGeom] = useState<Geom | null>(null);
  const tooltipRef = useRef<HTMLDivElement>(null);
  const frameRef = useRef(0);
  const titleId = useId();

  // Refs so the [open]-keyed lifecycle effect never re-runs (and never
  // re-locks body scroll) just because a parent re-rendered.
  const onFinishRef = useRef(onFinish);
  const chromeRef = useRef(chrome);
  useEffect(() => {
    onFinishRef.current = onFinish;
    chromeRef.current = chrome;
  });

  const step = steps[index];
  const last = index === steps.length - 1;

  const goNext = () => (last ? onFinishRef.current() : setIndex(index + 1));
  const goBack = () => setIndex(Math.max(0, index - 1));

  // Lifecycle: scroll lock, Escape, and the click shield. A box-shadow is not
  // hit-testable, so dimmed <Link>s are neutralised with a capture-phase
  // blocker instead - one stray tap must not unmount the tour mid-step.
  useEffect(() => {
    if (!open) return;

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") onFinishRef.current();
    };
    const onClickCapture = (event: MouseEvent) => {
      if (tooltipRef.current && event.target instanceof Node && tooltipRef.current.contains(event.target)) return;
      event.preventDefault();
      event.stopPropagation();
    };

    document.addEventListener("keydown", onKey);
    document.addEventListener("click", onClickCapture, true);

    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("click", onClickCapture, true);
      chromeRef.current?.closeMobileNav();
    };
  }, [open]);

  // A replay must start from the top. Scheduled, not called inline - the
  // same set-state-in-effect discipline auth-context.tsx explains.
  useEffect(() => {
    if (!open) return;
    const raf = requestAnimationFrame(() => setIndex(0));
    return () => cancelAnimationFrame(raf);
  }, [open]);

  // Per step: drawer sync, optional-step skip, scroll into view, focus.
  useEffect(() => {
    if (!open) return;
    const current = steps[index];
    if (!current) return;

    const raf = requestAnimationFrame(() => {
      const el = resolveTarget(current);

      if (current.optional && !el) {
        // Nothing to highlight (e.g. a bell with no news) - slip past quietly.
        if (index < steps.length - 1) setIndex(index + 1);
        else onFinishRef.current();
        return;
      }

      const wantsDrawer = !!current.nav && !!window.matchMedia("(max-width: 767px)").matches;
      if (wantsDrawer) chromeRef.current?.openMobileNav();
      else chromeRef.current?.closeMobileNav();

      if (el) {
        const r = el.getBoundingClientRect();
        const outOfView = r.top < 0 || r.bottom > window.innerHeight || r.left < 0 || r.right > window.innerWidth;
        if (outOfView) {
          el.scrollIntoView({ block: "center", behavior: reducedMotion() ? "auto" : "smooth" });
        }
      }

      tooltipRef.current?.focus({ preventScroll: true });
    });

    return () => cancelAnimationFrame(raf);
  }, [open, index, steps]);

  // The measurement loop: re-resolve and re-place every frame while open.
  useEffect(() => {
    if (!open) return;
    const current = steps[index];
    if (!current) return;

    const tick = () => {
      const vw = window.innerWidth;
      const vh = window.innerHeight;
      const el = resolveTarget(current);
      const rect = el?.getBoundingClientRect();

      const box: Box = rect
        ? { top: rect.top - PAD, left: rect.left - PAD, width: rect.width + PAD * 2, height: rect.height + PAD * 2 }
        : { top: vh / 2, left: vw / 2, width: 0, height: 0 };

      const tipRect = tooltipRef.current?.getBoundingClientRect();
      const tip = placeTooltip(
        box,
        tipRect?.width ?? 0,
        tipRect?.height ?? 0,
        current.placement ?? "below",
        vw,
        vh,
      );

      setGeom((previous) => {
        // Idle frames re-render nothing: a still spotlight keeps still.
        if (
          previous &&
          previous.found === !!rect &&
          Math.abs(previous.box.top - box.top) < 0.5 &&
          Math.abs(previous.box.left - box.left) < 0.5 &&
          Math.abs(previous.box.width - box.width) < 0.5 &&
          Math.abs(previous.box.height - box.height) < 0.5 &&
          Math.abs(previous.tip.x - tip.x) < 0.5 &&
          Math.abs(previous.tip.y - tip.y) < 0.5
        ) {
          return previous;
        }
        return { box, tip, found: !!rect };
      });

      frameRef.current = requestAnimationFrame(tick);
    };

    frameRef.current = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frameRef.current);
  }, [open, index, steps]);

  if (!open || !step) return null;

  const Icon = step.icon;
  const visible = geom !== null;

  return createPortal(
    <>
      {/* Spotlight - the shadow spread is the dim; the hole is the box. */}
      <div
        aria-hidden
        className={cn(
          "pointer-events-none fixed z-[90] rounded-2xl border-2 border-primary transition-all duration-200 ease-out motion-reduce:transition-none",
          !geom?.found && "opacity-0",
          !visible && "opacity-0",
        )}
        style={
          geom
            ? {
                top: geom.box.top,
                left: geom.box.left,
                width: geom.box.width,
                height: geom.box.height,
                boxShadow: DIM_SHADOW,
              }
            : undefined
        }
      />

      {/* Tooltip card */}
      <div
        ref={tooltipRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        className={cn(
          "fixed z-[91] w-[min(20rem,calc(100vw-2rem))] rounded-2xl border border-border bg-card p-4 shadow-2xl outline-none transition-all duration-200 ease-out motion-reduce:transition-none",
          !visible && "opacity-0",
        )}
        style={geom ? { top: geom.tip.y, left: geom.tip.x } : undefined}
      >
        <div className="flex items-start gap-3">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
            <Icon className="size-4.5" />
          </span>
          <div className="min-w-0">
            <h2 id={titleId} className="text-sm font-bold text-foreground">
              {step.title}
            </h2>
            <p className="mt-1 text-xs leading-relaxed text-muted-foreground">{step.body}</p>
          </div>
        </div>

        <div className="mt-4 flex items-center justify-between gap-2 border-t border-border/70 pt-3">
          <span className="text-[11px] tabular text-muted-foreground">
            {index + 1}/{steps.length}
          </span>
          <div className="flex items-center gap-2">
            {!last && (
              <Button size="sm" variant="ghost" onClick={onFinish}>
                Lewati
              </Button>
            )}
            <Button size="sm" variant="outline" onClick={goBack} disabled={index === 0}>
              Kembali
            </Button>
            <Button size="sm" onClick={goNext}>
              {last ? "Selesai" : "Lanjut"}
            </Button>
          </div>
        </div>
      </div>
    </>,
    document.body,
  );
}

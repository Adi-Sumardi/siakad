"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { ArrowRight, type LucideIcon } from "lucide-react";

/** When the hairline under the button has filled (see .splash-fill in globals.css). */
const AUTO_CONTINUE_MS = 6300;
const EXIT_MS = 500;

export type SplashFeature = { icon: LucideIcon; text: string };

export type Child = { nama_lengkap: string; nama_panggilan: string | null };

/** PMB hands names over in capitals; a greeting reads better in title case. */
export function tidy(name: string): string {
  const trimmed = name.trim();
  if (trimmed !== trimmed.toUpperCase()) return trimmed;
  return trimmed.toLowerCase().replace(/(^|[\s'-])\p{L}/gu, (m) => m.toUpperCase());
}

/** "Muhammad Fatih" is called Fatih - skip the name half the boys share. */
function firstName(full: string): string {
  const words = full.trim().split(/\s+/);
  const common = /^(muhammad|muhamad|mohammad|mohamad|mochammad|moh\.?|muh\.?|m\.?)$/i;
  return (words.length > 1 && common.test(words[0]) ? words[1] : words[0]) ?? "";
}

/**
 * One child: the full name. Two or more: nicknames (or first names), so
 * "Aisyah & Fatih" or "Aisyah, Fatih & Zahra" still fits on the screen.
 */
export function childrenLabel(children: Child[]): string {
  if (children.length === 1) return tidy(children[0].nama_lengkap);

  const short = children.map((c) => tidy(c.nama_panggilan || firstName(c.nama_lengkap)));
  if (short.length <= 1) return short.join("");

  return `${short.slice(0, -1).join(", ")} & ${short[short.length - 1]}`;
}

/**
 * The welcome splash (2026-09-30): shown once per account, right after the
 * first login - the session already exists, so "Masuk ke Dashboard" (or
 * waiting for the hairline to fill) simply reveals the portal behind it.
 * Serves every role now (wali, guru, admin unit); each passes its own
 * greeting, intro tail and feature list. Design: the "Splash Selamat Datang
 * Siakad" canvas.
 */
export function WelcomeSplash({
  greeting,
  intro,
  features,
  onDone,
}: {
  greeting: string;
  /** The sentence tail after "Selamat datang di SIAKAD YAPI Al Azhar - ". */
  intro: React.ReactNode;
  features: SplashFeature[];
  onDone: () => void;
}) {
  const [leaving, setLeaving] = useState(false);
  const finished = useRef(false);

  const finish = useCallback(() => {
    if (finished.current) return;
    finished.current = true;
    setLeaving(true);
    setTimeout(onDone, EXIT_MS);
  }, [onDone]);

  useEffect(() => {
    const timer = setTimeout(finish, AUTO_CONTINUE_MS);
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") finish();
    };
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    window.addEventListener("keydown", onKey);

    return () => {
      clearTimeout(timer);
      window.removeEventListener("keydown", onKey);
      document.body.style.overflow = previousOverflow;
    };
  }, [finish]);

  const delay = (value: string) => ({ "--splash-delay": value }) as React.CSSProperties;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label="Selamat datang di SIAKAD YAPI Al Azhar"
      // Focus lands on the dialog, not the button - autofocus there lit the
      // focus ring before anyone touched a key. Tab still reaches it.
      tabIndex={-1}
      ref={(node) => node?.focus({ preventScroll: true })}
      className="fixed inset-0 z-[100] overflow-y-auto overflow-x-hidden text-white outline-none transition-opacity duration-500 ease-out"
      style={{
        background: "linear-gradient(145deg, #0C1B4D 0%, #13286B 48%, #2856E0 100%)",
        opacity: leaving ? 0 : 1,
      }}
    >
      {/* Slow orbits - the same rings as the login panel, barely moving. A
          layer of their own so their overhang never makes the page scroll. */}
      <div aria-hidden className="pointer-events-none fixed inset-0 overflow-hidden">
        <div aria-hidden className="splash-orbit pointer-events-none absolute -right-52 -bottom-56 size-[480px] rounded-full border border-white/[0.09] sm:-right-56 sm:-bottom-64 sm:size-[760px]" />
        <div aria-hidden className="splash-orbit pointer-events-none absolute -right-28 -bottom-96 hidden size-[760px] rounded-full border border-white/[0.07] sm:block" />
        <div aria-hidden className="splash-orbit pointer-events-none absolute -left-56 -top-60 size-[440px] rounded-full border border-white/[0.06] sm:-left-64 sm:-top-72 sm:size-[680px]" />
      </div>

      {/* Scrolls on a short phone instead of running into the brand row. */}
      <div className="relative flex min-h-full flex-col items-center justify-center px-6 pb-20 pt-24 sm:py-28">
        <div className="splash-drop absolute left-6 top-7 flex items-center gap-2.5 sm:left-14 sm:top-11 sm:gap-3" style={delay("0.1s")}>
          <span className="flex items-center gap-1 rounded-full bg-white px-1.5 py-[3px] sm:gap-1.5 sm:px-2">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/images/logo-yapi.png" alt="Logo YAPI" className="size-[30px] object-contain sm:size-9" />
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/images/Logo-YPIA.png" alt="Logo YPI Al Azhar" className="size-[30px] object-contain sm:size-9" />
          </span>
          <span className="text-base font-semibold sm:text-lg" style={{ fontFamily: "var(--font-brand)" }}>
            SIAKAD YAPI Al Azhar
          </span>
        </div>

        <div className="relative flex w-full max-w-[760px] flex-col items-center text-center">
          <div className="relative mb-6 flex h-[124px] w-[236px] items-center justify-center sm:mb-8 sm:h-[156px] sm:w-[300px]">
            <div
              aria-hidden
              className="splash-breathe absolute -inset-8 rounded-full sm:-inset-10"
              style={{ background: "radial-gradient(ellipse, rgba(216,180,90,0.30) 0%, rgba(216,180,90,0) 65%)" }}
            />
            <svg aria-hidden viewBox="0 0 300 156" preserveAspectRatio="none" className="absolute inset-0 size-full">
              <rect x="1" y="1" width="298" height="154" rx="77" fill="none" stroke="rgba(255,255,255,0.12)" strokeWidth="1" vectorEffect="non-scaling-stroke" />
              <rect
                className="splash-draw"
                x="1"
                y="1"
                width="298"
                height="154"
                rx="77"
                fill="none"
                stroke="#D8B45A"
                strokeWidth="1.6"
                strokeLinecap="round"
                pathLength={100}
                vectorEffect="non-scaling-stroke"
              />
            </svg>
            <div className="splash-bloom flex h-[100px] w-[212px] items-center justify-center gap-[18px] rounded-full bg-white shadow-[0_18px_48px_rgba(6,14,44,0.45)] sm:h-32 sm:w-[272px] sm:gap-[22px]">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src="/images/logo-yapi.png" alt="Logo YAPI" className="size-[70px] object-contain sm:size-[92px]" />
              <span aria-hidden className="h-12 w-px bg-[#DCE3F2] sm:h-16" />
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src="/images/Logo-YPIA.png" alt="Logo YPI Al Azhar" className="size-[70px] object-contain sm:size-[92px]" />
            </div>
          </div>

          <p className="splash-in mb-2 text-[22px] italic text-[#D8B45A] sm:mb-2.5 sm:text-[28px]" style={{ ...delay("1.1s"), fontFamily: "var(--font-brand)" }}>
            Assalamu&apos;alaikum,
          </p>
          <h1
            className="splash-in mb-3.5 text-balance text-[30px] font-semibold leading-[1.18] tracking-[-0.5px] sm:mb-[18px] sm:text-5xl sm:leading-[1.12] sm:tracking-[-0.8px]"
            style={{ ...delay("1.5s"), fontFamily: "var(--font-display)" }}
          >
            {greeting}
          </h1>
          <p className="splash-in max-w-[560px] text-[15px] leading-relaxed text-white/80 sm:text-lg" style={delay("1.9s")}>
            Selamat datang di <span className="font-semibold text-white">SIAKAD YAPI Al Azhar</span> - {intro}
          </p>

          {/* Dropped on a short phone (e.g. 375x667) so the button stays in view. */}
          <ul className="mt-7 flex w-full flex-col gap-2.5 sm:mt-10 sm:w-auto sm:flex-row sm:gap-3 [@media(max-height:720px)]:hidden">
            {features.map((feature, i) => (
              <li
                key={feature.text}
                className="splash-in flex items-center gap-3 rounded-[14px] border border-white/15 bg-white/[0.08] py-2.5 pl-2.5 pr-3.5 text-sm text-white/90 sm:gap-2.5 sm:rounded-full sm:pr-4"
                style={delay(`${(2.4 + i * 0.2).toFixed(1)}s`)}
              >
                <span className="flex size-[30px] shrink-0 items-center justify-center rounded-full bg-white/[0.12]">
                  <feature.icon className="size-[15px]" />
                </span>
                {feature.text}
              </li>
            ))}
          </ul>

          <div className="splash-in mt-8 flex w-full flex-col items-center gap-4 sm:mt-12 sm:w-auto sm:gap-[18px]" style={delay("3.3s")}>
            <button
              type="button"
              onClick={finish}
              className="flex h-[50px] w-full items-center justify-center gap-2.5 rounded-xl bg-white px-[26px] text-[15px] font-semibold text-[#13286B] shadow-[0_10px_30px_rgba(6,14,44,0.35)] outline-none transition-transform hover:-translate-y-px focus-visible:ring-4 focus-visible:ring-[#D8B45A]/60 sm:h-12 sm:w-auto"
            >
              Masuk ke Dashboard
              <ArrowRight className="size-4" />
            </button>
            <div aria-hidden className="h-0.5 w-40 overflow-hidden rounded-full bg-white/15 sm:w-[200px]">
              <div className="splash-fill h-full w-full bg-[#D8B45A]" />
            </div>
          </div>
        </div>

        <p
          className="splash-drop absolute inset-x-0 bottom-6 text-center text-[10.5px] uppercase tracking-[1.4px] text-white/50 sm:bottom-9 sm:text-xs sm:tracking-[1.6px]"
          style={delay("0.1s")}
        >
          Yayasan Asrama Pelajar Islam · Al Azhar
        </p>
      </div>
    </div>
  );
}

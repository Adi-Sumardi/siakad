"use client";

import { useRef } from "react";
import { ArrowRight } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";

/**
 * The once-per-account welcome splash for guardians - the greeting a wali
 * meets the first time they open siakad after claiming their account from
 * PMB. Gated on the server-owned welcome_shown_at flag so it survives a
 * device change, and mounted in WaliShell so it appears on whichever wali
 * page loads first, not just the dashboard.
 */
export function WelcomeOverlay() {
  const { user, adopt } = useAuth();
  const acknowledged = useRef(false);

  if (!user || user.role !== "orangtua" || user.welcome_shown_at) return null;

  function start() {
    // The early return above narrows user for the JSX, but a hoisted
    // function declaration does not inherit that narrowing - re-guard here.
    if (!user || acknowledged.current) return;
    acknowledged.current = true;

    // Optimistic close: the splash disappears the instant it is tapped, and
    // the server's timestamp replaces our local guess once the POST lands.
    adopt({ ...user, welcome_shown_at: new Date().toISOString() });

    api
      .post<{ welcome_shown_at: string | null }>("/api/wali/welcome/acknowledge", {})
      .then(({ welcome_shown_at }) => {
        adopt({ ...user, welcome_shown_at });
      })
      .catch((error) => {
        // api.ts already toasts and redirects on an expired session; for
        // anything else the flag stays null, so the greeting simply returns
        // on the next visit rather than being lost.
        if (!(error instanceof ApiError) || error.status !== 401) {
          toast.error("Sambutan gagal tersimpan dan akan muncul lagi kunjungan berikutnya.");
        }
      });
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="welcome-title"
      className="fixed inset-0 z-[60] grid place-items-center bg-black/60 backdrop-blur-xs p-4"
    >
      <div className="w-full max-w-md rounded-2xl bg-linear-to-br from-[#13286B] to-[#2856E0] p-8 text-center text-white shadow-2xl">
        <div className="mx-auto flex size-16 items-center justify-center rounded-full bg-white shadow-lg">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/images/logo-yapi.png" alt="Logo YAPI" className="size-10 object-contain" />
        </div>

        <p className="mt-6 text-sm text-white/80">Assalamu&apos;alaikum</p>
        <h1
          id="welcome-title"
          style={{ fontFamily: "var(--font-display)" }}
          className="mt-1 text-2xl font-bold"
        >
          {user.name}
        </h1>
        <p className="mt-3 text-sm leading-relaxed text-white/80">
          Selamat datang di Portal Wali Murid YAPI. Pantau akademik, kedisiplinan, prestasi,
          dan administrasi SPP ananda di sini.
        </p>

        <Button
          onClick={start}
          className="mt-7 w-full bg-white text-[#13286B] hover:bg-white/90"
        >
          Mulai
          <ArrowRight className="size-4" />
        </Button>
      </div>
    </div>
  );
}

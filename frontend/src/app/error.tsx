"use client";

// Route-level error boundary (audit 2026-10-05): the app's pages are large
// client components, and before this file any unhandled render error
// white-screened the whole route with no way back. This boundary catches
// the crash, explains it in plain Indonesian, and offers the built-in
// retry (re-fetch and re-render the segment - note this Next.js version
// calls the prop `retry`, not `reset`).
import { useEffect } from "react";

export default function ErrorPage({
  error,
  retry,
}: {
  error: Error & { digest?: string };
  retry: () => void;
}) {
  useEffect(() => {
    // Surfaced in the browser console / error reporting, never shown raw.
    console.error("[route-error]", error);
  }, [error]);

  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center gap-4 px-6 text-center">
      <div className="rounded-full bg-red-100 p-3 text-2xl dark:bg-red-900/40">⚠️</div>
      <h2 className="text-lg font-semibold">Halaman ini gagal dimuat</h2>
      <p className="max-w-md text-sm text-muted-foreground">
        Terjadi kesalahan tak terduga saat menampilkan halaman. Data Anda aman —
        coba muat ulang, dan bila tetap gagal hubungi Tata Usaha.
      </p>
      <div className="flex gap-3">
        <button
          onClick={retry}
          className="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800"
        >
          Coba lagi
        </button>
        <a
          href="/dashboard"
          className="rounded-lg border px-4 py-2 text-sm font-medium hover:bg-muted"
        >
          Ke beranda
        </a>
      </div>
    </div>
  );
}

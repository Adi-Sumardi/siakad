// Root instant-loading state (audit 2026-10-05): wraps every route segment
// in a Suspense fallback so navigation shows the app responding instead of
// a frozen frame while a heavy client page streams in.
export default function Loading() {
  return (
    <div
      role="status"
      aria-label="Memuat"
      className="flex min-h-[60vh] items-center justify-center"
    >
      <span
        className="inline-block h-8 w-8 animate-spin rounded-full border-2 border-current border-t-transparent opacity-60 motion-reduce:animate-none"
      />
    </div>
  );
}

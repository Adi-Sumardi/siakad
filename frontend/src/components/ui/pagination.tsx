import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export type PageMeta = {
  current_page: number;
  last_page: number;
  total: number;
  per_page?: number;
  from?: number | null;
  to?: number | null;
};

// "1 … 4 5 6 … 12" - first, last, and the pages around the cursor, so the
// bar stays short enough to fit a phone even with hundreds of pages.
function numberedPages(current: number, last: number): (number | "gap")[] {
  const pages = [...new Set([1, last, current - 1, current, current + 1])]
    .filter((p) => p >= 1 && p <= last)
    .sort((a, b) => a - b);

  const out: (number | "gap")[] = [];
  let prev = 0;
  for (const p of pages) {
    if (prev && p - prev > 1) out.push("gap");
    out.push(p);
    prev = p;
  }
  return out;
}

/**
 * Server-side pager footer: "Menampilkan a-b dari N <label>" plus
 * Sebelumnya / numbered pages / Selanjutnya. Renders nothing while the whole
 * result fits one page (last_page <= 1), so small lists stay exactly as they
 * were.
 */
export function Pagination({
  meta,
  onPage,
  label = "data",
  className,
}: {
  meta: PageMeta;
  onPage: (page: number) => void;
  label?: string;
  className?: string;
}) {
  if (meta.last_page <= 1) return null;

  const perPage = meta.per_page ?? 20;
  const from = meta.from ?? (meta.current_page - 1) * perPage + 1;
  const to = meta.to ?? Math.min(meta.current_page * perPage, meta.total);

  return (
    <nav
      aria-label={`Navigasi halaman ${label}`}
      className={cn("flex flex-col-reverse items-center justify-between gap-3 sm:flex-row", className)}
    >
      <p className="text-xs text-muted-foreground">
        Menampilkan <strong className="font-semibold text-foreground">{from}</strong>–
        <strong className="font-semibold text-foreground">{to}</strong> dari{" "}
        <strong className="font-semibold text-foreground">{meta.total}</strong> {label}
      </p>

      <div className="flex flex-wrap items-center justify-center gap-1.5">
        <Button
          size="sm"
          variant="outline"
          disabled={meta.current_page <= 1}
          onClick={() => onPage(meta.current_page - 1)}
          className="text-xs"
        >
          Sebelumnya
        </Button>

        {numberedPages(meta.current_page, meta.last_page).map((p, i) =>
          p === "gap" ? (
            <span key={`gap-${i}`} aria-hidden="true" className="px-0.5 text-xs text-muted-foreground">
              …
            </span>
          ) : (
            <button
              key={p}
              type="button"
              aria-current={p === meta.current_page ? "page" : undefined}
              disabled={p === meta.current_page}
              onClick={() => onPage(p)}
              className={cn(
                "grid h-8 min-w-8 cursor-pointer place-items-center rounded-lg border px-2 text-xs font-bold tabular shadow-2xs transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                p === meta.current_page
                  ? "border-primary bg-primary text-primary-foreground"
                  : "border-input bg-card text-foreground hover:bg-canvas",
              )}
            >
              {p}
            </button>
          ),
        )}

        <Button
          size="sm"
          variant="outline"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
          className="text-xs"
        >
          Selanjutnya
        </Button>
      </div>
    </nav>
  );
}

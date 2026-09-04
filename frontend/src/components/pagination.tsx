"use client";

/**
 * Prev/next controls for a Laravel paginate() response.
 *
 * `meta`/`data.data` came back with current_page/last_page on every admin
 * list endpoint, but nothing ever rendered a way to reach page 2 - anything
 * past the backend's own page size (20-50 rows depending on the controller)
 * was invisible with no sign more existed. A recently-created account is
 * exactly what that looks like from the outside: "why isn't my user here" -
 * newest-first ordering means it should be on page 1, but an OLDER one
 * pushed off the end by every account made since is silently unreachable.
 */
export function Pagination({
  currentPage,
  lastPage,
  onChange,
}: {
  currentPage: number;
  lastPage: number;
  onChange: (page: number) => void;
}) {
  if (lastPage <= 1) return null;

  return (
    <div className="flex items-center justify-between mt-3 text-xs">
      <span className="text-muted-foreground">
        Halaman {currentPage} dari {lastPage}
      </span>
      <div className="flex gap-2">
        <button
          type="button"
          className="px-2 h-7 rounded-md border border-input disabled:opacity-40"
          disabled={currentPage <= 1}
          onClick={() => onChange(currentPage - 1)}
        >
          Sebelumnya
        </button>
        <button
          type="button"
          className="px-2 h-7 rounded-md border border-input disabled:opacity-40"
          disabled={currentPage >= lastPage}
          onClick={() => onChange(currentPage + 1)}
        >
          Berikutnya
        </button>
      </div>
    </div>
  );
}

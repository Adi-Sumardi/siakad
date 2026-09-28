"use client";

import { useState } from "react";
import { Plus, Search } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * A free-text combobox with suggestions (Poin 11, "Jenis Beasiswa"):
 * the user may pick a value that already exists OR type a brand-new one -
 * a creatable input, not a closed dropdown. The caller passes the values
 * already in use (plus any canonical seed), so the list grows with real
 * usage instead of being frozen to an enum in two codebases.
 *
 * Fully controlled: the input text IS the parent's value - typing IS
 * creating. Validation stays the caller's job (non-empty, trim) exactly
 * as it would be for a plain Input.
 */
export function CreatableCombobox({
  id,
  value,
  onChange,
  suggestions,
  placeholder,
  required,
  disabled,
  className,
}: {
  id?: string;
  value: string;
  onChange: (next: string) => void;
  suggestions: string[];
  placeholder?: string;
  required?: boolean;
  disabled?: boolean;
  className?: string;
}) {
  const [open, setOpen] = useState(false);
  // Keyboard support (audit 2026-09-28): arrows walk the list, Enter picks
  // the highlighted row (and must not submit the surrounding form), Escape
  // closes. Highlight index -1 = the "create new" row when it shows.
  const [highlight, setHighlight] = useState(-1);

  const needle = value.trim().toLowerCase();
  const matches = needle
    ? suggestions.filter((s) => s.toLowerCase().includes(needle))
    : suggestions;
  const exactExists = suggestions.some((s) => s.toLowerCase() === needle);
  const createRow = value.trim() !== "" && !exactExists;
  // Row 0 is the create row (when present), then the matches.
  const rows: (string | null)[] = [...(createRow ? [null] : []), ...matches];

  function pick(row: string | null) {
    if (row === null) {
      onChange(value.trim());
    } else {
      onChange(row);
    }
    setOpen(false);
    setHighlight(-1);
  }

  return (
    <div className="relative">
      <div className="relative">
        <Search className="pointer-events-none absolute inset-y-0 left-3 my-auto size-3.5 text-muted-foreground" />
        <input
          id={id}
          type="text"
          autoComplete="off"
          placeholder={placeholder}
          required={required}
          disabled={disabled}
          value={value}
          onChange={(e) => {
            onChange(e.target.value);
            setHighlight(-1);
          }}
          onFocus={() => setOpen(true)}
          onBlur={() => {
            setOpen(false);
            setHighlight(-1);
          }}
          onKeyDown={(e) => {
            if (!open) {
              if (e.key === "ArrowDown") setOpen(true);
              return;
            }
            if (e.key === "Escape") {
              setOpen(false);
              return;
            }
            if (e.key === "ArrowDown" || e.key === "ArrowUp") {
              e.preventDefault();
              setHighlight((h) => {
                const last = rows.length - 1;
                if (h === -1) return e.key === "ArrowDown" ? 0 : last;
                return e.key === "ArrowDown" ? (h === last ? -1 : h + 1) : (h === 0 ? -1 : h - 1);
              });
              return;
            }
            if (e.key === "Enter" && highlight >= 0 && rows[highlight] !== undefined) {
              e.preventDefault();
              pick(rows[highlight]);
            }
          }}
          className={cn(
            "flex h-10 w-full rounded-lg border border-input bg-card py-2 pl-9 pr-3 text-sm",
            "placeholder:text-muted-foreground",
            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:border-primary",
            "disabled:cursor-not-allowed disabled:opacity-60",
            "aria-[invalid=true]:border-bad aria-[invalid=true]:ring-bad/30",
            className,
          )}
        />
      </div>

      {open && (
        <div
          // Prevent the list's mousedown from blurring the input before the
          // click lands - the classic combobox race.
          onMouseDown={(e) => e.preventDefault()}
          className="absolute z-30 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-input bg-card p-1 shadow-lg"
        >
          {rows.map((row, i) =>
            row === null ? (
              <button
                key="__create"
                type="button"
                onClick={() => pick(null)}
                className={cn(
                  "flex w-full items-center gap-2 rounded-md bg-primary/10 px-3 py-2 text-left text-xs font-semibold text-primary",
                  highlight === i && "ring-2 ring-ring",
                )}
              >
                <Plus className="size-3.5 shrink-0" />
                <span>
                  Tambah baru: &quot;{value.trim()}&quot;
                </span>
              </button>
            ) : (
              <button
                key={row}
                type="button"
                onClick={() => pick(row)}
                className={cn(
                  "block w-full rounded-md px-3 py-2 text-left text-sm hover:bg-muted/60",
                  row === value && "font-semibold text-primary",
                  highlight === i && "bg-muted/60",
                )}
              >
                {row}
              </button>
            ),
          )}

          {matches.length === 0 && value.trim() === "" && (
            <p className="px-3 py-2 text-xs text-muted-foreground">
              Ketik untuk menambah jenis baru.
            </p>
          )}
        </div>
      )}
    </div>
  );
}

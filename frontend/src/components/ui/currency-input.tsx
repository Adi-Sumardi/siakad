"use client";

import { useState } from "react";
import { cn } from "@/lib/utils";

/**
 * The one rupiah input every money form uses (feature batch Poin 9/12):
 * Indonesian display formatting (prefix Rp, dot thousands separators), digit
 * typing only - and a clean integer out. The database keeps its
 * decimal(12,2) columns and the API payload stays a plain number; the
 * formatting lives entirely in this display layer.
 *
 * Controlled on the INTEGER: `value` is the raw number (null = empty), and
 * every keystroke reports the parsed integer back through onChange.
 *
 * Styling is the shared Input's, verbatim (Poin 8): same border, padding,
 * height and focus ring as every other text field on the same form, plus
 * pl-10 for the Rp prefix. className MERGES over the default (via
 * tailwind-merge) instead of replacing it, so the compact per-component
 * variants (h-8) tweak size without losing the rest.
 */
export function CurrencyInput({
  value,
  onChange,
  id,
  placeholder = "misal: 1.500.000",
  required,
  disabled,
  className,
  min = 0,
}: {
  value: number | null;
  onChange: (next: number | null) => void;
  id?: string;
  placeholder?: string;
  required?: boolean;
  disabled?: boolean;
  className?: string;
  min?: number;
}) {
  // The field text is owned locally so mid-typing states the user cannot
  // express in a formatted integer ("1500000" before the dots catch up)
  // never fight the controlled value. External changes (form reset, edit
  // prefill) are adopted during RENDER via React's own adjust-during-render
  // pattern - no effect, no cascading setState.
  const [text, setText] = useState(() => (value === null ? "" : formatRupiahInput(value)));
  const [lastValue, setLastValue] = useState(value);

  if (value !== lastValue) {
    setLastValue(value);
    setText(value === null ? "" : formatRupiahInput(value));
  }

  function handle(input: string) {
    // Digits only - everything else (letters, separators, minus) is dropped
    // on the spot, then re-rendered with dots as the user goes.
    const digits = input.replace(/\D/g, "").replace(/^0+(?=\d)/, "");
    setText(digits === "" ? "" : formatRupiahInput(Number(digits)));
    onChange(digits === "" ? null : Number(digits));
  }

  return (
    <div className="relative">
      <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm font-semibold text-muted-foreground">
        Rp
      </span>
      <input
        id={id}
        type="text"
        inputMode="numeric"
        autoComplete="off"
        placeholder={placeholder}
        required={required}
        disabled={disabled}
        min={min}
        value={text}
        onChange={(e) => handle(e.target.value)}
        className={cn(
          "flex h-10 w-full rounded-lg border border-input bg-card py-2 pl-10 pr-3 text-sm tabular",
          "placeholder:text-muted-foreground",
          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:border-primary",
          "disabled:cursor-not-allowed disabled:opacity-60",
          "aria-[invalid=true]:border-bad aria-[invalid=true]:ring-bad/30",
          className,
        )}
      />
    </div>
  );
}

function formatRupiahInput(amount: number): string {
  return new Intl.NumberFormat("id-ID", { maximumFractionDigits: 0 }).format(amount);
}

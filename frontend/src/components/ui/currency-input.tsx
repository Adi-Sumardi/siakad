"use client";

import { useState } from "react";

/**
 * The one rupiah input every money form uses (feature batch Poin 9/12):
 * Indonesian display formatting (prefix Rp, dot thousands separators), digit
 * typing only - and a clean integer out. The database keeps its
 * decimal(12,2) columns and the API payload stays a plain number; the
 * formatting lives entirely in this display layer.
 *
 * Controlled on the INTEGER: `value` is the raw number (null = empty), and
 * every keystroke reports the parsed integer back through onChange.
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
        className={
          className ??
          "h-10 w-full rounded-lg border border-input bg-card pl-10 pr-3 text-sm font-bold tabular shadow-2xs focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary"
        }
      />
    </div>
  );
}

function formatRupiahInput(amount: number): string {
  return new Intl.NumberFormat("id-ID", { maximumFractionDigits: 0 }).format(amount);
}

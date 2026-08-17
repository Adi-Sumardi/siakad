"use client";

import { useEffect, useRef } from "react";
import { cn } from "@/lib/utils";

/**
 * Six single-character boxes that behave like one field.
 *
 * Typing advances, backspace on an empty box steps back, and a pasted code
 * fills every box at once - people paste far more often than they type these,
 * and a set of boxes that rejects a paste is worse than a plain text input.
 */
export function OtpInput({
  value,
  onChange,
  onComplete,
  disabled,
  invalid,
}: {
  value: string;
  onChange: (value: string) => void;
  onComplete?: (value: string) => void;
  disabled?: boolean;
  invalid?: boolean;
}) {
  const refs = useRef<(HTMLInputElement | null)[]>([]);
  const digits = value.padEnd(6, " ").slice(0, 6).split("");

  useEffect(() => {
    refs.current[0]?.focus();
  }, []);

  function setDigit(index: number, digit: string) {
    const next = digits.map((d, i) => (i === index ? digit : d)).join("").replace(/\s/g, "");
    onChange(next);

    if (digit && index < 5) {
      refs.current[index + 1]?.focus();
    }

    if (next.length === 6) {
      onComplete?.(next);
    }
  }

  return (
    <div className="flex justify-between gap-2" role="group" aria-label="Kode enam digit">
      {digits.map((digit, index) => (
        <input
          key={index}
          ref={(el) => {
            refs.current[index] = el;
          }}
          value={digit.trim()}
          disabled={disabled}
          aria-invalid={invalid}
          aria-label={`Digit ${index + 1}`}
          inputMode="numeric"
          autoComplete={index === 0 ? "one-time-code" : "off"}
          maxLength={1}
          onChange={(e) => {
            const digit = e.target.value.replace(/\D/g, "").slice(-1);
            setDigit(index, digit);
          }}
          onKeyDown={(e) => {
            if (e.key === "Backspace" && !digits[index].trim() && index > 0) {
              refs.current[index - 1]?.focus();
            }
          }}
          onPaste={(e) => {
            e.preventDefault();
            const pasted = e.clipboardData.getData("text").replace(/\D/g, "").slice(0, 6);
            if (!pasted) return;
            onChange(pasted);
            refs.current[Math.min(pasted.length, 5)]?.focus();
            if (pasted.length === 6) onComplete?.(pasted);
          }}
          className={cn(
            "tabular h-13 w-full rounded-lg border border-input bg-card text-center text-xl font-semibold",
            "focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none",
            "disabled:opacity-60",
            invalid && "border-bad",
          )}
        />
      ))}
    </div>
  );
}

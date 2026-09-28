"use client";

import { JENJANG, JENJANG_GROUPS } from "@/lib/jenjang";
import { cn } from "@/lib/utils";

/**
 * The one jenjang dropdown every filter in the app uses (feature batch
 * Poin 1): renders the fixed 16-entry ladder grouped by jenjang, or the
 * six coarse groups when the context has no classroom granularity
 * (ekstrakurikuler and friends).
 *
 * `value` uses the entry key ("sd-3", "tk-a", "sd"); "" = all.
 *
 * `allowedKeys` (bug batch Poin 1-3) narrows what renders - the cascading
 * filters pass the keys that actually run in the picked unit, so the
 * dropdown can only ever offer combinations with real classes behind
 * them. Null/undefined = the whole ladder, the historical behaviour.
 * Groups whose entries are all filtered out (or, on coarse granularity,
 * that own none of the allowed keys) hide entirely.
 */
export function JenjangSelect({
  value,
  onChange,
  granularity = "fine",
  allLabel = "Semua Jenjang",
  disabled,
  className,
  id,
  allowedKeys,
}: {
  value: string;
  onChange: (key: string) => void;
  granularity?: "fine" | "coarse";
  allLabel?: string;
  disabled?: boolean;
  className?: string;
  id?: string;
  allowedKeys?: string[] | null;
}) {
  const isAllowed = (key: string) => !allowedKeys || allowedKeys.includes(key);

  return (
    <select
      id={id}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      disabled={disabled}
      className={cn(
        "h-10 w-full rounded-lg border border-input bg-card px-3 text-sm shadow-2xs focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary",
        className,
      )}
    >
      <option value="">{allLabel}</option>
      {granularity === "fine"
        ? JENJANG_GROUPS.map((group) => {
            const entries = JENJANG.filter((j) => j.group === group.key && isAllowed(j.key));
            if (entries.length === 0) return null;
            return (
              <optgroup key={group.key} label={group.label}>
                {entries.map((j) => (
                  <option key={j.key} value={j.key}>
                    {j.label}
                  </option>
                ))}
              </optgroup>
            );
          })
        : JENJANG_GROUPS.filter((g) => isAllowed(g.key) || JENJANG.some((j) => j.group === g.key && isAllowed(j.key)))
            .map((g) => (
              <option key={g.key} value={g.key}>
                {g.label}
              </option>
            ))}
    </select>
  );
}


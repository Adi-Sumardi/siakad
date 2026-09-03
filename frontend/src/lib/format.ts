/** Rp 1.950.000 — no decimals; rupiah amounts at a school are never fractional. */
export function rupiah(amount: number): string {
  return "Rp " + new Intl.NumberFormat("id-ID", { maximumFractionDigits: 0 }).format(amount);
}

/**
 * Due dates phrased the way they are acted on.
 *
 * "jatuh tempo 4 hari lagi" is what decides whether a parent pays today; the
 * calendar date makes them do the subtraction themselves.
 */
export function dueLabel(days: number | null): string {
  if (days === null) return "";
  if (days < 0) return `Telat ${Math.abs(days)} hari`;
  if (days === 0) return "Jatuh tempo hari ini";
  if (days === 1) return "Jatuh tempo besok";
  return `Jatuh tempo ${days} hari lagi`;
}

export function tanggal(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString("id-ID", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

export function tanggalWaktu(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("id-ID", {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

/**
 * Today's date in Jakarta, as YYYY-MM-DD - for a <input type="date">'s
 * default value or max. `new Date().toISOString().slice(0, 10)` (the usual
 * shortcut) converts to UTC first: for the seven hours every morning
 * (00:00-07:00 WIB) that fall on UTC's previous day, it silently returns
 * yesterday - capping the max at yesterday makes today unselectable, and
 * defaulting to yesterday pre-fills the wrong date, right when a teacher is
 * recording something that happened this morning.
 */
export function todayJakarta(): string {
  return new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Jakarta" });
}

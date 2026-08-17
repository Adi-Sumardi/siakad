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

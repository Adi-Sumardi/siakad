import { AlertOctagon, AlertTriangle, ArrowDownRight, ArrowRight, ArrowUpRight, CheckCircle2 } from "lucide-react";
import type { ExecAlert, MonthPoint } from "@/lib/types/eksekutif";
import { cn } from "@/lib/utils";

/*
 * Building blocks of the yayasan dashboard. Written for readers who are not
 * at ease with dashboards: every number is printed next to its bar (nothing
 * hides behind hover), status always carries a word as well as a colour, and
 * each card opens with one plain sentence saying what to look at.
 */

export function persen(value: number | null | undefined): string {
  if (value === null || value === undefined) return "—";
  return `${value.toLocaleString("id-ID", { maximumFractionDigits: 1 })}%`;
}

/** Rp 1,66 miliar / Rp 410 juta / Rp 950.000 - the scale a board meeting talks in. */
export function rupiahRingkas(amount: number): string {
  const fmt = (n: number, digits: number) => n.toLocaleString("id-ID", { maximumFractionDigits: digits });
  if (amount >= 1_000_000_000) return `Rp ${fmt(amount / 1_000_000_000, 2)} miliar`;
  if (amount >= 1_000_000) return `Rp ${fmt(amount / 1_000_000, 1)} juta`;
  return `Rp ${fmt(amount, 0)}`;
}

export function Section({ title, sentence, children, className }: { title: string; sentence?: string; children: React.ReactNode; className?: string }) {
  return (
    <section className={cn("print-avoid-break rounded-2xl border border-border bg-card p-5 sm:p-6", className)}>
      <h2 className="text-lg font-bold text-foreground">{title}</h2>
      {sentence && <p className="mt-1 text-base text-muted-foreground">{sentence}</p>}
      <div className="mt-5">{children}</div>
    </section>
  );
}

export function SectionLabel({ children }: { children: React.ReactNode }) {
  return <h2 className="mb-3 text-sm font-bold uppercase tracking-wider text-muted-foreground">{children}</h2>;
}

// ---------------------------------------------------------------------------

export function AlertList({
  alerts,
  more,
  onOpenUnit,
}: {
  alerts: ExecAlert[];
  more: number;
  onOpenUnit?: (code: string) => void;
}) {
  if (alerts.length === 0) {
    return (
      <div className="flex items-start gap-3 rounded-2xl border border-good/30 bg-good-soft p-5">
        <CheckCircle2 className="mt-0.5 size-6 shrink-0 text-good" aria-hidden />
        <p className="text-base font-semibold text-good">Tidak ada yang perlu perhatian khusus saat ini.</p>
      </div>
    );
  }

  return (
    <div className="space-y-3">
      <div className="grid gap-3 md:grid-cols-2">
        {alerts.map((a, i) => {
          const critical = a.tone === "kritis";
          const Icon = critical ? AlertOctagon : AlertTriangle;
          return (
            <div
              key={i}
              className={cn(
                "print-avoid-break flex flex-col gap-2 rounded-2xl border p-5",
                critical ? "border-bad/30 bg-bad-soft" : "border-warn/30 bg-warn-soft",
              )}
            >
              <span
                className={cn(
                  "inline-flex w-fit items-center gap-1.5 rounded-full bg-card px-2.5 py-1 text-xs font-bold tracking-wide",
                  critical ? "text-bad" : "text-warn",
                )}
              >
                <Icon className="size-4" aria-hidden />
                {critical ? "KRITIS" : "WASPADA"}
              </span>
              <p className="text-base font-bold leading-snug text-foreground">{a.title}</p>
              <p className="text-base leading-relaxed text-foreground/80">{a.body}</p>
              {onOpenUnit && (
                <button
                  type="button"
                  onClick={() => onOpenUnit(a.unit_code)}
                  className="no-print mt-1 inline-flex w-fit items-center gap-1 text-sm font-semibold text-primary hover:underline"
                >
                  Lihat unit ini <ArrowRight className="size-4" aria-hidden />
                </button>
              )}
            </div>
          );
        })}
      </div>
      {more > 0 && <p className="text-sm text-muted-foreground">Ada {more} hal lain yang lebih ringan. Pilih unit di atas untuk melihat semuanya.</p>}
    </div>
  );
}

// ---------------------------------------------------------------------------

export function StatTile({
  label,
  value,
  note,
  tone,
}: {
  label: string;
  value: string;
  note?: string;
  tone?: "good" | "warn" | "bad";
}) {
  return (
    <div className="print-avoid-break flex min-w-0 flex-col rounded-2xl border border-border bg-card p-5">
      <p className="text-sm font-semibold text-muted-foreground">{label}</p>
      <p
        className={cn(
          "mt-2 text-3xl font-bold tabular-nums tracking-tight text-foreground",
          tone === "bad" && "text-bad",
          tone === "warn" && "text-warn",
        )}
      >
        {value}
      </p>
      {note && <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{note}</p>}
    </div>
  );
}

// ---------------------------------------------------------------------------

export type CompareRow = {
  code: string;
  label: string;
  value: number | null;
  display: string;
  /** Status word printed after the value; also tints the bar. */
  flag?: { text: string; tone: "warn" | "bad" };
  /** Shown instead of a bar when the value is missing or not trustworthy yet. */
  emptyText?: string;
  /** Quiet caveat under the label, e.g. "presensi belum lengkap". */
  note?: string;
};

/**
 * One measure, every unit, one colour. Units are named on the row, so no
 * categorical palette or legend is needed; a selected unit is emphasised and
 * the others dim.
 */
export function CompareBars({
  rows,
  max,
  selected,
  threshold,
  thresholdLabel,
}: {
  rows: CompareRow[];
  max: number;
  selected?: string | null;
  threshold?: number;
  thresholdLabel?: string;
}) {
  const scale = max > 0 ? max : 1;

  return (
    <div>
      <ul className="space-y-3">
        {rows.map((r) => {
          const dim = selected && selected !== r.code;
          const width = r.value !== null ? Math.max(1.5, Math.min(100, (r.value / scale) * 100)) : 0;
          return (
            <li key={r.code} className={cn("grid grid-cols-[6.5rem_1fr_4.25rem] items-center gap-3 sm:grid-cols-[9rem_1fr_4.5rem]", dim && "opacity-40")}>
              <span className="min-w-0">
                <span className={cn("block truncate text-base", selected === r.code ? "font-bold text-foreground" : "font-medium text-foreground/90")} title={r.label}>
                  {r.label}
                </span>
                {r.flag && !r.emptyText && (
                  <span className={cn("block truncate text-xs font-bold", r.flag.tone === "bad" ? "text-bad" : "text-warn")}>{r.flag.text}</span>
                )}
                {r.note && <span className="block truncate text-xs font-semibold text-warn">{r.note}</span>}
              </span>
              <div className="relative h-7 rounded-md bg-canvas">
                {r.value !== null && !r.emptyText && (
                  <div
                    className={cn(
                      "h-full rounded-md",
                      r.flag?.tone === "bad" ? "bg-bad" : r.flag?.tone === "warn" ? "bg-warn" : "bg-[#2a78d6]",
                    )}
                    style={{ width: `${width}%` }}
                  />
                )}
                {r.emptyText && (
                  <span className="absolute inset-0 flex items-center truncate whitespace-nowrap px-3 text-sm italic text-muted-foreground">{r.emptyText}</span>
                )}
                {threshold !== undefined && (
                  <span
                    className="absolute -top-1 -bottom-1 border-l-2 border-dashed border-foreground/50"
                    style={{ left: `${Math.min(100, (threshold / scale) * 100)}%` }}
                    aria-hidden
                  />
                )}
              </div>
              <span className="text-right text-base font-semibold tabular-nums text-foreground">{r.emptyText ? "" : r.display}</span>
            </li>
          );
        })}
      </ul>
      {threshold !== undefined && thresholdLabel && (
        <p className="mt-4 flex items-center gap-2 text-sm text-muted-foreground">
          <span className="inline-block h-4 border-l-2 border-dashed border-foreground/50" aria-hidden />
          {thresholdLabel}
        </p>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------

/** Six months, one series, value printed above each bar. */
export function MonthBars({ points, field, format, max }: { points: MonthPoint[]; field: "rate" | "amount"; format: (v: number) => string; max?: number }) {
  const values = points.map((p) => (field === "rate" ? p.rate : p.amount) ?? null);
  const top = max ?? Math.max(1, ...values.map((v) => v ?? 0));

  return (
    <div className="grid grid-cols-6 items-end gap-2 sm:gap-4" style={{ height: "13rem" }}>
      {points.map((p, i) => {
        const v = values[i];
        const empty = v === null || (field === "amount" && v === 0);
        const isLast = i === points.length - 1;
        return (
          <div key={p.key} className="flex h-full flex-col items-center justify-end gap-1.5">
            <span className="text-center text-xs font-semibold tabular-nums text-foreground sm:text-sm">{empty ? "—" : format(v as number)}</span>
            <div
              className={cn("w-full max-w-14 rounded-t-md", empty ? "bg-canvas" : isLast ? "bg-[#1c5cab]" : "bg-[#2a78d6]")}
              style={{ height: empty ? "4px" : `${Math.max(4, ((v as number) / top) * 100)}%`, maxHeight: "calc(100% - 3.5rem)" }}
            />
            <span className="text-sm font-medium text-muted-foreground">{p.label}</span>
          </div>
        );
      })}
    </div>
  );
}

// ---------------------------------------------------------------------------

/** Ordinal blue ramp (validated: monotone, light end 2.06:1 on the surface). */
export const AGING_COLORS = ["#86b6ef", "#3987e5", "#1c5cab", "#0d366b"] as const;
export const AGING_LABELS = ["0–30 hari", "31–60 hari", "61–90 hari", "lebih dari 90 hari"] as const;

export function AgingBars({
  rows,
  selected,
}: {
  rows: Array<{ code: string; label: string; arrears: [number, number, number, number]; total: number }>;
  selected?: string | null;
}) {
  const max = Math.max(1, ...rows.map((r) => r.total));

  return (
    <div>
      <div className="mb-5 flex flex-wrap gap-x-5 gap-y-2">
        {AGING_LABELS.map((label, i) => (
          <span key={label} className="flex items-center gap-2 text-sm text-foreground/90">
            <span className="inline-block size-4 rounded-sm" style={{ background: AGING_COLORS[i] }} aria-hidden />
            {label}
          </span>
        ))}
      </div>
      <ul className="space-y-4">
        {rows.map((r) => (
          <li key={r.code} className={cn(selected && selected !== r.code && "opacity-40")}>
            <div className="flex items-baseline justify-between gap-3">
              <span className="truncate text-base font-medium text-foreground" title={r.label}>{r.label}</span>
              <span className="text-base font-semibold tabular-nums text-foreground">{r.total > 0 ? rupiahRingkas(r.total) : "Tidak ada tunggakan"}</span>
            </div>
            {r.total > 0 && (
              <>
                <div className="mt-1.5 flex h-6 gap-[2px]" style={{ width: `${Math.max(4, (r.total / max) * 100)}%` }}>
                  {r.arrears.map((v, i) =>
                    v > 0 ? (
                      <div
                        key={i}
                        className="h-full first:rounded-l-md last:rounded-r-md"
                        style={{ flexGrow: v, flexBasis: 0, background: AGING_COLORS[i], minWidth: "3px" }}
                        title={`${AGING_LABELS[i]}: ${rupiahRingkas(v)}`}
                      />
                    ) : null,
                  )}
                </div>
                <p className="mt-1 text-sm text-muted-foreground">
                  {r.arrears[3] > 0 ? (
                    <>
                      <span className="font-semibold text-foreground">{rupiahRingkas(r.arrears[3])}</span> sudah lebih dari 90 hari
                    </>
                  ) : (
                    "Tidak ada yang lebih dari 90 hari"
                  )}
                </p>
              </>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}

// ---------------------------------------------------------------------------

export function Change({ from, to }: { from: number; to: number }) {
  if (from === 0) return <span className="text-sm text-muted-foreground">—</span>;
  const pct = ((to - from) / from) * 100;
  const rounded = Math.round(Math.abs(pct) * 10) / 10;
  if (rounded === 0) {
    return <span className="inline-flex items-center gap-1 text-sm font-semibold text-muted-foreground">tetap</span>;
  }
  const up = pct > 0;
  const Icon = up ? ArrowUpRight : ArrowDownRight;
  return (
    <span className={cn("inline-flex items-center gap-1 text-sm font-bold", up ? "text-good" : "text-bad")}>
      <Icon className="size-4" aria-hidden />
      {up ? "naik" : "turun"} {rounded.toLocaleString("id-ID")}%
    </span>
  );
}

export function EnrollmentTable({
  years,
  units,
  totals,
}: {
  years: string[];
  units: Array<{ code: string; label: string; counts: number[] }>;
  totals: number[];
}) {
  const n = years.length;
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[30rem] text-base">
        <thead>
          <tr className="border-b border-border text-left text-sm text-muted-foreground">
            <th className="py-2 pr-3 font-semibold">Unit</th>
            {years.map((y) => (
              <th key={y} className="px-3 py-2 text-right font-semibold">TA {y}</th>
            ))}
            {n >= 2 && <th className="py-2 pl-3 text-right font-semibold">Dari tahun lalu</th>}
          </tr>
        </thead>
        <tbody>
          {units.map((u) => (
            <tr key={u.code} className="border-b border-border/60">
              <td className="py-2.5 pr-3 font-medium text-foreground">{u.label}</td>
              {u.counts.map((c, i) => (
                <td key={i} className={cn("px-3 py-2.5 text-right tabular-nums", i === n - 1 ? "font-bold text-foreground" : "text-foreground/80")}>
                  {c.toLocaleString("id-ID")}
                </td>
              ))}
              {n >= 2 && (
                <td className="py-2.5 pl-3 text-right">
                  <Change from={u.counts[n - 2]} to={u.counts[n - 1]} />
                </td>
              )}
            </tr>
          ))}
        </tbody>
        {units.length > 1 && (
          <tfoot>
            <tr className="font-bold text-foreground">
              <td className="pt-3 pr-3">Seluruh yayasan</td>
              {totals.map((t, i) => (
                <td key={i} className="px-3 pt-3 text-right tabular-nums">{t.toLocaleString("id-ID")}</td>
              ))}
              {n >= 2 && (
                <td className="pt-3 pl-3 text-right">
                  <Change from={totals[n - 2]} to={totals[n - 1]} />
                </td>
              )}
            </tr>
          </tfoot>
        )}
      </table>
    </div>
  );
}

export function DataNote({ ok, okText, badText }: { ok: boolean; okText: string; badText: string }) {
  return ok ? (
    <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-good">
      <CheckCircle2 className="size-4" aria-hidden /> {okText}
    </span>
  ) : (
    <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-warn">
      <AlertTriangle className="size-4" aria-hidden /> {badText}
    </span>
  );
}

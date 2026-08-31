import { Badge } from "@/components/ui/badge";
import type { AttendanceSummary } from "@/lib/types/kesiswaan";

/** Four tallies, not a single "balance" like PointMeter - attendance has no one number to summarize it. */
export function AttendanceMeter({ summary }: { summary: AttendanceSummary }) {
  const rows: { label: string; value: number; variant: "good" | "warn" | "default" | "bad" }[] = [
    { label: "Hadir", value: summary.hadir, variant: "good" },
    { label: "Sakit", value: summary.sakit, variant: "warn" },
    { label: "Izin", value: summary.izin, variant: "default" },
    { label: "Alpa", value: summary.alpa, variant: "bad" },
  ];

  return (
    <div className="grid grid-cols-2 gap-2">
      {rows.map((r) => (
        <div key={r.label} className="flex items-center justify-between rounded-lg bg-muted/30 px-3 py-2">
          <span className="text-xs font-semibold text-muted-foreground">{r.label}</span>
          <Badge variant={r.variant} className="tabular">{r.value}</Badge>
        </div>
      ))}
    </div>
  );
}

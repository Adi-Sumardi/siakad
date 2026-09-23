"use client";

import { Suspense, useEffect, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { AlertTriangle, ArrowLeft, ChevronRight, GraduationCap, TrendingDown, UserCheck } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuth } from "@/lib/auth/auth-context";
import { api, ApiError } from "@/lib/api";

type Reason = "absenteeism" | "below_kkm" | "grade_decline" | "point_violation";

type AttentionStudent = {
  ulid: string;
  nama_lengkap: string;
  nis: string | null;
  status: string;
  unit: { code: string; label: string; jenjang: string } | null;
  classroom: { ulid: string; name: string } | null;
  reasons: Reason[];
  metrics: {
    alpa_count: number;
    current_average: number | null;
    previous_average: number | null;
    average_drop: number | null;
    violation_records: number;
  };
};

type AttentionResponse = {
  period: { academic_year: string | null; term_label: string | null };
  thresholds: { kkm: number; min_alpa: number; grade_drop: number };
  total: number;
  counts: Record<Reason, number>;
  students: AttentionStudent[];
};

// Order matters: the pill row and each row's reason badges both read it.
const REASON_META: Record<Reason, { label: string; variant: "bad" | "warn" }> = {
  absenteeism: { label: "Absensi Tinggi", variant: "bad" },
  below_kkm: { label: "Nilai di Bawah KKM", variant: "warn" },
  grade_decline: { label: "Penurunan Nilai", variant: "warn" },
  point_violation: { label: "Pelanggaran Poin", variant: "bad" },
};

const REASONS = Object.keys(REASON_META) as Reason[];

/** Only the numbers behind the reasons this row was actually flagged for. */
function metricLine(s: AttentionStudent): string {
  const parts: string[] = [];
  for (const reason of s.reasons) {
    if (reason === "absenteeism") parts.push(`Alpa ${s.metrics.alpa_count} kali tahun ini`);
    if (reason === "below_kkm" && s.metrics.current_average !== null) {
      parts.push(`Rata-rata nilai akhir ${new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 }).format(s.metrics.current_average)}`);
    }
    if (reason === "grade_decline" && s.metrics.current_average !== null && s.metrics.previous_average !== null) {
      const fmt = (n: number) => new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 }).format(n);
      parts.push(`Rata-rata ${fmt(s.metrics.previous_average)} → ${fmt(s.metrics.current_average)} (turun ${fmt(s.metrics.average_drop ?? 0)} poin)`);
    }
    if (reason === "point_violation") parts.push(`${s.metrics.violation_records} catatan pelanggaran poin`);
  }
  return parts.join(" · ");
}

function AdminAttentionContent() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";
  const searchParams = useSearchParams();

  const [reason, setReason] = useState<Reason | "">((searchParams.get("reason") as Reason) ?? "");
  const [unit, setUnit] = useState(searchParams.get("unit") ?? "");
  // The loaded response remembers the query it answers; "current" below is
  // derived during render, so switching filters shows skeletons without a
  // synchronous setState in the effect (which the repo's react-hooks rule
  // forbids).
  const [loaded, setLoaded] = useState<{ query: string; data: AttentionResponse } | null>(null);
  const [units, setUnits] = useState<Array<{ ulid: string; code: string; label: string }>>([]);

  const query = `${reason}|${unit}`;

  useEffect(() => {
    if (!isCentral) return;
    api
      .get<{ school_units: Array<{ ulid: string; code: string; label: string }> }>("/api/admin/school-units")
      .then((d) => setUnits(d.school_units))
      .catch(() => {});
  }, [isCentral]);

  useEffect(() => {
    const params = new URLSearchParams();
    if (reason) params.set("reason", reason);
    if (unit) params.set("unit", unit);

    api
      .get<AttentionResponse>(`/api/admin/students/attention?${params.toString()}`)
      .then((data) => setLoaded({ query, data }))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar perhatian."));
  }, [reason, unit, query]);

  const data = loaded?.query === query ? loaded.data : null;
  const shown = data?.students ?? [];
  const thresholds = data?.thresholds;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <Link href="/admin" className="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" />
          <span>Kembali ke Dashboard</span>
        </Link>
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mt-2">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
              <AlertTriangle className="size-6 text-warn" />
              Siswa Perlu Perhatian
            </h1>
            <p className="text-sm text-muted-foreground mt-0.5">
              Daftar nama di balik angka watchlist dashboard
              {data?.period.term_label ? ` — ${data.period.term_label}` : ""}.
            </p>
          </div>
          {isCentral && (
            <div className="flex items-center gap-1.5 bg-card border border-input px-3 py-1.5 rounded-lg shadow-2xs">
              <span className="text-xs font-semibold text-muted-foreground">Unit:</span>
              <select
                value={unit}
                onChange={(e) => setUnit(e.target.value)}
                className="bg-transparent text-xs font-bold text-foreground focus:outline-hidden"
              >
                <option value="">Semua Unit</option>
                {units.map((u) => (
                  <option key={u.ulid} value={u.code}>
                    {u.label}
                  </option>
                ))}
              </select>
            </div>
          )}
        </div>
      </div>

      {/* Criteria note - quotes the thresholds the backend actually used */}
      {thresholds && (
        <p className="text-xs text-muted-foreground">
          Kriteria: alpa ≥ {thresholds.min_alpa} kali tahun ajaran berjalan · rata-rata nilai akhir &lt; {thresholds.kkm} (KKM) ·
          turun ≥ {thresholds.grade_drop} poin antar semester · memiliki catatan pelanggaran poin semester berjalan.
        </p>
      )}

      {/* Reason filter pills */}
      <div className="flex flex-wrap gap-2">
        <button
          type="button"
          onClick={() => setReason("")}
          className={`rounded-full px-4 py-1.5 text-xs font-bold transition-colors ${
            reason === "" ? "bg-primary text-primary-foreground shadow-xs" : "bg-muted/40 text-muted-foreground hover:bg-muted"
          }`}
        >
          Semua {data ? `· ${data.total}` : ""}
        </button>
        {REASONS.map((r) => (
          <button
            key={r}
            type="button"
            onClick={() => setReason(reason === r ? "" : r)}
            className={`rounded-full px-4 py-1.5 text-xs font-bold transition-colors ${
              reason === r ? "bg-primary text-primary-foreground shadow-xs" : "bg-muted/40 text-muted-foreground hover:bg-muted"
            }`}
          >
            {REASON_META[r].label} {data ? `· ${data.counts[r]}` : ""}
          </button>
        ))}
      </div>

      {/* List */}
      {data === null && (
        <div className="space-y-3">
          <Skeleton className="h-20 w-full rounded-2xl" />
          <Skeleton className="h-20 w-full rounded-2xl" />
          <Skeleton className="h-20 w-full rounded-2xl" />
        </div>
      )}

      {data !== null && shown.length === 0 && (
        <Card className="p-8 text-center text-sm text-muted-foreground">
          Tidak ada siswa yang memenuhi kriteria ini{reason ? ` untuk filter "${REASON_META[reason].label}"` : ""}.
        </Card>
      )}

      <div className="grid grid-cols-1 gap-3">
        {shown.map((s) => (
          <Card key={s.ulid} className="p-4 sm:p-5 border-border/80 shadow-xs flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4">
            <div className="flex min-w-0 flex-1 items-start gap-3">
              <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary">
                <GraduationCap className="size-5" />
              </span>
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-1.5">
                  <p className="font-bold text-sm text-foreground">{s.nama_lengkap}</p>
                  {s.status !== "active" && <Badge variant="default" className="text-[10px]">{s.status}</Badge>}
                </div>
                <p className="text-xs text-muted-foreground mt-0.5">
                  NIS: {s.nis ?? "—"}
                  {s.classroom ? ` · Kelas ${s.classroom.name}` : " · Belum punya rombel"}
                  {isCentral && s.unit ? ` · ${s.unit.label}` : ""}
                </p>
                <p className="text-xs text-muted-foreground mt-1">{metricLine(s)}</p>
              </div>
            </div>

            <div className="flex flex-wrap items-center gap-1.5 sm:justify-end">
              {s.reasons.map((r) => (
                <Badge key={r} variant={REASON_META[r].variant} className="gap-1 text-[10px] font-bold">
                  {r === "absenteeism" && <UserCheck className="size-3" />}
                  {r === "grade_decline" && <TrendingDown className="size-3" />}
                  {REASON_META[r].label}
                </Badge>
              ))}
            </div>
          </Card>
        ))}
      </div>

      {/* Where to act next per condition - the poin ledger lives in /admin/poin */}
      {data !== null && data.counts.point_violation > 0 && (
        <Link href="/admin/poin" className="group flex items-center justify-between rounded-xl border border-border/60 bg-card p-4 text-xs font-semibold text-primary hover:border-primary/50">
          <span>{data.counts.point_violation} siswa dengan pelanggaran poin — buku besar poin ada di menu Poin Siswa</span>
          <ChevronRight className="size-4 group-hover:translate-x-1 transition-transform" />
        </Link>
      )}
    </div>
  );
}

export default function AdminAttentionPage() {
  // useSearchParams reads the ?reason=/?unit= filters the dashboard links
  // append, and needs a Suspense boundary or the route bails out of
  // prerendering.
  return (
    <Suspense fallback={<Skeleton className="h-96 w-full rounded-2xl" />}>
      <AdminAttentionContent />
    </Suspense>
  );
}

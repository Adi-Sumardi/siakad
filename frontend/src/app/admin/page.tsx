"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { AlertTriangle, ChevronRight, Wallet, X } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuth } from "@/lib/auth/auth-context";
import { api } from "@/lib/api";
import { rupiah } from "@/lib/format";
import { cn } from "@/lib/utils";

type AttendanceTally = { hadir: number; sakit: number; izin: number; alpa: number };

type UnitSummary = {
  unit_id: number;
  unit_code: string;
  unit_label: string;
  jenjang: string;
  students_active: number;
  students_new: number;
  enrolled_students: number;
  classrooms: number;
  teachers: number;
  billed: number;
  paid: number;
  outstanding: number;
  collection_rate: number;
  overdue_bills: number;
  attendance_rate: number | null;
  attendance_today: AttendanceTally;
  attendance_today_rate: number | null;
  achievements: number;
  achievements_pending: number;
  grades_below_kkm: number;
  grades_graded: number;
  grades_average: number | null;
  students_high_absenteeism: number;
  students_declined: number;
  students_needing_attention: number;
  extracurriculars: number;
  extracurricular_members: number;
  violation_students: number;
  points_merit_records: number;
  points_violation_records: number;
};

type AlertItem = {
  id: string;
  label: string;
  detail: string;
  count: number;
  severity: "good" | "warn" | "bad";
  href: string | null;
  units: Array<{ code: string; label: string; count: number }>;
};

type SummaryResponse = {
  period: {
    academic_year: string | null;
    term: string | null;
    term_label: string | null;
    term_ulid: string | null;
    // What the billing numbers cover - "year" = running academic year,
    // "all" = every bill ever (T23).
    billing_scope: "year" | "all";
    billing_label: string;
  };
  thresholds: { kkm: number; min_alpa: number; grade_drop: number };
  scope: {
    is_central: boolean;
    unit_count: number;
    unit_label: string | null;
  };
  kpi: {
    students_active: number;
    students_new: number;
    enrolled_students: number;
    students_total: number;
    classrooms: number;
    teachers: number;
    billing: {
      total_billed: number;
      total_paid: number;
      total_outstanding: number;
      collection_rate: number;
      bill_count: number;
      overdue_bills: number;
      overdue_amount: number;
      unpaid_count: number;
      partial_count: number;
    };
    attendance: AttendanceTally & { rate: number | null };
    attendance_today: AttendanceTally & { rate: number | null; total: number };
    achievements: { verified: number; pending: number; siswa: number; guru: number };
    grades: { students_graded: number; average: number; below_kkm: number; declined: number };
    points: { merit_records: number; violation_records: number; violation_students: number };
    extracurriculars: { activities: number; members: number };
  };
  units: UnitSummary[];
  alerts: AlertItem[];
};

type FailureSummary = { failed_24h: number; failed_7d: number; exhausted_7d: number };

const num = (n: number) => new Intl.NumberFormat("id-ID").format(n);
const SEVERITY_ORDER = { bad: 0, warn: 1, good: 2 } as const;

/**
 * Ringkasan (redesigned 2026-09-24): one screen answering three questions -
 * how is the money coming in, what needs doing today, which unit is behind.
 * The old page showed every per-unit number three times (akademik cards,
 * rekap table, grafik) and eight KPI tiles; the detail now lives one click
 * away in the unit panel and in each module's own menu.
 */
export default function AdminHomePage() {
  const { user } = useAuth();
  const [data, setData] = useState<SummaryResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [billingPeriod, setBillingPeriod] = useState<"year" | "all">("year");
  const [switching, setSwitching] = useState(false);
  const [failures, setFailures] = useState<FailureSummary | null>(null);
  const [openUnit, setOpenUnit] = useState<UnitSummary | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .get<SummaryResponse>(`/api/admin/dashboard/summary?billing_period=${billingPeriod}`)
      .then((res) => {
        if (!cancelled) setData(res);
      })
      .catch(() => toast.error("Gagal memuat ringkasan."))
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
          setSwitching(false);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [billingPeriod]);

  // Central admin only (strict === true): a unit admin would only get a 403.
  // Silent on error - a network blip is not worth a toast on the dashboard.
  const isCentralStrict = data?.scope.is_central === true;
  useEffect(() => {
    if (!isCentralStrict) return;
    let cancelled = false;
    api
      .get<{ failures: FailureSummary }>("/api/admin/notification-failures")
      .then((res) => {
        if (!cancelled) setFailures(res.failures);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [isCentralStrict]);

  const isCentral = data?.scope.is_central ?? true;
  const kpi = data?.kpi;
  const billingLabel = data?.period.billing_label ?? "TA berjalan";

  const alerts: AlertItem[] = [
    ...(data?.alerts ?? []),
    ...(failures && failures.failed_24h > 0
      ? [{
          id: "notification-failures",
          label: "Notifikasi email/WhatsApp gagal terkirim",
          detail: failures.exhausted_7d > 0
            ? `${num(failures.exhausted_7d)} tidak akan dicoba ulang lagi - perlu dikirim manual`
            : "Sistem mencoba ulang otomatis (maks 3x dalam 24 jam)",
          count: failures.failed_24h,
          severity: (failures.exhausted_7d > 0 ? "bad" : "warn") as AlertItem["severity"],
          href: "/admin/monitoring",
          units: [],
        }]
      : []),
  ]
    .filter((a) => a.count > 0)
    .sort((a, b) => SEVERITY_ORDER[a.severity] - SEVERITY_ORDER[b.severity]);

  const today = kpi?.attendance_today;
  const absentToday = today ? today.sakit + today.izin + today.alpa : 0;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-extrabold tracking-tight text-foreground">Ringkasan</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            {isCentral ? `Semua unit (${data?.scope.unit_count ?? "-"})` : (data?.scope.unit_label ?? user?.name)}
            {data?.period.term_label && ` · Semester ${data.period.term_label}`}
          </p>
        </div>
        <Link href="/admin/generate">
          <Button className="gap-2 font-bold text-xs">
            <Wallet className="size-4" /> Terbitkan SPP
          </Button>
        </Link>
      </div>

      {/* Four numbers */}
      {loading || !kpi ? (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-24 rounded-xl" />
          ))}
        </div>
      ) : (
        <div className="space-y-2">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Kpi
              label="Siswa aktif"
              value={num(kpi.students_active)}
              sub={isCentral ? `${num(data?.scope.unit_count ?? 0)} unit` : `${num(kpi.classrooms)} rombel · ${num(kpi.teachers)} guru`}
            />
            <Kpi
              label="Kas masuk"
              value={rupiah(kpi.billing.total_paid)}
              sub={`${kpi.billing.collection_rate}% terbayar`}
              tone="good"
            />
            <Kpi
              label="Piutang"
              value={rupiah(kpi.billing.total_outstanding)}
              sub={kpi.billing.overdue_bills > 0 ? `${num(kpi.billing.overdue_bills)} lewat jatuh tempo` : "Tidak ada yang lewat jatuh tempo"}
              tone={kpi.billing.overdue_bills > 0 ? "bad" : undefined}
            />
            <Kpi
              label="Hadir hari ini"
              value={today?.rate !== null && today?.rate !== undefined ? `${today.rate}%` : "—"}
              sub={today && today.total > 0 ? `${num(absentToday)} tidak hadir (S ${num(today.sakit)} · I ${num(today.izin)} · A ${num(today.alpa)})` : "Belum ada catatan hari ini"}
            />
          </div>
          <div className="flex items-center justify-end gap-2 text-[11px] text-muted-foreground">
            <span>Angka keuangan:</span>
            <PeriodToggle
              value={billingPeriod}
              disabled={switching || loading}
              onChange={(next) => {
                if (next === billingPeriod || switching) return;
                setSwitching(true);
                setBillingPeriod(next);
              }}
            />
          </div>
        </div>
      )}

      {/* What needs doing */}
      <Card className="p-5 border-border/80 shadow-xs">
        <div className="flex items-center justify-between gap-3">
          <h2 className="text-base font-bold text-foreground">Perlu tindak lanjut</h2>
          {!loading && alerts.length > 0 && <Badge variant="warn">{alerts.length} hal</Badge>}
        </div>

        {loading ? (
          <Skeleton className="h-32 w-full rounded-xl mt-4" />
        ) : alerts.length === 0 ? (
          <p className="mt-3 text-sm text-muted-foreground">Tidak ada yang perlu ditindaklanjuti. Semua aman.</p>
        ) : (
          <ul className="mt-3 divide-y divide-border/60">
            {alerts.map((alert) => (
              <AlertRow key={alert.id} alert={alert} showUnits={isCentral} />
            ))}
          </ul>
        )}
      </Card>

      {/* Which unit is behind - central admin only */}
      {isCentral && data && data.units.length > 1 && (
        <Card className="p-5 border-border/80 shadow-xs">
          <div className="flex items-baseline justify-between gap-3">
            <h2 className="text-base font-bold text-foreground">Per unit</h2>
            <span className="text-[11px] text-muted-foreground">Keuangan {billingLabel} · klik baris untuk rincian</span>
          </div>
          <div className="mt-3 overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] font-bold uppercase tracking-wider text-muted-foreground border-b border-border/70">
                  <th className="py-2 pr-3">Unit</th>
                  <th className="py-2 px-3 text-right">Siswa</th>
                  <th className="py-2 px-3 text-right">Hadir</th>
                  <th className="py-2 px-3 min-w-44">Pelunasan</th>
                  <th className="py-2 px-3 text-right">Piutang</th>
                  <th className="py-2 pl-3 text-right">Perhatian</th>
                </tr>
              </thead>
              <tbody>
                {data.units.map((unit) => (
                  <tr
                    key={unit.unit_id}
                    onClick={() => setOpenUnit(unit)}
                    onKeyDown={(e) => (e.key === "Enter" || e.key === " ") && (e.preventDefault(), setOpenUnit(unit))}
                    tabIndex={0}
                    className="border-b border-border/50 last:border-0 cursor-pointer hover:bg-accent/40 focus-visible:bg-accent/40 outline-none transition-colors"
                  >
                    <td className="py-2.5 pr-3">
                      <span className="font-semibold text-foreground">{unit.unit_label}</span>
                    </td>
                    <td className="py-2.5 px-3 text-right tabular-nums">{num(unit.students_active)}</td>
                    <td className="py-2.5 px-3 text-right tabular-nums">
                      {unit.attendance_today_rate !== null ? `${unit.attendance_today_rate}%` : "—"}
                    </td>
                    <td className="py-2.5 px-3">
                      {unit.billed > 0 ? (
                        <div className="flex items-center gap-2">
                          <div className="h-2 flex-1 rounded-full bg-muted/70 overflow-hidden">
                            <div
                              style={{ width: `${Math.min(100, unit.collection_rate)}%` }}
                              className={cn(
                                "h-full rounded-full",
                                unit.collection_rate >= 80 ? "bg-good" : unit.collection_rate >= 50 ? "bg-warn" : "bg-bad",
                              )}
                            />
                          </div>
                          <span className="w-10 text-right text-xs tabular-nums">{unit.collection_rate}%</span>
                        </div>
                      ) : (
                        <span className="text-xs text-muted-foreground">Belum ada tagihan</span>
                      )}
                    </td>
                    <td className="py-2.5 px-3 text-right tabular-nums whitespace-nowrap">
                      {unit.outstanding > 0 ? rupiah(unit.outstanding) : <span className="text-good">Lunas</span>}
                    </td>
                    <td className="py-2.5 pl-3 text-right tabular-nums">
                      <span className="inline-flex items-center gap-1">
                        <span className={unit.students_needing_attention > 0 ? "font-semibold text-bad" : "text-muted-foreground"}>
                          {num(unit.students_needing_attention)}
                        </span>
                        <ChevronRight className="size-3.5 text-muted-foreground" />
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {openUnit && data && (
        <UnitPanel unit={openUnit} thresholds={data.thresholds} billingLabel={billingLabel} onClose={() => setOpenUnit(null)} />
      )}
    </div>
  );
}

function Kpi({ label, value, sub, tone }: { label: string; value: string; sub: string; tone?: "good" | "bad" }) {
  return (
    <Card className="p-4 border-border/80 shadow-xs">
      <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">{label}</p>
      <p
        className={cn(
          "mt-1 text-xl sm:text-2xl font-black leading-tight tabular-nums",
          tone === "good" ? "text-good" : tone === "bad" ? "text-bad" : "text-foreground",
        )}
      >
        {value}
      </p>
      <p className="mt-1 text-[11px] leading-snug text-muted-foreground">{sub}</p>
    </Card>
  );
}

function AlertRow({ alert, showUnits }: { alert: AlertItem; showUnits: boolean }) {
  const dot = alert.severity === "bad" ? "bg-bad" : alert.severity === "warn" ? "bg-warn" : "bg-good";
  const units = showUnits ? alert.units.slice(0, 3) : [];

  const body = (
    <>
      <span className={cn("mt-1.5 size-2 shrink-0 rounded-full", dot)} />
      <div className="min-w-0 flex-1">
        <p className="text-sm text-foreground">
          <span className="font-bold tabular-nums">{num(alert.count)}</span> {alert.label.charAt(0).toLowerCase() + alert.label.slice(1)}
        </p>
        <p className="text-[11px] text-muted-foreground mt-0.5">
          {alert.detail}
          {units.length > 0 && (
            <>
              {" · "}
              {units.map((u) => `${u.code} ${num(u.count)}`).join(" · ")}
              {alert.units.length > 3 && ` · +${alert.units.length - 3} unit`}
            </>
          )}
        </p>
      </div>
      {alert.href && (
        <span className="shrink-0 self-center text-xs font-semibold text-primary inline-flex items-center gap-0.5">
          Cek <ChevronRight className="size-3.5" />
        </span>
      )}
    </>
  );

  return (
    <li>
      {alert.href ? (
        <Link href={alert.href} className="flex items-start gap-3 py-2.5 -mx-2 px-2 rounded-lg hover:bg-accent/40 transition-colors">
          {body}
        </Link>
      ) : (
        <div className="flex items-start gap-3 py-2.5">{body}</div>
      )}
    </li>
  );
}

function PeriodToggle({
  value,
  disabled,
  onChange,
}: {
  value: "year" | "all";
  disabled: boolean;
  onChange: (next: "year" | "all") => void;
}) {
  const options = [
    { key: "year" as const, label: "TA berjalan" },
    { key: "all" as const, label: "Semua periode" },
  ];

  return (
    <div className="inline-flex items-center gap-0.5 p-0.5 rounded-lg bg-muted/60 border border-border/60">
      {options.map((opt) => (
        <button
          key={opt.key}
          type="button"
          disabled={disabled}
          onClick={() => onChange(opt.key)}
          className={cn(
            "rounded-md px-2.5 py-1 text-[11px] font-semibold transition-colors disabled:opacity-50",
            value === opt.key ? "bg-card text-foreground shadow-2xs" : "text-muted-foreground hover:text-foreground",
          )}
        >
          {opt.label}
        </button>
      ))}
    </div>
  );
}

/**
 * The detail that used to sit on the page itself as one big card per unit:
 * akademik, kehadiran, keuangan, ketertiban - opened from a row of the
 * per-unit table instead.
 */
function UnitPanel({
  unit,
  thresholds,
  billingLabel,
  onClose,
}: {
  unit: UnitSummary;
  thresholds: SummaryResponse["thresholds"];
  billingLabel: string;
  onClose: () => void;
}) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  const today = unit.attendance_today;
  const todayTotal = today.hadir + today.sakit + today.izin + today.alpa;

  return (
    <div className="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-label={`Rincian ${unit.unit_label}`}>
      <button type="button" aria-label="Tutup" className="absolute inset-0 bg-black/30" onClick={onClose} />
      <div className="relative h-full w-full max-w-md overflow-y-auto bg-background border-l border-border shadow-xl p-5 space-y-5">
        <div className="flex items-start justify-between gap-3">
          <div>
            <Badge variant="default" className="font-mono text-[10px] uppercase">{unit.jenjang}</Badge>
            <h2 className="mt-1 text-lg font-bold text-foreground">{unit.unit_label}</h2>
          </div>
          <button type="button" onClick={onClose} className="rounded-md p-1 text-muted-foreground hover:bg-accent" aria-label="Tutup">
            <X className="size-5" />
          </button>
        </div>

        <PanelSection title="Siswa & kelas">
          <PanelRow label="Siswa aktif" value={`${num(unit.students_active)} (${num(unit.students_new)} baru)`} />
          <PanelRow label="Terdaftar di kelas" value={num(unit.enrolled_students)} />
          <PanelRow label="Rombel · Guru" value={`${num(unit.classrooms)} · ${num(unit.teachers)}`} />
        </PanelSection>

        <PanelSection title="Kehadiran hari ini">
          <PanelRow label="Hadir" value={unit.attendance_today_rate !== null ? `${unit.attendance_today_rate}%` : "—"} />
          <PanelRow
            label="Sakit · Izin · Alpa"
            value={todayTotal > 0 ? `${num(today.sakit)} · ${num(today.izin)} · ${num(today.alpa)}` : "Belum ada catatan"}
          />
          <PanelRow label="Rata-rata semester" value={unit.attendance_rate !== null ? `${unit.attendance_rate}%` : "—"} />
        </PanelSection>

        <PanelSection title={`Keuangan (${billingLabel})`}>
          <PanelRow label="Tagihan terbit" value={rupiah(unit.billed)} />
          <PanelRow label="Kas masuk" value={`${rupiah(unit.paid)} (${unit.collection_rate}%)`} />
          <PanelRow label="Piutang" value={rupiah(unit.outstanding)} tone={unit.outstanding > 0 ? "bad" : undefined} />
          <PanelRow label="Lewat jatuh tempo" value={`${num(unit.overdue_bills)} tagihan`} tone={unit.overdue_bills > 0 ? "bad" : undefined} />
        </PanelSection>

        <PanelSection title="Akademik">
          <PanelRow label="Rata-rata nilai akhir" value={unit.grades_graded > 0 && unit.grades_average !== null ? String(unit.grades_average) : "—"} />
          <PanelRow label={`Di bawah KKM (${thresholds.kkm})`} value={num(unit.grades_below_kkm)} tone={unit.grades_below_kkm > 0 ? "bad" : undefined} />
          <PanelRow label={`Nilai turun ≥ ${thresholds.grade_drop} poin`} value={num(unit.students_declined)} />
          <PanelRow label={`Alpa ≥ ${thresholds.min_alpa} kali`} value={num(unit.students_high_absenteeism)} />
        </PanelSection>

        <PanelSection title="Prestasi & ketertiban">
          <PanelRow label="Prestasi terverifikasi" value={`${num(unit.achievements)} (${num(unit.achievements_pending)} menunggu)`} />
          <PanelRow label="Siswa dengan pelanggaran" value={num(unit.violation_students)} />
          <PanelRow label="Ekstrakurikuler" value={`${num(unit.extracurriculars)} kegiatan · ${num(unit.extracurricular_members)} anggota`} />
        </PanelSection>

        <Link href={`/admin/perhatian?unit=${unit.unit_code}`} className="block">
          <Button variant="outline" className="w-full gap-2 text-xs font-bold">
            <AlertTriangle className="size-4" /> Lihat {num(unit.students_needing_attention)} siswa perlu perhatian
          </Button>
        </Link>
      </div>
    </div>
  );
}

function PanelSection({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section>
      <h3 className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground mb-1.5">{title}</h3>
      <dl className="divide-y divide-border/50 rounded-lg border border-border/60">{children}</dl>
    </section>
  );
}

function PanelRow({ label, value, tone }: { label: string; value: string; tone?: "bad" }) {
  return (
    <div className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className={cn("font-semibold tabular-nums text-right", tone === "bad" ? "text-bad" : "text-foreground")}>{value}</dd>
    </div>
  );
}

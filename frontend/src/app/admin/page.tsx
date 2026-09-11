"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import {
  AlertTriangle,
  ArrowRight,
  Award,
  BarChart3,
  Building2,
  ChevronRight,
  GraduationCap,
  Receipt,
  School,
  Sparkles,
  TrendingDown,
  TrendingUp,
  Trophy,
  UserCheck,
  Users,
  Wallet,
} from "lucide-react";
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
  };
  // What the watchlist conditions were measured against - quoted by the
  // tiles instead of hardcoded numbers.
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

const num = (n: number) => new Intl.NumberFormat("id-ID").format(n);

export default function AdminHomePage() {
  const { user } = useAuth();
  const [data, setData] = useState<SummaryResponse | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api
      .get<SummaryResponse>("/api/admin/dashboard/summary")
      .then(setData)
      .catch(() => toast.error("Gagal memuat ringkasan dashboard."))
      .finally(() => setLoading(false));
  }, []);

  const isCentral = data?.scope.is_central ?? true;
  const kpi = data?.kpi;

  const maxBilled = useMemo(
    () => (data?.units.length ? Math.max(...data.units.map((u) => u.billed), 1) : 1),
    [data],
  );

  return (
    <div className="space-y-8">
      {/* =================================================================== */}
      {/* Banner */}
      {/* =================================================================== */}
      <div className="rounded-3xl bg-linear-to-br from-[#13286B] to-[#2856E0] p-6 sm:p-8 text-white shadow-xs">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <Badge className="mb-2 bg-white/15 text-white border border-white/20">
              {isCentral ? "Portal Eksekutif & Administrasi Yayasan YAPI" : `Dashboard Unit ${data?.scope.unit_label ?? ""}`}
            </Badge>
            <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-white">
              Assalamu&apos;alaikum, {user?.name}
            </h1>
            <p className="text-sm text-white/80 mt-1">
              {isCentral
                ? `Ringkasan data ${data?.scope.unit_count ?? "-"} unit sekolah - siswa, keuangan, akademik, dan hal yang perlu perhatian penuh.`
                : "Ringkasan siswa, keuangan, dan akademik untuk unit Anda, lengkap dengan hal-hal yang perlu perhatian."}
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2.5">
            {data?.period.term_label && (
              <Badge className="bg-white/15 text-white border border-white/20 text-xs font-bold">
                Semester aktif: {data.period.term_label}
              </Badge>
            )}
            <Link href="/admin/siswa">
              <Button variant="ghost" className="gap-2 font-bold text-xs bg-white/15 text-white border border-white/20 hover:bg-white/25">
                <GraduationCap className="size-4" />
                <span>Data Siswa & SPP</span>
              </Button>
            </Link>
            <Link href="/admin/generate">
              <Button className="gap-2 font-bold text-xs bg-white text-[#13286B] hover:bg-white/90 shadow-md">
                <Wallet className="size-4" />
                <span>Terbitkan SPP Massal</span>
              </Button>
            </Link>
          </div>
        </div>
      </div>

      {/* =================================================================== */}
      {/* KPI Cards */}
      {/* =================================================================== */}
      {loading || !kpi ? (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} className="h-28 rounded-xl" />
          ))}
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          <KpiCard
            label="Siswa Aktif"
            value={`${num(kpi.students_active)} Siswa`}
            sub={`${num(kpi.students_new)} baru tahun ini · ${num(kpi.students_total)} total`}
            icon={<GraduationCap className="size-5 text-primary" />}
          />
          <KpiCard
            label="Rombel Aktif"
            value={`${num(kpi.classrooms)} Rombel`}
            sub={`${num(kpi.teachers)} guru aktif · ${num(kpi.enrolled_students)} terdaftar di kelas`}
            icon={<School className="size-5 text-primary" />}
          />
          <KpiCard
            label="Penerimaan Kas"
            value={rupiah(kpi.billing.total_paid)}
            sub={`Tingkat pelunasan ${kpi.billing.collection_rate}%`}
            icon={<TrendingUp className="size-5 text-good" />}
            tone="good"
          />
          <KpiCard
            label="Sisa Piutang"
            value={rupiah(kpi.billing.total_outstanding)}
            sub={`${num(kpi.billing.bill_count)} tagihan · ${num(kpi.billing.overdue_bills)} lewat jatuh tempo`}
            icon={<TrendingDown className="size-5 text-destructive" />}
            tone="bad"
          />
          <KpiCard
            label="Tagihan Jatuh Tempo"
            value={`${num(kpi.billing.overdue_bills)} Tagihan`}
            sub={kpi.billing.overdue_amount > 0 ? `Senilai ${rupiah(kpi.billing.overdue_amount)} perlu ditindaklanjuti` : "Tidak ada tunggakan jatuh tempo"}
            icon={<Receipt className="size-5 text-warn" />}
            tone="warn"
          />
          <KpiCard
            label="Kehadiran Siswa"
            value={kpi.attendance.rate !== null ? `${kpi.attendance.rate}%` : "—"}
            sub={`H ${num(kpi.attendance.hadir)} · S ${num(kpi.attendance.sakit)} · I ${num(kpi.attendance.izin)} · A ${num(kpi.attendance.alpa)}`}
            icon={<UserCheck className="size-5 text-good" />}
            tone="good"
          />
          <KpiCard
            label="Prestasi Terverifikasi"
            value={`${num(kpi.achievements.verified)} Prestasi`}
            sub={`${num(kpi.achievements.siswa)} siswa · ${num(kpi.achievements.guru)} guru · ${num(kpi.achievements.pending)} menunggu`}
            icon={<Trophy className="size-5 text-amber-500" />}
          />
          <KpiCard
            label="Ekstrakurikuler"
            value={`${num(kpi.extracurriculars.activities)} Kegiatan`}
            sub={`${num(kpi.extracurriculars.members)} anggota aktif mengikuti`}
            icon={<Sparkles className="size-5 text-primary" />}
          />
        </div>
      )}

      {/* =================================================================== */}
      {/* Ringkasan Akademik - khusus admin_unit (terpisah dari keuangan SPP) */}
      {/* =================================================================== */}
      {!isCentral && data && kpi && data.units[0] && (
        <RingkasanAkademikCard unit={data.units[0]} kpi={kpi} thresholds={data.thresholds} />
      )}

      {/* =================================================================== */}
      {/* Alert / Watchlist - Perlu Perhatian */}
      {/* =================================================================== */}
      <Card className="p-6 border-border/80 shadow-md">
        <div className="flex items-center justify-between gap-4 border-b border-border/70 pb-4">
          <div>
            <div className="flex items-center gap-2">
              <AlertTriangle className="size-5 text-warn" />
              <h2 className="text-lg font-bold text-foreground">Perlu Perhatian</h2>
            </div>
            <p className="text-xs text-muted-foreground mt-0.5">
              Kondisi yang membutuhkan pemantauan atau tindak lanjut staf.
            </p>
          </div>
          {data && (
            <Badge variant={data.alerts.some((a) => a.severity === "bad" && a.count > 0) ? "bad" : "primary"}>
              {data.alerts.reduce((sum, a) => sum + a.count, 0)} item
            </Badge>
          )}
        </div>

        {loading ? (
          <Skeleton className="h-48 w-full rounded-2xl mt-5" />
        ) : (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-3 mt-5">
            {data?.alerts.map((alert) => {
              const sevColor =
                alert.severity === "bad"
                  ? "text-bad bg-bad-soft border-bad/20"
                  : alert.severity === "warn"
                    ? "text-warn bg-warn-soft border-warn/20"
                    : "text-good bg-good-soft border-good/20";

              const row = (
                <>
                  <span className={cn("mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg border", sevColor)}>
                    <AlertTriangle className="size-4" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="font-bold text-sm text-foreground leading-snug">{alert.label}</p>
                    <p className="text-[11px] text-muted-foreground mt-0.5 leading-snug">{alert.detail}</p>
                    {isCentral && alert.units.length > 0 && (
                      <div className="flex flex-wrap gap-1 mt-2">
                        {alert.units.slice(0, 4).map((u) => (
                          <Badge key={u.code} variant="default" className="text-[10px] font-bold">
                            {u.code} · {num(u.count)}
                          </Badge>
                        ))}
                        {alert.units.length > 4 && (
                          <Badge variant="default" className="text-[10px]">
                            +{alert.units.length - 4} unit lagi
                          </Badge>
                        )}
                      </div>
                    )}
                  </div>
                  <div className="flex flex-col items-end gap-1 shrink-0">
                    <span className="flex size-9 items-center justify-center rounded-xl bg-canvas text-base font-extrabold text-foreground">
                      {num(alert.count)}
                    </span>
                    {alert.count > 0 && alert.href && (
                      <span className="text-[10px] font-semibold text-primary inline-flex items-center gap-0.5">
                        Cek <ChevronRight className="size-3" />
                      </span>
                    )}
                  </div>
                </>
              );

              return alert.href && alert.count > 0 ? (
                <Link key={alert.id} href={alert.href} className="flex items-start gap-3 p-4 rounded-xl bg-card border border-border/60 hover:border-primary/50 hover:bg-accent/40 transition-all">
                  {row}
                </Link>
              ) : (
                <div key={alert.id} className="flex items-start gap-3 p-4 rounded-xl bg-card border border-border/60">
                  {row}
                </div>
              );
            })}
          </div>
        )}
      </Card>

      {/* =================================================================== */}
      {/* Ringkasan Akademik per Unit Sekolah (central admin) */}
      {/* =================================================================== */}
      {isCentral && data && data.units.length > 0 && (
        <Card className="p-6 border-border/80 shadow-md space-y-5">
          <div className="flex items-center gap-2 border-b border-border/70 pb-4">
            <GraduationCap className="size-5 text-primary" />
            <div>
              <h2 className="text-lg font-bold text-foreground">Ringkasan Akademik per Unit Sekolah</h2>
              <p className="text-xs text-muted-foreground mt-0.5">
                Kehadiran, nilai akhir, capaian prestasi, dan poin tata tertib tiap unit - klik kartu unit untuk melihat detailnya.
              </p>
            </div>
          </div>

          {loading ? (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {Array.from({ length: 2 }).map((_, i) => (
                <Skeleton key={i} className="h-80 rounded-2xl" />
              ))}
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {data.units.map((unit) => (
                <UnitAkademikCard key={unit.unit_id} unit={unit} thresholds={data.thresholds} />
              ))}
            </div>
          )}
        </Card>
      )}

      {/* =================================================================== */}
      {/* Rekap per Unit (central admin) */}
      {/* =================================================================== */}
      {isCentral && data && data.units.length > 1 && (
        <Card className="p-6 border-border/80 shadow-md">
          <div className="flex items-center gap-2 border-b border-border/70 pb-4">
            <Building2 className="size-5 text-primary" />
            <div>
              <h2 className="text-lg font-bold text-foreground">Rekap per Unit Sekolah</h2>
              <p className="text-xs text-muted-foreground mt-0.5">Indikator kunci setiap unit dalam satu pandangan - untuk mengenali unit mana yang perlu pendampingan lebih.</p>
            </div>
          </div>
          <div className="mt-4 overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] font-bold uppercase tracking-wider text-muted-foreground border-b border-border/70">
                  <th className="py-2.5 pr-3">Unit</th>
                  <th className="py-2.5 px-3 text-right">Siswa</th>
                  <th className="py-2.5 px-3 text-right">Rombel</th>
                  <th className="py-2.5 px-3 text-right">Guru</th>
                  <th className="py-2.5 px-3 text-right">Kehadiran</th>
                  <th className="py-2.5 px-3 text-right">Tagihan Terbit</th>
                  <th className="py-2.5 px-3 text-right">Piutang</th>
                  <th className="py-2.5 px-3 text-right">Prestasi</th>
                </tr>
              </thead>
              <tbody>
                {data.units.map((unit) => (
                  <tr key={unit.unit_id} className="border-b border-border/50 hover:bg-accent/30 transition-colors">
                    <td className="py-2.5 pr-3">
                      <div className="flex items-center gap-2">
                        <Badge variant="default" className="text-[10px] font-mono uppercase">{unit.jenjang}</Badge>
                        <span className="font-bold text-foreground">{unit.unit_label}</span>
                      </div>
                    </td>
                    <td className="py-2.5 px-3 text-right font-semibold">{num(unit.students_active)}</td>
                    <td className="py-2.5 px-3 text-right text-muted-foreground">{unit.classrooms}</td>
                    <td className="py-2.5 px-3 text-right text-muted-foreground">{unit.teachers}</td>
                    <td className="py-2.5 px-3 text-right">
                      <Badge variant={unit.attendance_rate !== null && unit.attendance_rate >= 90 ? "good" : unit.attendance_rate !== null ? "warn" : "default"}>
                        {unit.attendance_rate !== null ? `${unit.attendance_rate}%` : "—"}
                      </Badge>
                    </td>
                    <td className="py-2.5 px-3 text-right font-semibold text-foreground">{rupiah(unit.billed)}</td>
                    <td className="py-2.5 px-3 text-right">
                      <Badge variant={unit.outstanding > 0 ? "warn" : "good"}>
                        {unit.outstanding > 0 ? rupiah(unit.outstanding) : "Lunas"}
                      </Badge>
                    </td>
                    <td className="py-2.5 px-3 text-right font-semibold">{unit.achievements}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {/* =================================================================== */}
      {/* Grafik Arus Tagihan & Piutang per Unit */}
      {/* =================================================================== */}
      <Card className="p-6 border-border/80 shadow-md">
        <div className="border-b border-border/70 pb-4">
          <div className="flex items-center gap-2">
            <BarChart3 className="size-5 text-primary" />
            <h2 className="text-lg font-bold text-foreground">
              {isCentral ? "Grafik Arus Tagihan & Piutang per Unit Sekolah" : "Arus Tagihan & Piutang Unit Anda"}
            </h2>
          </div>
          <p className="text-xs text-muted-foreground mt-0.5">
            Perbandingan tagihan terbit, kas masuk, dan sisa piutang pada {data?.period.term_label ?? "tahun ajaran berjalan"}.
          </p>
        </div>

        {loading ? (
          <Skeleton className="h-64 w-full rounded-2xl mt-5" />
        ) : (
          <div className="grid grid-cols-1 gap-4 mt-5">
            {data?.units.map((unit) => {
              const paidPct = unit.billed > 0 ? (unit.paid / maxBilled) * 100 : 0;
              const outstandingPct = unit.billed > 0 ? (unit.outstanding / maxBilled) * 100 : 0;

              return (
                <div key={unit.unit_id} className="p-4 rounded-xl bg-card border border-border/60 hover:border-primary/50 transition-all shadow-2xs">
                  <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-2">
                    <div className="flex items-center gap-2">
                      <Badge variant="default" className="font-mono text-[10px] uppercase">{unit.jenjang}</Badge>
                      <span className="font-bold text-sm text-foreground">{unit.unit_label}</span>
                    </div>
                    <div className="flex items-center gap-4 text-xs font-semibold">
                      <span className="text-good">Lunas: {rupiah(unit.paid)}</span>
                      <span className="text-destructive">Piutang: {rupiah(unit.outstanding)}</span>
                      <Badge variant={unit.collection_rate >= 80 ? "good" : unit.collection_rate >= 50 ? "warn" : "bad"}>
                        {unit.collection_rate}% Terbayar
                      </Badge>
                    </div>
                  </div>

                  <div className="space-y-1.5">
                    <div className="h-3 w-full bg-muted/60 rounded-full overflow-hidden flex">
                      <div
                        style={{ width: `${paidPct}%` }}
                        className="h-full bg-good transition-all duration-500 rounded-l-full"
                        title={`Kas Masuk: ${rupiah(unit.paid)}`}
                      />
                      <div
                        style={{ width: `${outstandingPct}%` }}
                        className="h-full bg-destructive/70 transition-all duration-500 rounded-r-full"
                        title={`Sisa Piutang: ${rupiah(unit.outstanding)}`}
                      />
                    </div>
                    <div className="flex justify-between text-[11px] text-muted-foreground">
                      <span>Total Tagihan: <strong>{rupiah(unit.billed)}</strong></span>
                      <span>{unit.overdue_bills > 0 ? <span className="text-bad font-semibold">⚠️ {unit.overdue_bills} tagihan lewat jatuh tempo</span> : "Jatuh tempo aman"}</span>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </Card>

      {/* =================================================================== */}
      {/* Quick shortcuts */}
      {/* =================================================================== */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <ShortcutCard href="/admin/tagihan" icon={<Wallet className="size-5 text-primary" />} title="Tagihan & Transaksi" desc="Pantau tagihan SPP, pembayaran, dan piutang." />
        <ShortcutCard href="/admin/laporan" icon={<TrendingDown className="size-5 text-good" />} title="Laporan Keuangan" desc="Arus kas, penerimaan per metode, dan piutang per kelas." />
        <ShortcutCard href="/admin/nilai" icon={<Award className="size-5 text-primary" />} title="Nilai & Rapor" desc="Kelola nilai tugas, UTS, UAS, dan rapor siswa." />
        <ShortcutCard href="/admin/kelas" icon={<School className="size-5 text-primary" />} title="Data Kelas" desc="Rombel, wali kelas, dan penempatan siswa." />
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------

function KpiCard({
  label,
  value,
  sub,
  icon,
  tone,
}: {
  label: string;
  value: string;
  sub: string;
  icon: React.ReactNode;
  tone?: "good" | "bad" | "warn";
}) {
  const toneClass =
    tone === "good" ? "text-good" : tone === "bad" ? "text-destructive" : tone === "warn" ? "text-warn" : "text-foreground";

  return (
    <Card className="p-5 border-border/80 shadow-xs">
      <div className="flex items-center justify-between">
        <span className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">{label}</span>
        {icon}
      </div>
      <div className={cn("mt-2 text-xl sm:text-2xl font-black leading-tight", toneClass)}>{value}</div>
      <p className="mt-1 text-[11px] leading-snug text-muted-foreground">{sub}</p>
    </Card>
  );
}

function ShortcutCard({ href, icon, title, desc }: { href: string; icon: React.ReactNode; title: string; desc: string }) {
  return (
    <Link href={href} className="group">
      <Card className="p-5 border-border/80 shadow-xs hover:border-primary/50 hover:shadow-md transition-all h-full">
        <div className="flex items-start justify-between gap-3">
          <span className="flex size-10 items-center justify-center rounded-xl bg-accent/60 text-primary">{icon}</span>
          <ArrowRight className="size-4 text-muted-foreground group-hover:text-primary group-hover:translate-x-0.5 transition-all" />
        </div>
        <h3 className="mt-3 font-bold text-sm text-foreground">{title}</h3>
        <p className="mt-1 text-xs text-muted-foreground leading-snug">{desc}</p>
      </Card>
    </Link>
  );
}

// ---------------------------------------------------------------------------

/**
 * A compact academic summary for ONE school unit, used inside the central
 * admin's "Ringkasan Akademik per Unit Sekolah" card. The whole card opens
 * that unit's watchlist drill-down - the named students these numbers are
 * made of.
 */
function UnitAkademikCard({ unit, thresholds }: { unit: UnitSummary; thresholds: SummaryResponse["thresholds"] }) {
  const today = unit.attendance_today;
  const todayRate = unit.attendance_today_rate;
  const todayTotal = today.hadir + today.sakit + today.izin + today.alpa;
  const pointRecords = unit.points_merit_records + unit.points_violation_records;

  return (
    <Link href={`/admin/perhatian?unit=${unit.unit_code}`} className="group">
      <Card className="p-5 border-border/80 shadow-xs hover:border-primary/50 hover:shadow-md transition-all h-full">
        <div className="flex items-center justify-between gap-2 border-b border-border/60 pb-3">
          <div className="flex flex-wrap items-center gap-2 min-w-0">
            <Badge variant="default" className="font-mono text-[10px] uppercase">{unit.jenjang}</Badge>
            <span className="font-bold text-sm text-foreground truncate">{unit.unit_label}</span>
          </div>
          <ChevronRight className="size-4 text-muted-foreground group-hover:text-primary group-hover:translate-x-0.5 transition-all shrink-0" />
        </div>

        <div className="grid grid-cols-3 gap-2 mt-3">
          <MiniStat label="Siswa" value={num(unit.students_active)} />
          <MiniStat label="Guru" value={num(unit.teachers)} />
          <MiniStat label="Kelas" value={num(unit.classrooms)} />
        </div>

        <div className="grid grid-cols-3 gap-2 mt-2">
          <MiniStat
            label="Perlu Perhatian"
            value={num(unit.students_needing_attention)}
            tone={unit.students_needing_attention > 0 ? "bad" : "neutral"}
          />
          <MiniStat
            label="Absensi Tinggi"
            value={num(unit.students_high_absenteeism)}
            tone={unit.students_high_absenteeism > 0 ? "warn" : "neutral"}
          />
          <MiniStat
            label="Penurunan Nilai"
            value={num(unit.students_declined)}
            tone={unit.students_declined > 0 ? "warn" : "neutral"}
          />
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
          <div className="p-3.5 rounded-xl bg-card border border-border/60">
            <div className="flex items-center justify-between">
              <p className="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">Kehadiran Hari Ini</p>
              <UserCheck className="size-4 text-good" />
            </div>
            <p className="mt-1 text-xl font-black text-foreground leading-tight">{todayRate !== null ? `${todayRate}%` : "—"}</p>
            <div className="h-1.5 w-full bg-muted/60 rounded-full overflow-hidden mt-1.5">
              <div style={{ width: `${todayRate ?? 0}%` }} className="h-full bg-good transition-all duration-500" />
            </div>
            <p className="mt-1.5 text-[10px] font-semibold text-muted-foreground">
              <span className="text-good">H {num(today.hadir)}</span> · <span className="text-warn">S {num(today.sakit)}</span> ·{" "}
              <span className="text-primary">I {num(today.izin)}</span> · <span className="text-bad">A {num(today.alpa)}</span>
            </p>
            <p className="mt-1 text-[10px] text-muted-foreground">
              {todayTotal > 0 ? `${num(todayTotal)} catatan per-sesi` : "Belum ada kehadiran hari ini"}
            </p>
          </div>

          <div className="p-3.5 rounded-xl bg-card border border-border/60">
            <div className="flex items-center justify-between">
              <p className="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">Nilai Akhir</p>
              <Award className="size-4 text-primary" />
            </div>
            <p className="mt-1 text-xl font-black text-foreground leading-tight">
              {unit.grades_graded > 0 && unit.grades_average !== null ? unit.grades_average : "—"}
            </p>
            <p className="mt-1.5 text-[10px] font-semibold text-muted-foreground">
              {unit.grades_graded > 0
                ? `${num(unit.grades_graded)} dinilai · ${num(unit.grades_below_kkm)} di bawah KKM (${thresholds.kkm})`
                : "Belum ada nilai akhir"}
            </p>
          </div>

          <div className="p-3.5 rounded-xl bg-card border border-border/60">
            <div className="flex items-center justify-between">
              <p className="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">Capaian Prestasi</p>
              <Trophy className="size-4 text-amber-500" />
            </div>
            <p className="mt-1 text-xl font-black text-foreground leading-tight">
              {num(unit.achievements)} <span className="text-xs font-semibold text-muted-foreground">terverifikasi</span>
            </p>
            <p className="mt-1.5 text-[10px] font-semibold text-muted-foreground">
              {unit.achievements_pending > 0 ? `${num(unit.achievements_pending)} menunggu verifikasi` : "Tidak ada pengajuan tertunda"}
            </p>
          </div>

          <div className="p-3.5 rounded-xl bg-card border border-border/60">
            <div className="flex items-center justify-between">
              <p className="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">Poin Tata Tertib</p>
              <Sparkles className="size-4 text-primary" />
            </div>
            <p className="mt-1 text-xl font-black text-foreground leading-tight">
              {num(pointRecords)} <span className="text-xs font-semibold text-muted-foreground">catatan</span>
            </p>
            <p className="mt-1.5 text-[10px] font-semibold text-muted-foreground">
              <span className="text-good">+{num(unit.points_merit_records)} prestasi</span> ·{" "}
              <span className="text-bad">-{num(unit.points_violation_records)} pelanggaran</span>
            </p>
          </div>
        </div>
      </Card>
    </Link>
  );
}

function MiniStat({
  label,
  value,
  tone = "neutral",
}: {
  label: string;
  value: string | number;
  tone?: "good" | "warn" | "bad" | "neutral";
}) {
  const toneClass =
    tone === "good" ? "text-good" : tone === "warn" ? "text-warn" : tone === "bad" ? "text-bad" : "text-foreground";

  return (
    <div className="p-3 rounded-xl bg-card border border-border/60 min-w-0">
      <p className="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider truncate">{label}</p>
      <p className={cn("mt-0.5 text-lg font-black leading-tight", toneClass)}>{value}</p>
    </div>
  );
}

/**
 * The dedicated academic summary for a unit-scoped admin: siswa/guru/kelas,
 * kehadiran hari ini, nilai akhir semester, capaian prestasi, dan poin tata
 * tertib - strictly academic, kept apart from the SPP/keuangan overview.
 */
function RingkasanAkademikCard({
  unit,
  kpi,
  thresholds,
}: {
  unit: UnitSummary;
  kpi: NonNullable<SummaryResponse["kpi"]>;
  thresholds: SummaryResponse["thresholds"];
}) {
  const today = kpi.attendance_today;
  const todayRate = today.rate;
  const todayTotal = today.hadir + today.sakit + today.izin + today.alpa;

  return (
    <Card className="p-6 border-border/80 shadow-md space-y-5">
      <div className="flex items-center gap-2 border-b border-border/70 pb-4">
        <GraduationCap className="size-5 text-primary" />
        <div className="flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-lg font-bold text-foreground">Ringkasan Akademik</h2>
            <Badge variant="default" className="font-mono text-[10px] uppercase">{unit.jenjang}</Badge>
            <Badge variant="primary" className="text-[10px]">{unit.unit_label}</Badge>
          </div>
          <p className="text-xs text-muted-foreground mt-0.5">
            Siswa, guru, kelas, kehadiran hari ini, nilai akhir semester, dan capaian prestasi - terpisah dari ringkasan keuangan SPP.
          </p>
        </div>
      </div>

      {/* Siswa / Guru / Kelas */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div className="p-4 rounded-2xl bg-card border border-border/60 shadow-2xs flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
            <GraduationCap className="size-5" />
          </span>
          <div>
            <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Siswa</p>
            <p className="text-xl font-black text-foreground leading-tight">
              {num(unit.students_active)} <span className="text-xs font-semibold text-muted-foreground">aktif</span>
            </p>
            <p className="text-[11px] text-muted-foreground">{num(unit.students_new)} baru tahun ini · {num(unit.enrolled_students)} di kelas</p>
          </div>
        </div>
        <div className="p-4 rounded-2xl bg-card border border-border/60 shadow-2xs flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent/60 text-primary">
            <Users className="size-5" />
          </span>
          <div>
            <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Guru</p>
            <p className="text-xl font-black text-foreground leading-tight">
              {num(unit.teachers)} <span className="text-xs font-semibold text-muted-foreground">aktif</span>
            </p>
            <p className="text-[11px] text-muted-foreground">Tenaga pengajar terdaftar di unit ini</p>
          </div>
        </div>
        <div className="p-4 rounded-2xl bg-card border border-border/60 shadow-2xs flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600">
            <School className="size-5" />
          </span>
          <div>
            <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Kelas</p>
            <p className="text-xl font-black text-foreground leading-tight">
              {num(unit.classrooms)} <span className="text-xs font-semibold text-muted-foreground">rombel aktif</span>
            </p>
            <p className="text-[11px] text-muted-foreground">Tahun ajaran berjalan</p>
          </div>
        </div>
      </div>

      {/* Watchlist akademik - siswa perlu perhatian. Setiap tile membuka
          daftar nama di /admin/perhatian, bukan halaman umum. */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <WatchStatTile
          href="/admin/perhatian"
          icon={<AlertTriangle className="size-5" />}
          tone="bad"
          label="Siswa Perlu Perhatian"
          count={unit.students_needing_attention}
          sub={unit.students_needing_attention > 0 ? "Terindikasi dalam kondisi akademik yang perlu dimonitor" : "Tidak ada siswa yang perlu dimonitor"}
        />
        <WatchStatTile
          href="/admin/perhatian?reason=absenteeism"
          icon={<UserCheck className="size-5" />}
          tone="warn"
          label="Absensi Tinggi"
          count={unit.students_high_absenteeism}
          sub={`Alpa ${thresholds.min_alpa} kali atau lebih tahun ini`}
        />
        <WatchStatTile
          href="/admin/perhatian?reason=grade_decline"
          icon={<TrendingDown className="size-5" />}
          tone="warn"
          label="Penurunan Nilai"
          count={unit.students_declined}
          sub={`Rata-rata turun ${thresholds.grade_drop} poin antar semester`}
        />
      </div>

      {/* Kehadiran / Nilai / Prestasi / Ketertiban */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {/* Kehadiran hari ini */}
        <div className="p-5 rounded-2xl bg-card border border-border/60 shadow-2xs">
          <div className="flex items-center justify-between mb-3">
            <div>
              <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Kehadiran Hari Ini</p>
              <p className="text-2xl font-black text-foreground">
                {todayRate !== null ? `${todayRate}%` : "—"}
                <span className="text-sm font-semibold text-muted-foreground"> hadir</span>
              </p>
            </div>
            <UserCheck className="size-6 text-good" />
          </div>
          <div className="h-2.5 w-full bg-muted/60 rounded-full overflow-hidden flex">
            <div style={{ width: `${todayRate ?? 0}%` }} className="h-full bg-good transition-all duration-500" />
          </div>
          <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[11px] font-semibold text-muted-foreground">
            <span className="text-good">Hadir {num(today.hadir)}</span>
            <span className="text-warn">Sakit {num(today.sakit)}</span>
            <span className="text-primary">Izin {num(today.izin)}</span>
            <span className="text-bad">Alpa {num(today.alpa)}</span>
          </div>
          <p className="mt-2 text-[11px] text-muted-foreground">
            {todayTotal > 0 ? `${num(todayTotal)} catatan kehadiran per-sesi tercatat hari ini.` : "Belum ada kehadiran tercatat hari ini."}
          </p>
        </div>

        {/* Nilai akhir semester */}
        <div className="p-5 rounded-2xl bg-card border border-border/60 shadow-2xs">
          <div className="flex items-center justify-between mb-3">
            <div>
              <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Nilai Akhir Semester</p>
              <p className="text-2xl font-black text-foreground">
                {unit.grades_graded > 0 && unit.grades_average !== null ? unit.grades_average : "—"}
                {unit.grades_graded > 0 && unit.grades_average !== null && (
                  <span className="text-sm font-semibold text-muted-foreground"> rata-rata</span>
                )}
              </p>
            </div>
            <Award className="size-6 text-primary" />
          </div>
          <p className="text-xs text-muted-foreground">
            {unit.grades_graded > 0
              ? `${num(unit.grades_graded)} siswa dinilai · ${num(unit.grades_below_kkm)} di bawah KKM (${70}) · ${num(unit.students_declined)} menurun`
              : "Belum ada nilai akhir lengkap pada semester ini."}
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            {unit.grades_below_kkm > 0 && <Badge variant="bad">{num(unit.grades_below_kkm)} di bawah KKM</Badge>}
            {unit.students_declined > 0 && <Badge variant="warn">{num(unit.students_declined)} menurun</Badge>}
            {unit.grades_below_kkm === 0 && unit.grades_graded > 0 && <Badge variant="good">Semua di atas KKM</Badge>}
          </div>
        </div>

        {/* Capaian prestasi (disatukan) */}
        <div className="p-5 rounded-2xl bg-card border border-border/60 shadow-2xs">
          <div className="flex items-center justify-between mb-3">
            <div>
              <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Capaian Prestasi</p>
              <p className="text-2xl font-black text-foreground">
                {num(unit.achievements)} <span className="text-sm font-semibold text-muted-foreground">terverifikasi</span>
              </p>
            </div>
            <Trophy className="size-6 text-amber-500" />
          </div>
          <p className="text-xs text-muted-foreground">
            {num(kpi.achievements.siswa)} dari siswa · {num(kpi.achievements.guru)} dari guru
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            {unit.achievements_pending > 0 ? (
              <Badge variant="warn">{num(unit.achievements_pending)} menunggu verifikasi</Badge>
            ) : (
              <Badge variant="good">Tidak ada pengajuan tertunda</Badge>
            )}
          </div>
        </div>

        {/* Poin tata tertib */}
        <div className="p-5 rounded-2xl bg-card border border-border/60 shadow-2xs">
          <div className="flex items-center justify-between mb-3">
            <div>
              <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Poin Tata Tertib</p>
              <p className="text-2xl font-black text-foreground">
                {num(kpi.points.merit_records + kpi.points.violation_records)}{" "}
                <span className="text-sm font-semibold text-muted-foreground">catatan</span>
              </p>
            </div>
            <Sparkles className="size-6 text-primary" />
          </div>
          <div className="flex items-center gap-4 text-xs font-semibold text-muted-foreground">
            <span className="text-good">+{num(kpi.points.merit_records)} poin prestasi</span>
            <span className="text-bad">-{num(kpi.points.violation_records)} pelanggaran</span>
          </div>
          <div className="mt-3 flex flex-wrap gap-2">
            {kpi.points.violation_students > 0 ? (
              <Badge variant="bad">{num(kpi.points.violation_students)} siswa dengan pelanggaran</Badge>
            ) : (
              <Badge variant="good">Tidak ada pelanggaran tercatat</Badge>
            )}
          </div>
        </div>
      </div>
    </Card>
  );
}

/**
 * One academic watchlist stat on the unit-scoped Ringkasan Akademik card.
 * Links through to the page where the affected students can be acted on.
 */
function WatchStatTile({
  href,
  icon,
  tone,
  label,
  count,
  sub,
}: {
  href: string;
  icon: React.ReactNode;
  tone: "bad" | "warn";
  label: string;
  count: number;
  sub: string;
}) {
  const toneClass = tone === "bad" ? "text-bad bg-bad-soft border-bad/20" : "text-warn bg-warn-soft border-warn/20";

  return (
    <Link href={href} className="group">
      <div className="p-4 rounded-2xl bg-card border border-border/60 shadow-2xs hover:border-primary/50 hover:shadow-md transition-all h-full flex items-center gap-3">
        <span className={cn("flex size-10 shrink-0 items-center justify-center rounded-xl border", toneClass)}>{icon}</span>
        <div className="min-w-0">
          <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">{label}</p>
          <p className="text-xl font-black text-foreground leading-tight">
            {num(count)} <span className="text-xs font-semibold text-muted-foreground">siswa</span>
          </p>
          <p className="text-[11px] text-muted-foreground">{sub}</p>
        </div>
      </div>
    </Link>
  );
}
"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import {
  AlertTriangle,
  Award,
  BadgePercent,
  BarChart3,
  Calendar,
  CheckCircle2,
  ChevronRight,
  FileSpreadsheet,
  Filter,
  GraduationCap,
  Megaphone,
  Percent,
  Receipt,
  RefreshCw,
  SlidersHorizontal,
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
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuth } from "@/lib/auth/auth-context";
import { api, ApiError } from "@/lib/api";
import { rupiah } from "@/lib/format";

type UnitBillingData = {
  unit_id: number;
  unit_code: string;
  unit_label: string;
  jenjang: string;
  total_billed: number;
  total_paid: number;
  total_outstanding: number;
  collection_rate: number;
  bill_count: number;
  paid_count: number;
  unpaid_count: number;
  overdue_count: number;
};

type BillingChartResponse = {
  summary: {
    total_billed: number;
    total_paid: number;
    total_outstanding: number;
    collection_rate: number;
    total_bills: number;
  };
  units: UnitBillingData[];
};

type UnitAchievementData = {
  unit_id: number;
  unit_code: string;
  unit_label: string;
  jenjang: string;
  total_achievements: number;
  siswa_count: number;
  guru_count: number;
  by_tingkat: Record<string, number>;
  recent: Array<{
    nama_prestasi: string;
    tingkat: string;
    juara: string | null;
    achiever: string;
    achiever_type: string;
    tanggal: string | null;
  }>;
};

type AchievementChartResponse = {
  summary: {
    total_achievements: number;
    total_siswa: number;
    total_guru: number;
    by_level: Record<string, number>;
  };
  units: UnitAchievementData[];
};

type AcademicYear = { ulid: string; year: string; is_active: boolean };

export default function AdminHomePage() {
  const { user } = useAuth();
  const [studentCount, setStudentCount] = useState<number | null>(null);
  const [years, setYears] = useState<AcademicYear[]>([]);

  // Billing Chart State
  const [billingStartDate, setBillingStartDate] = useState("");
  const [billingEndDate, setBillingEndDate] = useState("");
  const [billingYear, setBillingYear] = useState("");
  const [billingData, setBillingData] = useState<BillingChartResponse | null>(null);
  const [loadingBilling, setLoadingBilling] = useState(true);

  // Achievement Chart State
  const [achieverType, setAchieverType] = useState<"all" | "siswa" | "guru">("all");
  const [achieveStartDate, setAchieveStartDate] = useState("");
  const [achieveEndDate, setAchieveEndDate] = useState("");
  const [achievementData, setAchievementData] = useState<AchievementChartResponse | null>(null);
  const [loadingAchieve, setLoadingAchieve] = useState(true);

  function loadBillingChart() {
    setLoadingBilling(true);
    const params = new URLSearchParams();
    if (billingStartDate) params.set("start_date", billingStartDate);
    if (billingEndDate) params.set("end_date", billingEndDate);
    if (billingYear) params.set("academic_year", billingYear);

    api
      .get<BillingChartResponse>(`/api/admin/dashboard/billing-chart?${params.toString()}`)
      .then((d) => setBillingData(d))
      .catch(() => toast.error("Gagal memuat grafik tagihan."))
      .finally(() => setLoadingBilling(false));
  }

  function loadAchievementChart() {
    setLoadingAchieve(true);
    const params = new URLSearchParams();
    if (achieverType !== "all") params.set("achiever_type", achieverType);
    if (achieveStartDate) params.set("start_date", achieveStartDate);
    if (achieveEndDate) params.set("end_date", achieveEndDate);

    api
      .get<AchievementChartResponse>(`/api/admin/dashboard/achievements-chart?${params.toString()}`)
      .then((d) => setAchievementData(d))
      .catch(() => toast.error("Gagal memuat grafik prestasi."))
      .finally(() => setLoadingAchieve(false));
  }

  useEffect(() => {
    loadBillingChart();
    loadAchievementChart();

    api
      .get<{ academic_years: AcademicYear[] }>("/api/admin/academic-years")
      .then((d) => {
        setYears(d.academic_years);
        const active = d.academic_years.find((y) => y.is_active);
        if (active) setBillingYear(active.year);
      })
      .catch(() => {});

    api
      .get<{ students: { meta: { total: number } } }>("/api/admin/students?per_page=1")
      .then((d) => setStudentCount(d.students.meta?.total ?? 0))
      .catch(() => {});
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    loadBillingChart();
  }, [billingYear]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    loadAchievementChart();
  }, [achieverType]); // eslint-disable-line react-hooks/exhaustive-deps

  // Max value for billing bars calculation
  const maxBilled = billingData?.units
    ? Math.max(...billingData.units.map((u) => u.total_billed), 1)
    : 1;

  // Max value for achievement bars calculation
  const maxAchievements = achievementData?.units
    ? Math.max(...achievementData.units.map((u) => u.total_achievements), 1)
    : 1;

  return (
    <div className="space-y-8">
      {/* Welcome Banner - same blue gradient as the parent (wali) dashboard,
          so the executive portal reads as the same product instead of a
          different app with a lavender tint. */}
      <div className="rounded-3xl bg-linear-to-br from-[#13286B] to-[#2856E0] p-6 sm:p-8 text-white shadow-xs">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <Badge className="mb-2 bg-white/15 text-white border border-white/20">Portal Eksekutif & Administrasi Yayasan YAPI</Badge>
            <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-white">
              Assalamu&apos;alaikum, {user?.name}
            </h1>
            <p className="text-sm text-white/80 mt-1">
              Pantau arus kas tagihan/piutang per unit sekolah dan grafik apresiasi prestasi siswa & guru secara real-time.
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2.5">
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

      {/* KPI Cards Summary */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card className="p-5 border-border/80 shadow-xs">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Total Siswa Terdaftar</span>
            <GraduationCap className="size-5 text-primary" />
          </div>
          <p className="mt-2 text-2xl font-black text-foreground">
            {studentCount !== null ? `${studentCount} Siswa` : <Skeleton className="h-8 w-28" />}
          </p>
          <p className="mt-1 text-xs text-muted-foreground">Aktif di 8 unit sekolah</p>
        </Card>

        <Card className="p-5 border-border/80 shadow-xs">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Penerimaan Kas (Terbayar)</span>
            <TrendingUp className="size-5 text-good" />
          </div>
          <p className="mt-2 text-2xl font-black text-good">
            {billingData ? rupiah(billingData.summary.total_paid) : <Skeleton className="h-8 w-32" />}
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            {billingData ? `Tingkat Pelunasan: ${billingData.summary.collection_rate}%` : "Memuat..."}
          </p>
        </Card>

        <Card className="p-5 border-border/80 shadow-xs">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Sisa Piutang / Tunggakan</span>
            <TrendingDown className="size-5 text-destructive" />
          </div>
          <p className="mt-2 text-2xl font-black text-destructive">
            {billingData ? rupiah(billingData.summary.total_outstanding) : <Skeleton className="h-8 w-32" />}
          </p>
          <p className="mt-1 text-xs text-muted-foreground">Perlu pemantauan & tindak lanjut</p>
        </Card>

        <Card className="p-5 border-border/80 shadow-xs">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Total Prestasi Terverifikasi</span>
            <Trophy className="size-5 text-amber-500" />
          </div>
          <p className="mt-2 text-2xl font-black text-foreground">
            {achievementData ? `${achievementData.summary.total_achievements} Prestasi` : <Skeleton className="h-8 w-24" />}
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            {achievementData ? `${achievementData.summary.total_siswa} Siswa · ${achievementData.summary.total_guru} Guru` : "Memuat..."}
          </p>
        </Card>
      </div>

      {/* ========================================================================= */}
      {/* 1. GRAFIK DATA TAGIHAN & PIUTANG PER UNIT DENGAN CUSTOM DATE RANGE */}
      {/* ========================================================================= */}
      <Card className="p-6 border-border/80 shadow-md space-y-6">
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 border-b border-border/70 pb-4">
          <div>
            <div className="flex items-center gap-2">
              <BarChart3 className="size-5 text-primary" />
              <h2 className="text-lg font-bold text-foreground">Grafik Arus Tagihan & Piutang per Unit Sekolah</h2>
            </div>
            <p className="text-xs text-muted-foreground mt-0.5">
              Perbandingan tagihan terbit, kas masuk, dan sisa piutang dengan filter rentang tanggal fleksibel.
            </p>
          </div>

          {/* Custom Date Range & Year Filter */}
          <form
            onSubmit={(e) => {
              e.preventDefault();
              loadBillingChart();
            }}
            className="flex flex-wrap items-center gap-2 text-xs"
          >
            <div>
              <select
                value={billingYear}
                onChange={(e) => {
                  setBillingYear(e.target.value);
                  setBillingStartDate("");
                  setBillingEndDate("");
                }}
                className="h-8.5 rounded-lg border border-input bg-card px-2.5 text-xs font-semibold shadow-2xs"
              >
                <option value="">Semua Tahun Ajaran</option>
                {years.map((y) => (
                  <option key={y.ulid} value={y.year}>
                    Tahun {y.year} {y.is_active ? "(Aktif)" : ""}
                  </option>
                ))}
              </select>
            </div>

            <div className="flex items-center gap-1.5 bg-muted/40 p-1 rounded-lg border border-border">
              <Calendar className="size-3.5 text-muted-foreground ml-1" />
              <Input
                type="date"
                value={billingStartDate}
                onChange={(e) => setBillingStartDate(e.target.value)}
                className="h-7 text-xs w-32 bg-card"
                placeholder="Tgl Mulai"
              />
              <span className="text-muted-foreground font-bold">s/d</span>
              <Input
                type="date"
                value={billingEndDate}
                onChange={(e) => setBillingEndDate(e.target.value)}
                className="h-7 text-xs w-32 bg-card"
                placeholder="Tgl Selesai"
              />
            </div>

            <Button type="submit" size="sm" className="h-8.5 text-xs font-semibold gap-1 shadow-xs">
              <Filter className="size-3" />
              <span>Terapkan</span>
            </Button>

            {(billingStartDate || billingEndDate) && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => {
                  setBillingStartDate("");
                  setBillingEndDate("");
                  loadBillingChart();
                }}
                className="h-8.5 text-xs"
              >
                Reset
              </Button>
            )}
          </form>
        </div>

        {/* Visual Bar Chart Breakdown */}
        {loadingBilling && <Skeleton className="h-64 w-full rounded-2xl" />}

        {!loadingBilling && billingData && (
          <div className="space-y-5">
            <div className="grid grid-cols-1 gap-4">
              {billingData.units.map((unit) => {
                const paidWidthPercent = unit.total_billed > 0 ? (unit.total_paid / maxBilled) * 100 : 0;
                const outstandingWidthPercent = unit.total_billed > 0 ? (unit.total_outstanding / maxBilled) * 100 : 0;

                return (
                  <div key={unit.unit_id} className="p-4 rounded-xl bg-card border border-border/60 hover:border-primary/50 transition-all shadow-2xs">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-2">
                      <div className="flex items-center gap-2">
                        <Badge variant="default" className="font-mono text-[10px] uppercase">
                          {unit.jenjang}
                        </Badge>
                        <span className="font-bold text-sm text-foreground">{unit.unit_label}</span>
                      </div>
                      <div className="flex items-center gap-4 text-xs font-semibold">
                        <span className="text-good">Lunas: {rupiah(unit.total_paid)}</span>
                        <span className="text-destructive">Piutang: {rupiah(unit.total_outstanding)}</span>
                        <Badge variant={unit.collection_rate >= 80 ? "good" : unit.collection_rate >= 50 ? "warn" : "bad"}>
                          {unit.collection_rate}% Terbayar
                        </Badge>
                      </div>
                    </div>

                    {/* Progress Track Bars */}
                    <div className="space-y-1.5">
                      <div className="h-3 w-full bg-muted/60 rounded-full overflow-hidden flex">
                        <div
                          style={{ width: `${paidWidthPercent}%` }}
                          className="h-full bg-good transition-all duration-500 rounded-l-full"
                          title={`Kas Masuk: ${rupiah(unit.total_paid)}`}
                        />
                        <div
                          style={{ width: `${outstandingWidthPercent}%` }}
                          className="h-full bg-destructive/70 transition-all duration-500 rounded-r-full"
                          title={`Sisa Piutang: ${rupiah(unit.total_outstanding)}`}
                        />
                      </div>
                      <div className="flex justify-between text-[11px] text-muted-foreground">
                        <span>Total Tagihan: <strong>{rupiah(unit.total_billed)}</strong> ({unit.bill_count} tagihan)</span>
                        <span>{unit.overdue_count > 0 ? `⚠️ ${unit.overdue_count} tagihan lewat jatuh tempo` : "Jatuh tempo aman"}</span>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        )}
      </Card>

      {/* ========================================================================= */}
      {/* 2. GRAFIK PRESTASI SEKOLAH PER UNIT DENGAN FILTER SISWA VS GURU */}
      {/* ========================================================================= */}
      <Card className="p-6 border-border/80 shadow-md space-y-6">
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 border-b border-border/70 pb-4">
          <div>
            <div className="flex items-center gap-2">
              <Trophy className="size-5 text-amber-500" />
              <h2 className="text-lg font-bold text-foreground">Grafik Capaian Prestasi per Unit Sekolah</h2>
            </div>
            <p className="text-xs text-muted-foreground mt-0.5">
              Statistik perolehan juara dan penghargaan tingkat Sekolah, Kecamatan, Kota, Provinsi hingga Nasional.
            </p>
          </div>

          {/* Filter Siswa vs Guru & Date */}
          <div className="flex flex-wrap items-center gap-2 text-xs">
            <div className="flex items-center bg-muted/60 p-1 rounded-xl border border-border">
              <button
                type="button"
                onClick={() => setAchieverType("all")}
                className={`px-3 py-1.5 rounded-lg font-bold transition-all ${
                  achieverType === "all" ? "bg-primary text-primary-foreground shadow-2xs" : "text-muted-foreground hover:text-foreground"
                }`}
              >
                Semua ({achievementData?.summary.total_achievements ?? 0})
              </button>
              <button
                type="button"
                onClick={() => setAchieverType("siswa")}
                className={`px-3 py-1.5 rounded-lg font-bold transition-all ${
                  achieverType === "siswa" ? "bg-primary text-primary-foreground shadow-2xs" : "text-muted-foreground hover:text-foreground"
                }`}
              >
                Prestasi Murid ({achievementData?.summary.total_siswa ?? 0})
              </button>
              <button
                type="button"
                onClick={() => setAchieverType("guru")}
                className={`px-3 py-1.5 rounded-lg font-bold transition-all ${
                  achieverType === "guru" ? "bg-primary text-primary-foreground shadow-2xs" : "text-muted-foreground hover:text-foreground"
                }`}
              >
                Prestasi Guru ({achievementData?.summary.total_guru ?? 0})
              </button>
            </div>
          </div>
        </div>

        {/* Visual Achievements Grid */}
        {loadingAchieve && <Skeleton className="h-56 w-full rounded-2xl" />}

        {!loadingAchieve && achievementData && (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            {achievementData.units.map((unit) => {
              const heightBarPercent = (unit.total_achievements / maxAchievements) * 100;

              return (
                <Card key={unit.unit_id} className="p-4.5 border-border/80 hover:border-primary/50 transition-all shadow-xs flex flex-col justify-between">
                  <div>
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <Badge variant="default" className="text-[10px] font-mono uppercase">
                          {unit.jenjang}
                        </Badge>
                        <h3 className="font-bold text-sm text-foreground mt-1">{unit.unit_label}</h3>
                      </div>
                      <span className="flex size-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600 font-extrabold text-base">
                        {unit.total_achievements}
                      </span>
                    </div>

                    {/* Breakdown Siswa vs Guru */}
                    <div className="flex items-center gap-2 mt-3 pt-2.5 border-t border-border/60 text-xs">
                      <span className="font-medium text-foreground">Siswa: <strong>{unit.siswa_count}</strong></span>
                      <span className="text-muted-foreground">·</span>
                      <span className="font-medium text-foreground">Guru: <strong>{unit.guru_count}</strong></span>
                    </div>

                    {/* Breakdown Tingkat */}
                    <div className="flex flex-wrap gap-1 mt-2.5">
                      {unit.by_tingkat["Nasional"] > 0 && (
                        <Badge variant="bad" className="text-[10px] font-bold">
                          {unit.by_tingkat["Nasional"]} Nasional
                        </Badge>
                      )}
                      {unit.by_tingkat["Provinsi"] > 0 && (
                        <Badge variant="warn" className="text-[10px] font-bold">
                          {unit.by_tingkat["Provinsi"]} Provinsi
                        </Badge>
                      )}
                      {unit.by_tingkat["Kabupaten/Kota"] > 0 && (
                        <Badge variant="primary" className="text-[10px] font-bold">
                          {unit.by_tingkat["Kabupaten/Kota"]} Kota/Kab
                        </Badge>
                      )}
                    </div>
                  </div>

                  {/* Recent Prestasi Snippet */}
                  {unit.recent.length > 0 && (
                    <div className="mt-3 pt-2.5 border-t border-border/50 text-[11px] text-muted-foreground">
                      <p className="font-semibold text-foreground truncate">
                        🏆 {unit.recent[0].nama_prestasi}
                      </p>
                      <p className="truncate text-[10px]">
                        Oleh: {unit.recent[0].achiever} ({unit.recent[0].achiever_type === "guru" ? "Guru" : "Siswa"})
                      </p>
                    </div>
                  )}
                </Card>
              );
            })}
          </div>
        )}
      </Card>
    </div>
  );
}

"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import {
  AlertTriangle,
  Award,
  BadgePercent,
  CheckCircle2,
  ChevronRight,
  FileSpreadsheet,
  Megaphone,
  Percent,
  Receipt,
  Sparkles,
  TrendingDown,
  Users,
  Wallet,
} from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuth } from "@/lib/auth/auth-context";
import { api, ApiError } from "@/lib/api";
import { rupiah } from "@/lib/format";

type ReceivablesSummary = {
  outstanding: number;
  bills: number;
  families: number;
  overdue_bills: number;
};

export default function AdminHomePage() {
  const { user } = useAuth();
  const [summary, setSummary] = useState<ReceivablesSummary | null>(null);
  const [pendingAchievements, setPendingAchievements] = useState<number | null>(null);

  useEffect(() => {
    api
      .get<{ summary: ReceivablesSummary }>("/api/admin/reports/receivables")
      .then((d) => setSummary(d.summary))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat ringkasan tunggakan."));
    api
      .get<{ achievements: unknown[] }>("/api/admin/achievements?status=pending")
      .then((d) => setPendingAchievements(d.achievements.length))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat prestasi menunggu."));
  }, []);

  return (
    <div className="space-y-8">
      {/* Welcome Banner */}
      <div className="rounded-3xl bg-linear-to-r from-primary/10 via-primary/5 to-accent/20 p-6 sm:p-8 border border-primary/20">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <Badge variant="primary" className="mb-2">Portal Administrasi YAPI</Badge>
            <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-foreground">
              Assalamu&apos;alaikum, {user?.name}
            </h1>
            <p className="text-sm text-muted-foreground mt-1">
              {user?.role === "admin"
                ? "Ringkasan data keuangan, tagihan, dan operasional seluruh unit sekolah YAPI."
                : `Ringkasan data operasional ${user?.school_unit?.label ?? "unit sekolah"}.`}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Link
              href="/admin/generate"
              className="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-xs font-bold text-primary-foreground shadow-md hover:bg-primary/90 transition-all"
            >
              <Wallet className="size-4" />
              <span>Terbitkan SPP Bulan Ini</span>
            </Link>
          </div>
        </div>
      </div>

      {/* Main KPI Stat Cards */}
      <div>
        <div className="flex items-center justify-between mb-3">
          <h2 className="text-base font-bold text-foreground">Ringkasan Keuangan & Piutang</h2>
          <Link href="/admin/laporan" className="text-xs font-semibold text-primary hover:underline inline-flex items-center gap-1">
            Lihat Laporan Lengkap <ChevronRight className="size-3.5" />
          </Link>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Card className="p-5 border-border/80 hover:border-primary/40 transition-all shadow-xs">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Total Piutang / Tunggakan</span>
              <TrendingDown className="size-5 text-destructive" />
            </div>
            <p className="mt-2 text-2xl font-black text-foreground">
              {summary ? rupiah(summary.outstanding) : <Skeleton className="h-8 w-32" />}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">
              {summary ? `Berasal dari ${summary.families} keluarga wali murid` : "Memuat..."}
            </p>
          </Card>

          <Card className="p-5 border-border/80 hover:border-primary/40 transition-all shadow-xs">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Tagihan Belum Lunas</span>
              <Receipt className="size-5 text-amber-600" />
            </div>
            <p className="mt-2 text-2xl font-black text-foreground">
              {summary ? `${summary.bills} tagihan` : <Skeleton className="h-8 w-20" />}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">Tagihan SPP & biaya terbuka</p>
          </Card>

          <Card className="p-5 border-border/80 hover:border-primary/40 transition-all shadow-xs">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Lewat Jatuh Tempo</span>
              <AlertTriangle className="size-5 text-destructive" />
            </div>
            <p className="mt-2 text-2xl font-black text-destructive">
              {summary ? `${summary.overdue_bills} tagihan` : <Skeleton className="h-8 w-20" />}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">Perlu follow-up penagihan</p>
          </Card>

          <Card className="p-5 border-border/80 hover:border-primary/40 transition-all shadow-xs">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Verifikasi Prestasi</span>
              <Award className="size-5 text-primary" />
            </div>
            <p className="mt-2 text-2xl font-black text-foreground">
              {pendingAchievements !== null ? `${pendingAchievements} pengajuan` : <Skeleton className="h-8 w-20" />}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">Menunggu persetujuan admin/guru</p>
          </Card>
        </div>
      </div>

      {/* Quick Action Navigation Grid */}
      <div>
        <h2 className="text-base font-bold text-foreground mb-3">Pintasan Menu Cepat</h2>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Link href="/admin/generate">
            <Card className="group p-5 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer">
              <div className="flex items-start gap-4">
                <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary group-hover:bg-primary group-hover:text-primary-foreground transition-colors">
                  <Wallet className="size-6" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="font-bold text-foreground group-hover:text-primary transition-colors">Terbitkan SPP Massal</p>
                  <p className="text-xs text-muted-foreground mt-1">Pratinjau dan eksekusi generate tagihan bulanan seluruh siswa.</p>
                </div>
              </div>
            </Card>
          </Link>

          <Link href="/admin/tagihan">
            <Card className="group p-5 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer">
              <div className="flex items-start gap-4">
                <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-emerald-500/10 text-emerald-600 group-hover:bg-emerald-600 group-hover:text-white transition-colors">
                  <Receipt className="size-6" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="font-bold text-foreground group-hover:text-primary transition-colors">Kelola Tagihan & Transaksi</p>
                  <p className="text-xs text-muted-foreground mt-1">Catat pembayaran front desk, batalkan, atau bebaskan tagihan.</p>
                </div>
              </div>
            </Card>
          </Link>

          <Link href="/admin/diskon">
            <Card className="group p-5 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer">
              <div className="flex items-start gap-4">
                <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-amber-500/10 text-amber-600 group-hover:bg-amber-600 group-hover:text-white transition-colors">
                  <BadgePercent className="size-6" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="font-bold text-foreground group-hover:text-primary transition-colors">Kelola Diskon & Beasiswa</p>
                  <p className="text-xs text-muted-foreground mt-1">Atur potongan SPP, subsidi anak guru, dan beasiswa tahfidz.</p>
                </div>
              </div>
            </Card>
          </Link>

          <Link href="/admin/tarif">
            <Card className="group p-5 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer">
              <div className="flex items-start gap-4">
                <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-indigo-500/10 text-indigo-600 group-hover:bg-indigo-600 group-hover:text-white transition-colors">
                  <Percent className="size-6" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="font-bold text-foreground group-hover:text-primary transition-colors">Kelola Tarif Biaya</p>
                  <p className="text-xs text-muted-foreground mt-1">Atur besaran SPP, uang gedung, dan seragam per unit sekolah.</p>
                </div>
              </div>
            </Card>
          </Link>

          <Link href="/admin/laporan">
            <Card className="group p-5 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer">
              <div className="flex items-start gap-4">
                <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-purple-500/10 text-purple-600 group-hover:bg-purple-600 group-hover:text-white transition-colors">
                  <FileSpreadsheet className="size-6" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="font-bold text-foreground group-hover:text-primary transition-colors">Laporan & Rekonsiliasi</p>
                  <p className="text-xs text-muted-foreground mt-1">Rekapitulasi penerimaan, penuaan piutang, dan unduh XLS/CSV.</p>
                </div>
              </div>
            </Card>
          </Link>

          <Link href="/admin/prestasi">
            <Card className="group p-5 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer">
              <div className="flex items-start gap-4">
                <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-rose-500/10 text-rose-600 group-hover:bg-rose-600 group-hover:text-white transition-colors">
                  <Award className="size-6" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="font-bold text-foreground group-hover:text-primary transition-colors">Verifikasi Prestasi Siswa</p>
                  <p className="text-xs text-muted-foreground mt-1">Tinjau sertifikat dan ajuan prestasi dari wali murid/guru.</p>
                </div>
              </div>
            </Card>
          </Link>
        </div>
      </div>
    </div>
  );
}

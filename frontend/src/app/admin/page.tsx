"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import {
  AlertTriangle,
  Award,
  BadgePercent,
  ChevronRight,
  FileSpreadsheet,
  GraduationCap,
  Megaphone,
  Percent,
  Receipt,
  SlidersHorizontal,
  Sparkles,
  TrendingDown,
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
  const [studentCount, setStudentCount] = useState<number | null>(null);

  useEffect(() => {
    api
      .get<{ summary: ReceivablesSummary }>("/api/admin/reports/receivables")
      .then((d) => setSummary(d.summary))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat ringkasan tunggakan."));

    api
      .get<{ achievements: unknown[] }>("/api/admin/achievements?status=pending")
      .then((d) => setPendingAchievements(d.achievements.length))
      .catch(() => {});

    api
      .get<{ students: { meta: { total: number } } }>("/api/admin/students?per_page=1")
      .then((d) => setStudentCount(d.students.meta?.total ?? 0))
      .catch(() => {});
  }, []);

  return (
    <div className="space-y-8">
      {/* Welcome Banner */}
      <div className="rounded-3xl bg-linear-to-r from-primary/10 via-primary/5 to-accent/20 p-6 sm:p-8 border border-primary/20 shadow-xs">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <Badge variant="primary" className="mb-2">Portal Administrasi Yayasan YAPI</Badge>
            <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-foreground">
              Assalamu&apos;alaikum, {user?.name}
            </h1>
            <p className="text-sm text-muted-foreground mt-1">
              {user?.role === "admin"
                ? "Kelola data akademik, penetapan tarif SPP, diskon/beasiswa, transaksi pembayaran, dan hak akses pengguna."
                : `Ringkasan data operasional ${user?.school_unit?.label ?? "unit sekolah"}.`}
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2.5">
            <Link href="/admin/siswa">
              <Button variant="outline" className="gap-2 font-bold text-xs shadow-xs">
                <GraduationCap className="size-4" />
                <span>Lihat Data Siswa & SPP</span>
              </Button>
            </Link>
            <Link href="/admin/generate">
              <Button className="gap-2 font-bold text-xs shadow-md">
                <Wallet className="size-4" />
                <span>Terbitkan SPP Massal</span>
              </Button>
            </Link>
          </div>
        </div>
      </div>

      {/* Main KPI Stat Cards */}
      <div>
        <div className="flex items-center justify-between mb-3">
          <h2 className="text-base font-bold text-foreground">Ringkasan Keuangan & Operasional</h2>
          <Link href="/admin/laporan" className="text-xs font-semibold text-primary hover:underline inline-flex items-center gap-1">
            Lihat Laporan Lengkap <ChevronRight className="size-3.5" />
          </Link>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Card className="p-5 border-border/80 hover:border-primary/40 transition-all shadow-xs">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Total Siswa Terdaftar</span>
              <GraduationCap className="size-5 text-primary" />
            </div>
            <p className="mt-2 text-2xl font-black text-foreground">
              {studentCount !== null ? `${studentCount} Siswa` : <Skeleton className="h-8 w-28" />}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">Aktif di 8 unit sekolah</p>
          </Card>

          <Card className="p-5 border-border/80 hover:border-primary/40 transition-all shadow-xs">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Total Piutang Berjalan</span>
              <TrendingDown className="size-5 text-destructive" />
            </div>
            <p className="mt-2 text-2xl font-black text-foreground">
              {summary ? rupiah(summary.outstanding) : <Skeleton className="h-8 w-32" />}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">
              {summary ? `Dari ${summary.families} keluarga wali murid` : "Memuat..."}
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
            <p className="mt-1 text-xs text-muted-foreground">Termasuk SPP & biaya unit</p>
          </Card>

          <Card className="p-5 border-border/80 hover:border-primary/40 transition-all shadow-xs">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Verifikasi Prestasi</span>
              <Award className="size-5 text-emerald-600" />
            </div>
            <p className="mt-2 text-2xl font-black text-foreground">
              {pendingAchievements !== null ? `${pendingAchievements} pengajuan` : <Skeleton className="h-8 w-20" />}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">Menunggu validasi staff/guru</p>
          </Card>
        </div>
      </div>

      {/* Core Feature Navigation Matrix */}
      <div>
        <h2 className="text-base font-bold text-foreground mb-3.5">Modul & Fitur Administrasi Utama</h2>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Link href="/admin/siswa">
            <Card className="group h-full p-5 border-border/80 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer flex items-start gap-4">
              <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-blue-500/10 text-blue-600 group-hover:bg-blue-600 group-hover:text-white transition-colors">
                <GraduationCap className="size-6" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="font-bold text-foreground group-hover:text-primary transition-colors">Data Siswa & Tarif SPP</p>
                <p className="text-xs text-muted-foreground mt-1">Daftar siswa per jenjang & unit, rincian SPP pokok, potongan beasiswa, dan SPP bersih.</p>
              </div>
            </Card>
          </Link>

          <Link href="/admin/tarif">
            <Card className="group h-full p-5 border-border/80 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer flex items-start gap-4">
              <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-indigo-500/10 text-indigo-600 group-hover:bg-indigo-600 group-hover:text-white transition-colors">
                <SlidersHorizontal className="size-6" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="font-bold text-foreground group-hover:text-primary transition-colors">Pengaturan Biaya & SPP</p>
                <p className="text-xs text-muted-foreground mt-1">Atur jenis tagihan, nominal SPP per tingkat, uang gedung, seragam, jatuh tempo & denda.</p>
              </div>
            </Card>
          </Link>

          <Link href="/admin/diskon">
            <Card className="group h-full p-5 border-border/80 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer flex items-start gap-4">
              <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-amber-500/10 text-amber-600 group-hover:bg-amber-600 group-hover:text-white transition-colors">
                <BadgePercent className="size-6" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="font-bold text-foreground group-hover:text-primary transition-colors">Kelola Diskon & Beasiswa</p>
                <p className="text-xs text-muted-foreground mt-1">Master skema potongan SPP (% atau Rp) dan penetapan beasiswa per siswa.</p>
              </div>
            </Card>
          </Link>

          <Link href="/admin/generate">
            <Card className="group h-full p-5 border-border/80 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer flex items-start gap-4">
              <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary group-hover:bg-primary group-hover:text-primary-foreground transition-colors">
                <Wallet className="size-6" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="font-bold text-foreground group-hover:text-primary transition-colors">Terbitkan SPP Massal</p>
                <p className="text-xs text-muted-foreground mt-1">Pratinjau otomatis pemotongan beasiswa dan generate invoice SPP bulanan.</p>
              </div>
            </Card>
          </Link>

          <Link href="/admin/tagihan">
            <Card className="group h-full p-5 border-border/80 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer flex items-start gap-4">
              <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-emerald-500/10 text-emerald-600 group-hover:bg-emerald-600 group-hover:text-white transition-colors">
                <Receipt className="size-6" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="font-bold text-foreground group-hover:text-primary transition-colors">Tagihan & Transaksi</p>
                <p className="text-xs text-muted-foreground mt-1">Catat pembayaran kasir/front desk, pembatalan, pembebasan tagihan, dan unduh PDF invoice.</p>
              </div>
            </Card>
          </Link>

          {user?.role === "admin" && (
            <Link href="/admin/users">
              <Card className="group h-full p-5 border-border/80 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer flex items-start gap-4">
                <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-cyan-500/10 text-cyan-600 group-hover:bg-cyan-600 group-hover:text-white transition-colors">
                  <Users className="size-6" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="font-bold text-foreground group-hover:text-primary transition-colors">Manajemen Pengguna</p>
                  <p className="text-xs text-muted-foreground mt-1">Kelola akun dan hak akses Super Admin, Tata Usaha Unit, Guru, dan Wali Murid.</p>
                </div>
              </Card>
            </Link>
          )}

          <Link href="/admin/laporan">
            <Card className="group h-full p-5 border-border/80 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer flex items-start gap-4">
              <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-purple-500/10 text-purple-600 group-hover:bg-purple-600 group-hover:text-white transition-colors">
                <FileSpreadsheet className="size-6" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="font-bold text-foreground group-hover:text-primary transition-colors">Laporan Keuangan</p>
                <p className="text-xs text-muted-foreground mt-1">Rekap penerimaan kas, penuaan piutang per unit/kelas, dan ekspor data.</p>
              </div>
            </Card>
          </Link>

          <Link href="/admin/prestasi">
            <Card className="group h-full p-5 border-border/80 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer flex items-start gap-4">
              <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-rose-500/10 text-rose-600 group-hover:bg-rose-600 group-hover:text-white transition-colors">
                <Award className="size-6" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="font-bold text-foreground group-hover:text-primary transition-colors">Verifikasi Prestasi Siswa</p>
                <p className="text-xs text-muted-foreground mt-1">Validasi piagam lomba, ajuan prestasi wali murid, dan pemberian poin reward.</p>
              </div>
            </Card>
          </Link>

          <Link href="/admin/poin">
            <Card className="group h-full p-5 border-border/80 hover:border-primary transition-all duration-200 shadow-xs hover:shadow-md cursor-pointer flex items-start gap-4">
              <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-amber-500/10 text-amber-600 group-hover:bg-amber-600 group-hover:text-white transition-colors">
                <Sparkles className="size-6" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="font-bold text-foreground group-hover:text-primary transition-colors">Poin & Tata Tertib</p>
                <p className="text-xs text-muted-foreground mt-1">Buku rekapitulasi poin pelanggaran & apresiasi serta ambang surat peringatan.</p>
              </div>
            </Card>
          </Link>
        </div>
      </div>
    </div>
  );
}

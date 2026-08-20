"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { toast } from "sonner";
import {
  ArrowRight,
  Award,
  ChevronRight,
  CreditCard,
  GraduationCap,
  Megaphone,
  Receipt,
  Sparkles,
  User,
} from "lucide-react";
import { WaliShell } from "@/components/layout/wali-shell";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useRequireRole } from "@/lib/auth/use-require-role";
import { PointMeter } from "@/components/point-meter";
import type { PointThresholdInfo } from "@/lib/types/kesiswaan";

type Student = {
  ulid: string;
  nama_lengkap: string;
  nama_panggilan: string | null;
  nis: string | null;
  status: string;
  unit: { code: string; label: string } | null;
  kelas: { name: string; tingkat: number; wali_kelas: string | null } | null;
  poin: { balance: number; threshold: PointThresholdInfo | null } | null;
};

export default function DashboardPage() {
  const { user, loading } = useRequireRole("orangtua");
  const [students, setStudents] = useState<Student[] | null>(null);

  useEffect(() => {
    if (user?.role === "orangtua") {
      api
        .get<{ students: Student[] }>("/api/wali/students")
        .then(({ students }) => setStudents(students))
        .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat data anak."));
    }
  }, [user]);

  if (loading || !user || user.role !== "orangtua") {
    return (
      <div className="flex min-h-dvh items-center justify-center bg-canvas p-6">
        <Skeleton className="h-10 w-48" />
      </div>
    );
  }

  return (
    <WaliShell>
      <div className="space-y-8">
        {/* Welcome Greeting Banner */}
        <div className="rounded-3xl bg-linear-to-r from-primary/10 via-primary/5 to-accent/30 p-6 sm:p-8 border border-primary/20">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <Badge variant="primary" className="mb-2">Portal Wali Murid YAPI</Badge>
              <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-foreground">
                Assalamu&apos;alaikum, {user.name}
              </h1>
              <p className="text-sm text-muted-foreground mt-1">
                Pantau perkembangan akademik, kedisiplinan, prestasi, dan administrasi SPP ananda di sini.
              </p>
            </div>

            <div className="flex items-center gap-2">
              <Link href="/tagihan">
                <Button className="gap-2 shadow-md">
                  <Receipt className="size-4" />
                  <span>Bayar Tagihan SPP</span>
                </Button>
              </Link>
            </div>
          </div>
        </div>

        {/* Quick Menu Action Bar */}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Link href="/tagihan">
            <Card className="group p-4 hover:border-primary transition-all duration-200 shadow-xs cursor-pointer">
              <div className="flex items-center gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary group-hover:bg-primary group-hover:text-white transition-colors">
                  <Receipt className="size-5" />
                </span>
                <div>
                  <p className="text-xs font-bold text-foreground group-hover:text-primary transition-colors">Tagihan SPP</p>
                  <p className="text-[11px] text-muted-foreground">Multi-bayar & cicilan</p>
                </div>
              </div>
            </Card>
          </Link>

          <Link href="/pembayaran">
            <Card className="group p-4 hover:border-primary transition-all duration-200 shadow-xs cursor-pointer">
              <div className="flex items-center gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600 group-hover:bg-emerald-600 group-hover:text-white transition-colors">
                  <CreditCard className="size-5" />
                </span>
                <div>
                  <p className="text-xs font-bold text-foreground group-hover:text-primary transition-colors">Riwayat Bayar</p>
                  <p className="text-[11px] text-muted-foreground">Struk & bukti transaksi</p>
                </div>
              </div>
            </Card>
          </Link>

          <Link href="/informasi">
            <Card className="group p-4 hover:border-primary transition-all duration-200 shadow-xs cursor-pointer">
              <div className="flex items-center gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-amber-500/10 text-amber-600 group-hover:bg-amber-600 group-hover:text-white transition-colors">
                  <Megaphone className="size-5" />
                </span>
                <div>
                  <p className="text-xs font-bold text-foreground group-hover:text-primary transition-colors">Pengumuman</p>
                  <p className="text-[11px] text-muted-foreground">Kabar & agenda sekolah</p>
                </div>
              </div>
            </Card>
          </Link>

          <Link href="/dashboard">
            <Card className="group p-4 hover:border-primary transition-all duration-200 shadow-xs cursor-pointer border-primary/40 bg-primary/5">
              <div className="flex items-center gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary text-primary-foreground transition-colors">
                  <GraduationCap className="size-5" />
                </span>
                <div>
                  <p className="text-xs font-bold text-primary">Data Ananda</p>
                  <p className="text-[11px] text-muted-foreground">{students?.length ?? 0} terdaftar</p>
                </div>
              </div>
            </Card>
          </Link>
        </div>

        {/* Student Cards Section */}
        <section className="space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-bold tracking-tight text-foreground">Ananda Terdaftar</h2>
            <span className="text-xs text-muted-foreground">Klik nama ananda untuk melihat buku rekap lengkap</span>
          </div>

          {students === null && (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <Skeleton className="h-48 w-full rounded-2xl" />
              <Skeleton className="h-48 w-full rounded-2xl" />
            </div>
          )}

          {students?.length === 0 && (
            <Card className="p-8 text-center text-sm text-muted-foreground">
              Belum ada anak yang terhubung dengan akun ini. Hubungi tata usaha unit sekolah jika ini keliru.
            </Card>
          )}

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {students?.map((student) => (
              <Card
                key={student.ulid}
                className="group relative flex flex-col justify-between p-6 border-border/80 hover:border-primary/50 transition-all duration-200 shadow-xs hover:shadow-md"
              >
                <div className="space-y-4">
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3.5">
                      <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary group-hover:bg-primary group-hover:text-primary-foreground transition-colors">
                        <GraduationCap className="size-6" />
                      </span>
                      <div>
                        <Link href={`/anak/${student.ulid}`} className="font-bold text-foreground text-lg group-hover:text-primary transition-colors flex items-center gap-1.5">
                          <span>{student.nama_lengkap}</span>
                          <ChevronRight className="size-4 opacity-0 group-hover:opacity-100 transition-opacity" />
                        </Link>
                        <p className="text-xs font-medium text-muted-foreground mt-0.5">
                          {student.unit?.label ?? "Unit belum diatur"} · {student.kelas ? `Kelas ${student.kelas.name}` : "Kelas belum ditentukan"}
                        </p>
                      </div>
                    </div>

                    <Badge variant={student.status === "active" ? "good" : "warn"}>
                      {student.status === "active" ? "Aktif" : student.status}
                    </Badge>
                  </div>

                  <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 border-t border-border/70 pt-4 text-xs">
                    <div className="p-2.5 rounded-xl bg-muted/40">
                      <p className="text-muted-foreground">NIS</p>
                      <p className="font-bold text-foreground mt-0.5">{student.nis ?? "Belum terbit"}</p>
                    </div>
                    <div className="p-2.5 rounded-xl bg-muted/40">
                      <p className="text-muted-foreground">Wali Kelas</p>
                      <p className="font-bold text-foreground mt-0.5 truncate">{student.kelas?.wali_kelas ?? "—"}</p>
                    </div>
                    <div className="p-2.5 rounded-xl bg-muted/40 col-span-2 sm:col-span-1">
                      <p className="text-muted-foreground">Administrasi</p>
                      <Link href="/tagihan" className="font-bold text-primary hover:underline mt-0.5 block">
                        Cek Tagihan →
                      </Link>
                    </div>
                  </div>

                  {student.poin && (
                    <div className="border-t border-border/70 pt-3">
                      <div className="flex items-center justify-between mb-1.5 text-xs">
                        <span className="font-medium text-muted-foreground flex items-center gap-1">
                          <Sparkles className="size-3.5 text-amber-500" />
                          <span>Poin Tata Tertib Semester Ini</span>
                        </span>
                        <span className="font-bold text-foreground">{student.poin.balance} poin</span>
                      </div>
                      <Link href={`/anak/${student.ulid}`}>
                        <PointMeter balance={student.poin.balance} threshold={student.poin.threshold} size="sm" />
                      </Link>
                    </div>
                  )}
                </div>

                <div className="mt-5 border-t border-border/70 pt-3 flex items-center justify-between">
                  <Link
                    href={`/anak/${student.ulid}`}
                    className="text-xs font-bold text-primary hover:underline inline-flex items-center gap-1"
                  >
                    <span>Buka Profil & Rekap Nilai</span>
                    <ArrowRight className="size-3.5" />
                  </Link>

                  <Link href="/tagihan">
                    <Button size="sm" variant="outline" className="text-xs">
                      Bayar Tagihan
                    </Button>
                  </Link>
                </div>
              </Card>
            ))}
          </div>
        </section>
      </div>
    </WaliShell>
  );
}

"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { toast } from "sonner";
import {
  ArrowRight,
  ChevronRight,
  CreditCard,
  GraduationCap,
  Megaphone,
  Receipt,
  Sparkles,
} from "lucide-react";
import { WaliShell } from "@/components/layout/wali-shell";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useRequireRole } from "@/lib/auth/use-require-role";
import { PointMeter } from "@/components/point-meter";
import { rupiah } from "@/lib/format";
import { cn } from "@/lib/utils";
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
  tunggakan: number;
};

export default function DashboardPage() {
  const { user, loading } = useRequireRole("orangtua");
  const [students, setStudents] = useState<Student[] | null>(null);
  const [announcementCount, setAnnouncementCount] = useState<number | null>(null);

  useEffect(() => {
    if (user?.role === "orangtua") {
      api
        .get<{ students: Student[] }>("/api/wali/students")
        .then(({ students }) => setStudents(students))
        .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat data anak."));
      api
        .get<{ announcements: unknown[] }>("/api/wali/announcements")
        .then((d) => setAnnouncementCount(d.announcements.length))
        .catch(() => setAnnouncementCount(0));
    }
  }, [user]);

  const totalTunggakan = students?.reduce((sum, s) => sum + s.tunggakan, 0) ?? 0;
  const flaggedStudents = students?.filter((s) => s.poin?.threshold) ?? [];

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
        {/* Welcome banner - same gradient PMB uses on its own dashboard, so
            a family coming from either app lands on a screen that reads as
            the same product. */}
        <div className="rounded-2xl bg-linear-to-br from-[#13286B] to-[#2856E0] p-6 sm:p-7 text-white flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="text-lg font-semibold" style={{ fontFamily: "var(--font-display)" }}>
              Assalamu&apos;alaikum, {user.name}
            </h1>
            <p className="text-sm text-white/80 mt-1 max-w-lg">
              Pantau perkembangan akademik, kedisiplinan, prestasi, dan administrasi SPP ananda di sini.
            </p>
          </div>

          <Link href="/tagihan" className="shrink-0">
            <Button variant="ghost" className="bg-white/15 text-white border border-white/20 hover:bg-white/25 gap-2">
              <Receipt className="size-4" />
              <span>Bayar Tagihan SPP</span>
              <ArrowRight className="size-4" />
            </Button>
          </Link>
        </div>

        {/* Stat grid - PMB's pattern: icon-badge + value + status badge,
            not just a navigation shortcut, so the number that matters is
            visible before a tap. */}
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Link href="/tagihan">
            <Card className="py-4 gap-2 hover:border-primary transition-colors">
              <CardContent className="flex flex-col gap-1.5">
                <div className="flex items-center justify-between">
                  <span className="text-xs text-muted-foreground">Tunggakan SPP</span>
                  <span className={cn("flex size-7 items-center justify-center rounded-lg", totalTunggakan > 0 ? "bg-warn-soft text-warn" : "bg-good-soft text-good")}>
                    <Receipt className="size-3.5" />
                  </span>
                </div>
                <span className="tabular text-sm font-semibold">{rupiah(totalTunggakan)}</span>
                <Badge variant={totalTunggakan > 0 ? "warn" : "good"} className="w-fit">
                  {totalTunggakan > 0 ? "Ada tunggakan" : "Lunas semua"}
                </Badge>
              </CardContent>
            </Card>
          </Link>

          <Link href="/pembayaran">
            <Card className="py-4 gap-2 hover:border-primary transition-colors">
              <CardContent className="flex flex-col gap-1.5">
                <div className="flex items-center justify-between">
                  <span className="text-xs text-muted-foreground">Riwayat Bayar</span>
                  <span className="flex size-7 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                    <CreditCard className="size-3.5" />
                  </span>
                </div>
                <span className="text-sm font-semibold">Struk & bukti</span>
                <Badge className="w-fit">Lihat transaksi</Badge>
              </CardContent>
            </Card>
          </Link>

          <Link href="/informasi">
            <Card className="py-4 gap-2 hover:border-primary transition-colors">
              <CardContent className="flex flex-col gap-1.5">
                <div className="flex items-center justify-between">
                  <span className="text-xs text-muted-foreground">Pengumuman</span>
                  <span className="flex size-7 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                    <Megaphone className="size-3.5" />
                  </span>
                </div>
                <span className="text-sm font-semibold">
                  {announcementCount === null ? "…" : `${announcementCount} pengumuman`}
                </span>
                <Badge className="w-fit">Kabar & agenda</Badge>
              </CardContent>
            </Card>
          </Link>

          <Card className="py-4 gap-2">
            <CardContent className="flex flex-col gap-1.5">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground">Poin Tata Tertib</span>
                <span className={cn("flex size-7 items-center justify-center rounded-lg", flaggedStudents.length > 0 ? "bg-warn-soft text-warn" : "bg-good-soft text-good")}>
                  <Sparkles className="size-3.5" />
                </span>
              </div>
              <span className="text-sm font-semibold">{students?.length ?? 0} ananda terdaftar</span>
              <Badge variant={flaggedStudents.length > 0 ? "warn" : "good"} className="w-fit">
                {flaggedStudents.length > 0 ? `${flaggedStudents.length} perlu perhatian` : "Semua baik"}
              </Badge>
            </CardContent>
          </Card>
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

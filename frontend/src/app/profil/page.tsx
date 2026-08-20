"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import {
  Building2,
  CheckCircle2,
  GraduationCap,
  Info,
  LogOut,
  Mail,
  Phone,
  Receipt,
  ShieldCheck,
  Smartphone,
  User,
  Users,
} from "lucide-react";
import { toast } from "sonner";
import { WaliShell } from "@/components/layout/wali-shell";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";
import { useRequireRole } from "@/lib/auth/use-require-role";

type Student = {
  ulid: string;
  nama_lengkap: string;
  nis: string | null;
  status: string;
  unit: { code: string; label: string } | null;
  kelas: { name: string } | null;
};

export default function WaliProfilePage() {
  const { user, loading } = useRequireRole("orangtua");
  const { logout } = useAuth();
  const [students, setStudents] = useState<Student[] | null>(null);

  useEffect(() => {
    if (user?.role === "orangtua") {
      api
        .get<{ students: Student[] }>("/api/wali/students")
        .then(({ students }) => setStudents(students))
        .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat profil."));
    }
  }, [user]);

  if (loading || !user || user.role !== "orangtua") {
    return (
      <WaliShell>
        <div className="space-y-4 max-w-4xl mx-auto">
          <Skeleton className="h-8 w-48" />
          <Skeleton className="h-64 w-full rounded-2xl" />
        </div>
      </WaliShell>
    );
  }

  return (
    <WaliShell>
      <div className="max-w-4xl mx-auto space-y-6 pb-24">
        {/* Page Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-2xl sm:text-3xl font-black tracking-tight text-foreground flex items-center gap-2.5">
              <User className="size-7 text-primary" />
              <span>Profil Akun Wali Murid</span>
            </h1>
            <p className="text-xs sm:text-sm text-muted-foreground mt-0.5">
              Informasi identitas akun, kontak terdaftar, dan data ananda yang terhubung.
            </p>
          </div>

          <Button
            variant="outline"
            size="sm"
            onClick={logout}
            className="gap-2 text-destructive hover:bg-destructive/10 hover:text-destructive self-start sm:self-auto text-xs font-semibold"
          >
            <LogOut className="size-3.5" />
            <span>Keluar Akun</span>
          </Button>
        </div>

        {/* Profile Card */}
        <Card className="p-6 border-border bg-card shadow-xs rounded-2xl space-y-6">
          <div className="flex flex-col sm:flex-row sm:items-center gap-4 border-b border-border/80 pb-6">
            <div className="size-16 rounded-2xl bg-primary/10 text-primary flex items-center justify-center text-2xl font-black shrink-0 shadow-2xs">
              {user.name.charAt(0)}
            </div>

            <div className="space-y-1 min-w-0 flex-1">
              <div className="flex items-center gap-2 flex-wrap">
                <h2 className="text-xl font-bold text-foreground">{user.name}</h2>
                <Badge variant="good">Wali Murid Aktif</Badge>
              </div>
              <p className="text-xs text-muted-foreground">
                Terdaftar di Sistem Informasi Akademik & Keuangan Terpadu (SIAKAD) YAPI Jakarta
              </p>
            </div>
          </div>

          {/* Contact Details Grid */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div className="p-4 rounded-xl bg-muted/40 border border-border/60 space-y-1">
              <div className="flex items-center gap-2 text-muted-foreground text-xs">
                <Smartphone className="size-4 text-emerald-600" />
                <span className="font-semibold">Nomor WhatsApp / HP</span>
              </div>
              <p className="font-mono font-bold text-foreground text-sm pt-0.5">
                {user.phone ?? "Belum terdaftar"}
              </p>
              <p className="text-[10px] text-muted-foreground">
                Digunakan untuk menerima notifikasi tagihan SPP & kode OTP login.
              </p>
            </div>

            <div className="p-4 rounded-xl bg-muted/40 border border-border/60 space-y-1">
              <div className="flex items-center gap-2 text-muted-foreground text-xs">
                <Mail className="size-4 text-primary" />
                <span className="font-semibold">Alamat Email</span>
              </div>
              <p className="font-semibold text-foreground text-sm truncate pt-0.5">
                {user.email ?? "—"}
              </p>
              <p className="text-[10px] text-muted-foreground">
                Digunakan untuk menerima lembar invoice elektronik & kuitansi resmi.
              </p>
            </div>
          </div>
        </Card>

        {/* Connected Children Section */}
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <h3 className="text-base font-bold text-foreground flex items-center gap-2">
              <Users className="size-4 text-primary" />
              <span>Ananda Terhubung ({students?.length ?? 0})</span>
            </h3>
            <span className="text-xs text-muted-foreground">Unit Sekolah YAPI Al Azhar Rawamangun</span>
          </div>

          {students === null && (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <Skeleton className="h-28 w-full rounded-2xl" />
              <Skeleton className="h-28 w-full rounded-2xl" />
            </div>
          )}

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            {students?.map((student) => (
              <Card
                key={student.ulid}
                className="p-5 border-border hover:border-primary/50 transition-all bg-card shadow-xs rounded-2xl space-y-3"
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="flex items-center gap-3">
                    <div className="size-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center font-bold shrink-0">
                      <GraduationCap className="size-5" />
                    </div>
                    <div>
                      <h4 className="font-bold text-foreground text-sm">{student.nama_lengkap}</h4>
                      <p className="text-xs text-muted-foreground">{student.unit?.label}</p>
                    </div>
                  </div>

                  <Badge variant={student.status === "active" ? "good" : "warn"}>
                    {student.status === "active" ? "Aktif" : student.status}
                  </Badge>
                </div>

                <div className="grid grid-cols-2 gap-2 text-xs bg-muted/30 p-2.5 rounded-xl border border-border/50">
                  <div>
                    <span className="text-muted-foreground block text-[11px]">NIS Siswa:</span>
                    <strong className="font-mono text-foreground">{student.nis ?? "—"}</strong>
                  </div>
                  <div>
                    <span className="text-muted-foreground block text-[11px]">Kelas:</span>
                    <strong className="text-foreground">{student.kelas?.name ?? "—"}</strong>
                  </div>
                </div>

                <div className="flex items-center justify-between pt-2 border-t border-border/60">
                  <Link href={`/anak/${student.ulid}`} className="text-xs text-primary font-semibold hover:underline">
                    Lihat Nilai & Poin →
                  </Link>
                  <Link href="/tagihan" className="text-xs text-primary font-semibold hover:underline">
                    Cek SPP →
                  </Link>
                </div>
              </Card>
            ))}
          </div>
        </div>

        {/* Security & Support Info */}
        <Card className="p-5 border-border/80 bg-muted/20 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
          <div className="flex items-center gap-3">
            <ShieldCheck className="size-6 text-primary shrink-0" />
            <div>
              <p className="font-bold text-foreground">Butuh Bantuan Akun atau Perubahan Data Kontak?</p>
              <p className="text-muted-foreground text-[11px] mt-0.5">
                Hubungi staf tata usaha sekolah atau Helpdesk YAPI di WhatsApp 0812-9270-2075.
              </p>
            </div>
          </div>

          <a
            href="https://wa.me/6281292702075?text=Halo%20Admin%20YAPI,%20saya%20butuh%20bantuan%20mengenai%20akun%20SIAKAD"
            target="_blank"
            rel="noopener noreferrer"
            className="shrink-0"
          >
            <Button size="sm" variant="outline" className="text-xs font-semibold gap-1.5 w-full sm:w-auto">
              <span>Chat WhatsApp</span>
            </Button>
          </a>
        </Card>
      </div>
    </WaliShell>
  );
}

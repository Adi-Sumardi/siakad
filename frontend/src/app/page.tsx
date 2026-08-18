"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { GraduationCap, LogOut, Megaphone, Receipt } from "lucide-react";
import { BrandMark } from "@/components/brand-mark";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";
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
  const { user, loading, logout } = useAuth();
  const router = useRouter();
  const [students, setStudents] = useState<Student[] | null>(null);

  useEffect(() => {
    if (!loading && !user) {
      router.replace("/login");
    }
  }, [loading, user, router]);

  useEffect(() => {
    if (user?.role === "orangtua") {
      api.get<{ students: Student[] }>("/api/wali/students").then(({ students }) => setStudents(students));
    }
  }, [user]);

  if (loading || !user) {
    return (
      <main className="mx-auto flex max-w-3xl flex-col gap-4 p-6">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-32 w-full" />
      </main>
    );
  }

  return (
    <div className="min-h-dvh bg-canvas">
      <header className="border-b border-border bg-card">
        <div className="mx-auto flex max-w-3xl items-center justify-between gap-4 px-6 py-3.5">
          <BrandMark />
          <div className="flex items-center gap-2">
            {user.role === "orangtua" && (
              <>
                <Link href="/informasi">
                  <Button variant="outline" size="sm">
                    <Megaphone className="size-4" />
                    Informasi
                  </Button>
                </Link>
                <Link href="/tagihan">
                  <Button variant="outline" size="sm">
                    <Receipt className="size-4" />
                    Tagihan
                  </Button>
                </Link>
              </>
            )}
            <Button variant="ghost" size="sm" onClick={logout}>
              <LogOut className="size-4" />
              Keluar
            </Button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-3xl px-6 py-8">
        <h1 className="text-xl font-bold tracking-tight">Assalamu&apos;alaikum, {user.name}</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {user.role === "orangtua"
            ? "Berikut anak Anda yang terdaftar di sekolah."
            : "Anda masuk sebagai staf. Modul admin menyusul di fase berikutnya."}
        </p>

        {user.role === "orangtua" && (
          <section className="mt-6 flex flex-col gap-3">
            {students === null && <Skeleton className="h-28 w-full" />}

            {students?.length === 0 && (
              <Card className="p-6 text-sm text-muted-foreground">
                Belum ada anak yang terhubung dengan akun ini. Hubungi tata usaha unit bila ini
                keliru.
              </Card>
            )}

            {students?.map((student) => (
              <Card key={student.ulid} className="flex flex-col gap-4 p-5">
                <div className="flex items-start justify-between gap-3">
                  <Link href={`/anak/${student.ulid}`} className="flex items-center gap-3">
                    <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-accent text-accent-foreground">
                      <GraduationCap className="size-5" />
                    </span>
                    <div>
                      <p className="font-semibold hover:text-primary">{student.nama_lengkap}</p>
                      <p className="text-sm text-muted-foreground">
                        {student.unit?.label ?? "Unit belum diatur"}
                        {student.kelas ? ` · Kelas ${student.kelas.name}` : " · Kelas belum ditentukan"}
                      </p>
                    </div>
                  </Link>
                  <Badge variant={student.status === "active" ? "good" : "warn"}>
                    {student.status === "active" ? "Aktif" : student.status}
                  </Badge>
                </div>

                <dl className="grid grid-cols-2 gap-3 border-t border-border pt-4 text-sm sm:grid-cols-3">
                  <div>
                    <dt className="text-xs text-muted-foreground">NIS</dt>
                    <dd className="tabular font-medium">{student.nis ?? "Belum terbit"}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-muted-foreground">Wali kelas</dt>
                    <dd className="font-medium">{student.kelas?.wali_kelas ?? "—"}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-muted-foreground">Tagihan</dt>
                    <dd>
                      <Link href="/tagihan" className="font-medium text-primary">
                        Lihat tagihan
                      </Link>
                    </dd>
                  </div>
                </dl>

                {student.poin && (
                  <div className="border-t border-border pt-4">
                    <p className="mb-1.5 text-xs text-muted-foreground">Poin semester ini</p>
                    <Link href={`/anak/${student.ulid}`}>
                      <PointMeter balance={student.poin.balance} threshold={student.poin.threshold} size="sm" />
                    </Link>
                  </div>
                )}
              </Card>
            ))}
          </section>
        )}
      </main>
    </div>
  );
}

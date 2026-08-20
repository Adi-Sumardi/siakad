"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Award, ChevronRight, GraduationCap, School, Sparkles, Star, Users } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";

type Classroom = {
  ulid: string;
  name: string;
  tingkat: number;
  is_homeroom: boolean;
  homeroom_teacher: string | null;
  student_count: number;
};

export default function GuruClassroomsPage() {
  const [classrooms, setClassrooms] = useState<Classroom[] | null>(null);

  useEffect(() => {
    api
      .get<{ classrooms: Classroom[] }>("/api/guru/classrooms")
      .then((d) => setClassrooms(d.classrooms))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar kelas."));
  }, []);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Portal Guru & Wali Kelas</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Kelola kedisiplinan poin siswa, input apresiasi prestasi, dan pantau rekap kelas.
          </p>
        </div>

        <Link href="/guru/prestasi">
          <Button className="gap-2 shadow-xs">
            <Award className="size-4" />
            <span>Catat Prestasi Siswa</span>
          </Button>
        </Link>
      </div>

      {classrooms === null && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Skeleton className="h-32 w-full rounded-2xl" />
          <Skeleton className="h-32 w-full rounded-2xl" />
          <Skeleton className="h-32 w-full rounded-2xl" />
        </div>
      )}

      {classrooms?.length === 0 && (
        <Card className="p-8 text-center text-sm text-muted-foreground">
          Belum ada kelas yang terdaftar di unit Anda.
        </Card>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {classrooms?.map((c) => (
          <Link key={c.ulid} href={`/guru/kelas/${c.ulid}`} className="group">
            <Card className="h-full p-5 border-border/80 group-hover:border-primary transition-all duration-200 shadow-xs group-hover:shadow-md flex flex-col justify-between">
              <div>
                <div className="flex items-start justify-between gap-2">
                  <span className="grid size-11 place-items-center rounded-xl bg-primary/10 text-primary group-hover:bg-primary group-hover:text-primary-foreground transition-colors">
                    <GraduationCap className="size-6" />
                  </span>
                  {c.is_homeroom && (
                    <Badge variant="primary" className="gap-1 text-[10px] font-bold">
                      <Star className="size-3 fill-current" />
                      <span>Wali Kelas Anda</span>
                    </Badge>
                  )}
                </div>

                <div className="mt-4">
                  <h2 className="text-lg font-bold text-foreground group-hover:text-primary transition-colors flex items-center gap-1.5">
                    <span>Kelas {c.name}</span>
                  </h2>
                  <p className="text-xs text-muted-foreground mt-0.5">
                    {c.student_count} siswa terdaftar
                    {c.homeroom_teacher && !c.is_homeroom ? ` · Wali: ${c.homeroom_teacher}` : ""}
                  </p>
                </div>
              </div>

              <div className="mt-4 pt-3 border-t border-border/60 flex items-center justify-between text-xs font-semibold text-primary">
                <span>Buka Rombel Siswa</span>
                <ChevronRight className="size-4 group-hover:translate-x-1 transition-transform" />
              </div>
            </Card>
          </Link>
        ))}
      </div>
    </div>
  );
}

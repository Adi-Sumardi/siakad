"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ChevronRight, Star, Users } from "lucide-react";
import { toast } from "sonner";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";

type Classroom = {
  ulid: string; name: string; tingkat: number; is_homeroom: boolean;
  homeroom_teacher: string | null; student_count: number;
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
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-xl font-bold tracking-tight">Kelas saya</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Semua kelas di unit Anda — kelas yang Anda walikan ditandai tersendiri.
        </p>
      </div>

      {classrooms === null && <Skeleton className="h-40 w-full" />}

      <div className="flex flex-col gap-2">
        {classrooms?.map((c) => (
          <Link key={c.ulid} href={`/guru/kelas/${c.ulid}`}>
            <Card className="flex items-center justify-between gap-3 p-4 transition-colors hover:border-primary">
              <div className="flex items-center gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-accent text-accent-foreground">
                  <Users className="size-5" />
                </span>
                <div>
                  <p className="flex items-center gap-1.5 font-semibold">
                    {c.name}
                    {c.is_homeroom && <Star className="size-3.5 fill-warn text-warn" />}
                  </p>
                  <p className="text-sm text-muted-foreground">
                    {c.student_count} siswa{c.is_homeroom ? " · Wali kelas Anda" : ""}
                  </p>
                </div>
              </div>
              <ChevronRight className="size-4 text-muted-foreground" />
            </Card>
          </Link>
        ))}

        {classrooms?.length === 0 && (
          <Card className="p-6 text-sm text-muted-foreground">Belum ada kelas di unit Anda.</Card>
        )}
      </div>
    </div>
  );
}

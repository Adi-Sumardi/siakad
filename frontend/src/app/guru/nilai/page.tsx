"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ClipboardList } from "lucide-react";
import { toast } from "sonner";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import type { TeachingAssignment } from "@/lib/types/kesiswaan";

export default function GuruNilaiPage() {
  const [assignments, setAssignments] = useState<TeachingAssignment[] | null>(null);

  useEffect(() => {
    api
      .get<{ assignments: TeachingAssignment[] }>("/api/guru/my-subjects")
      .then((d) => setAssignments(d.assignments))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar mata pelajaran."));
  }, []);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">Nilai</h1>
        <p className="text-sm text-muted-foreground mt-0.5">
          Kelas dan mata pelajaran yang Anda ampu, sesuai jadwal pelajaran.
        </p>
      </div>

      {assignments === null && (
        <div className="space-y-3">
          <Skeleton className="h-20 w-full rounded-xl" />
          <Skeleton className="h-20 w-full rounded-xl" />
        </div>
      )}

      {assignments?.length === 0 && (
        <Card className="p-8 text-center text-sm text-muted-foreground">
          Anda belum ditugaskan mengajar mata pelajaran apa pun di jadwal pelajaran.
        </Card>
      )}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {assignments?.map((a) => (
          <Link key={`${a.classroom.ulid}-${a.subject.ulid}`} href={`/guru/nilai/${a.classroom.ulid}/${a.subject.ulid}`}>
            <Card className="flex items-center gap-3 p-5 hover:border-primary/50 transition-colors">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                <ClipboardList className="size-5" />
              </span>
              <div>
                <p className="font-bold text-foreground text-sm">{a.subject.name}</p>
                <p className="text-xs text-muted-foreground">Kelas {a.classroom.name}</p>
              </div>
            </Card>
          </Link>
        ))}
      </div>
    </div>
  );
}

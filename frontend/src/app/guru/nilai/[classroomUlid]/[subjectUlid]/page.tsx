"use client";

import { use, useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { GRADE_CATEGORY_LABEL, type GradeCategory, type GradeRosterEntry } from "@/lib/types/kesiswaan";

type RosterResponse = {
  classroom: { ulid: string; name: string };
  subject: { ulid: string; name: string };
  students: GradeRosterEntry[];
};

const CATEGORIES: GradeCategory[] = ["tugas", "uts", "uas"];

export default function GuruGradeEntryPage({
  params,
}: {
  params: Promise<{ classroomUlid: string; subjectUlid: string }>;
}) {
  const { classroomUlid, subjectUlid } = use(params);

  const [roster, setRoster] = useState<RosterResponse | null>(null);
  const [category, setCategory] = useState<GradeCategory>("tugas");
  const [scores, setScores] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);

  function load() {
    api
      .get<RosterResponse>(`/api/guru/classrooms/${classroomUlid}/subjects/${subjectUlid}/grades`)
      .then((d) => setRoster(d))
      .catch((err) => setLoadError(err instanceof ApiError ? err.message : "Gagal memuat data nilai."));
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [classroomUlid, subjectUlid]);

  useEffect(() => {
    if (!roster) return;
    const next: Record<string, string> = {};
    for (const s of roster.students) {
      const value = s[category];
      next[s.ulid] = value === null ? "" : String(value);
    }
    setScores(next);
  }, [roster, category]);

  async function submit() {
    if (!roster) return;
    setSubmitting(true);

    const entries = roster.students
      .filter((s) => scores[s.ulid] !== undefined && scores[s.ulid] !== "")
      .map((s) => ({ student_ulid: s.ulid, score: Number(scores[s.ulid]) }));

    if (entries.length === 0) {
      toast.error("Isi nilai untuk setidaknya satu siswa.");
      setSubmitting(false);
      return;
    }

    try {
      const { recorded } = await api.post<{ recorded: number }>(
        `/api/guru/classrooms/${classroomUlid}/subjects/${subjectUlid}/grades`,
        { category, entries },
      );
      toast.success(`Nilai ${GRADE_CATEGORY_LABEL[category]} tersimpan untuk ${recorded} siswa.`);
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyimpan nilai.");
    } finally {
      setSubmitting(false);
    }
  }

  if (loadError) {
    return (
      <div className="space-y-4">
        <Link href="/guru/nilai" className="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" />
          <span>Kembali ke Nilai</span>
        </Link>
        <Card className="p-6 text-sm text-destructive">{loadError}</Card>
      </div>
    );
  }

  return (
    <div className="space-y-6 pb-28">
      <div>
        <Link href="/guru/nilai" className="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" />
          <span>Kembali ke Nilai</span>
        </Link>
        <h1 className="text-2xl font-bold tracking-tight text-foreground mt-2">
          {roster ? `${roster.subject.name} — Kelas ${roster.classroom.name}` : "Memuat…"}
        </h1>
      </div>

      <div className="flex gap-2">
        {CATEGORIES.map((c) => (
          <button
            key={c}
            onClick={() => setCategory(c)}
            className={`rounded-lg px-4 py-2 text-sm font-semibold transition-colors ${
              category === c ? "bg-primary text-primary-foreground" : "bg-muted/40 text-muted-foreground hover:bg-muted"
            }`}
          >
            {GRADE_CATEGORY_LABEL[c]}
          </button>
        ))}
      </div>

      {roster === null && (
        <div className="space-y-2">
          <Skeleton className="h-14 w-full rounded-xl" />
          <Skeleton className="h-14 w-full rounded-xl" />
        </div>
      )}

      <div className="flex flex-col gap-2">
        {roster?.students.map((s) => (
          <Card key={s.ulid} className="flex items-center justify-between gap-3 p-4">
            <div>
              <p className="font-semibold text-foreground text-sm">{s.nama_lengkap}</p>
              <p className="text-xs text-muted-foreground">NIS: {s.nis ?? "—"}</p>
            </div>
            <Input
              type="number"
              min={0}
              max={100}
              placeholder="0-100"
              value={scores[s.ulid] ?? ""}
              onChange={(e) => setScores((prev) => ({ ...prev, [s.ulid]: e.target.value }))}
              className="w-24 text-center"
            />
          </Card>
        ))}
      </div>

      {roster && roster.students.length > 0 && (
        <div className="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-card/95 backdrop-blur-md shadow-2xl">
          <div className="mx-auto flex max-w-3xl items-center justify-between px-4 py-3.5">
            <p className="text-sm text-muted-foreground">
              Menyimpan nilai {GRADE_CATEGORY_LABEL[category]} untuk kelas ini.
            </p>
            <Button onClick={submit} disabled={submitting} className="font-bold">
              {submitting ? "Menyimpan…" : "Simpan Nilai"}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

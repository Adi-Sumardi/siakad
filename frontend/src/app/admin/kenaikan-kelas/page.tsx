"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";

type ClassroomOption = { ulid: string; name: string; tingkat: number; school_unit: { code: string; label: string } };
type AcademicYearOption = { ulid: string; year: string; is_active: boolean };
type RosterStudent = { ulid: string; nama_lengkap: string; nis: string | null };
type Outcome = "promoted" | "repeated" | "graduated" | "left";

const OUTCOME_LABEL: Record<Outcome, string> = {
  promoted: "Naik kelas",
  repeated: "Tinggal kelas",
  graduated: "Lulus",
  left: "Keluar/Pindah",
};

export default function KenaikanKelasPage() {
  const [classrooms, setClassrooms] = useState<ClassroomOption[] | null>(null);
  const [years, setYears] = useState<AcademicYearOption[]>([]);
  const [sourceClassroom, setSourceClassroom] = useState("");
  const [targetYear, setTargetYear] = useState("");

  const [roster, setRoster] = useState<RosterStudent[] | null>(null);
  const [outcomes, setOutcomes] = useState<Record<string, Outcome>>({});
  const [targetClassrooms, setTargetClassrooms] = useState<Record<string, string>>({});

  const [promotedTargets, setPromotedTargets] = useState<{ same_unit: ClassroomOption[]; other: ClassroomOption[] } | null>(null);
  const [repeatedTargets, setRepeatedTargets] = useState<{ same_unit: ClassroomOption[]; other: ClassroomOption[] } | null>(null);
  const [bulkTarget, setBulkTarget] = useState("");
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    api.get<{ classrooms: ClassroomOption[] }>("/api/admin/classrooms").then((d) => setClassrooms(d.classrooms));
    api.get<{ academic_years: AcademicYearOption[] }>("/api/admin/academic-years").then((d) => setYears(d.academic_years));
  }, []);

  useEffect(() => {
    if (!sourceClassroom) {
      setRoster(null);
      return;
    }
    setRoster(null);
    api.get<{ students: RosterStudent[] }>(`/api/admin/classrooms/${sourceClassroom}/promotion-roster`)
      .then((d) => {
        setRoster(d.students);
        const defaults: Record<string, Outcome> = {};
        d.students.forEach((s) => { defaults[s.ulid] = "promoted"; });
        setOutcomes(defaults);
        setTargetClassrooms({});
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar siswa."));
  }, [sourceClassroom]);

  useEffect(() => {
    if (!sourceClassroom || !targetYear) {
      setPromotedTargets(null);
      setRepeatedTargets(null);
      return;
    }
    api.get<{ same_unit: ClassroomOption[]; other: ClassroomOption[] }>(
      `/api/admin/classrooms/${sourceClassroom}/promotion-targets?academic_year_ulid=${targetYear}&outcome=promoted`,
    ).then(setPromotedTargets).catch(() => setPromotedTargets({ same_unit: [], other: [] }));

    api.get<{ same_unit: ClassroomOption[]; other: ClassroomOption[] }>(
      `/api/admin/classrooms/${sourceClassroom}/promotion-targets?academic_year_ulid=${targetYear}&outcome=repeated`,
    ).then(setRepeatedTargets).catch(() => setRepeatedTargets({ same_unit: [], other: [] }));

    setBulkTarget("");
  }, [sourceClassroom, targetYear]);

  function targetsFor(outcome: Outcome) {
    const groups = outcome === "repeated" ? repeatedTargets : promotedTargets;
    return groups ? [...groups.same_unit, ...groups.other] : [];
  }

  function applyBulkTarget() {
    if (!bulkTarget || !roster) return;
    setTargetClassrooms((prev) => {
      const next = { ...prev };
      roster.forEach((s) => {
        if (outcomes[s.ulid] === "promoted") next[s.ulid] = bulkTarget;
      });
      return next;
    });
    toast.success("Kelas tujuan diterapkan ke semua siswa yang naik kelas.");
  }

  async function submit() {
    if (!roster || !targetYear) return;
    setSubmitting(true);
    try {
      const entries = roster.map((s) => ({
        student_ulid: s.ulid,
        outcome: outcomes[s.ulid],
        target_classroom_ulid: ["promoted", "repeated"].includes(outcomes[s.ulid]) ? targetClassrooms[s.ulid] : undefined,
      }));

      const result = await api.post<{ promoted: number }>(`/api/admin/classrooms/${sourceClassroom}/promote`, {
        academic_year_ulid: targetYear,
        entries,
      });

      toast.success(`${result.promoted} siswa berhasil diproses.`);
      setRoster(null);
      setSourceClassroom("");
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menjalankan kenaikan kelas.");
    } finally {
      setSubmitting(false);
    }
  }

  const readyToSubmit = roster !== null && roster.length > 0 && targetYear
    && roster.every((s) => !["promoted", "repeated"].includes(outcomes[s.ulid]) || targetClassrooms[s.ulid]);

  return (
    <div className="flex flex-col gap-5 pb-24">
      <div>
        <h1 className="text-xl font-bold tracking-tight">Kenaikan Kelas</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Pindahkan satu rombongan kelas ke tahun ajaran berikutnya sekaligus - naik kelas, tinggal kelas, lulus, atau keluar.
        </p>
      </div>

      <Card className="flex flex-wrap items-end gap-3 p-5">
        <div className="flex flex-col gap-1.5">
          <Label>Kelas sumber (tahun ajaran berjalan)</Label>
          {classrooms === null ? (
            <Skeleton className="h-10 w-64" />
          ) : (
            <select
              value={sourceClassroom}
              onChange={(e) => setSourceClassroom(e.target.value)}
              className="h-10 w-64 rounded-lg border border-input bg-card px-3 text-sm"
            >
              <option value="">Pilih kelas</option>
              {classrooms.map((c) => (
                <option key={c.ulid} value={c.ulid}>{c.school_unit.label} · {c.name} (tingkat {c.tingkat})</option>
              ))}
            </select>
          )}
        </div>
        <div className="flex flex-col gap-1.5">
          <Label>Tahun ajaran tujuan</Label>
          <select
            value={targetYear}
            onChange={(e) => setTargetYear(e.target.value)}
            className="h-10 w-40 rounded-lg border border-input bg-card px-3 text-sm"
          >
            <option value="">Pilih tahun</option>
            {years.map((y) => <option key={y.ulid} value={y.ulid}>{y.year}</option>)}
          </select>
        </div>
      </Card>

      {!targetYear && sourceClassroom && (
        <p className="text-sm text-muted-foreground">Pilih tahun ajaran tujuan untuk melihat kelas yang bisa dipilih.</p>
      )}

      {sourceClassroom && targetYear && (
        <Card className="flex flex-wrap items-end gap-3 p-5">
          <div className="flex flex-col gap-1.5">
            <Label>Terapkan kelas tujuan ke semua yang "Naik kelas"</Label>
            <select
              value={bulkTarget}
              onChange={(e) => setBulkTarget(e.target.value)}
              className="h-10 w-64 rounded-lg border border-input bg-card px-3 text-sm"
            >
              <option value="">Pilih kelas tujuan</option>
              {promotedTargets?.same_unit.map((c) => (
                <option key={c.ulid} value={c.ulid}>{c.school_unit.label} · {c.name} (unit sendiri)</option>
              ))}
              {promotedTargets?.other.map((c) => (
                <option key={c.ulid} value={c.ulid}>{c.school_unit.label} · {c.name}</option>
              ))}
            </select>
          </div>
          <Button type="button" variant="outline" onClick={applyBulkTarget} disabled={!bulkTarget}>
            Terapkan ke Semua
          </Button>
        </Card>
      )}

      {roster === null && sourceClassroom && <Skeleton className="h-64 w-full" />}

      {roster !== null && (
        <div className="flex flex-col gap-2">
          {roster.length === 0 && <p className="text-sm text-muted-foreground">Tidak ada siswa aktif di kelas ini.</p>}
          {roster.map((s) => {
            const outcome = outcomes[s.ulid];
            const needsTarget = outcome === "promoted" || outcome === "repeated";

            return (
              <Card key={s.ulid} className="flex flex-wrap items-center justify-between gap-3 p-4">
                <div>
                  <p className="font-medium">{s.nama_lengkap}</p>
                  <p className="text-sm text-muted-foreground">{s.nis ?? "-"}</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <select
                    value={outcome}
                    onChange={(e) => setOutcomes((prev) => ({ ...prev, [s.ulid]: e.target.value as Outcome }))}
                    className="h-9 rounded-lg border border-input bg-card px-2 text-sm"
                  >
                    {(Object.keys(OUTCOME_LABEL) as Outcome[]).map((o) => (
                      <option key={o} value={o}>{OUTCOME_LABEL[o]}</option>
                    ))}
                  </select>
                  {needsTarget && (
                    <select
                      value={targetClassrooms[s.ulid] ?? ""}
                      onChange={(e) => setTargetClassrooms((prev) => ({ ...prev, [s.ulid]: e.target.value }))}
                      className="h-9 w-56 rounded-lg border border-input bg-card px-2 text-sm"
                    >
                      <option value="">Pilih kelas tujuan</option>
                      {targetsFor(outcome).map((c) => (
                        <option key={c.ulid} value={c.ulid}>{c.school_unit.label} · {c.name}</option>
                      ))}
                    </select>
                  )}
                </div>
              </Card>
            );
          })}
        </div>
      )}

      {roster !== null && roster.length > 0 && (
        <div className="fixed inset-x-0 bottom-0 border-t bg-card/95 px-6 py-4 backdrop-blur-sm">
          <div className="mx-auto flex max-w-5xl items-center justify-between">
            <p className="text-sm text-muted-foreground">
              {roster.length} siswa akan diproses ke tahun ajaran {years.find((y) => y.ulid === targetYear)?.year}.
            </p>
            <Button onClick={submit} disabled={!readyToSubmit || submitting}>
              {submitting ? "Memproses…" : "Jalankan Kenaikan Kelas"}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

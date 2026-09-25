"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";
import { ArrowRight, Users } from "lucide-react";
import { useAuth } from "@/lib/auth/auth-context";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { JenjangSelect } from "@/components/ui/jenjang-select";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { jenjangEntry, jenjangSortIndex, keyForClassroom, matchesClassroom } from "@/lib/jenjang";

type ClassroomOption = {
  ulid: string;
  name: string;
  tingkat: number;
  school_unit: { code: string; label: string; jenjang_group?: string | null };
  academic_year?: string | null;
  active_student_count?: number;
};
type AcademicYearOption = { ulid: string; year: string; is_active: boolean };
type RosterStudent = {
  ulid: string;
  nama_lengkap: string;
  nis: string | null;
  /** Outstanding bills travel with the student - shown so the operator
      knows before moving a debtor cohort (audit T49-c). */
  has_open_bills?: boolean;
};
type Outcome = "promoted" | "repeated" | "graduated" | "left";

const OUTCOME_LABEL: Record<Outcome, string> = {
  promoted: "Naik kelas",
  repeated: "Tinggal kelas",
  graduated: "Lulus",
  left: "Keluar/Pindah",
};

export default function KenaikanKelasPage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [classrooms, setClassrooms] = useState<ClassroomOption[] | null>(null);
  const [years, setYears] = useState<AcademicYearOption[]>([]);
  const [sourceClassroom, setSourceClassroom] = useState("");
  const [targetYear, setTargetYear] = useState("");
  // The per-class table's narrowing (Poin 3): unit + jenjang, so a central
  // admin works one campus at a time instead of every unit in one flat
  // list. Promotion itself is always per source classroom, so a filtered
  // view cannot touch another unit's students by construction.
  const [unitFilter, setUnitFilter] = useState("");
  const [jenjangFilter, setJenjangFilter] = useState("");
  const [unitOptions, setUnitOptions] = useState<{ code: string; label: string }[]>([]);

  const [roster, setRoster] = useState<RosterStudent[] | null>(null);
  const [outcomes, setOutcomes] = useState<Record<string, Outcome>>({});
  const [targetClassrooms, setTargetClassrooms] = useState<Record<string, string>>({});

  const [promotedTargets, setPromotedTargets] = useState<{ same_unit: ClassroomOption[]; other: ClassroomOption[] } | null>(null);
  const [repeatedTargets, setRepeatedTargets] = useState<{ same_unit: ClassroomOption[]; other: ClassroomOption[] } | null>(null);
  const [bulkTarget, setBulkTarget] = useState("");
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    // Source picker stays inside the ACTIVE academic year (audit T63-a):
    // unfiltered, the picker showed every year's classrooms with identical
    // labels, and picking an old-year source sailed right past the
    // target-year guard (only target <= source is refused - the reverse
    // re-closed a closed year and re-issued the current one).
    api
      .get<{ academic_years: AcademicYearOption[] }>("/api/admin/academic-years")
      .then((d) => {
        setYears(d.academic_years);
        const active = d.academic_years.find((y) => y.is_active);
        const query = active ? `?academic_year_ulid=${active.ulid}` : "";
        return api.get<{ classrooms: ClassroomOption[] }>(`/api/admin/classrooms${query}`);
      })
      .then((d) => setClassrooms(d.classrooms));

    if (isCentral) {
      api.get<{ school_units: { code: string; label: string }[] }>("/api/admin/school-units")
        .then((d) => setUnitOptions(d.school_units))
        .catch(() => {});
    }
  }, [isCentral]);

  // No sync reset-to-null inside the effects: "empty" is derived during
  // render from the selectors (a roster without a source class, target
  // options without a target year, render nothing), and a switch keeps the
  // previous rows until the new ones land - no setState ever runs
  // synchronously from an effect.
  useEffect(() => {
    if (!sourceClassroom) return;

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
    if (!sourceClassroom || !targetYear) return;

    const url = (outcome: Outcome) =>
      `/api/admin/classrooms/${sourceClassroom}/promotion-targets?academic_year_ulid=${targetYear}&outcome=${outcome}`;

    Promise.all([
      api.get<{ same_unit: ClassroomOption[]; other: ClassroomOption[] }>(url("promoted")),
      api.get<{ same_unit: ClassroomOption[]; other: ClassroomOption[] }>(url("repeated")),
    ])
      .then(([promoted, repeated]) => {
        setPromotedTargets(promoted);
        setRepeatedTargets(repeated);
        setBulkTarget("");
      })
      .catch(() => {
        setPromotedTargets({ same_unit: [], other: [] });
        setRepeatedTargets({ same_unit: [], other: [] });
      });
  }, [sourceClassroom, targetYear]);

  function targetsFor(outcome: Outcome) {
    if (!targetYear) return [];
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

  async function undoPromotion() {
    if (!sourceClassroom || submitting) return;
    if (!window.confirm(
      "Batalkan promosi yang sudah dijalankan untuk kelas ini? Enrollment tahun tujuan dihapus dan siswa dikembalikan ke kelas ini. Siswa yang sudah punya nilai/tagihan di tahun tujuan dilewati (disebut di hasil).",
    )) {
      return;
    }

    setSubmitting(true);
    try {
      const result = await api.post<{ undone: number; skipped: string[]; message: string }>(
        `/api/admin/classrooms/${sourceClassroom}/promotion-undo`,
      );
      toast.success(result.message);
      setRoster(null);
      setSourceClassroom("");
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal membatalkan promosi.");
    } finally {
      setSubmitting(false);
    }
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

  // Poin 3's per-class table: the active-year classrooms narrowed by unit +
  // jenjang, in ladder order, with each row's active roster size.
  const tableRows = (classrooms ?? [])
    .filter((c) => !unitFilter || c.school_unit.code === unitFilter)
    .filter((c) => matchesClassroom(c, jenjangFilter || null))
    .slice()
    .sort((a, b) => jenjangSortIndex(a) - jenjangSortIndex(b) || a.name.localeCompare(b.name));

  return (
    <div className="flex flex-col gap-5 pb-24">
      <div>
        <h1 className="text-xl font-bold tracking-tight">Kenaikan Kelas</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Pindahkan satu rombongan kelas ke tahun ajaran berikutnya sekaligus - naik kelas, tinggal kelas, lulus, atau keluar.
        </p>
      </div>

      {/* Poin 3: filter per unit + jenjang di atas tabel kelas. */}
      <Card className="flex flex-wrap items-end gap-3 p-5">
        {isCentral && (
          <div className="flex flex-col gap-1.5">
            <Label className="text-xs">Unit</Label>
            <select
              value={unitFilter}
              onChange={(e) => setUnitFilter(e.target.value)}
              className="h-10 w-52 rounded-lg border border-input bg-card px-3 text-sm"
            >
              <option value="">Semua Unit</option>
              {unitOptions.map((u) => (
                <option key={u.code} value={u.code}>{u.label}</option>
              ))}
            </select>
          </div>
        )}
        <div className="flex flex-col gap-1.5">
          <Label className="text-xs">Jenjang</Label>
          <JenjangSelect
            value={jenjangFilter}
            onChange={setJenjangFilter}
            className="h-10 w-56 rounded-lg border border-input bg-card px-3 text-sm"
          />
        </div>
      </Card>

      <Card className="overflow-x-auto p-0">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
              <th className="px-5 py-3.5">Jenjang</th>
              <th className="px-5 py-3.5">Nama Kelas</th>
              <th className="px-5 py-3.5">Unit</th>
              <th className="px-5 py-3.5">Jumlah Siswa</th>
              <th className="px-5 py-3.5">Tahun Ajaran</th>
              <th className="px-5 py-3.5 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody>
            {classrooms === null && (
              <tr>
                <td colSpan={6} className="px-5 py-10 text-center text-sm text-muted-foreground">
                  <Skeleton className="mx-auto h-6 w-40" />
                </td>
              </tr>
            )}
            {classrooms !== null && tableRows.length === 0 && (
              <tr>
                <td colSpan={6} className="px-5 py-10 text-center text-sm text-muted-foreground">
                  Tidak ada kelas aktif untuk filter ini.
                </td>
              </tr>
            )}
            {tableRows.map((c) => {
              const jenjang = jenjangEntry(keyForClassroom(c) ?? "");

              return (
                <tr key={c.ulid} className="border-b border-border/60 last:border-0 hover:bg-muted/20 transition-colors">
                  <td className="px-5 py-4 font-medium">{jenjang?.label ?? "—"}</td>
                  <td className="px-5 py-4 font-bold">{c.name}</td>
                  <td className="px-5 py-4 text-muted-foreground">{c.school_unit.label}</td>
                  <td className="px-5 py-4">
                    <span className="inline-flex items-center gap-1.5 font-semibold">
                      <Users className="size-3.5 text-muted-foreground" />
                      {c.active_student_count ?? 0}
                    </span>
                  </td>
                  <td className="px-5 py-4 text-muted-foreground">{c.academic_year ?? "—"}</td>
                  <td className="px-5 py-4 text-right">
                    <Button
                      size="sm"
                      variant="outline"
                      className="gap-1.5"
                      onClick={() => {
                        setSourceClassroom(c.ulid);
                        window.scrollTo({ top: 0, behavior: "smooth" });
                      }}
                    >
                      <ArrowRight className="size-3.5" />
                      Proses
                    </Button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </Card>

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
              {classrooms
                .filter((c) => !unitFilter || c.school_unit.code === unitFilter)
                .filter((c) => matchesClassroom(c, jenjangFilter || null))
                .map((c) => (
                  <option key={c.ulid} value={c.ulid}>
                    {c.school_unit.label} · {c.name} (tingkat {c.tingkat}
                    {c.academic_year ? ` · TA ${c.academic_year}` : ""})
                  </option>
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
            <Label>Terapkan kelas tujuan ke semua yang &quot;Naik kelas&quot;</Label>
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
          <Button
            type="button"
            variant="ghost"
            className="ml-auto text-bad hover:text-bad"
            onClick={undoPromotion}
            disabled={submitting}
          >
            Batalkan promosi kelas ini
          </Button>
        </Card>
      )}

      {sourceClassroom && roster === null && <Skeleton className="h-64 w-full" />}

      {sourceClassroom && roster !== null && (
        <div className="flex flex-col gap-2">
          {roster.length === 0 && <p className="text-sm text-muted-foreground">Tidak ada siswa aktif di kelas ini.</p>}
          {roster.map((s) => {
            const outcome = outcomes[s.ulid];
            const needsTarget = outcome === "promoted" || outcome === "repeated";

            return (
              <Card key={s.ulid} className="flex flex-wrap items-center justify-between gap-3 p-4">
                <div>
                  <p className="font-medium">
                    {s.nama_lengkap}
                    {s.has_open_bills && (
                      <span className="ml-2 rounded-full border border-warn/40 bg-warn-soft px-2 py-0.5 text-[11px] font-semibold text-warn">
                        Ada tunggakan
                      </span>
                    )}
                  </p>
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

      {sourceClassroom && roster !== null && roster.length > 0 && (
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

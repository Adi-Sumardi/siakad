"use client";

import { use, useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { ArrowLeft, Loader2, Pencil, Plus, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { tanggal } from "@/lib/format";

type Assignment = { ulid: string; title: string; assigned_on: string | null };

type SheetStudent = {
  ulid: string;
  nama_lengkap: string;
  nis: string | null;
  tugas_scores: Record<string, number>;
  tugas: number | null;
  uts: number | null;
  uas: number | null;
  final: number | null;
};

type Sheet = {
  classroom: { ulid: string; name: string };
  subject: { ulid: string; name: string };
  term: { ulid: string; name: string };
  editable: boolean;
  weights: { tugas: number; uts: number; uas: number };
  kkm: number;
  assignments: Assignment[];
  students: SheetStudent[];
};

/** A cell is an assignment ULID, "uts" or "uas"; edits hold the raw typed text. */
type Edits = Record<string, Record<string, string>>;

function original(student: SheetStudent, key: string): string {
  const value = key === "uts" || key === "uas" ? student[key] : student.tugas_scores[key];
  return value === null || value === undefined ? "" : String(value);
}

function parseScore(text: string): number | null | "invalid" {
  if (text.trim() === "") return null;
  const n = Number(text.replace(",", "."));
  return Number.isFinite(n) && n >= 0 && n <= 100 ? n : "invalid";
}

function fmt(n: number | null): string {
  return n === null ? "—" : Number.isInteger(n) ? String(n) : n.toFixed(2);
}

export default function GuruGradeSheetPage({
  params,
}: {
  params: Promise<{ classroomUlid: string; subjectUlid: string }>;
}) {
  const { classroomUlid, subjectUlid } = use(params);
  const base = `/api/guru/classrooms/${classroomUlid}/subjects/${subjectUlid}`;

  const [sheet, setSheet] = useState<Sheet | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [edits, setEdits] = useState<Edits>({});
  const [saving, setSaving] = useState(false);

  // The add/rename form: null = closed, "" = adding, a ULID = renaming it.
  const [formFor, setFormFor] = useState<string | null>(null);
  const [formTitle, setFormTitle] = useState("");
  const [formDate, setFormDate] = useState("");
  const [formBusy, setFormBusy] = useState(false);

  const load = useCallback(() => {
    return api
      .get<Sheet>(`${base}/grades`)
      .then(setSheet)
      .catch((err) => setLoadError(err instanceof ApiError ? err.message : "Gagal memuat data nilai."));
  }, [base]);

  useEffect(() => {
    void load();
  }, [load]);

  const value = (student: SheetStudent, key: string) => edits[student.ulid]?.[key] ?? original(student, key);

  // Only cells whose text actually differs from what is stored.
  const dirty = useMemo(() => {
    if (!sheet) return [] as Array<{ student: SheetStudent; key: string; text: string }>;
    const out: Array<{ student: SheetStudent; key: string; text: string }> = [];
    for (const s of sheet.students) {
      for (const [key, text] of Object.entries(edits[s.ulid] ?? {})) {
        if (text.trim() !== original(s, key)) out.push({ student: s, key, text });
      }
    }
    return out;
  }, [edits, sheet]);

  const invalidCount = dirty.filter((d) => parseScore(d.text) === "invalid").length;

  // Leaving with typed-but-unsaved scores is the costly mistake here.
  useEffect(() => {
    if (dirty.length === 0) return;
    const warn = (e: BeforeUnloadEvent) => e.preventDefault();
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty.length]);

  /** The row's live tugas average / final, from what is on screen now (typed edits included). */
  function live(student: SheetStudent): { tugas: number | null; final: number | null } {
    if (!sheet) return { tugas: null, final: null };

    let tugas: number | null;
    if (sheet.assignments.length > 0) {
      const scores = sheet.assignments
        .map((a) => parseScore(value(student, a.ulid)))
        .filter((n): n is number => typeof n === "number");
      tugas = scores.length ? Math.round((scores.reduce((a, b) => a + b, 0) / scores.length) * 100) / 100 : null;
    } else {
      tugas = student.tugas;
    }

    const uts = parseScore(value(student, "uts"));
    const uas = parseScore(value(student, "uas"));
    if (tugas === null || typeof uts !== "number" || typeof uas !== "number") return { tugas, final: null };

    const w = sheet.weights;
    return { tugas, final: Math.round((tugas * w.tugas + uts * w.uts + uas * w.uas) * 100) / 100 };
  }

  function setCell(studentUlid: string, key: string, text: string) {
    setEdits((prev) => ({ ...prev, [studentUlid]: { ...prev[studentUlid], [key]: text } }));
  }

  /** Enter jumps to the same column one row down - typing a whole column without the mouse. */
  function onCellKey(e: React.KeyboardEvent<HTMLInputElement>, row: number, key: string) {
    if (e.key !== "Enter") return;
    e.preventDefault();
    const next = document.querySelector<HTMLInputElement>(`[data-cell="${row + (e.shiftKey ? -1 : 1)}:${key}"]`);
    next?.focus();
    next?.select();
  }

  async function save() {
    if (!sheet || dirty.length === 0 || invalidCount > 0) return;
    setSaving(true);

    const byStudent = new Map<string, { student_ulid: string; tugas?: Record<string, number | null>; uts?: number | null; uas?: number | null }>();
    for (const d of dirty) {
      const entry = byStudent.get(d.student.ulid) ?? { student_ulid: d.student.ulid };
      const score = parseScore(d.text) as number | null;
      if (d.key === "uts" || d.key === "uas") entry[d.key] = score;
      else entry.tugas = { ...entry.tugas, [d.key]: score };
      byStudent.set(d.student.ulid, entry);
    }

    try {
      const res = await api.put<{ saved: number; student_count: number }>(`${base}/grades/sheet`, {
        entries: [...byStudent.values()],
      });
      toast.success(`${res.saved} nilai tersimpan untuk ${res.student_count} siswa.`);
      setEdits({});
      await load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyimpan nilai.");
    } finally {
      setSaving(false);
    }
  }

  function openForm(assignment?: Assignment) {
    setFormFor(assignment?.ulid ?? "");
    setFormTitle(assignment?.title ?? `Tugas ${(sheet?.assignments.length ?? 0) + 1}`);
    setFormDate(assignment?.assigned_on ?? "");
  }

  async function submitForm(e: React.FormEvent) {
    e.preventDefault();
    if (formFor === null) return;
    setFormBusy(true);
    const body = { title: formTitle.trim(), assigned_on: formDate || null };

    try {
      if (formFor === "") {
        await api.post(`${base}/assignments`, body);
        toast.success(`${body.title} ditambahkan. Isi nilainya di kolom baru.`);
      } else {
        await api.patch(`/api/guru/grade-assignments/${formFor}`, body);
        toast.success("Tugas diperbarui.");
      }
      setFormFor(null);
      await load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyimpan tugas.");
    } finally {
      setFormBusy(false);
    }
  }

  async function removeAssignment(a: Assignment) {
    const filled = sheet?.students.filter((s) => s.tugas_scores[a.ulid] !== undefined).length ?? 0;
    const ok = window.confirm(
      filled > 0
        ? `Hapus "${a.title}" beserta ${filled} nilai di dalamnya? Rata-rata tugas siswa akan dihitung ulang. Tindakan ini tidak bisa dibatalkan.`
        : `Hapus kolom "${a.title}"?`,
    );
    if (!ok) return;

    try {
      await api.delete(`/api/guru/grade-assignments/${a.ulid}`);
      setEdits((prev) => {
        const next: Edits = {};
        for (const [s, cells] of Object.entries(prev)) {
          next[s] = Object.fromEntries(Object.entries(cells).filter(([key]) => key !== a.ulid));
        }
        return next;
      });
      toast.success(`"${a.title}" dihapus.`);
      await load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus tugas.");
    }
  }

  const backLink = (
    <Link href="/guru/nilai" className="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground">
      <ArrowLeft className="size-4" />
      <span>Kembali ke Nilai</span>
    </Link>
  );

  if (loadError) {
    return (
      <div className="space-y-4">
        {backLink}
        <Card className="p-6 text-sm text-destructive">{loadError}</Card>
      </div>
    );
  }

  if (!sheet) {
    return (
      <div className="space-y-4">
        {backLink}
        <Skeleton className="h-8 w-72" />
        <Skeleton className="h-64 w-full rounded-xl" />
      </div>
    );
  }

  const pct = (n: number) => `${Math.round(n * 100)}%`;
  const editable = sheet.editable;
  const inputClass = "h-9 w-[4.5rem] px-1 text-center tabular-nums";

  return (
    <div className="space-y-5">
      <div>
        {backLink}
        <h1 className="mt-2 text-2xl font-bold tracking-tight text-foreground">
          {sheet.subject.name} — Kelas {sheet.classroom.name}
        </h1>
        <p className="mt-0.5 text-sm text-muted-foreground">
          Semester {sheet.term.name} · Bobot nilai akhir: Tugas {pct(sheet.weights.tugas)}, UTS {pct(sheet.weights.uts)}, UAS{" "}
          {pct(sheet.weights.uas)} · KKM {sheet.kkm}
        </p>
      </div>

      {!editable && (
        <Card className="border-warn/40 bg-warn/5 p-4 text-sm text-foreground">
          Semester aktif bukan milik tahun ajaran kelas ini, jadi nilai hanya bisa dilihat.
        </Card>
      )}

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-xs text-muted-foreground">
          Nilai tugas = rata-rata dari semua tugas yang sudah dinilai. Tekan <kbd className="rounded border px-1">Enter</kbd> untuk
          pindah ke siswa berikutnya.
        </p>
        {editable && formFor === null && (
          <Button size="sm" variant="outline" className="gap-1.5" onClick={() => openForm()}>
            <Plus className="size-4" />
            Tambah Tugas
          </Button>
        )}
      </div>

      {formFor !== null && (
        <Card className="p-4">
          <form onSubmit={submitForm} className="flex flex-wrap items-end gap-3">
            <div className="grid gap-1.5">
              <Label htmlFor="assignment-title">Nama tugas</Label>
              <Input
                id="assignment-title"
                value={formTitle}
                onChange={(e) => setFormTitle(e.target.value)}
                maxLength={100}
                required
                autoFocus
                className="w-64"
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="assignment-date">Tanggal (opsional)</Label>
              <Input id="assignment-date" type="date" value={formDate} onChange={(e) => setFormDate(e.target.value)} className="w-44" />
            </div>
            <div className="flex gap-2">
              <Button type="submit" size="sm" disabled={formBusy || formTitle.trim() === ""}>
                {formBusy && <Loader2 className="size-4 animate-spin" />}
                {formFor === "" ? "Tambah" : "Simpan"}
              </Button>
              <Button type="button" size="sm" variant="ghost" onClick={() => setFormFor(null)}>
                Batal
              </Button>
            </div>
          </form>
        </Card>
      )}

      {sheet.students.length === 0 ? (
        <Card className="p-8 text-center text-sm text-muted-foreground">Belum ada siswa aktif di kelas ini.</Card>
      ) : (
        <Card className="overflow-hidden p-0">
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr className="border-b border-border bg-muted/40 text-left text-xs font-semibold text-muted-foreground">
                  <th className="sticky left-0 z-10 min-w-36 bg-muted px-3 py-2.5 sm:min-w-48">Siswa</th>
                  {sheet.assignments.map((a) => (
                    <th key={a.ulid} className="px-1.5 py-2 text-center align-bottom">
                      <div className="mx-auto flex w-[5.5rem] flex-col items-center gap-0.5">
                        <span className="line-clamp-2 text-foreground" title={a.title}>
                          {a.title}
                        </span>
                        {a.assigned_on && <span className="font-normal">{tanggal(a.assigned_on)}</span>}
                        {editable && (
                          <span className="flex gap-0.5">
                            <button
                              type="button"
                              onClick={() => openForm(a)}
                              className="rounded p-1 hover:bg-accent hover:text-foreground"
                              aria-label={`Ubah ${a.title}`}
                            >
                              <Pencil className="size-3" />
                            </button>
                            <button
                              type="button"
                              onClick={() => removeAssignment(a)}
                              className="rounded p-1 hover:bg-destructive/10 hover:text-destructive"
                              aria-label={`Hapus ${a.title}`}
                            >
                              <Trash2 className="size-3" />
                            </button>
                          </span>
                        )}
                      </div>
                    </th>
                  ))}
                  {sheet.assignments.length === 0 && (
                    <th className="px-3 py-2.5 text-center">
                      Tugas
                      {editable && <span className="block font-normal">belum ada daftar tugas</span>}
                    </th>
                  )}
                  {sheet.assignments.length > 0 && <th className="bg-muted/60 px-3 py-2.5 text-center">Rata-rata Tugas</th>}
                  <th className="px-1.5 py-2.5 text-center">UTS</th>
                  <th className="px-1.5 py-2.5 text-center">UAS</th>
                  <th className="bg-muted/60 px-3 py-2.5 text-center">Nilai Akhir</th>
                </tr>
              </thead>
              <tbody>
                {sheet.students.map((s, row) => {
                  const calc = live(s);
                  const below = calc.final !== null && calc.final < sheet.kkm;

                  const cell = (key: string, label: string) => {
                    const text = value(s, key);
                    const changed = text.trim() !== original(s, key);
                    const invalid = parseScore(text) === "invalid";
                    return (
                      <td key={key} className="px-1.5 py-1.5 text-center">
                        <Input
                          type="text"
                          inputMode="decimal"
                          aria-label={`${label} ${s.nama_lengkap}`}
                          data-cell={`${row}:${key}`}
                          value={text}
                          disabled={!editable}
                          onChange={(e) => setCell(s.ulid, key, e.target.value)}
                          onKeyDown={(e) => onCellKey(e, row, key)}
                          onFocus={(e) => e.target.select()}
                          className={`${inputClass} ${
                            invalid ? "border-destructive bg-destructive/5" : changed ? "border-primary bg-primary/5" : ""
                          }`}
                        />
                      </td>
                    );
                  };

                  return (
                    <tr key={s.ulid} className="border-b border-border/60 last:border-0">
                      <td className="sticky left-0 z-10 bg-card px-3 py-2">
                        <p className="font-medium text-foreground">{s.nama_lengkap}</p>
                        <p className="text-xs text-muted-foreground">NIS {s.nis ?? "—"}</p>
                      </td>
                      {sheet.assignments.map((a) => cell(a.ulid, a.title))}
                      {sheet.assignments.length === 0 && (
                        <td className="px-3 py-2 text-center tabular-nums text-muted-foreground">{fmt(s.tugas)}</td>
                      )}
                      {sheet.assignments.length > 0 && (
                        <td className="bg-muted/20 px-3 py-2 text-center font-semibold tabular-nums">{fmt(calc.tugas)}</td>
                      )}
                      {cell("uts", "UTS")}
                      {cell("uas", "UAS")}
                      <td
                        className={`bg-muted/20 px-3 py-2 text-center font-bold tabular-nums ${below ? "text-destructive" : "text-foreground"}`}
                        title={below ? `Di bawah KKM ${sheet.kkm}` : undefined}
                      >
                        {fmt(calc.final)}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {sheet.assignments.length === 0 && editable && sheet.students.length > 0 && (
        <p className="text-xs text-muted-foreground">
          Klik <strong>Tambah Tugas</strong> untuk membuat kolom Tugas 1, Tugas 2, dan seterusnya. Nilai tugas yang sudah ada otomatis
          menjadi isi Tugas 1.
        </p>
      )}

      {editable && sheet.students.length > 0 && (
        <div className="sticky bottom-3 z-20 rounded-xl border border-border bg-card/95 p-3 shadow-sm backdrop-blur-sm">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-muted-foreground">
              {invalidCount > 0
                ? `${invalidCount} nilai tidak valid (harus 0–100).`
                : dirty.length > 0
                  ? `${dirty.length} nilai berubah, belum disimpan.`
                  : "Semua nilai sudah tersimpan."}
            </p>
            <div className="flex gap-2">
              {dirty.length > 0 && (
                <Button variant="ghost" size="sm" className="gap-1" onClick={() => setEdits({})} disabled={saving}>
                  <X className="size-4" />
                  Batalkan
                </Button>
              )}
              <Button onClick={save} disabled={saving || dirty.length === 0 || invalidCount > 0} className="font-bold">
                {saving && <Loader2 className="size-4 animate-spin" />}
                Simpan Nilai
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

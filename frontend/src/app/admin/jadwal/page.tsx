"use client";

import { useEffect, useRef, useState } from "react";
import { Edit2, Power, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { JenjangSelect } from "@/components/ui/jenjang-select";
import { matchesClassroom } from "@/lib/jenjang";
import { deriveUnitJenjang, jenjangKeysForUnit } from "@/lib/unit-jenjang";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";
import { DAY_OF_WEEK_LABEL, type ClassSchedule, type Subject } from "@/lib/types/kesiswaan";

type ClassroomOption = { ulid: string; name: string; tingkat: number; school_unit: { code: string; label: string; jenjang_group?: string | null } };
type TeacherOption = { ulid: string; name: string };

/** "7 · 8 · 9" - an inactive tingkat stays visible but struck through. Empty = every tingkat. */
function TingkatChips({ subject }: { subject: Subject }) {
  const rows = subject.tingkat ?? [];
  if (rows.length === 0) {
    return <Badge variant="default" className="text-[10px] px-1.5 py-0">Semua tingkat</Badge>;
  }
  return (
    <span className="text-xs text-muted-foreground">
      Tingkat{" "}
      {rows.map((t, i) => (
        <span key={t.tingkat}>
          {i > 0 && " · "}
          <span className={t.is_active ? "font-semibold text-foreground" : "line-through"} title={t.is_active ? undefined : "Nonaktif untuk tingkat ini"}>
            {t.tingkat}
          </span>
        </span>
      ))}
    </span>
  );
}

/** Toggle chips for the grade levels a subject runs in - options come from the unit's own classrooms. */
function TingkatPicker({ options, value, onChange }: { options: number[]; value: number[]; onChange: (next: number[]) => void }) {
  if (options.length === 0) {
    return <p className="text-xs text-muted-foreground">Belum ada kelas di unit ini.</p>;
  }
  return (
    <div className="flex flex-wrap gap-1.5">
      {options.map((t) => {
        const on = value.includes(t);
        return (
          <button
            key={t}
            type="button"
            aria-pressed={on}
            onClick={() => onChange(on ? value.filter((v) => v !== t) : [...value, t].sort((a, b) => a - b))}
            className={`h-8 min-w-9 rounded-lg border px-2.5 text-xs font-semibold transition-colors ${
              on ? "border-primary bg-primary text-primary-foreground" : "border-input bg-card text-muted-foreground hover:bg-muted"
            }`}
          >
            {t}
          </button>
        );
      })}
    </div>
  );
}

function NewSubjectForm({ tingkatOptions, onCreated }: { tingkatOptions: number[]; onCreated: () => void }) {
  const [name, setName] = useState("");
  const [tingkat, setTingkat] = useState<number[]>([]);
  const [submitting, setSubmitting] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (tingkat.length === 0) {
      toast.error("Pilih minimal satu tingkat.");
      return;
    }
    setSubmitting(true);
    try {
      await api.post("/api/admin/subjects", { name, tingkat });
      toast.success("Mata pelajaran ditambahkan.");
      setName("");
      setTingkat([]);
      onCreated();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menambah mata pelajaran.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
      <div className="flex flex-col gap-1.5">
        <Label>Nama mata pelajaran</Label>
        <Input value={name} onChange={(e) => setName(e.target.value)} required className="w-56" placeholder="Bahasa Indonesia" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Tingkat</Label>
        <TingkatPicker options={tingkatOptions} value={tingkat} onChange={setTingkat} />
      </div>
      <Button type="submit" size="sm" disabled={submitting}>{submitting ? "Menyimpan…" : "Tambah Mapel"}</Button>
    </form>
  );
}

/**
 * The catalogue as editable rows (T30): one row per subject with the grade
 * levels it runs in. Edit renames it and picks its tingkat - a tingkat that
 * still has schedules is switched off (not removed) server-side, after a
 * warning here. Deactivate/delete warn first when the subject is already
 * timetabled. School-wide rows (null unit) belong to the central admin only,
 * so a unit admin just sees them without buttons.
 */
function SubjectCatalog({
  subjects, tingkatOptions, isCentral, reload,
}: { subjects: Subject[]; tingkatOptions: number[]; isCentral: boolean; reload: () => void }) {
  const [editing, setEditing] = useState<Subject | null>(null);
  const [name, setName] = useState("");
  const [tingkat, setTingkat] = useState<number[]>([]);
  const [busy, setBusy] = useState(false);

  function startEdit(s: Subject) {
    setEditing(s);
    setName(s.name);
    setTingkat((s.tingkat ?? []).filter((t) => t.is_active).map((t) => t.tingkat));
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    if (!editing) return;
    if (tingkat.length === 0) {
      toast.error("Pilih minimal satu tingkat.");
      return;
    }
    const dropped = (editing.tingkat ?? []).filter((t) => t.is_active && !tingkat.includes(t.tingkat) && t.schedules_count > 0);
    if (dropped.length > 0) {
      const detail = dropped.map((t) => `tingkat ${t.tingkat} (${t.schedules_count} jadwal)`).join(", ");
      if (!confirm(`${editing.name} masih dipakai di ${detail}. Tingkat tersebut akan dinonaktifkan untuk jadwal baru; jadwal yang ada tetap tersimpan. Lanjutkan?`)) return;
    }
    setBusy(true);
    try {
      await api.patch(`/api/admin/subjects/${editing.ulid}`, { name, tingkat });
      toast.success("Mata pelajaran diperbarui.");
      setEditing(null);
      reload();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memperbarui mata pelajaran.");
    } finally {
      setBusy(false);
    }
  }

  async function toggleActive(s: Subject) {
    const deactivating = s.is_active !== false;
    if (deactivating && (s.schedules_count ?? 0) > 0
      && !confirm(`${s.name} sudah dipakai di ${s.schedules_count} jadwal. Jika dinonaktifkan, mapel ini tidak bisa dipilih untuk jadwal baru (jadwal yang ada tetap). Lanjutkan?`)) {
      return;
    }
    try {
      await api.patch(`/api/admin/subjects/${s.ulid}`, { is_active: !deactivating });
      toast.success(deactivating ? "Mata pelajaran dinonaktifkan - tidak lagi ditawarkan di jadwal baru." : "Mata pelajaran diaktifkan kembali.");
      reload();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengubah status mata pelajaran.");
    }
  }

  async function remove(s: Subject) {
    if ((s.schedules_count ?? 0) > 0) {
      if (s.is_active !== false && confirm(`${s.name} sudah dipakai di ${s.schedules_count} jadwal sehingga tidak bisa dihapus. Nonaktifkan saja?`)) {
        await toggleActiveConfirmed(s);
      } else if (s.is_active === false) {
        toast.error(`${s.name} sudah dipakai di ${s.schedules_count} jadwal sehingga tidak bisa dihapus.`);
      }
      return;
    }
    if (!confirm(`Hapus mata pelajaran ${s.name}?`)) return;
    try {
      await api.delete(`/api/admin/subjects/${s.ulid}`);
      toast.success("Mata pelajaran dihapus.");
      reload();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus mata pelajaran.");
    }
  }

  async function toggleActiveConfirmed(s: Subject) {
    try {
      await api.patch(`/api/admin/subjects/${s.ulid}`, { is_active: false });
      toast.success("Mata pelajaran dinonaktifkan - tidak lagi ditawarkan di jadwal baru.");
      reload();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengubah status mata pelajaran.");
    }
  }

  return (
    <div className="mt-3 flex flex-col">
      {subjects.map((s) => {
        const centralOwned = s.school_unit === null && !isCentral;

        if (editing?.ulid === s.ulid) {
          return (
            <form key={s.ulid} onSubmit={save} className="flex flex-wrap items-end gap-3 border-t border-border/60 py-3 first:border-t-0">
              <div className="flex flex-col gap-1.5">
                <Label className="text-xs">Nama</Label>
                <Input value={name} onChange={(e) => setName(e.target.value)} required className="h-8 w-48 text-xs" autoFocus />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label className="text-xs">Tingkat</Label>
                <TingkatPicker
                  options={[...new Set([...tingkatOptions, ...(s.tingkat ?? []).map((t) => t.tingkat)])].sort((a, b) => a - b)}
                  value={tingkat}
                  onChange={setTingkat}
                />
              </div>
              <div className="flex items-center gap-1.5">
                <Button type="submit" size="sm" disabled={busy} className="h-8 px-2.5 text-xs font-semibold">Simpan</Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setEditing(null)} disabled={busy} className="h-8 px-2">
                  <X className="size-3.5" />
                </Button>
              </div>
            </form>
          );
        }

        return (
          <div key={s.ulid} className="flex flex-wrap items-center justify-between gap-3 border-t border-border/60 py-2 first:border-t-0">
            <div className="flex min-w-0 flex-wrap items-center gap-2 text-sm">
              <span className={`font-medium ${s.is_active === false ? "text-muted-foreground line-through" : ""}`}>{s.name}</span>
              <TingkatChips subject={s} />
              {isCentral && <span className="text-xs text-muted-foreground">{s.school_unit ?? "Seluruh sekolah"}</span>}
              {s.is_active === false && <Badge variant="default" className="text-[10px] px-1.5 py-0">Nonaktif</Badge>}
            </div>

            {centralOwned ? (
              <span className="shrink-0 text-xs text-muted-foreground">Dikelola admin pusat</span>
            ) : (
              <div className="flex shrink-0 items-center gap-1.5">
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => toggleActive(s)}
                  title={s.is_active === false ? "Aktifkan kembali" : "Nonaktifkan"}
                  className={`h-8 px-2.5 text-xs font-semibold gap-1 ${s.is_active === false ? "text-good border-good/40" : ""}`}
                >
                  <Power className="size-3.5" />
                  <span>{s.is_active === false ? "Aktifkan" : "Nonaktifkan"}</span>
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => startEdit(s)}
                  title="Edit nama dan tingkat"
                  className="h-8 px-2.5 text-xs font-semibold gap-1"
                >
                  <Edit2 className="size-3.5" />
                  <span>Edit</span>
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => remove(s)}
                  title="Hapus (hanya bila belum dipakai di jadwal)"
                  className="h-8 px-2 text-destructive hover:bg-destructive/10 hover:text-destructive"
                >
                  <Trash2 className="size-3.5" />
                </Button>
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}


/** Whether a subject can be timetabled for this classroom: active, from its unit (or school-wide), and running in its tingkat. */
function subjectFitsClassroom(s: Subject, c: ClassroomOption): boolean {
  if (s.is_active === false) return false;
  if (s.school_unit !== null && s.school_unit !== c.school_unit.label) return false;
  const rows = s.tingkat ?? [];
  return rows.length === 0 || rows.some((t) => t.tingkat === c.tingkat && t.is_active);
}

/**
 * Same rules as ScheduleController's clash checks, run before submit so the
 * admin sees the conflict right away: periods may touch (07:00-08:00 then
 * 08:00-09:00) but never overlap, per classroom and per teacher.
 */
function findScheduleClash(
  rows: ClassSchedule[],
  slot: { classroomUlid: string; teacherUlid: string; day: number; start: string; end: string },
  teacherName?: string,
): string | null {
  if (slot.end <= slot.start) return "Jam selesai harus lebih besar dari jam mulai.";

  const overlapping = rows.filter((r) => r.day_of_week === slot.day && r.start_time < slot.end && r.end_time > slot.start);

  const classClash = overlapping.find((r) => r.classroom.ulid === slot.classroomUlid);
  if (classClash) {
    return `Bentrok dengan ${classClash.subject.name} (${classClash.start_time}-${classClash.end_time}) di kelas ${classClash.classroom.name} pada hari yang sama.`;
  }

  const teacherClash = slot.teacherUlid ? overlapping.find((r) => r.teacher?.ulid === slot.teacherUlid) : undefined;
  if (teacherClash) {
    return `Guru ${teacherName ?? teacherClash.teacher?.name} sudah mengajar ${teacherClash.subject.name} di kelas ${teacherClash.classroom.name} pada jam yang sama (${teacherClash.start_time}-${teacherClash.end_time}).`;
  }

  return null;
}

/**
 * Kelas → Mapel → Guru → Hari → Jam. The kelas comes pre-filled from the
 * filter (still changeable); the subject list only offers what runs in that
 * kelas's tingkat, and teachers who already teach the subject come first.
 */
function NewScheduleForm({
  classrooms, defaultClassroom, subjects, teachers, schedules, showUnit, onCreated,
}: {
  classrooms: ClassroomOption[];
  defaultClassroom: string;
  subjects: Subject[];
  teachers: TeacherOption[];
  schedules: ClassSchedule[];
  showUnit: boolean;
  onCreated: () => void;
}) {
  const [classroomUlid, setClassroomUlid] = useState(defaultClassroom);
  const [subjectUlid, setSubjectUlid] = useState("");
  const [teacherUlid, setTeacherUlid] = useState("");
  const [dayOfWeek, setDayOfWeek] = useState("1");
  const [startTime, setStartTime] = useState("07:00");
  const [endTime, setEndTime] = useState("08:00");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const classroom = classrooms.find((c) => c.ulid === classroomUlid);
  const subjectOptions = classroom ? subjects.filter((s) => subjectFitsClassroom(s, classroom)) : [];
  // A subject picked for another kelas that doesn't run here falls away.
  const effectiveSubject = subjectOptions.some((s) => s.ulid === subjectUlid) ? subjectUlid : "";

  const teachingThis = new Set(
    schedules.filter((r) => r.subject.ulid === effectiveSubject && r.teacher).map((r) => r.teacher!.ulid),
  );
  const primaryTeachers = teachers.filter((t) => teachingThis.has(t.ulid));
  const otherTeachers = teachers.filter((t) => !teachingThis.has(t.ulid));

  const clash = classroomUlid
    ? findScheduleClash(
        schedules,
        { classroomUlid, teacherUlid, day: Number(dayOfWeek), start: startTime, end: endTime },
        teachers.find((t) => t.ulid === teacherUlid)?.name,
      )
    : null;

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (clash) {
      setError(clash);
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      await api.post(`/api/admin/classrooms/${classroomUlid}/schedules`, {
        subject_ulid: effectiveSubject,
        teacher_ulid: teacherUlid || undefined,
        day_of_week: Number(dayOfWeek),
        start_time: startTime,
        end_time: endTime,
      });
      toast.success("Jadwal ditambahkan.");
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal menyimpan jadwal.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
      <div className="flex flex-col gap-1.5">
        <Label>Kelas</Label>
        <select value={classroomUlid} onChange={(e) => setClassroomUlid(e.target.value)} required className="h-10 w-44 rounded-lg border border-input bg-card px-3 text-sm">
          <option value="">Pilih kelas</option>
          {classrooms.map((c) => (
            <option key={c.ulid} value={c.ulid}>{showUnit ? `${c.school_unit.label} · ` : ""}{c.name}</option>
          ))}
        </select>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Mata pelajaran</Label>
        <select value={effectiveSubject} onChange={(e) => setSubjectUlid(e.target.value)} required disabled={!classroom} className="h-10 w-48 rounded-lg border border-input bg-card px-3 text-sm disabled:opacity-60">
          <option value="">{!classroom ? "Pilih kelas dulu" : subjectOptions.length === 0 ? `Belum ada mapel tingkat ${classroom.tingkat}` : "Pilih mapel"}</option>
          {subjectOptions.map((s) => <option key={s.ulid} value={s.ulid}>{s.name}</option>)}
        </select>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Guru pengampu</Label>
        <select value={teacherUlid} onChange={(e) => setTeacherUlid(e.target.value)} className="h-10 w-48 rounded-lg border border-input bg-card px-3 text-sm">
          <option value="">Belum ditentukan</option>
          {primaryTeachers.length > 0 ? (
            <>
              <optgroup label="Mengampu mapel ini">
                {primaryTeachers.map((t) => <option key={t.ulid} value={t.ulid}>{t.name}</option>)}
              </optgroup>
              <optgroup label="Guru lain">
                {otherTeachers.map((t) => <option key={t.ulid} value={t.ulid}>{t.name}</option>)}
              </optgroup>
            </>
          ) : (
            teachers.map((t) => <option key={t.ulid} value={t.ulid}>{t.name}</option>)
          )}
        </select>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Hari</Label>
        <select value={dayOfWeek} onChange={(e) => setDayOfWeek(e.target.value)} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
          {Object.entries(DAY_OF_WEEK_LABEL).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Jam mulai</Label>
        <Input type="time" value={startTime} onChange={(e) => setStartTime(e.target.value)} required className="w-28" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Jam selesai</Label>
        <Input type="time" value={endTime} onChange={(e) => setEndTime(e.target.value)} required className="w-28" />
      </div>
      <Button type="submit" disabled={submitting || !!clash}>{submitting ? "Menyimpan…" : "Tambah Jadwal"}</Button>
      {(clash ?? error) && <p className="w-full rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{clash ?? error}</p>}
    </form>
  );
}

export default function JadwalPage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [classrooms, setClassrooms] = useState<ClassroomOption[] | null>(null);
  // "" = Semua kelas (the default): the whole unit's timetable on one screen,
  // so teacher clashes and free periods across classes are visible.
  const [selectedClassroom, setSelectedClassroom] = useState<string>("");
  // Unit and Jenjang only narrow a central admin's cross-unit view; a unit
  // admin is locked to one unit by the API and picks a kelas directly.
  const [unitFilter, setUnitFilter] = useState("");
  const [jenjangFilter, setJenjangFilter] = useState("");
  const [unitOptions, setUnitOptions] = useState<{ code: string; label: string }[]>([]);
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [teachers, setTeachers] = useState<TeacherOption[]>([]);
  // Every visible period, tagged with the unit filter it was fetched for;
  // the single-class view is a client-side slice of it.
  const [schedules, setSchedules] = useState<{ unit: string; rows: ClassSchedule[] } | null>(null);

  function loadSubjects() {
    // include_inactive so the catalogue below can show - and re-activate or
    // clean up - deactivated subjects; the schedule dropdown filters them
    // out client-side.
    api.get<{ subjects: Subject[] }>("/api/admin/subjects?include_inactive=1")
      .then((d) => setSubjects(d.subjects))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat mata pelajaran."));
  }

  useEffect(() => {
    api.get<{ classrooms: ClassroomOption[] }>("/api/admin/classrooms")
      .then((d) => setClassrooms(d.classrooms))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar kelas."));

    loadSubjects();

    api.get<{ school_units: { code: string; label: string }[] }>("/api/admin/school-units")
      .then((d) => setUnitOptions(d.school_units))
      .catch(() => {});

    api.get<{ users: { data: TeacherOption[] } }>("/api/admin/users?role=guru&per_page=200")
      .then((d) => setTeachers(d.users.data))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar guru."));
  }, []);

  // Request-tagged (audit 2026-09-28): a late response for a previous unit
  // filter is dropped instead of overwriting the fresh one.
  const schedulesRequestId = useRef(0);

  function loadSchedules(unit: string) {
    const requestId = ++schedulesRequestId.current;
    const query = unit ? `?unit=${encodeURIComponent(unit)}` : "";
    api.get<{ schedules: ClassSchedule[] }>(`/api/admin/schedules${query}`)
      .then((d) => {
        if (requestId !== schedulesRequestId.current) return;
        setSchedules({ unit, rows: d.schedules });
      })
      .catch((err) => {
        if (requestId !== schedulesRequestId.current) return;
        toast.error(err instanceof ApiError ? err.message : "Gagal memuat jadwal.");
      });
  }

  useEffect(() => {
    loadSchedules(unitFilter);
  }, [unitFilter]);

  // The cascade's map (bug batch Poin 3): derived from the SAME classrooms
  // the Kelas picker lists, so Jenjang offers exactly the ladder that runs
  // in the picked unit.
  const jenjangByUnit = deriveUnitJenjang(classrooms ?? []);

  // The tingkat a subject can run in: whatever the visible classrooms use
  // (the API already scopes a unit admin to their own unit), never hardcoded.
  const tingkatOptions = [...new Set((classrooms ?? []).map((c) => c.tingkat))].sort((a, b) => a - b);

  const matchesFilters = (c: ClassroomOption, unit: string, jenjang: string) =>
    (!unit || c.school_unit.code === unit) && matchesClassroom(c, jenjang || null);

  const visibleClassrooms = (classrooms ?? []).filter((c) => matchesFilters(c, unitFilter, jenjangFilter));

  /** Top-down cascade (Poin 3): when Unit/Jenjang change, a selected kelas that no longer matches falls back to "Semua kelas". */
  function refocusKelas(nextUnit: string, nextJenjang: string) {
    if (!selectedClassroom) return;
    const current = (classrooms ?? []).find((c) => c.ulid === selectedClassroom);
    if (current && !matchesFilters(current, nextUnit, nextJenjang)) setSelectedClassroom("");
  }

  async function removeSchedule(s: ClassSchedule) {
    if (!confirm(`Hapus jadwal ${s.subject.name} kelas ${s.classroom.name} (${DAY_OF_WEEK_LABEL[s.day_of_week]} ${s.start_time}–${s.end_time})?`)) return;
    try {
      await api.delete(`/api/admin/classrooms/${s.classroom.ulid}/schedules/${s.ulid}`);
      toast.success("Jadwal dihapus.");
      loadSchedules(unitFilter);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus jadwal.");
    }
  }

  const visibleIds = new Set(visibleClassrooms.map((c) => c.ulid));
  const loadedRows = schedules !== null && schedules.unit === unitFilter
    ? schedules.rows.filter((s) => (selectedClassroom ? s.classroom.ulid === selectedClassroom : visibleIds.has(s.classroom.ulid)))
    : null;

  const byDay = (loadedRows ?? []).reduce<Record<number, ClassSchedule[]>>((acc, s) => {
    (acc[s.day_of_week] ??= []).push(s);
    return acc;
  }, {});

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-xl font-bold tracking-tight">Jadwal Pelajaran</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Jadwal per kelas - dasar bagi guru untuk membuka sesi presensi per mata pelajaran.
        </p>
      </div>

      <Card className="p-5">
        <h2 className="mb-3 text-sm font-semibold">Katalog mata pelajaran</h2>
        <NewSubjectForm tingkatOptions={tingkatOptions} onCreated={loadSubjects} />
        {subjects.length > 0 && (
          <SubjectCatalog subjects={subjects} tingkatOptions={tingkatOptions} isCentral={isCentral} reload={loadSubjects} />
        )}
      </Card>

      <Card className="p-5">
        <div className="flex flex-wrap items-end gap-4">
          {isCentral && (
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs">Unit</Label>
              <select
                value={unitFilter}
                onChange={(e) => {
                  const nextUnit = e.target.value;
                  setUnitFilter(nextUnit);
                  // Top-down cascade (Poin 3): a new unit resets a jenjang
                  // it doesn't run, and the kelas follows whatever survives.
                  const nextJenjang =
                    jenjangFilter && nextUnit && !(jenjangByUnit[nextUnit] ?? []).includes(jenjangFilter)
                      ? ""
                      : jenjangFilter;
                  if (nextJenjang !== jenjangFilter) setJenjangFilter(nextJenjang);
                  refocusKelas(nextUnit, nextJenjang);
                }}
                className="h-10 w-52 rounded-lg border border-input bg-card px-3 text-sm"
              >
                <option value="">Semua Unit</option>
                {unitOptions.map((u) => (
                  <option key={u.code} value={u.code}>{u.label}</option>
                ))}
              </select>
            </div>
          )}
          {isCentral && (
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs">Jenjang</Label>
              <JenjangSelect
                value={jenjangFilter}
                onChange={(key) => {
                  setJenjangFilter(key);
                  refocusKelas(unitFilter, key);
                }}
                allowedKeys={jenjangKeysForUnit(jenjangByUnit, unitFilter || null)}
                className="h-10 w-56 rounded-lg border border-input bg-card px-3 text-sm"
              />
            </div>
          )}
          <div className="flex flex-col gap-1.5">
            <Label>Kelas</Label>
            {classrooms === null ? (
              <Skeleton className="h-10 w-64" />
            ) : (
              <select
                value={selectedClassroom}
                onChange={(e) => setSelectedClassroom(e.target.value)}
                className="h-10 w-64 rounded-lg border border-input bg-card px-3 text-sm"
              >
                <option value="">Semua kelas</option>
                {visibleClassrooms.map((c) => (
                  <option key={c.ulid} value={c.ulid}>{isCentral ? `${c.school_unit.label} · ` : ""}{c.name}</option>
                ))}
              </select>
            )}
          </div>
        </div>
      </Card>

      <Card className="p-5">
        <h2 className="mb-3 text-sm font-semibold">Tambah jadwal</h2>
        <NewScheduleForm
          // Remount on a filter change so the Kelas field picks up the newly
          // selected kelas (or goes blank for "Semua kelas").
          key={selectedClassroom}
          classrooms={visibleClassrooms}
          defaultClassroom={selectedClassroom}
          subjects={subjects}
          teachers={teachers}
          schedules={schedules?.rows ?? []}
          showUnit={isCentral}
          onCreated={() => loadSchedules(unitFilter)}
        />
      </Card>

      <div className="flex flex-col gap-4">
        {loadedRows === null && <Skeleton className="h-40 w-full" />}
        {loadedRows !== null && Object.keys(byDay).length === 0 && (
          <p className="text-sm text-muted-foreground">
            {selectedClassroom ? "Belum ada jadwal untuk kelas ini." : "Belum ada jadwal."}
          </p>
        )}
        {Object.entries(DAY_OF_WEEK_LABEL).map(([dayValue, dayLabel]) => {
          const items = byDay[Number(dayValue)];
          if (!items || items.length === 0) return null;

          return (
            <div key={dayValue}>
              <h3 className="mb-2 text-sm font-semibold text-muted-foreground">{dayLabel}</h3>
              <div className="flex flex-col gap-2">
                {items.map((s) => (
                  <Card key={s.ulid} className="flex items-center justify-between gap-3 p-4">
                    <div>
                      <p className="font-medium">
                        {s.subject.name}
                        <Badge variant="default" className="ml-2 text-[10px] px-1.5 py-0 align-middle">Kelas {s.classroom.name}</Badge>
                      </p>
                      <p className="text-sm text-muted-foreground">
                        {s.start_time}–{s.end_time} · {s.teacher?.name ?? "Belum ada guru"}
                      </p>
                    </div>
                    <Button size="sm" variant="ghost" onClick={() => removeSchedule(s)} title="Hapus jadwal">
                      <Trash2 className="size-4" />
                    </Button>
                  </Card>
                ))}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

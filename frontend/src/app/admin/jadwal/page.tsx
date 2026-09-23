"use client";

import { useEffect, useState } from "react";
import { Edit2, Power, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";
import { DAY_OF_WEEK_LABEL, type ClassSchedule, type Subject } from "@/lib/types/kesiswaan";

type ClassroomOption = { ulid: string; name: string; tingkat: number; school_unit: { code: string; label: string } };
type TeacherOption = { ulid: string; name: string };

function NewSubjectForm({ onCreated }: { onCreated: () => void }) {
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [submitting, setSubmitting] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      await api.post("/api/admin/subjects", { code, name });
      toast.success("Mata pelajaran ditambahkan.");
      setCode("");
      setName("");
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
        <Label>Kode</Label>
        <Input value={code} onChange={(e) => setCode(e.target.value)} required className="w-28" placeholder="BINDO" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Nama mata pelajaran</Label>
        <Input value={name} onChange={(e) => setName(e.target.value)} required className="w-56" placeholder="Bahasa Indonesia" />
      </div>
      <Button type="submit" size="sm" disabled={submitting}>{submitting ? "Menyimpan…" : "Tambah Mapel"}</Button>
    </form>
  );
}

/**
 * The catalogue as editable rows (T30): rename in place (code stays locked -
 * it is the key schedules and grades point at), deactivate a subject that
 * must stop offering itself to new schedules, or delete one that has never
 * been used - a subject with history is refused server-side with the reason,
 * surfaced here as a toast. School-wide rows (null unit) belong to the
 * central admin only, so a unit admin just sees them without buttons.
 */
function SubjectCatalog({ subjects, isCentral, reload }: { subjects: Subject[]; isCentral: boolean; reload: () => void }) {
  const [editing, setEditing] = useState<Subject | null>(null);
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    if (!editing) return;
    setBusy(true);
    try {
      await api.patch(`/api/admin/subjects/${editing.ulid}`, { name });
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
    try {
      await api.patch(`/api/admin/subjects/${s.ulid}`, { is_active: s.is_active === false });
      toast.success(s.is_active === false ? "Mata pelajaran diaktifkan kembali." : "Mata pelajaran dinonaktifkan - tidak lagi tawarkan di jadwal baru.");
      reload();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengubah status mata pelajaran.");
    }
  }

  async function remove(s: Subject) {
    try {
      await api.delete(`/api/admin/subjects/${s.ulid}`);
      toast.success("Mata pelajaran dihapus.");
      reload();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus mata pelajaran.");
    }
  }

  return (
    <div className="mt-3 flex flex-col">
      {subjects.map((s) => {
        const centralOwned = s.school_unit === null && !isCentral;

        return (
          <div key={s.ulid} className="flex items-center justify-between gap-3 border-t border-border/60 py-2 first:border-t-0">
            <div className="flex min-w-0 flex-wrap items-center gap-1.5 text-sm">
              <span className={`font-medium ${s.is_active === false ? "text-muted-foreground line-through" : ""}`}>{s.name}</span>
              <Badge variant="default" className="text-[10px] px-1.5 py-0 font-mono">{s.code}</Badge>
              {isCentral && <span className="text-xs text-muted-foreground">{s.school_unit ?? "Seluruh sekolah"}</span>}
              {s.is_active === false && <Badge variant="default" className="text-[10px] px-1.5 py-0">Nonaktif</Badge>}
            </div>

            {editing?.ulid === s.ulid ? (
              <form onSubmit={save} className="flex shrink-0 items-center gap-1.5">
                <Input value={name} onChange={(e) => setName(e.target.value)} required className="h-8 w-48 text-xs" autoFocus />
                <Button type="submit" size="sm" disabled={busy} className="h-8 px-2.5 text-xs font-semibold">Simpan</Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setEditing(null)} disabled={busy} className="h-8 px-2">
                  <X className="size-3.5" />
                </Button>
              </form>
            ) : centralOwned ? (
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
                  onClick={() => { setEditing(s); setName(s.name); }}
                  title="Edit nama"
                  className="h-8 px-2.5 text-xs font-semibold gap-1"
                >
                  <Edit2 className="size-3.5" />
                  <span>Edit</span>
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => remove(s)}
                  title="Hapus (ditolak bila sudah terpakai)"
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

function NewScheduleForm({
  classroomUlid, subjects, teachers, onCreated,
}: {
  classroomUlid: string;
  subjects: Subject[];
  teachers: TeacherOption[];
  onCreated: () => void;
}) {
  const [subjectUlid, setSubjectUlid] = useState("");
  const [teacherUlid, setTeacherUlid] = useState("");
  const [dayOfWeek, setDayOfWeek] = useState("1");
  const [startTime, setStartTime] = useState("07:00");
  const [endTime, setEndTime] = useState("08:00");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await api.post(`/api/admin/classrooms/${classroomUlid}/schedules`, {
        subject_ulid: subjectUlid,
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
        <Label>Mata pelajaran</Label>
        <select value={subjectUlid} onChange={(e) => setSubjectUlid(e.target.value)} required className="h-10 w-48 rounded-lg border border-input bg-card px-3 text-sm">
          <option value="">Pilih mapel</option>
          {subjects.filter((s) => s.is_active !== false).map((s) => <option key={s.ulid} value={s.ulid}>{s.name}</option>)}
        </select>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Guru pengampu</Label>
        <select value={teacherUlid} onChange={(e) => setTeacherUlid(e.target.value)} className="h-10 w-48 rounded-lg border border-input bg-card px-3 text-sm">
          <option value="">Belum ditentukan</option>
          {teachers.map((t) => <option key={t.ulid} value={t.ulid}>{t.name}</option>)}
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
      <Button type="submit" disabled={submitting}>{submitting ? "Menyimpan…" : "Tambah Jadwal"}</Button>
      {error && <p className="w-full rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
    </form>
  );
}

export default function JadwalPage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [classrooms, setClassrooms] = useState<ClassroomOption[] | null>(null);
  const [selectedClassroom, setSelectedClassroom] = useState<string>("");
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [teachers, setTeachers] = useState<TeacherOption[]>([]);
  const [schedules, setSchedules] = useState<{ classroomUlid: string; rows: ClassSchedule[] } | null>(null);

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
      .then((d) => {
        setClassrooms(d.classrooms);
        if (d.classrooms.length > 0) setSelectedClassroom(d.classrooms[0].ulid);
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar kelas."));

    loadSubjects();

    api.get<{ users: { data: TeacherOption[] } }>("/api/admin/users?role=guru&per_page=200")
      .then((d) => setTeachers(d.users.data))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar guru."));
  }, []);

  // Rows are stored tagged with the classroom they belong to: a classroom
  // switch shows the skeleton (not the previous classroom's timetable) until
  // the new one lands, without any synchronous setState in the effect.
  function loadSchedules(classroomUlid: string) {
    if (!classroomUlid) return;
    api.get<{ schedules: ClassSchedule[] }>(`/api/admin/classrooms/${classroomUlid}/schedules`)
      .then((d) => setSchedules({ classroomUlid, rows: d.schedules }))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat jadwal."));
  }

  useEffect(() => {
    if (selectedClassroom) loadSchedules(selectedClassroom);
  }, [selectedClassroom]);

  async function removeSchedule(ulid: string) {
    try {
      await api.delete(`/api/admin/classrooms/${selectedClassroom}/schedules/${ulid}`);
      toast.success("Jadwal dihapus.");
      loadSchedules(selectedClassroom);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus jadwal.");
    }
  }

  const loadedRows = schedules !== null && schedules.classroomUlid === selectedClassroom
    ? schedules.rows
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
        <NewSubjectForm onCreated={loadSubjects} />
        {subjects.length > 0 && (
          <SubjectCatalog subjects={subjects} isCentral={isCentral} reload={loadSubjects} />
        )}
      </Card>

      <Card className="p-5">
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
              {classrooms.map((c) => (
                <option key={c.ulid} value={c.ulid}>{c.school_unit.label} · {c.name}</option>
              ))}
            </select>
          )}
        </div>
      </Card>

      {selectedClassroom && (
        <Card className="p-5">
          <h2 className="mb-3 text-sm font-semibold">Tambah jadwal</h2>
          <NewScheduleForm
            classroomUlid={selectedClassroom}
            subjects={subjects}
            teachers={teachers}
            onCreated={() => loadSchedules(selectedClassroom)}
          />
        </Card>
      )}

      <div className="flex flex-col gap-4">
        {loadedRows === null && <Skeleton className="h-40 w-full" />}
        {loadedRows !== null && Object.keys(byDay).length === 0 && (
          <p className="text-sm text-muted-foreground">Belum ada jadwal untuk kelas ini.</p>
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
                      <p className="font-medium">{s.subject.name}</p>
                      <p className="text-sm text-muted-foreground">
                        {s.start_time.slice(0, 5)}–{s.end_time.slice(0, 5)} · {s.teacher?.name ?? "Belum ada guru"}
                      </p>
                    </div>
                    <Button size="sm" variant="ghost" onClick={() => removeSchedule(s.ulid)}>
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

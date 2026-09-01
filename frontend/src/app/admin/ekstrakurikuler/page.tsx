"use client";

import { useEffect, useState } from "react";
import { ChevronDown, ChevronUp, UserMinus } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuth } from "@/lib/auth/auth-context";
import { api, ApiError } from "@/lib/api";

type SchoolUnitOption = { ulid: string; code: string; label: string };
type AcademicYearOption = { ulid: string; year: string; is_active: boolean };
type TeacherOption = { ulid: string; name: string };
type StudentOption = { ulid: string; nama_lengkap: string; nis: string | null };

type EkskulRow = {
  ulid: string;
  name: string;
  description: string | null;
  school_unit: { code: string; label: string } | null;
  academic_year: string | null;
  pembina: string | null;
  capacity: number | null;
  member_count: number;
  is_active: boolean;
};

type Member = { ulid: string; student: StudentOption; joined_on: string | null };

function NewEkskulForm({
  isCentral, units, years, teachers, onCreated,
}: {
  isCentral: boolean;
  units: SchoolUnitOption[];
  years: AcademicYearOption[];
  teachers: TeacherOption[];
  onCreated: () => void;
}) {
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [unitCode, setUnitCode] = useState("");
  const [yearUlid, setYearUlid] = useState("");
  const [pembinaUlid, setPembinaUlid] = useState("");
  const [capacity, setCapacity] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await api.post("/api/admin/extracurriculars", {
        name,
        description: description || undefined,
        school_unit_code: isCentral ? (unitCode || undefined) : undefined,
        academic_year_ulid: yearUlid,
        pembina_ulid: pembinaUlid || undefined,
        capacity: capacity ? Number(capacity) : undefined,
      });
      toast.success("Ekstrakurikuler ditambahkan.");
      setName("");
      setDescription("");
      setCapacity("");
      setPembinaUlid("");
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal menambah ekstrakurikuler.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
      <div className="flex flex-col gap-1.5">
        <Label>Nama kegiatan</Label>
        <Input value={name} onChange={(e) => setName(e.target.value)} required className="w-40" placeholder="Pramuka" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Deskripsi</Label>
        <Input value={description} onChange={(e) => setDescription(e.target.value)} className="w-56" placeholder="Opsional" />
      </div>
      {isCentral && (
        <div className="flex flex-col gap-1.5">
          <Label>Unit sekolah</Label>
          <select value={unitCode} onChange={(e) => setUnitCode(e.target.value)} className="h-10 w-56 rounded-lg border border-input bg-card px-3 text-sm">
            <option value="">Sekolah-luas (semua unit)</option>
            {units.map((u) => <option key={u.ulid} value={u.code}>{u.label}</option>)}
          </select>
        </div>
      )}
      <div className="flex flex-col gap-1.5">
        <Label>Tahun ajaran</Label>
        <select value={yearUlid} onChange={(e) => setYearUlid(e.target.value)} required className="h-10 w-40 rounded-lg border border-input bg-card px-3 text-sm">
          <option value="">Pilih tahun</option>
          {years.map((y) => <option key={y.ulid} value={y.ulid}>{y.year}</option>)}
        </select>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Pembina</Label>
        <select value={pembinaUlid} onChange={(e) => setPembinaUlid(e.target.value)} className="h-10 w-48 rounded-lg border border-input bg-card px-3 text-sm">
          <option value="">Belum ditentukan</option>
          {teachers.map((t) => <option key={t.ulid} value={t.ulid}>{t.name}</option>)}
        </select>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Kapasitas</Label>
        <Input type="number" min={1} max={500} value={capacity} onChange={(e) => setCapacity(e.target.value)} className="w-24" placeholder="30" />
      </div>
      <Button type="submit" disabled={submitting}>{submitting ? "Menyimpan…" : "Tambah Ekskul"}</Button>
      {error && <p className="w-full rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
    </form>
  );
}

function RosterPanel({ ekskul, students, onChanged }: { ekskul: EkskulRow; students: StudentOption[]; onChanged: () => void }) {
  const [members, setMembers] = useState<Member[] | null>(null);
  const [studentUlid, setStudentUlid] = useState("");
  const [assigning, setAssigning] = useState(false);

  function load() {
    setMembers(null);
    api.get<{ members: Member[] }>(`/api/admin/extracurriculars/${ekskul.ulid}/members`)
      .then((d) => setMembers(d.members))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat anggota."));
  }

  useEffect(load, [ekskul.ulid]);

  async function assign() {
    if (!studentUlid) return;
    setAssigning(true);
    try {
      await api.post(`/api/admin/extracurriculars/${ekskul.ulid}/members`, { student_ulid: studentUlid });
      toast.success("Siswa ditambahkan.");
      setStudentUlid("");
      load();
      onChanged();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menambah anggota.");
    } finally {
      setAssigning(false);
    }
  }

  async function remove(memberUlid: string) {
    try {
      await api.delete(`/api/admin/extracurriculars/${ekskul.ulid}/members/${memberUlid}`);
      toast.success("Anggota dikeluarkan.");
      load();
      onChanged();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengeluarkan anggota.");
    }
  }

  return (
    <div className="border-t border-border p-4">
      <div className="flex flex-wrap items-end gap-2">
        <div className="flex flex-col gap-1.5">
          <Label>Tambah siswa</Label>
          <select value={studentUlid} onChange={(e) => setStudentUlid(e.target.value)} className="h-9 w-64 rounded-lg border border-input bg-card px-2 text-sm">
            <option value="">Pilih siswa</option>
            {students.map((s) => <option key={s.ulid} value={s.ulid}>{s.nama_lengkap}{s.nis ? ` (${s.nis})` : ""}</option>)}
          </select>
        </div>
        <Button size="sm" onClick={assign} disabled={!studentUlid || assigning}>Tambah</Button>
      </div>

      <div className="mt-3 flex flex-col gap-1.5">
        {members === null && <Skeleton className="h-16 w-full" />}
        {members !== null && members.length === 0 && <p className="text-sm text-muted-foreground">Belum ada anggota.</p>}
        {members?.map((m) => (
          <div key={m.ulid} className="flex items-center justify-between rounded-lg bg-muted/30 px-3 py-2">
            <span className="text-sm">{m.student.nama_lengkap}{m.student.nis ? ` · ${m.student.nis}` : ""}</span>
            <Button size="sm" variant="ghost" onClick={() => remove(m.ulid)}>
              <UserMinus className="size-4" />
            </Button>
          </div>
        ))}
      </div>
    </div>
  );
}

export default function EkstrakurikulerPage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [units, setUnits] = useState<SchoolUnitOption[]>([]);
  const [years, setYears] = useState<AcademicYearOption[]>([]);
  const [teachers, setTeachers] = useState<TeacherOption[]>([]);
  const [students, setStudents] = useState<StudentOption[]>([]);
  const [activities, setActivities] = useState<EkskulRow[] | null>(null);
  const [expanded, setExpanded] = useState<string | null>(null);

  useEffect(() => {
    api.get<{ school_units: SchoolUnitOption[] }>("/api/admin/school-units").then((d) => setUnits(d.school_units));
    api.get<{ academic_years: AcademicYearOption[] }>("/api/admin/academic-years").then((d) => setYears(d.academic_years));
    api.get<{ users: { data: TeacherOption[] } }>("/api/admin/users?role=guru&per_page=200").then((d) => setTeachers(d.users.data));
    api.get<{ students: { data: StudentOption[] } }>("/api/admin/students?per_page=500").then((d) => setStudents(d.students.data));
  }, []);

  function loadActivities() {
    setActivities(null);
    api.get<{ extracurriculars: EkskulRow[] }>("/api/admin/extracurriculars")
      .then((d) => setActivities(d.extracurriculars))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar ekstrakurikuler."));
  }

  useEffect(loadActivities, []);

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-xl font-bold tracking-tight">Ekstrakurikuler</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Katalog kegiatan dan roster anggota. Biaya ekskul hanya tertagih untuk siswa yang terdaftar aktif di sini.
        </p>
      </div>

      <Card className="p-5">
        <h2 className="mb-3 text-sm font-semibold">Tambah ekstrakurikuler</h2>
        <NewEkskulForm isCentral={isCentral} units={units} years={years} teachers={teachers} onCreated={loadActivities} />
      </Card>

      <div className="flex flex-col gap-2">
        {activities === null && <Skeleton className="h-40 w-full" />}
        {activities !== null && activities.length === 0 && (
          <p className="text-sm text-muted-foreground">Belum ada ekstrakurikuler.</p>
        )}
        {activities?.map((e) => (
          <Card key={e.ulid} className="overflow-hidden">
            <div className="flex items-center justify-between gap-3 p-4">
              <div>
                <p className="font-medium">
                  {e.school_unit?.label ?? "Sekolah-luas"} · {e.name}
                  {!e.is_active && <Badge variant="default" className="ml-2">Nonaktif</Badge>}
                </p>
                <p className="text-sm text-muted-foreground">
                  {e.pembina ?? "Belum ada pembina"} · {e.member_count} anggota{e.capacity ? ` / ${e.capacity}` : ""}
                </p>
              </div>
              <Button size="sm" variant="outline" onClick={() => setExpanded(expanded === e.ulid ? null : e.ulid)}>
                {expanded === e.ulid ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
                <span className="ml-1">Kelola Anggota</span>
              </Button>
            </div>
            {expanded === e.ulid && <RosterPanel ekskul={e} students={students} onChanged={loadActivities} />}
          </Card>
        ))}
      </div>
    </div>
  );
}

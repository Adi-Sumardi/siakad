"use client";

import { useEffect, useState } from "react";
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

type ClassroomRow = {
  ulid: string;
  name: string;
  tingkat: number;
  school_unit: { code: string; label: string };
  academic_year: string | null;
  capacity: number | null;
  homeroom_teacher: string | null;
  is_active: boolean;
};

function NewClassroomForm({
  isCentral, units, years, teachers, onCreated,
}: {
  isCentral: boolean;
  units: SchoolUnitOption[];
  years: AcademicYearOption[];
  teachers: TeacherOption[];
  onCreated: () => void;
}) {
  const [name, setName] = useState("");
  const [tingkat, setTingkat] = useState("1");
  const [unitCode, setUnitCode] = useState("");
  const [yearUlid, setYearUlid] = useState("");
  const [capacity, setCapacity] = useState("");
  const [teacherUlid, setTeacherUlid] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await api.post("/api/admin/classrooms", {
        name,
        tingkat: Number(tingkat),
        school_unit_code: isCentral ? unitCode : undefined,
        academic_year_ulid: yearUlid,
        capacity: capacity ? Number(capacity) : undefined,
        homeroom_teacher_ulid: teacherUlid || undefined,
      });
      toast.success("Kelas ditambahkan.");
      setName("");
      setCapacity("");
      setTeacherUlid("");
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal menambah kelas.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
      <div className="flex flex-col gap-1.5">
        <Label>Nama kelas</Label>
        <Input value={name} onChange={(e) => setName(e.target.value)} required className="w-32" placeholder="7-A" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Tingkat</Label>
        <Input type="number" min={1} max={12} value={tingkat} onChange={(e) => setTingkat(e.target.value)} required className="w-20" />
      </div>
      {isCentral && (
        <div className="flex flex-col gap-1.5">
          <Label>Unit sekolah</Label>
          <select value={unitCode} onChange={(e) => setUnitCode(e.target.value)} required className="h-10 w-56 rounded-lg border border-input bg-card px-3 text-sm">
            <option value="">Pilih unit</option>
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
        <Label>Kapasitas</Label>
        <Input type="number" min={1} max={100} value={capacity} onChange={(e) => setCapacity(e.target.value)} className="w-24" placeholder="32" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Wali kelas</Label>
        <select value={teacherUlid} onChange={(e) => setTeacherUlid(e.target.value)} className="h-10 w-48 rounded-lg border border-input bg-card px-3 text-sm">
          <option value="">Belum ditentukan</option>
          {teachers.map((t) => <option key={t.ulid} value={t.ulid}>{t.name}</option>)}
        </select>
      </div>
      <Button type="submit" disabled={submitting}>{submitting ? "Menyimpan…" : "Tambah Kelas"}</Button>
      {error && <p className="w-full rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
    </form>
  );
}

export default function KelasPage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [units, setUnits] = useState<SchoolUnitOption[]>([]);
  const [years, setYears] = useState<AcademicYearOption[]>([]);
  const [teachers, setTeachers] = useState<TeacherOption[]>([]);
  const [yearFilter, setYearFilter] = useState<string>("");
  const [classrooms, setClassrooms] = useState<ClassroomRow[] | null>(null);

  useEffect(() => {
    api.get<{ school_units: SchoolUnitOption[] }>("/api/admin/school-units").then((d) => setUnits(d.school_units));
    api.get<{ academic_years: AcademicYearOption[] }>("/api/admin/academic-years").then((d) => {
      setYears(d.academic_years);
      const active = d.academic_years.find((y) => y.is_active);
      if (active) setYearFilter(active.ulid);
    });
    api.get<{ users: { data: TeacherOption[] } }>("/api/admin/users?role=guru&per_page=200").then((d) => setTeachers(d.users.data));
  }, []);

  function loadClassrooms(yearUlid: string) {
    setClassrooms(null);
    const query = yearUlid ? `?academic_year_ulid=${yearUlid}` : "";
    api.get<{ classrooms: ClassroomRow[] }>(`/api/admin/classrooms${query}`)
      .then((d) => setClassrooms(d.classrooms))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar kelas."));
  }

  useEffect(() => {
    if (yearFilter) loadClassrooms(yearFilter);
  }, [yearFilter]);

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-xl font-bold tracking-tight">Data Kelas</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Kelas per tahun ajaran - dibutuhkan sebelum kenaikan kelas bisa memindahkan siswa ke tahun berikutnya.
        </p>
      </div>

      <Card className="p-5">
        <h2 className="mb-3 text-sm font-semibold">Tambah kelas</h2>
        <NewClassroomForm
          isCentral={isCentral}
          units={units}
          years={years}
          teachers={teachers}
          onCreated={() => loadClassrooms(yearFilter)}
        />
      </Card>

      <Card className="p-5">
        <div className="flex flex-col gap-1.5">
          <Label>Tahun ajaran</Label>
          {years.length === 0 ? (
            <Skeleton className="h-10 w-48" />
          ) : (
            <select
              value={yearFilter}
              onChange={(e) => setYearFilter(e.target.value)}
              className="h-10 w-48 rounded-lg border border-input bg-card px-3 text-sm"
            >
              {years.map((y) => <option key={y.ulid} value={y.ulid}>{y.year}{y.is_active ? " (aktif)" : ""}</option>)}
            </select>
          )}
        </div>
      </Card>

      <div className="flex flex-col gap-2">
        {classrooms === null && <Skeleton className="h-40 w-full" />}
        {classrooms !== null && classrooms.length === 0 && (
          <p className="text-sm text-muted-foreground">Belum ada kelas untuk tahun ajaran ini.</p>
        )}
        {classrooms?.map((c) => (
          <Card key={c.ulid} className="flex items-center justify-between gap-3 p-4">
            <div>
              <p className="font-medium">
                {c.school_unit.label} · {c.name}
                {!c.is_active && <Badge variant="default" className="ml-2">Nonaktif</Badge>}
              </p>
              <p className="text-sm text-muted-foreground">
                Tingkat {c.tingkat} · {c.homeroom_teacher ?? "Belum ada wali kelas"}
                {c.capacity ? ` · Kapasitas ${c.capacity}` : ""}
              </p>
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}

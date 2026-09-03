"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Edit2, Power, Trash2, X } from "lucide-react";
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
  homeroom_teacher_ulid: string | null;
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

  const [editingClassroom, setEditingClassroom] = useState<ClassroomRow | null>(null);
  const [deletingClassroom, setDeletingClassroom] = useState<ClassroomRow | null>(null);
  const [editForm, setEditForm] = useState({ name: "", tingkat: "1", capacity: "", teacherUlid: "", isActive: true });
  const [submitting, setSubmitting] = useState(false);
  const [deleteConfirmInput, setDeleteConfirmInput] = useState("");

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

  function openEdit(c: ClassroomRow) {
    setEditingClassroom(c);
    setEditForm({
      name: c.name,
      tingkat: String(c.tingkat),
      capacity: c.capacity ? String(c.capacity) : "",
      teacherUlid: c.homeroom_teacher_ulid ?? "",
      isActive: c.is_active,
    });
  }

  async function handleUpdate(e: React.FormEvent) {
    e.preventDefault();
    if (!editingClassroom) return;
    setSubmitting(true);
    try {
      await api.patch(`/api/admin/classrooms/${editingClassroom.ulid}`, {
        name: editForm.name,
        tingkat: Number(editForm.tingkat),
        capacity: editForm.capacity ? Number(editForm.capacity) : null,
        homeroom_teacher_ulid: editForm.teacherUlid || null,
        is_active: editForm.isActive,
      });
      toast.success("Kelas berhasil diperbarui.");
      setEditingClassroom(null);
      loadClassrooms(yearFilter);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memperbarui kelas.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleToggleActive(c: ClassroomRow) {
    try {
      await api.patch(`/api/admin/classrooms/${c.ulid}`, { is_active: !c.is_active });
      toast.success(c.is_active ? "Kelas dinonaktifkan." : "Kelas diaktifkan kembali.");
      loadClassrooms(yearFilter);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengubah status kelas.");
    }
  }

  async function handleDelete() {
    if (!deletingClassroom) return;
    setSubmitting(true);
    try {
      await api.delete(`/api/admin/classrooms/${deletingClassroom.ulid}`);
      toast.success("Kelas berhasil dihapus.");
      setDeletingClassroom(null);
      loadClassrooms(yearFilter);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus kelas.");
    } finally {
      setSubmitting(false);
    }
  }

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
            <div className="flex items-center gap-1.5 shrink-0">
              <Button
                size="sm"
                variant="outline"
                onClick={() => handleToggleActive(c)}
                title={c.is_active ? "Nonaktifkan kelas" : "Aktifkan kelas"}
                className={`h-8 px-2.5 text-xs font-semibold gap-1 ${c.is_active ? "" : "text-good border-good/40"}`}
              >
                <Power className="size-3.5" />
                <span>{c.is_active ? "Nonaktifkan" : "Aktifkan"}</span>
              </Button>
              <Button size="sm" variant="outline" onClick={() => openEdit(c)} className="h-8 px-2.5 text-xs font-semibold gap-1">
                <Edit2 className="size-3.5" />
                <span>Edit</span>
              </Button>
              <Button size="sm" variant="ghost" onClick={() => { setDeletingClassroom(c); setDeleteConfirmInput(""); }} className="h-8 px-2 text-destructive hover:bg-destructive/10 hover:text-destructive">
                <Trash2 className="size-3.5" />
              </Button>
            </div>
          </Card>
        ))}
      </div>

      {/* MODAL: EDIT KELAS */}
      {editingClassroom && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
          <Card className="w-full max-w-lg p-6 border-border shadow-2xl space-y-4 my-8">
            <div className="flex items-center justify-between border-b border-border pb-3">
              <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
                <Edit2 className="size-5 text-primary" />
                <span>Edit Kelas</span>
              </h2>
              <button onClick={() => setEditingClassroom(null)} className="text-muted-foreground hover:text-foreground">
                <X className="size-5" />
              </button>
            </div>

            <p className="text-xs text-muted-foreground">
              {editingClassroom.school_unit.label} · Tahun Ajaran {editingClassroom.academic_year ?? "-"}
              <span className="block italic mt-0.5">Unit dan tahun ajaran tidak bisa diubah - buat kelas baru bila perlu berbeda.</span>
            </p>

            <form onSubmit={handleUpdate} className="space-y-3.5 text-xs">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <Label className="text-xs">Nama Kelas</Label>
                  <Input value={editForm.name} onChange={(e) => setEditForm((f) => ({ ...f, name: e.target.value }))} required className="mt-1" />
                </div>
                <div>
                  <Label className="text-xs">Tingkat</Label>
                  <Input type="number" min={1} max={12} value={editForm.tingkat} onChange={(e) => setEditForm((f) => ({ ...f, tingkat: e.target.value }))} required className="mt-1" />
                </div>
              </div>
              <div>
                <Label className="text-xs">Kapasitas</Label>
                <Input type="number" min={1} max={100} value={editForm.capacity} onChange={(e) => setEditForm((f) => ({ ...f, capacity: e.target.value }))} className="mt-1" />
              </div>
              <div>
                <Label className="text-xs">Wali Kelas</Label>
                <select
                  value={editForm.teacherUlid}
                  onChange={(e) => setEditForm((f) => ({ ...f, teacherUlid: e.target.value }))}
                  className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-2xs"
                >
                  <option value="">Belum ditentukan</option>
                  {teachers.map((t) => <option key={t.ulid} value={t.ulid}>{t.name}</option>)}
                </select>
              </div>
              <div className="flex items-center gap-2 pt-1">
                <input
                  type="checkbox"
                  id="classroom_is_active"
                  checked={editForm.isActive}
                  onChange={(e) => setEditForm((f) => ({ ...f, isActive: e.target.checked }))}
                  className="size-4 rounded border-input"
                />
                <Label htmlFor="classroom_is_active" className="text-xs font-semibold cursor-pointer">Kelas Aktif</Label>
              </div>
              <div className="flex justify-end gap-2 border-t border-border pt-4">
                <Button type="button" variant="ghost" onClick={() => setEditingClassroom(null)} disabled={submitting}>Batal</Button>
                <Button type="submit" disabled={submitting} className="font-bold shadow-xs">
                  {submitting ? "Menyimpan…" : "Simpan Perubahan"}
                </Button>
              </div>
            </form>
          </Card>
        </div>
      )}

      {/* MODAL: KONFIRMASI HAPUS KELAS */}
      {deletingClassroom && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4">
          <Card className="w-full max-w-md p-6 border-border shadow-2xl space-y-4">
            <div className="flex items-center gap-3 text-destructive">
              <div className="size-10 rounded-full bg-destructive/10 grid place-items-center">
                <Trash2 className="size-5" />
              </div>
              <div>
                <h3 className="font-bold text-base text-foreground">Hapus Kelas</h3>
                <p className="text-xs text-muted-foreground">Ditolak otomatis bila kelas ini masih punya riwayat siswa.</p>
              </div>
            </div>
            <p className="text-xs text-muted-foreground">
              Hapus kelas <strong className="text-foreground">{deletingClassroom.school_unit.label} · {deletingClassroom.name}</strong>?
            </p>
            <div>
              <Label className="text-xs">
                Ketik <strong className="font-mono text-foreground">{deletingClassroom.name}</strong> untuk konfirmasi
              </Label>
              <Input
                value={deleteConfirmInput}
                onChange={(e) => setDeleteConfirmInput(e.target.value)}
                placeholder={deletingClassroom.name}
                className="mt-1"
                autoFocus
              />
            </div>
            <div className="flex justify-end gap-2 border-t border-border pt-4">
              <Button variant="ghost" size="sm" onClick={() => setDeletingClassroom(null)} disabled={submitting}>Batal</Button>
              <Button
                variant="destructive"
                size="sm"
                onClick={handleDelete}
                disabled={submitting || deleteConfirmInput.trim() !== deletingClassroom.name}
                className="font-bold"
              >
                {submitting ? "Menghapus…" : "Ya, Hapus Kelas"}
              </Button>
            </div>
          </Card>
        </div>
      )}
    </div>
  );
}

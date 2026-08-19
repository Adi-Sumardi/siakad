"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api, ApiError } from "@/lib/api";
import { JUARA_OPTIONS, KATEGORI_OPTIONS, TINGKAT_OPTIONS } from "@/lib/types/kesiswaan";

type Classroom = { ulid: string; name: string };
type StudentRow = { ulid: string; nama_lengkap: string };

export default function GuruAchievementPage() {
  const [classrooms, setClassrooms] = useState<Classroom[]>([]);
  const [classroomUlid, setClassroomUlid] = useState("");
  const [students, setStudents] = useState<StudentRow[]>([]);
  const [studentUlid, setStudentUlid] = useState("");

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api
      .get<{ classrooms: Classroom[] }>("/api/guru/classrooms")
      .then((d) => {
        setClassrooms(d.classrooms);
        if (d.classrooms[0]) setClassroomUlid(d.classrooms[0].ulid);
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar kelas."));
  }, []);

  useEffect(() => {
    if (!classroomUlid) return;
    api
      .get<{ students: StudentRow[] }>(`/api/guru/classrooms/${classroomUlid}/students`)
      .then((d) => {
        setStudents(d.students);
        setStudentUlid(d.students[0]?.ulid ?? "");
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar siswa."));
  }, [classroomUlid]);

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    const form = new FormData(e.currentTarget);
    form.set("student_ulid", studentUlid);

    try {
      await api.post("/api/guru/achievements", form);
      toast.success("Prestasi tercatat dan langsung terverifikasi.");
      (e.target as HTMLFormElement).reset();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal menyimpan.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-xl font-bold tracking-tight">Catat prestasi</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Dicatat guru langsung terverifikasi — Anda saksi langsung. Boleh sekalian beri poin.
        </p>
      </div>

      <Card className="p-5">
        <form onSubmit={submit} className="flex flex-col gap-3" noValidate>
          <div className="grid grid-cols-2 gap-3">
            <div className="flex flex-col gap-1.5">
              <Label>Kelas</Label>
              <select value={classroomUlid} onChange={(e) => setClassroomUlid(e.target.value)} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
                {classrooms.map((c) => <option key={c.ulid} value={c.ulid}>{c.name}</option>)}
              </select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Siswa</Label>
              <select value={studentUlid} onChange={(e) => setStudentUlid(e.target.value)} required className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
                {students.map((s) => <option key={s.ulid} value={s.ulid}>{s.nama_lengkap}</option>)}
              </select>
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="nama_prestasi">Nama prestasi</Label>
            <Input id="nama_prestasi" name="nama_prestasi" required placeholder="Juara 1 Lomba Tahfidz" />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="kategori">Kategori</Label>
              <select id="kategori" name="kategori" required className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
                {KATEGORI_OPTIONS.map((k) => <option key={k} value={k}>{k}</option>)}
              </select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="tingkat">Tingkat</Label>
              <select id="tingkat" name="tingkat" required className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
                {TINGKAT_OPTIONS.map((t) => <option key={t} value={t}>{t}</option>)}
              </select>
            </div>
          </div>

          <div className="grid grid-cols-3 gap-3">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="juara">Juara</Label>
              <select id="juara" name="juara" className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
                <option value="">—</option>
                {JUARA_OPTIONS.map((j) => <option key={j} value={j}>{j}</option>)}
              </select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="tanggal_event">Tanggal</Label>
              <Input id="tanggal_event" name="tanggal_event" type="date" max={new Date().toISOString().slice(0, 10)} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="points_awarded">Beri poin (opsional)</Label>
              <Input id="points_awarded" name="points_awarded" type="number" min={1} placeholder="20" />
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="nama_event">Nama acara (opsional)</Label>
            <Input id="nama_event" name="nama_event" placeholder="Lomba Tahfidz Kecamatan" />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="sertifikat">Sertifikat (opsional)</Label>
              <input id="sertifikat" name="sertifikat" type="file" accept=".jpg,.jpeg,.png,.pdf" className="text-sm" />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="foto_kegiatan">Foto kegiatan (opsional)</Label>
              <input id="foto_kegiatan" name="foto_kegiatan" type="file" accept=".jpg,.jpeg,.png" className="text-sm" />
            </div>
          </div>

          {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
          <Button type="submit" disabled={submitting || !studentUlid} className="self-start">
            {submitting ? "Menyimpan…" : "Catat prestasi"}
          </Button>
        </form>
      </Card>
    </div>
  );
}

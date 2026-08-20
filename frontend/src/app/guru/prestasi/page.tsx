"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, Award, CheckCircle2 } from "lucide-react";
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
      toast.success("Prestasi berhasil dicatat dan otomatis terverifikasi.");
      (e.target as HTMLFormElement).reset();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal menyimpan.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <Link href="/guru" className="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" />
          <span>Kembali ke Kelas Saya</span>
        </Link>
        <div className="mt-2">
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Catat Prestasi Siswa</h1>
          <p className="text-xs text-muted-foreground mt-0.5">
            Prestasi yang dicatat guru/wali kelas otomatis terverifikasi dan poin apresiasi langsung diberikan.
          </p>
        </div>
      </div>

      <Card className="p-6 border-border/80 shadow-md max-w-4xl">
        <form onSubmit={submit} className="space-y-4" noValidate>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <Label className="text-xs">Pilih Kelas</Label>
              <select
                value={classroomUlid}
                onChange={(e) => setClassroomUlid(e.target.value)}
                className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-primary"
              >
                {classrooms.map((c) => <option key={c.ulid} value={c.ulid}>Kelas {c.name}</option>)}
              </select>
            </div>
            <div>
              <Label className="text-xs">Pilih Siswa</Label>
              <select
                value={studentUlid}
                onChange={(e) => setStudentUlid(e.target.value)}
                required
                className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-primary"
              >
                {students.map((s) => <option key={s.ulid} value={s.ulid}>{s.nama_lengkap}</option>)}
              </select>
            </div>
          </div>

          <div>
            <Label htmlFor="nama_prestasi" className="text-xs">Nama Prestasi / Juara</Label>
            <Input id="nama_prestasi" name="nama_prestasi" required placeholder="misal: Juara 1 Lomba Cerdas Cermat" className="mt-1" />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <Label htmlFor="kategori" className="text-xs">Kategori</Label>
              <select id="kategori" name="kategori" required className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-primary">
                {KATEGORI_OPTIONS.map((k) => <option key={k} value={k}>{k}</option>)}
              </select>
            </div>
            <div>
              <Label htmlFor="tingkat" className="text-xs">Tingkat Perlombaan</Label>
              <select id="tingkat" name="tingkat" required className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-primary">
                {TINGKAT_OPTIONS.map((t) => <option key={t} value={t}>{t}</option>)}
              </select>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <Label htmlFor="juara" className="text-xs">Peringkat Juara (Opsional)</Label>
              <select id="juara" name="juara" className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-primary">
                <option value="">—</option>
                {JUARA_OPTIONS.map((j) => <option key={j} value={j}>{j}</option>)}
              </select>
            </div>
            <div>
              <Label htmlFor="tanggal_event" className="text-xs">Tanggal Pelaksanaan</Label>
              <Input id="tanggal_event" name="tanggal_event" type="date" max={new Date().toISOString().slice(0, 10)} className="mt-1" />
            </div>
            <div>
              <Label htmlFor="points_awarded" className="text-xs">Poin Apresiasi Diberikan</Label>
              <Input id="points_awarded" name="points_awarded" type="number" min={1} placeholder="contoh: 20" defaultValue="15" className="mt-1 font-bold" />
            </div>
          </div>

          <div>
            <Label htmlFor="nama_event" className="text-xs">Nama Acara / Penyelenggara (Opsional)</Label>
            <Input id="nama_event" name="nama_event" placeholder="misal: Olimpiade Sains Nasional" className="mt-1" />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <Label htmlFor="sertifikat" className="text-xs">Unggah Sertifikat / Piagam (Opsional)</Label>
              <input id="sertifikat" name="sertifikat" type="file" accept=".jpg,.jpeg,.png,.pdf" className="mt-1 block w-full text-xs text-muted-foreground file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20" />
            </div>
            <div>
              <Label htmlFor="foto_kegiatan" className="text-xs">Foto Dokumentasi Kegiatan (Opsional)</Label>
              <input id="foto_kegiatan" name="foto_kegiatan" type="file" accept=".jpg,.jpeg,.png" className="mt-1 block w-full text-xs text-muted-foreground file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20" />
            </div>
          </div>

          {error && <p className="rounded-lg bg-destructive/10 p-2.5 text-xs text-destructive">{error}</p>}

          <div className="flex justify-end pt-2">
            <Button type="submit" disabled={submitting || !studentUlid} className="gap-2 font-bold shadow-xs">
              <CheckCircle2 className="size-4" />
              <span>{submitting ? "Menyimpan…" : "Simpan & Verifikasi Prestasi"}</span>
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
}

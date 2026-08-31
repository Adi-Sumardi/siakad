"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Download, Search } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { API_BASE, api, ApiError } from "@/lib/api";
import { GRADE_CATEGORY_LABEL, type GradeCategory } from "@/lib/types/kesiswaan";

type GradeRow = {
  ulid: string;
  student: { ulid: string; nama_lengkap: string };
  subject: string;
  classroom: string;
  term: string;
  category: GradeCategory;
  score: number;
  updated_at: string;
};

type ClassroomOption = { ulid: string; name: string; school_unit: { label: string } };
type SubjectOption = { ulid: string; name: string };
type StudentOption = { ulid: string; nama_lengkap: string; nis: string | null };

export default function AdminNilaiPage() {
  const [grades, setGrades] = useState<GradeRow[] | null>(null);
  const [classrooms, setClassrooms] = useState<ClassroomOption[]>([]);
  const [subjects, setSubjects] = useState<SubjectOption[]>([]);
  const [filterClassroom, setFilterClassroom] = useState("");
  const [filterSubject, setFilterSubject] = useState("");

  const [search, setSearch] = useState("");
  const [searchResults, setSearchResults] = useState<StudentOption[] | null>(null);
  const [downloading, setDownloading] = useState<string | null>(null);

  useEffect(() => {
    api.get<{ classrooms: ClassroomOption[] }>("/api/admin/classrooms").then((d) => setClassrooms(d.classrooms));
    api.get<{ subjects: SubjectOption[] }>("/api/admin/subjects").then((d) => setSubjects(d.subjects));
  }, []);

  useEffect(() => {
    const params = new URLSearchParams();
    if (filterClassroom) params.set("classroom", filterClassroom);
    if (filterSubject) params.set("subject", filterSubject);

    api
      .get<{ grades: GradeRow[] }>(`/api/admin/grades?${params.toString()}`)
      .then((d) => setGrades(d.grades))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat data nilai."));
  }, [filterClassroom, filterSubject]);

  async function searchStudents(e: React.FormEvent) {
    e.preventDefault();
    if (!search.trim()) return;

    try {
      const { students } = await api.get<{ students: { data: StudentOption[] } }>(
        `/api/admin/students?search=${encodeURIComponent(search)}`,
      );
      setSearchResults(students.data);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mencari siswa.");
    }
  }

  async function downloadRapor(student: StudentOption) {
    setDownloading(student.ulid);
    try {
      const res = await fetch(`${API_BASE}/api/admin/students/${student.ulid}/rapor`, { credentials: "include" });
      if (!res.ok) throw new Error("Gagal mengunduh rapor.");

      const blob = await res.blob();
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = `Rapor-${student.nama_lengkap.replace(/\s+/g, "-")}.pdf`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);
    } catch {
      toast.error("Gagal mengunduh rapor - mungkin belum ada semester aktif.");
    } finally {
      setDownloading(null);
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold tracking-tight">Nilai &amp; Rapor</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Rekap nilai bersifat lihat-saja - guru yang ditugaskan mengisi lewat panel mereka sendiri.
        </p>
      </div>

      <Card className="p-5">
        <h2 className="mb-3 text-sm font-semibold">Unduh rapor siswa</h2>
        <form onSubmit={searchStudents} className="flex gap-2">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Cari nama atau NIS siswa..."
            className="max-w-sm"
          />
          <Button type="submit" size="sm" variant="outline" className="gap-1.5">
            <Search className="size-3.5" />
            Cari
          </Button>
        </form>
        {searchResults && (
          <div className="mt-3 flex flex-col gap-1.5">
            {searchResults.length === 0 && <p className="text-xs text-muted-foreground">Tidak ada siswa ditemukan.</p>}
            {searchResults.map((s) => (
              <div key={s.ulid} className="flex items-center justify-between rounded-lg bg-muted/30 px-3 py-2">
                <span className="text-sm">{s.nama_lengkap} <span className="text-xs text-muted-foreground">{s.nis}</span></span>
                <Button size="sm" variant="ghost" disabled={downloading === s.ulid} onClick={() => downloadRapor(s)} className="gap-1.5 text-xs">
                  <Download className="size-3.5" />
                  {downloading === s.ulid ? "Mengunduh…" : "Unduh Rapor"}
                </Button>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Card className="p-5">
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label className="text-xs font-semibold text-muted-foreground">Kelas</label>
            <select value={filterClassroom} onChange={(e) => setFilterClassroom(e.target.value)} className="mt-1 block h-9 rounded-lg border border-input bg-card px-3 text-sm">
              <option value="">Semua kelas</option>
              {classrooms.map((c) => <option key={c.ulid} value={c.ulid}>{c.school_unit.label} · {c.name}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs font-semibold text-muted-foreground">Mata Pelajaran</label>
            <select value={filterSubject} onChange={(e) => setFilterSubject(e.target.value)} className="mt-1 block h-9 rounded-lg border border-input bg-card px-3 text-sm">
              <option value="">Semua mapel</option>
              {subjects.map((s) => <option key={s.ulid} value={s.ulid}>{s.name}</option>)}
            </select>
          </div>
        </div>
      </Card>

      {grades === null && <Skeleton className="h-40 w-full" />}

      <div className="flex flex-col gap-2">
        {grades?.map((g) => (
          <Card key={g.ulid} className="flex items-center justify-between gap-3 p-4">
            <div>
              <p className="text-sm font-semibold">{g.student.nama_lengkap}</p>
              <p className="text-xs text-muted-foreground">{g.subject} · Kelas {g.classroom} · {g.term}</p>
            </div>
            <div className="flex items-center gap-2">
              <Badge variant="default">{GRADE_CATEGORY_LABEL[g.category]}</Badge>
              <span className="tabular font-bold text-primary text-sm">{g.score}</span>
            </div>
          </Card>
        ))}
        {grades?.length === 0 && (
          <p className="py-6 text-center text-sm text-muted-foreground">Belum ada nilai tercatat.</p>
        )}
      </div>
    </div>
  );
}

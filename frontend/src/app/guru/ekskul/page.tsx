"use client";

import { useEffect, useState } from "react";
import { ChevronDown, ChevronUp, UserMinus } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";

type StudentOption = { ulid: string; nama_lengkap: string; nis: string | null };
type EkskulRow = { ulid: string; name: string; capacity: number | null; member_count: number };
type Member = { ulid: string; student: StudentOption; joined_on: string | null };

function RosterPanel({ ekskul, onChanged }: { ekskul: EkskulRow; onChanged: () => void }) {
  const [members, setMembers] = useState<Member[] | null>(null);
  const [studentUlid, setStudentUlid] = useState("");
  const [classrooms, setClassrooms] = useState<{ ulid: string; students: StudentOption[] }[]>([]);
  const [allStudents, setAllStudents] = useState<StudentOption[]>([]);
  const [assigning, setAssigning] = useState(false);

  function load() {
    setMembers(null);
    api.get<{ members: Member[] }>(`/api/guru/extracurriculars/${ekskul.ulid}/members`)
      .then((d) => setMembers(d.members))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat anggota."));
  }

  useEffect(load, [ekskul.ulid]);

  useEffect(() => {
    api.get<{ classrooms: { ulid: string }[] }>("/api/guru/classrooms").then(async (d) => {
      const rosters = await Promise.all(
        d.classrooms.map((c) => api.get<{ students: StudentOption[] }>(`/api/guru/classrooms/${c.ulid}/students`)),
      );
      const merged = rosters.flatMap((r) => r.students);
      const unique = Array.from(new Map(merged.map((s) => [s.ulid, s])).values());
      setAllStudents(unique);
    });
  }, []);

  async function assign() {
    if (!studentUlid) return;
    setAssigning(true);
    try {
      await api.post(`/api/guru/extracurriculars/${ekskul.ulid}/members`, { student_ulid: studentUlid });
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
      await api.delete(`/api/guru/extracurriculars/${ekskul.ulid}/members/${memberUlid}`);
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
            {allStudents.map((s) => <option key={s.ulid} value={s.ulid}>{s.nama_lengkap}{s.nis ? ` (${s.nis})` : ""}</option>)}
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

export default function EkskulSayaPage() {
  const [activities, setActivities] = useState<EkskulRow[] | null>(null);
  const [expanded, setExpanded] = useState<string | null>(null);

  function load() {
    api.get<{ extracurriculars: EkskulRow[] }>("/api/guru/my-extracurriculars")
      .then((d) => setActivities(d.extracurriculars))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat ekstrakurikuler."));
  }

  useEffect(load, []);

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-xl font-bold tracking-tight">Ekskul Saya</h1>
        <p className="mt-1 text-sm text-muted-foreground">Kegiatan yang Anda bina, dan siswa yang terdaftar di dalamnya.</p>
      </div>

      {activities === null && <Skeleton className="h-32 w-full" />}
      {activities !== null && activities.length === 0 && (
        <Card className="p-6 text-center text-sm text-muted-foreground">
          Anda belum ditugaskan sebagai pembina ekstrakurikuler apa pun.
        </Card>
      )}

      <div className="flex flex-col gap-2">
        {activities?.map((e) => (
          <Card key={e.ulid} className="overflow-hidden">
            <div className="flex items-center justify-between gap-3 p-4">
              <div>
                <p className="font-medium">{e.name}</p>
                <p className="text-sm text-muted-foreground">{e.member_count} anggota{e.capacity ? ` / ${e.capacity}` : ""}</p>
              </div>
              <Button size="sm" variant="outline" onClick={() => setExpanded(expanded === e.ulid ? null : e.ulid)}>
                {expanded === e.ulid ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
                <span className="ml-1">Kelola Anggota</span>
              </Button>
            </div>
            {expanded === e.ulid && <RosterPanel ekskul={e} onChanged={load} />}
          </Card>
        ))}
      </div>
    </div>
  );
}

"use client";

import { useEffect, useState } from "react";
import { FileDown, Pin, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { API_BASE, api, ApiError } from "@/lib/api";
import { tanggal } from "@/lib/format";
import { useAuth } from "@/lib/auth/auth-context";
import type { Announcement } from "@/lib/types/kesiswaan";

type Unit = { ulid: string; code: string; label: string };
type Classroom = { ulid: string; name: string; school_unit: { code: string; label: string } };

const SCOPE_LABEL: Record<Announcement["scope"], string> = { school: "Seluruh sekolah", unit: "Unit", classroom: "Kelas" };

function NewAnnouncementForm({ units, classrooms, isCentral, onCreated }: {
  units: Unit[]; classrooms: Classroom[]; isCentral: boolean; onCreated: () => void;
}) {
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const [scope, setScope] = useState<"school" | "unit" | "classroom">(isCentral ? "school" : "unit");
  const [unitCode, setUnitCode] = useState(units[0]?.code ?? "");
  const [classroomUlid, setClassroomUlid] = useState("");
  const [isPinned, setIsPinned] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    const form = new FormData(e.currentTarget as HTMLFormElement);
    form.set("title", title);
    form.set("body", body);
    form.set("is_pinned", isPinned ? "1" : "0");
    if (isCentral && scope !== "school") form.set("school_unit_code", unitCode);
    if (scope === "classroom") form.set("classroom_ulid", classroomUlid);

    try {
      await api.post("/api/admin/announcements", form);
      toast.success("Pengumuman diterbitkan.");
      setTitle(""); setBody("");
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal menerbitkan.");
    } finally {
      setSubmitting(false);
    }
  }

  const classroomOptions = classrooms.filter((c) => !unitCode || c.school_unit.code === unitCode);

  return (
    <form onSubmit={submit} className="flex flex-col gap-3">
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="title">Judul</Label>
        <Input id="title" value={title} onChange={(e) => setTitle(e.target.value)} required />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="body">Isi</Label>
        <textarea
          id="body" value={body} onChange={(e) => setBody(e.target.value)} required rows={3}
          className="rounded-lg border border-input bg-card px-3 py-2 text-sm"
        />
      </div>

      <div className="flex flex-wrap items-end gap-3">
        {isCentral && (
          <div className="flex flex-col gap-1.5">
            <Label>Cakupan</Label>
            <select value={scope} onChange={(e) => setScope(e.target.value as typeof scope)} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
              <option value="school">Seluruh sekolah</option>
              <option value="unit">Satu unit</option>
              <option value="classroom">Satu kelas</option>
            </select>
          </div>
        )}

        {(scope === "unit" || scope === "classroom") && (
          <div className="flex flex-col gap-1.5">
            <Label>Unit</Label>
            {isCentral ? (
              <select value={unitCode} onChange={(e) => setUnitCode(e.target.value)} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
                {units.map((u) => <option key={u.code} value={u.code}>{u.label}</option>)}
              </select>
            ) : (
              <Input value={units[0]?.label ?? "Unit Anda"} readOnly className="bg-canvas" />
            )}
          </div>
        )}

        {scope === "classroom" && (
          <div className="flex flex-col gap-1.5">
            <Label>Kelas</Label>
            <select value={classroomUlid} onChange={(e) => setClassroomUlid(e.target.value)} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
              <option value="">Pilih kelas</option>
              {classroomOptions.map((c) => <option key={c.ulid} value={c.ulid}>{c.name}</option>)}
            </select>
          </div>
        )}

        <div className="flex flex-col gap-1.5">
          <Label htmlFor="file">Lampiran (opsional)</Label>
          <input id="file" name="file" type="file" accept=".jpg,.jpeg,.png,.pdf" className="text-sm" />
        </div>

        <label className="flex items-center gap-2 pb-2.5 text-sm">
          <input type="checkbox" checked={isPinned} onChange={(e) => setIsPinned(e.target.checked)} />
          Sematkan
        </label>
      </div>

      {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
      <Button type="submit" disabled={submitting} className="self-start">
        {submitting ? "Menerbitkan…" : "Terbitkan pengumuman"}
      </Button>
    </form>
  );
}

export default function AdminAnnouncementsPage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [announcements, setAnnouncements] = useState<Announcement[] | null>(null);
  const [units, setUnits] = useState<Unit[]>([]);
  const [classrooms, setClassrooms] = useState<Classroom[]>([]);

  function load() {
    api
      .get<{ announcements: Announcement[] }>("/api/admin/announcements")
      .then((d) => setAnnouncements(d.announcements))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat pengumuman."));
  }

  useEffect(() => {
    load();
    api
      .get<{ school_units: Unit[] }>("/api/admin/school-units")
      .then((d) => setUnits(d.school_units))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar unit."));
    api
      .get<{ classrooms: Classroom[] }>("/api/admin/classrooms")
      .then((d) => setClassrooms(d.classrooms))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar kelas."));
  }, []);

  async function remove(a: Announcement) {
    try {
      await api.delete(`/api/admin/announcements/${a.ulid}`);
      toast.success("Pengumuman dihapus.");
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus.");
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-xl font-bold tracking-tight">Informasi</h1>
        <p className="mt-1 text-sm text-muted-foreground">Pengumuman untuk sekolah, unit, atau kelas.</p>
      </div>

      <Card className="p-5">
        {announcements === null ? <Skeleton className="h-32 w-full" /> : (
          <NewAnnouncementForm units={units} classrooms={classrooms} isCentral={isCentral} onCreated={load} />
        )}
      </Card>

      <div className="flex flex-col gap-2">
        {announcements === null && <Skeleton className="h-40 w-full" />}
        {announcements?.map((a) => (
          <Card key={a.ulid} className="flex flex-col gap-2 p-4">
            <div className="flex items-start justify-between gap-3">
              <div className="flex items-center gap-2">
                {a.is_pinned && <Pin className="size-3.5 text-primary" />}
                <p className="font-semibold">{a.title}</p>
              </div>
              <Button size="sm" variant="ghost" onClick={() => remove(a)}><Trash2 className="size-4" /></Button>
            </div>
            <p className="whitespace-pre-line text-sm text-muted-foreground">{a.body}</p>
            <div className="flex flex-wrap items-center gap-2">
              <Badge>
                {SCOPE_LABEL[a.scope]}
                {a.classroom && ` · ${a.classroom}`}
                {!a.classroom && a.school_unit && ` · ${a.school_unit}`}
              </Badge>
              {a.published_at && <span className="text-xs text-muted-foreground">{tanggal(a.published_at)}</span>}
              {a.has_file && (
                <a href={`${API_BASE}/api/files/announcements/${a.ulid}/file`} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs text-primary">
                  <FileDown className="size-3" />{a.file_name ?? "Lampiran"}
                </a>
              )}
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}

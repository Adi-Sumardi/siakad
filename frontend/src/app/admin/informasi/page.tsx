"use client";

import { useEffect, useState } from "react";
import { FileDown, Megaphone, Pin, Plus, RefreshCw, Trash2 } from "lucide-react";
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

const SCOPE_LABEL: Record<Announcement["scope"], string> = {
  school: "Seluruh Sekolah",
  unit: "Unit Sekolah",
  classroom: "Kelas Khusus",
};

function NewAnnouncementForm({
  units,
  classrooms,
  isCentral,
  onCreated,
}: {
  units: Unit[];
  classrooms: Classroom[];
  isCentral: boolean;
  onCreated: () => void;
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
      toast.success("Pengumuman berhasil diterbitkan.");
      setTitle("");
      setBody("");
      setIsPinned(false);
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal menerbitkan.");
    } finally {
      setSubmitting(false);
    }
  }

  const classroomOptions = classrooms.filter((c) => !unitCode || c.school_unit.code === unitCode);

  return (
    <form onSubmit={submit} className="space-y-4">
      <h2 className="text-base font-bold text-foreground">Terbitkan Pengumuman Baru</h2>

      <div>
        <Label htmlFor="title" className="text-xs">Judul Pengumuman</Label>
        <Input
          id="title"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          required
          placeholder="misal: Libur Awal Ramadhan 1448 H"
          className="mt-1"
        />
      </div>

      <div>
        <Label htmlFor="body" className="text-xs">Isi Pengumuman</Label>
        <textarea
          id="body"
          value={body}
          onChange={(e) => setBody(e.target.value)}
          required
          rows={4}
          placeholder="Tulis detail pengumuman resmi di sini..."
          className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-primary focus:outline-hidden"
        />
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        {isCentral && (
          <div>
            <Label className="text-xs">Cakupan Pengumuman</Label>
            <select
              value={scope}
              onChange={(e) => setScope(e.target.value as typeof scope)}
              className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-primary"
            >
              <option value="school">Seluruh Sekolah YAPI</option>
              <option value="unit">Satu Unit Sekolah</option>
              <option value="classroom">Satu Kelas Spesifik</option>
            </select>
          </div>
        )}

        {(scope === "unit" || scope === "classroom") && (
          <div>
            <Label className="text-xs">Unit Sekolah</Label>
            {isCentral ? (
              <select
                value={unitCode}
                onChange={(e) => setUnitCode(e.target.value)}
                className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-primary"
              >
                {units.map((u) => <option key={u.code} value={u.code}>{u.label}</option>)}
              </select>
            ) : (
              <Input value={units[0]?.label ?? "Unit Anda"} readOnly className="mt-1 bg-muted/40" />
            )}
          </div>
        )}

        {scope === "classroom" && (
          <div>
            <Label className="text-xs">Pilih Kelas</Label>
            <select
              value={classroomUlid}
              onChange={(e) => setClassroomUlid(e.target.value)}
              required
              className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-primary"
            >
              <option value="">Pilih kelas...</option>
              {classroomOptions.map((c) => <option key={c.ulid} value={c.ulid}>{c.name}</option>)}
            </select>
          </div>
        )}
      </div>

      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-2">
        <div className="flex items-center gap-4">
          <div>
            <Label htmlFor="file" className="text-xs">Lampiran Dokumen (Opsional):</Label>
            <input
              id="file"
              name="file"
              type="file"
              accept=".jpg,.jpeg,.png,.pdf"
              className="block w-full text-xs text-muted-foreground mt-1 file:mr-2 file:py-1 file:px-2.5 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20"
            />
          </div>

          <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer pt-4">
            <input
              type="checkbox"
              checked={isPinned}
              onChange={(e) => setIsPinned(e.target.checked)}
              className="rounded border-input text-primary"
            />
            <span>Sematkan di atas (Pin)</span>
          </label>
        </div>

        <Button type="submit" disabled={submitting} className="gap-2 self-end sm:self-auto font-bold shadow-xs">
          <Megaphone className="size-4" />
          <span>{submitting ? "Menerbitkan…" : "Terbitkan Sekarang"}</span>
        </Button>
      </div>

      {error && <p className="rounded-lg bg-destructive/10 p-2.5 text-xs text-destructive">{error}</p>}
    </form>
  );
}

export default function AdminAnnouncementsPage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [announcements, setAnnouncements] = useState<Announcement[] | null>(null);
  const [units, setUnits] = useState<Unit[]>([]);
  const [classrooms, setClassrooms] = useState<Classroom[]>([]);
  const [showForm, setShowForm] = useState(false);

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
      .catch(() => {});
    api
      .get<{ classrooms: Classroom[] }>("/api/admin/classrooms")
      .then((d) => setClassrooms(d.classrooms))
      .catch(() => {});
  }, []);

  async function remove(a: Announcement) {
    if (!confirm(`Hapus pengumuman "${a.title}"?`)) return;

    try {
      await api.delete(`/api/admin/announcements/${a.ulid}`);
      toast.success("Pengumuman berhasil dihapus.");
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus.");
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Pusat Informasi & Pengumuman Sekolah</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Publikasikan surat edaran, jadwal libur, dan kabar penting untuk seluruh unit atau kelas.
          </p>
        </div>

        <Button onClick={() => setShowForm(!showForm)} className="gap-2 self-start sm:self-auto shadow-xs">
          <Plus className="size-4" />
          <span>{showForm ? "Tutup Form" : "Buat Pengumuman Baru"}</span>
        </Button>
      </div>

      {/* Form Card (collapsible/visible) */}
      {showForm && (
        <Card className="p-6 border-primary/40 shadow-md">
          {announcements === null ? (
            <Skeleton className="h-32 w-full" />
          ) : (
            <NewAnnouncementForm
              units={units}
              classrooms={classrooms}
              isCentral={isCentral}
              onCreated={() => {
                setShowForm(false);
                load();
              }}
            />
          )}
        </Card>
      )}

      {/* List */}
      {announcements === null && (
        <div className="space-y-3">
          <Skeleton className="h-32 w-full rounded-2xl" />
          <Skeleton className="h-32 w-full rounded-2xl" />
        </div>
      )}

      {announcements?.length === 0 && (
        <Card className="p-8 text-center text-sm text-muted-foreground">
          Belum ada pengumuman yang diterbitkan.
        </Card>
      )}

      <div className="grid grid-cols-1 gap-4">
        {announcements?.map((a) => (
          <Card key={a.ulid} className="p-6 border-border/80 hover:border-primary/40 transition-colors">
            <div className="flex items-start justify-between gap-3">
              <div className="flex items-center gap-2 flex-wrap">
                <h2 className="text-lg font-bold text-foreground">{a.title}</h2>
                {a.is_pinned && (
                  <Badge variant="primary" className="gap-1 font-bold text-[10px]">
                    <Pin className="size-3" />
                    <span>DIPASANG</span>
                  </Badge>
                )}
              </div>
              <Button
                size="sm"
                variant="ghost"
                onClick={() => remove(a)}
                className="text-destructive hover:bg-destructive/10 h-8 w-8 p-0"
              >
                <Trash2 className="size-4" />
              </Button>
            </div>

            <p className="mt-2 text-sm text-muted-foreground whitespace-pre-line leading-relaxed">{a.body}</p>

            <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-border/60 pt-3">
              <div className="flex items-center gap-2 flex-wrap">
                <Badge variant="default">
                  {SCOPE_LABEL[a.scope]}
                  {a.classroom && ` · Kelas ${a.classroom}`}
                  {!a.classroom && a.school_unit && ` · ${a.school_unit}`}
                </Badge>
                {a.published_at && (
                  <span className="text-xs text-muted-foreground">{tanggal(a.published_at)}</span>
                )}
              </div>

              {a.has_file && (
                <a
                  href={`${API_BASE}/api/files/announcements/${a.ulid}/file`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1.5 rounded-lg bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary/20 transition-colors"
                >
                  <FileDown className="size-3.5" />
                  <span>{a.file_name ?? "Unduh Lampiran Dokumen"}</span>
                </a>
              )}
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}

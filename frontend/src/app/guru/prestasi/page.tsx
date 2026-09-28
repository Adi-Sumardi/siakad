"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, CheckCircle2, Inbox } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { todayJakarta } from "@/lib/format";
import { JUARA_OPTIONS, KATEGORI_OPTIONS, TINGKAT_OPTIONS } from "@/lib/types/kesiswaan";

type Classroom = { ulid: string; name: string };
type StudentRow = { ulid: string; nama_lengkap: string };

/** A pending STUDENT achievement for one of this teacher's homeroom classrooms. */
type PendingAchievement = {
  ulid: string;
  nama_prestasi: string;
  kategori: string;
  tingkat: string;
  juara: string | null;
  tanggal_event: string | null;
  point_awarded: number | null;
  student: { nama_lengkap: string } | null;
  recorded_by?: { name: string } | null;
};

export default function GuruAchievementPage() {
  // Poin 7: two tabs - a student's achievement (proposed here, verified by
  // the child's wali kelas) or the teacher's own (verified by their
  // admin_unit). Neither lands verified-on-arrival anymore.
  const [tab, setTab] = useState<"siswa" | "diri">("siswa");

  const [classrooms, setClassrooms] = useState<Classroom[]>([]);
  const [classroomUlid, setClassroomUlid] = useState("");
  const [students, setStudents] = useState<StudentRow[]>([]);
  const [studentUlid, setStudentUlid] = useState("");

  // The homeroom lane's decision queue (audit 2026-09-28): pending cards
  // for the classrooms THIS teacher homerooms, whoever proposed them. The
  // verify/reject endpoints existed since Poin 7 but nothing surfaced the
  // queue - the lane was unreachable dead code.
  const [pending, setPending] = useState<PendingAchievement[] | null>(null);

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function loadPending() {
    api
      .get<{ achievements: PendingAchievement[] }>("/api/guru/achievements")
      .then((d) => setPending(d.achievements))
      .catch(() => setPending([]));
  }

  useEffect(() => {
    if (tab !== "siswa") return;
    api
      .get<{ classrooms: Classroom[] }>("/api/guru/classrooms")
      .then((d) => {
        setClassrooms(d.classrooms);
        if (d.classrooms[0]) setClassroomUlid(d.classrooms[0].ulid);
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar kelas."));
    loadPending();
  }, [tab]);

  useEffect(() => {
    if (!classroomUlid || tab !== "siswa") return;
    api
      .get<{ students: StudentRow[] }>(`/api/guru/classrooms/${classroomUlid}/students`)
      .then((d) => {
        setStudents(d.students);
        setStudentUlid(d.students[0]?.ulid ?? "");
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar siswa."));
  }, [classroomUlid, tab]);

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    const form = new FormData(e.currentTarget);

    try {
      if (tab === "siswa") {
        form.set("student_ulid", studentUlid);
        await api.post("/api/guru/achievements", form);
        toast.success("Pengajuan prestasi siswa tersimpan — menunggu verifikasi wali kelas.");
        loadPending();
      } else {
        await api.post("/api/guru/achievements/self", form);
        toast.success("Pengajuan prestasi pribadi tersimpan — menunggu verifikasi admin unit.");
      }
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
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Ajukan Prestasi</h1>
          <p className="text-xs text-muted-foreground mt-0.5">
            {tab === "siswa"
              ? "Pengajuan prestasi siswa diverifikasi oleh wali kelas anak sebelum poin diberikan."
              : "Pengajuan prestasi pribadi diverifikasi oleh admin unit sekolah Anda."}
          </p>
        </div>
      </div>

      <div className="flex w-fit rounded-lg border border-input bg-card p-0.5 shadow-2xs">
        <button
          type="button"
          onClick={() => setTab("siswa")}
          className={`px-4 py-1.5 rounded-md text-sm font-semibold transition-colors ${tab === "siswa" ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground"}`}
        >
          Prestasi Siswa
        </button>
        <button
          type="button"
          onClick={() => setTab("diri")}
          className={`px-4 py-1.5 rounded-md text-sm font-semibold transition-colors ${tab === "diri" ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground"}`}
        >
          Prestasi Diri Saya
        </button>
      </div>

      {tab === "siswa" && (
        <HomeroomDecisionQueue pending={pending} onDecided={loadPending} />
      )}

      <Card className="p-6 border-border/80 shadow-md max-w-4xl">
        <form onSubmit={submit} className="space-y-4" noValidate>
          {tab === "siswa" && (
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
          )}

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
              <Input id="tanggal_event" name="tanggal_event" type="date" max={todayJakarta()} className="mt-1" />
            </div>
            {tab === "siswa" && (
              <div>
                <Label htmlFor="points_awarded" className="text-xs">Usulan Poin Apresiasi</Label>
                <Input id="points_awarded" name="points_awarded" type="number" min={1} placeholder="contoh: 20" defaultValue="15" className="mt-1 font-bold" />
                <p className="mt-1 text-[11px] text-muted-foreground">
                  Usulan saja — poin baru diberikan saat wali kelas memverifikasi.
                </p>
              </div>
            )}
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
            {/* The diri tab needs no student - gating on !studentUlid kept a
                homeroom-less teacher from EVER submitting their own win
                (audit 2026-09-28). */}
            <Button type="submit" disabled={submitting || (tab === "siswa" && !studentUlid)} className="gap-2 font-bold shadow-xs">
              <CheckCircle2 className="size-4" />
              <span>{submitting ? "Menyimpan…" : "Kirim Pengajuan"}</span>
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
}

/**
 * The homeroom teacher's decision queue: pending STUDENT achievements for
 * the classrooms this teacher homerooms - their own proposals, a
 * colleague's, or a guardian's. Only they (or an admin) can decide these;
 * the API enforces it, this surface finally makes it reachable.
 */
function HomeroomDecisionQueue({ pending, onDecided }: { pending: PendingAchievement[] | null; onDecided: () => void }) {
  const [points, setPoints] = useState<Record<string, string>>({});
  const [reasons, setReasons] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState<string | null>(null);
  const [rowError, setRowError] = useState<string | null>(null);

  async function decide(a: PendingAchievement, kind: "verify" | "reject") {
    setBusy(a.ulid + kind);
    setRowError(null);
    try {
      if (kind === "verify") {
        const p = points[a.ulid] ?? (a.point_awarded ? String(a.point_awarded) : "10");
        await api.post(`/api/guru/achievements/${a.ulid}/verify`, {
          ...(p ? { points_awarded: Number(p) } : {}),
        });
        toast.success(`Prestasi ${a.student?.nama_lengkap ?? ""} terverifikasi${p ? ` — ${p} poin dicatat` : ""}.`);
      } else {
        const reason = (reasons[a.ulid] ?? "").trim();
        if (!reason) {
          setRowError("Alasan penolakan wajib diisi.");
          setBusy(null);
          return;
        }
        await api.post(`/api/guru/achievements/${a.ulid}/reject`, { reason });
        toast.success("Pengajuan ditolak.");
      }
      onDecided();
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        // Decided elsewhere (an admin got there first) - refresh, the card
        // will drop out of the queue on its own.
        toast.info(err.message);
        onDecided();
        return;
      }
      setRowError(err instanceof ApiError ? err.message : "Gagal memutuskan.");
    } finally {
      setBusy(null);
    }
  }

  if (pending === null) {
    return <Skeleton className="h-24 w-full rounded-2xl" />;
  }

  if (pending.length === 0) return null;

  return (
    <Card className="p-5 border-warn/30 bg-warn-soft/30">
      <div className="flex items-center gap-2">
        <Inbox className="size-4 text-warn" />
        <h2 className="text-sm font-bold text-foreground">Menunggu Keputusan Anda (wali kelas)</h2>
        <Badge variant="warn">{pending.length}</Badge>
      </div>
      <p className="mt-0.5 text-xs text-muted-foreground">
        Pengajuan prestasi siswa kelas binaan Anda — dari sesama guru maupun wali murid. Poin hanya tercatat saat Anda memutuskan.
      </p>

      <div className="mt-4 space-y-3">
        {pending.map((a) => (
          <div key={a.ulid} className="rounded-xl border border-border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p className="text-sm font-bold text-foreground">{a.nama_prestasi}</p>
                <p className="text-xs text-muted-foreground">
                  {a.student?.nama_lengkap ?? "—"} · {a.kategori} · Tingkat {a.tingkat}{a.juara ? ` · Juara ${a.juara}` : ""}
                  {a.tanggal_event ? ` · ${a.tanggal_event}` : ""}
                  {a.point_awarded ? ` · usulan ${a.point_awarded} poin` : ""}
                  {a.recorded_by?.name ? ` · diajukan oleh ${a.recorded_by.name}` : ""}
                </p>
              </div>
            </div>

            <div className="mt-3 flex flex-wrap items-end gap-2">
              <div>
                <Label className="text-[11px] text-muted-foreground">Poin Apresiasi</Label>
                <Input
                  type="number"
                  min={1}
                  value={points[a.ulid] ?? (a.point_awarded ? String(a.point_awarded) : "10")}
                  onChange={(e) => setPoints((prev) => ({ ...prev, [a.ulid]: e.target.value }))}
                  className="mt-1 h-9 w-28 text-xs font-bold"
                />
              </div>
              <Button
                size="sm"
                disabled={busy !== null}
                onClick={() => decide(a, "verify")}
                className="gap-1.5 text-xs font-semibold"
              >
                <CheckCircle2 className="size-3.5" />
                {busy === a.ulid + "verify" ? "Memproses…" : "Verifikasi & Beri Poin"}
              </Button>
              <div className="flex-1 min-w-[180px]">
                <Label className="text-[11px] text-muted-foreground">Alasan (untuk menolak)</Label>
                <Input
                  value={reasons[a.ulid] ?? ""}
                  onChange={(e) => setReasons((prev) => ({ ...prev, [a.ulid]: e.target.value }))}
                  placeholder="wajib bila menolak"
                  className="mt-1 h-9 text-xs"
                />
              </div>
              <Button
                size="sm"
                variant="ghost"
                disabled={busy !== null}
                onClick={() => decide(a, "reject")}
                className="text-xs text-destructive hover:bg-destructive/10"
              >
                {busy === a.ulid + "reject" ? "Memproses…" : "Tolak"}
              </Button>
            </div>

            {rowError && busy === null && <p className="mt-2 rounded-lg bg-destructive/10 p-2 text-xs text-destructive">{rowError}</p>}
          </div>
        ))}
      </div>
    </Card>
  );
}

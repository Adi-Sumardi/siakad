"use client";

import { use, useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, Award, ClipboardList, Download, FileDown, Plus, Sparkles, Trophy, UserCheck, X } from "lucide-react";
import { toast } from "sonner";
import { WaliShell } from "@/components/layout/wali-shell";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { PointMeter } from "@/components/point-meter";
import { AttendanceMeter } from "@/components/attendance-meter";
import { API_BASE, api, ApiError } from "@/lib/api";
import { useRequireRole } from "@/lib/auth/use-require-role";
import { tanggal } from "@/lib/format";
import {
  ATTENDANCE_STATUS_LABEL,
  JUARA_OPTIONS,
  KATEGORI_OPTIONS,
  TINGKAT_OPTIONS,
  type Achievement,
  type AttendanceOverview,
  type PointSummary,
  type SubjectGradeSummary,
} from "@/lib/types/kesiswaan";

const STATUS_LABEL: Record<Achievement["status"], { label: string; variant: "good" | "warn" | "bad" }> = {
  verified: { label: "Terverifikasi", variant: "good" },
  pending: { label: "Menunggu verifikasi", variant: "warn" },
  rejected: { label: "Ditolak", variant: "bad" },
};

function PointHistory({ points }: { points: PointSummary }) {
  if (points.records.length === 0) {
    return <p className="p-5 text-sm text-muted-foreground text-center">Belum ada catatan poin semester ini.</p>;
  }

  return (
    <div className="flex flex-col divide-y divide-border">
      {points.records.map((record) => (
        <div
          key={record.ulid}
          className={`flex items-start justify-between gap-4 px-5 py-3.5 ${record.status === "revoked" ? "opacity-50" : ""}`}
        >
          <div className="min-w-0 flex-1">
            <p className="text-sm font-semibold text-foreground">
              {record.description}
              {record.status === "revoked" && <span className="ml-2 text-xs text-muted-foreground">(dibatalkan)</span>}
            </p>
            <p className="text-xs text-muted-foreground mt-0.5">
              {tanggal(record.occurred_on)}
              {record.rule && ` · ${record.rule.category}`}
              {record.recorded_by && ` · dicatat oleh ${record.recorded_by}`}
            </p>
            {record.status === "revoked" && record.revoke_reason && (
              <p className="mt-1 text-xs text-muted-foreground">Alasan: {record.revoke_reason}</p>
            )}
          </div>
          <span className={`tabular shrink-0 text-sm font-bold ${record.points > 0 ? "text-good" : "text-bad"}`}>
            {record.points > 0 ? `+${record.points}` : record.points}
          </span>
        </div>
      ))}
    </div>
  );
}

function AttendanceHistory({ attendance }: { attendance: AttendanceOverview }) {
  if (attendance.records.length === 0) {
    return <p className="p-5 text-sm text-muted-foreground text-center">Belum ada catatan presensi semester ini.</p>;
  }

  return (
    <div className="flex flex-col divide-y divide-border">
      {attendance.records.map((record) => (
        <div key={record.ulid} className="flex items-start justify-between gap-4 px-5 py-3.5">
          <div className="min-w-0 flex-1">
            <p className="text-sm font-semibold text-foreground">{tanggal(record.occurred_on)}</p>
            {record.description && <p className="text-xs text-muted-foreground mt-0.5">{record.description}</p>}
          </div>
          <Badge
            variant={
              record.attendance_status === "hadir" ? "good" :
              record.attendance_status === "alpa" ? "bad" :
              record.attendance_status === "sakit" ? "warn" : "default"
            }
          >
            {ATTENDANCE_STATUS_LABEL[record.attendance_status]}
          </Badge>
        </div>
      ))}
    </div>
  );
}

function AchievementCard({ achievement }: { achievement: Achievement }) {
  const status = STATUS_LABEL[achievement.status];

  return (
    <Card className="flex flex-col gap-3 p-5 border-border/80 hover:border-primary/40 transition-colors">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="font-bold text-foreground text-base">{achievement.nama_prestasi}</p>
          <p className="text-xs text-muted-foreground mt-0.5">
            {achievement.kategori} · Tingkat {achievement.tingkat}
            {achievement.juara && ` · Juara ${achievement.juara}`}
          </p>
        </div>
        <Badge variant={status.variant}>{status.label}</Badge>
      </div>

      {achievement.nama_event && (
        <p className="text-xs text-muted-foreground">
          Event: <strong className="text-foreground">{achievement.nama_event}</strong>
          {achievement.tanggal_event && ` · ${tanggal(achievement.tanggal_event)}`}
        </p>
      )}

      {achievement.status === "rejected" && achievement.rejection_reason && (
        <p className="text-xs text-bad bg-bad-soft/40 p-2 rounded-lg">
          Alasan penolakan: {achievement.rejection_reason}
        </p>
      )}

      {achievement.point_awarded && (
        <p className="text-xs font-bold text-good">+{achievement.point_awarded} poin apresiasi diberikan</p>
      )}

      {achievement.has_sertifikat && (
        <a
          href={`${API_BASE}/api/files/achievements/${achievement.ulid}/sertifikat`}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex w-fit items-center gap-1.5 rounded-lg bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary/20 transition-colors"
        >
          <FileDown className="size-3.5" />
          <span>Lihat Sertifikat Piagam</span>
        </a>
      )}
    </Card>
  );
}

function SubmitAchievementForm({ studentUlid, onSubmitted }: { studentUlid: string; onSubmitted: () => void }) {
  const [open, setOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      await api.post(`/api/wali/students/${studentUlid}/achievements`, new FormData(event.currentTarget));
      toast.success("Prestasi berhasil diajukan. Menunggu verifikasi dari sekolah.");
      setOpen(false);
      onSubmitted();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Tidak dapat mengirim pengajuan.");
    } finally {
      setSubmitting(false);
    }
  }

  if (!open) {
    return (
      <Button onClick={() => setOpen(true)} className="gap-2 shadow-xs text-xs">
        <Plus className="size-4" />
        <span>Ajukan Prestasi Baru</span>
      </Button>
    );
  }

  return (
    <Card className="p-6 border-primary/40 shadow-lg">
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h3 className="font-bold text-foreground text-base">Ajukan Prestasi Ananda</h3>
          <p className="text-xs text-muted-foreground">Unggah sertifikat/piagam kejuaraan atau perlombaan ananda.</p>
        </div>
        <button type="button" onClick={() => setOpen(false)} className="rounded-lg p-1 text-muted-foreground hover:bg-accent">
          <X className="size-4" />
        </button>
      </div>

      <form onSubmit={submit} className="flex flex-col gap-3.5" noValidate>
        <div>
          <Label htmlFor="nama_prestasi" className="text-xs">Nama Prestasi / Juara</Label>
          <Input id="nama_prestasi" name="nama_prestasi" required placeholder="misal: Juara 1 Lomba Tahfidz Al-Quran" className="mt-1" />
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
        </div>

        <div>
          <Label htmlFor="nama_event" className="text-xs">Nama Acara / Penyelenggara</Label>
          <Input id="nama_event" name="nama_event" placeholder="misal: Festival Anak Sholeh Tingkat Provinsi" className="mt-1" />
        </div>

        <div>
          <Label htmlFor="sertifikat" className="text-xs">Unggah Sertifikat / Piagam (PDF / Gambar)</Label>
          <input id="sertifikat" name="sertifikat" type="file" accept=".jpg,.jpeg,.png,.pdf" className="mt-1 block w-full text-xs text-muted-foreground file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20" />
        </div>

        {error && <p role="alert" className="rounded-lg bg-destructive/10 p-2.5 text-xs text-destructive">{error}</p>}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" size="sm" onClick={() => setOpen(false)}>
            Batal
          </Button>
          <Button type="submit" size="sm" disabled={submitting}>
            {submitting ? "Mengirim Pengajuan…" : "Kirim Ajuan Prestasi"}
          </Button>
        </div>
      </form>
    </Card>
  );
}

export default function StudentDetailPage({ params }: { params: Promise<{ ulid: string }> }) {
  const { ulid } = use(params);
  const { user, loading } = useRequireRole("orangtua");

  const [points, setPoints] = useState<PointSummary | null>(null);
  const [attendance, setAttendance] = useState<AttendanceOverview | null>(null);
  const [achievements, setAchievements] = useState<Achievement[] | null>(null);
  const [grades, setGrades] = useState<{ term: string | null; subjects: SubjectGradeSummary[] } | null>(null);
  const [extracurriculars, setExtracurriculars] = useState<{ ulid: string; name: string; pembina: string | null; school_unit: string | null }[] | null>(null);
  const [downloadingRapor, setDownloadingRapor] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function loadAchievements() {
    api
      .get<{ achievements: Achievement[] }>(`/api/wali/students/${ulid}/achievements`)
      .then((d) => setAchievements(d.achievements))
      .catch((err) => setError(err instanceof ApiError ? err.message : "Tidak dapat memuat data anak."));
  }

  useEffect(() => {
    if (user?.role !== "orangtua") return;

    api
      .get<PointSummary>(`/api/wali/students/${ulid}/points`)
      .then(setPoints)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Tidak dapat memuat data anak."));
    api
      .get<AttendanceOverview>(`/api/wali/students/${ulid}/attendance`)
      .then(setAttendance)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Tidak dapat memuat data anak."));
    api
      .get<{ term: string | null; subjects: SubjectGradeSummary[] }>(`/api/wali/students/${ulid}/grades`)
      .then(setGrades)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Tidak dapat memuat data anak."));
    api
      .get<{ extracurriculars: { ulid: string; name: string; pembina: string | null; school_unit: string | null }[] }>(`/api/wali/students/${ulid}/extracurriculars`)
      .then((d) => setExtracurriculars(d.extracurriculars))
      .catch((err) => setError(err instanceof ApiError ? err.message : "Tidak dapat memuat data anak."));
    loadAchievements();
  }, [ulid, user]);

  async function downloadRapor() {
    setDownloadingRapor(true);
    try {
      const res = await fetch(`${API_BASE}/api/wali/students/${ulid}/rapor`, { credentials: "include" });
      if (!res.ok) throw new Error("Gagal mengunduh rapor.");

      const blob = await res.blob();
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = `Rapor-${points?.student.nama_lengkap ?? "siswa"}.pdf`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);
    } catch {
      toast.error("Gagal mengunduh rapor - mungkin belum ada semester aktif.");
    } finally {
      setDownloadingRapor(false);
    }
  }

  if (loading || !user || user.role !== "orangtua") {
    return (
      <WaliShell>
        <div className="space-y-4">
          <Skeleton className="h-8 w-48" />
          <Skeleton className="h-32 w-full" />
        </div>
      </WaliShell>
    );
  }

  if (error) {
    return (
      <WaliShell>
        <Card className="p-8 text-center space-y-3">
          <p className="text-sm text-destructive">{error}</p>
          <Link href="/dashboard">
            <Button variant="outline" size="sm">Kembali ke Beranda</Button>
          </Link>
        </Card>
      </WaliShell>
    );
  }

  return (
    <WaliShell>
      <div className="space-y-8">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <Link href="/dashboard" className="text-xs font-semibold text-muted-foreground hover:text-foreground inline-flex items-center gap-1">
                <ArrowLeft className="size-3.5" />
                <span>Kembali ke Beranda</span>
              </Link>
            </div>
            <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-foreground mt-1">
              {points ? `${points.student.nama_lengkap}` : "Detail Perkembangan Ananda"}
            </h1>
            {points?.term && (
              <p className="text-sm text-muted-foreground">
                Rekapitulasi Tata Tertib & Prestasi · Semester {points.term}
              </p>
            )}
          </div>
        </div>

        {/* SECTION 1: POINT METER & HISTORY */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <Card className="p-6 border-border/80 lg:col-span-1 flex flex-col justify-between">
            <div>
              <h2 className="text-base font-bold text-foreground flex items-center gap-2 mb-4">
                <Sparkles className="size-5 text-amber-500" />
                <span>Poin Kedisiplinan</span>
              </h2>
              {points === null ? (
                <Skeleton className="h-20 w-full" />
              ) : (
                <div className="space-y-3">
                  <PointMeter balance={points.balance} threshold={points.threshold} />
                  {points.threshold?.action && (
                    <div className="mt-3 p-3 rounded-xl bg-muted/40 text-xs text-muted-foreground">
                      <strong>Tindakan Pembinaan:</strong> {points.threshold.action}
                    </div>
                  )}
                </div>
              )}
            </div>
          </Card>

          <Card className="overflow-hidden border-border/80 lg:col-span-2">
            <div className="border-b border-border bg-muted/30 px-5 py-3.5">
              <h2 className="text-sm font-bold text-foreground">Riwayat Catatan Poin Semester Ini</h2>
            </div>
            {points === null ? (
              <div className="p-5"><Skeleton className="h-24 w-full" /></div>
            ) : (
              <PointHistory points={points} />
            )}
          </Card>
        </div>

        {/* SECTION 1B: PRESENSI */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <Card className="p-6 border-border/80 lg:col-span-1">
            <h2 className="text-base font-bold text-foreground flex items-center gap-2 mb-4">
              <UserCheck className="size-5 text-emerald-500" />
              <span>Presensi Kehadiran</span>
            </h2>
            {attendance === null ? <Skeleton className="h-20 w-full" /> : <AttendanceMeter summary={attendance.summary} />}
          </Card>

          <Card className="overflow-hidden border-border/80 lg:col-span-2">
            <div className="border-b border-border bg-muted/30 px-5 py-3.5">
              <h2 className="text-sm font-bold text-foreground">Riwayat Presensi Semester Ini</h2>
            </div>
            {attendance === null ? (
              <div className="p-5"><Skeleton className="h-24 w-full" /></div>
            ) : (
              <AttendanceHistory attendance={attendance} />
            )}
          </Card>
        </div>

        {/* SECTION 1C: NILAI AKADEMIK */}
        <section className="space-y-3 pt-4 border-t border-border">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
                <ClipboardList className="size-5 text-primary" />
                <span>Nilai Akademik</span>
              </h2>
              <p className="text-xs text-muted-foreground">
                {grades?.term ? `Semester ${grades.term}` : "Rekap nilai per mata pelajaran."}
              </p>
            </div>
            <Button size="sm" variant="outline" disabled={downloadingRapor} onClick={downloadRapor} className="gap-2 text-xs font-semibold">
              <Download className="size-4" />
              <span>{downloadingRapor ? "Mengunduh…" : "Unduh Rapor (PDF)"}</span>
            </Button>
          </div>

          {grades === null ? (
            <Skeleton className="h-24 w-full" />
          ) : grades.subjects.length === 0 ? (
            <Card className="p-6 text-center text-sm text-muted-foreground">
              Belum ada mata pelajaran terjadwal untuk kelas ananda.
            </Card>
          ) : (
            <Card className="overflow-hidden border-border/80">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-border bg-muted/30 text-xs font-bold uppercase tracking-wide text-muted-foreground">
                  <tr>
                    <th className="px-5 py-3">Mata Pelajaran</th>
                    <th className="px-3 py-3 text-right">Tugas</th>
                    <th className="px-3 py-3 text-right">UTS</th>
                    <th className="px-3 py-3 text-right">UAS</th>
                    <th className="px-5 py-3 text-right">Nilai Akhir</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {grades.subjects.map((row) => (
                    <tr key={row.subject.ulid}>
                      <td className="px-5 py-3 font-semibold">{row.subject.name}</td>
                      <td className="px-3 py-3 text-right tabular">{row.tugas ?? "—"}</td>
                      <td className="px-3 py-3 text-right tabular">{row.uts ?? "—"}</td>
                      <td className="px-3 py-3 text-right tabular">{row.uas ?? "—"}</td>
                      <td className="px-5 py-3 text-right">
                        {row.final !== null ? (
                          <span className="tabular font-bold text-primary">{row.final}</span>
                        ) : (
                          <Badge variant="warn">Belum lengkap</Badge>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          )}
        </section>

        {/* SECTION 1D: EKSTRAKURIKULER */}
        <section className="space-y-3 pt-4 border-t border-border">
          <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
            <Trophy className="size-5 text-primary" />
            <span>Ekstrakurikuler</span>
          </h2>

          {extracurriculars === null ? (
            <Skeleton className="h-16 w-full" />
          ) : extracurriculars.length === 0 ? (
            <Card className="p-6 text-center text-sm text-muted-foreground">
              Ananda belum terdaftar di ekstrakurikuler apa pun.
            </Card>
          ) : (
            <div className="flex flex-wrap gap-2">
              {extracurriculars.map((e) => (
                <Badge key={e.ulid} variant="primary" className="px-3 py-1.5 text-xs">
                  {e.name}{e.pembina ? ` · ${e.pembina}` : ""}
                </Badge>
              ))}
            </div>
          )}
        </section>

        {/* SECTION 2: PRESTASI */}
        <section className="space-y-4 pt-4 border-t border-border">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
                <Award className="size-5 text-primary" />
                <span>Prestasi & Kejuaraan Ananda</span>
              </h2>
              <p className="text-xs text-muted-foreground">Koleksi prestasi terverifikasi dan ajuan piagam penghargaan.</p>
            </div>
            <SubmitAchievementForm studentUlid={ulid} onSubmitted={loadAchievements} />
          </div>

          {achievements === null && (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Skeleton className="h-24 w-full rounded-2xl" />
              <Skeleton className="h-24 w-full rounded-2xl" />
            </div>
          )}

          {achievements?.length === 0 && (
            <Card className="p-8 text-center text-sm text-muted-foreground">
              Belum ada prestasi yang tercatat. Klik tombol &quot;Ajukan Prestasi Baru&quot; di atas untuk mendaftarkan prestasi ananda.
            </Card>
          )}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {achievements?.map((a) => (
              <AchievementCard key={a.ulid} achievement={a} />
            ))}
          </div>
        </section>
      </div>
    </WaliShell>
  );
}

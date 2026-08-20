"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import {
  Award,
  Calendar,
  CheckCircle2,
  ChevronRight,
  ExternalLink,
  FileDown,
  GraduationCap,
  Plus,
  RefreshCw,
  Sparkles,
  Trophy,
  X,
} from "lucide-react";
import { toast } from "sonner";
import { WaliShell } from "@/components/layout/wali-shell";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { API_BASE, api, ApiError } from "@/lib/api";
import { useRequireRole } from "@/lib/auth/use-require-role";
import { tanggal } from "@/lib/format";
import {
  JUARA_OPTIONS,
  KATEGORI_OPTIONS,
  TINGKAT_OPTIONS,
  type Achievement,
} from "@/lib/types/kesiswaan";

type StudentWithAchievements = {
  ulid: string;
  nama_lengkap: string;
  nis: string | null;
  unit: { code: string; label: string } | null;
  kelas: { name: string } | null;
  achievements: Achievement[];
};

export default function WaliPrestasiPage() {
  const { user, loading } = useRequireRole("orangtua");
  const [students, setStudents] = useState<StudentWithAchievements[] | null>(null);
  const [selectedStudent, setSelectedStudent] = useState<string>("all");
  const [submittingFor, setSubmittingFor] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function loadData() {
    try {
      const { students: rawStudents } = await api.get<{
        students: Array<{
          ulid: string;
          nama_lengkap: string;
          nis: string | null;
          unit: { code: string; label: string } | null;
          kelas: { name: string } | null;
        }>;
      }>("/api/wali/students");

      const studentsWithAch = await Promise.all(
        rawStudents.map(async (st) => {
          try {
            const { achievements } = await api.get<{ achievements: Achievement[] }>(
              `/api/wali/students/${st.ulid}/achievements`
            );
            return { ...st, achievements };
          } catch {
            return { ...st, achievements: [] };
          }
        })
      );

      setStudents(studentsWithAch);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar prestasi.");
    }
  }

  useEffect(() => {
    if (user?.role === "orangtua") {
      loadData();
    }
  }, [user]);

  async function submitAchievement(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!submittingFor) return;

    setSubmitting(true);
    setError(null);

    try {
      await api.post(`/api/wali/students/${submittingFor}/achievements`, new FormData(event.currentTarget));
      toast.success("Prestasi ananda berhasil diajukan! Tim kesiswaan sekolah akan segera memverifikasinya.");
      setSubmittingFor(null);
      loadData();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Tidak dapat mengirim pengajuan.");
    } finally {
      setSubmitting(false);
    }
  }

  if (loading || !user || user.role !== "orangtua") {
    return (
      <WaliShell>
        <div className="space-y-4">
          <Skeleton className="h-8 w-48" />
          <Skeleton className="h-32 w-full rounded-2xl" />
        </div>
      </WaliShell>
    );
  }

  const allAchievements = students?.flatMap((s) => s.achievements.map((a) => ({ ...a, student: s }))) ?? [];
  const filteredAchievements = selectedStudent === "all"
    ? allAchievements
    : allAchievements.filter((a) => a.student.ulid === selectedStudent);

  return (
    <WaliShell>
      <div className="space-y-6 pb-24">
        {/* Page Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-2xl sm:text-3xl font-black tracking-tight text-foreground flex items-center gap-2.5">
              <Trophy className="size-7 text-amber-500" />
              <span>Prestasi & Rekap Kejuaraan Siswa</span>
            </h1>
            <p className="text-xs sm:text-sm text-muted-foreground mt-1">
              Catatan piagam penghargaan, apresiasi bakat, dan pengajuan prestasi lomba ananda.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={loadData} className="gap-2 text-xs font-semibold">
              <RefreshCw className="size-3.5" />
              <span>Segarkan</span>
            </Button>
            {students && students.length > 0 && (
              <Button
                size="sm"
                onClick={() => setSubmittingFor(students[0].ulid)}
                className="gap-2 text-xs font-bold shadow-xs"
              >
                <Plus className="size-4" />
                <span>Ajukan Prestasi</span>
              </Button>
            )}
          </div>
        </div>

        {/* Filter by Child */}
        {students && students.length > 1 && (
          <div className="flex items-center gap-2 overflow-x-auto pb-2">
            <Button
              size="sm"
              variant={selectedStudent === "all" ? "default" : "outline"}
              onClick={() => setSelectedStudent("all")}
              className="text-xs font-bold shrink-0"
            >
              Semua Ananda ({allAchievements.length})
            </Button>
            {students.map((st) => (
              <Button
                key={st.ulid}
                size="sm"
                variant={selectedStudent === st.ulid ? "default" : "outline"}
                onClick={() => setSelectedStudent(st.ulid)}
                className="text-xs font-semibold shrink-0 gap-1.5"
              >
                <GraduationCap className="size-3.5" />
                <span>{st.nama_lengkap} ({st.achievements.length})</span>
              </Button>
            ))}
          </div>
        )}

        {/* Loading Skeleton */}
        {students === null && (
          <div className="space-y-3">
            <Skeleton className="h-32 w-full rounded-2xl" />
            <Skeleton className="h-32 w-full rounded-2xl" />
          </div>
        )}

        {/* Empty State */}
        {students !== null && filteredAchievements.length === 0 && (
          <Card className="p-12 text-center space-y-3 border-dashed">
            <Trophy className="size-10 text-muted-foreground mx-auto" />
            <p className="font-bold text-foreground">Belum ada prestasi yang tercatat</p>
            <p className="text-xs text-muted-foreground max-w-sm mx-auto">
              Bila ananda meraih kejuaraan atau prestasi di luar sekolah, silakan ajukan piagamnya agar dicatat secara resmi.
            </p>
            {students.length > 0 && (
              <Button
                size="sm"
                onClick={() => setSubmittingFor(students[0].ulid)}
                className="gap-2 font-bold mt-2"
              >
                <Plus className="size-4" />
                <span>Ajukan Prestasi Ananda</span>
              </Button>
            )}
          </Card>
        )}

        {/* Achievement Cards Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {filteredAchievements.map((ach) => {
            const isVerified = ach.status === "verified";
            const isPending = ach.status === "pending";

            return (
              <Card
                key={ach.ulid}
                className="p-5 border-border hover:border-primary/40 transition-all bg-card shadow-xs rounded-2xl flex flex-col justify-between space-y-4"
              >
                <div className="space-y-2.5">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <span className="text-[11px] font-bold text-primary block">{ach.student.nama_lengkap}</span>
                      <h3 className="font-bold text-base text-foreground mt-0.5">{ach.nama_prestasi}</h3>
                      <p className="text-xs text-muted-foreground mt-0.5">
                        {ach.kategori} · Tingkat {ach.tingkat} {ach.juara ? `· ${ach.juara}` : ""}
                      </p>
                    </div>

                    <Badge variant={isVerified ? "good" : isPending ? "warn" : "bad"}>
                      {isVerified ? "Terverifikasi" : isPending ? "Menunggu Verifikasi" : "Ditolak"}
                    </Badge>
                  </div>

                  {ach.nama_event && (
                    <div className="text-xs text-muted-foreground bg-muted/30 p-2.5 rounded-xl">
                      <p><strong>Penyelenggara:</strong> {ach.nama_event}</p>
                      {ach.tanggal_event && <p className="mt-0.5"><strong>Tanggal:</strong> {tanggal(ach.tanggal_event)}</p>}
                    </div>
                  )}

                  {ach.point_awarded && ach.point_awarded > 0 && (
                    <div className="flex items-center gap-1.5 text-xs font-bold text-emerald-600 bg-emerald-500/10 p-2 rounded-lg">
                      <Sparkles className="size-3.5" />
                      <span>+{ach.point_awarded} Poin Apresiasi Kesiswaan Diberikan</span>
                    </div>
                  )}
                </div>

                <div className="flex items-center justify-between pt-3 border-t border-border/60">
                  <Link
                    href={`/anak/${ach.student.ulid}`}
                    className="text-xs font-semibold text-primary hover:underline inline-flex items-center gap-1"
                  >
                    <span>Profil Ananda</span>
                    <ChevronRight className="size-3.5" />
                  </Link>

                  {ach.has_sertifikat && (
                    <a
                      href={`${API_BASE}/api/files/achievements/${ach.ulid}/sertifikat`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1.5 text-xs font-bold text-foreground bg-muted/60 hover:bg-muted px-3 py-1.5 rounded-lg transition-colors"
                    >
                      <FileDown className="size-3.5 text-primary" />
                      <span>Lihat Piagam</span>
                    </a>
                  )}
                </div>
              </Card>
            );
          })}
        </div>

        {/* MODAL AJUKAN PRESTASI */}
        {submittingFor && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
            <div className="w-full max-w-lg rounded-2xl bg-card p-6 shadow-2xl border border-border space-y-4 my-8">
              <div className="flex items-center justify-between border-b border-border/80 pb-3">
                <div>
                  <h2 className="text-base font-black text-foreground">Ajukan Prestasi Baru</h2>
                  <p className="text-xs text-muted-foreground">
                    Unggah bukti sertifikat atau piagam kejuaraan ananda
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => setSubmittingFor(null)}
                  className="rounded-lg p-1.5 text-muted-foreground hover:bg-accent"
                >
                  <X className="size-5" />
                </button>
              </div>

              <form onSubmit={submitAchievement} className="space-y-3.5" noValidate>
                {students && students.length > 1 && (
                  <div>
                    <Label htmlFor="student_select" className="text-xs font-semibold">Pilih Ananda</Label>
                    <select
                      id="student_select"
                      value={submittingFor}
                      onChange={(e) => setSubmittingFor(e.target.value)}
                      className="mt-1 w-full rounded-xl border border-input bg-card px-3 py-2 text-sm shadow-xs font-semibold"
                    >
                      {students.map((st) => (
                        <option key={st.ulid} value={st.ulid}>{st.nama_lengkap}</option>
                      ))}
                    </select>
                  </div>
                )}

                <div>
                  <Label htmlFor="nama_prestasi" className="text-xs font-semibold">Nama Prestasi / Juara</Label>
                  <Input
                    id="nama_prestasi"
                    name="nama_prestasi"
                    required
                    placeholder="misal: Juara 1 Olimpiade Sains Nasional"
                    className="mt-1"
                  />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div>
                    <Label htmlFor="kategori" className="text-xs font-semibold">Kategori Bidang</Label>
                    <select
                      id="kategori"
                      name="kategori"
                      required
                      className="mt-1 w-full rounded-xl border border-input bg-card px-3 py-2 text-sm shadow-xs"
                    >
                      {KATEGORI_OPTIONS.map((k) => <option key={k} value={k}>{k}</option>)}
                    </select>
                  </div>
                  <div>
                    <Label htmlFor="tingkat" className="text-xs font-semibold">Tingkat Lomba</Label>
                    <select
                      id="tingkat"
                      name="tingkat"
                      required
                      className="mt-1 w-full rounded-xl border border-input bg-card px-3 py-2 text-sm shadow-xs"
                    >
                      {TINGKAT_OPTIONS.map((t) => <option key={t} value={t}>{t}</option>)}
                    </select>
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div>
                    <Label htmlFor="juara" className="text-xs font-semibold">Peringkat (Opsional)</Label>
                    <select
                      id="juara"
                      name="juara"
                      className="mt-1 w-full rounded-xl border border-input bg-card px-3 py-2 text-sm shadow-xs"
                    >
                      <option value="">—</option>
                      {JUARA_OPTIONS.map((j) => <option key={j} value={j}>{j}</option>)}
                    </select>
                  </div>
                  <div>
                    <Label htmlFor="tanggal_event" className="text-xs font-semibold">Tanggal Kegiatan</Label>
                    <Input
                      id="tanggal_event"
                      name="tanggal_event"
                      type="date"
                      max={new Date().toISOString().slice(0, 10)}
                      className="mt-1"
                    />
                  </div>
                </div>

                <div>
                  <Label htmlFor="nama_event" className="text-xs font-semibold">Nama Event / Penyelenggara</Label>
                  <Input
                    id="nama_event"
                    name="nama_event"
                    placeholder="misal: Kemendikbudristek RI"
                    className="mt-1"
                  />
                </div>

                <div>
                  <Label htmlFor="sertifikat" className="text-xs font-semibold">Unggah File Sertifikat (PDF / JPG / PNG)</Label>
                  <input
                    id="sertifikat"
                    name="sertifikat"
                    type="file"
                    accept=".jpg,.jpeg,.png,.pdf"
                    className="mt-1 block w-full text-xs text-muted-foreground file:mr-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 cursor-pointer"
                  />
                </div>

                {error && <p className="text-xs text-destructive bg-destructive/10 p-2.5 rounded-xl">{error}</p>}

                <div className="flex justify-end gap-2.5 pt-2 border-t border-border/60">
                  <Button type="button" variant="outline" size="sm" onClick={() => setSubmittingFor(null)}>
                    Batal
                  </Button>
                  <Button type="submit" size="sm" disabled={submitting} className="font-bold">
                    {submitting ? "Mengirim..." : "Kirim Ajuan Prestasi"}
                  </Button>
                </div>
              </form>
            </div>
          </div>
        )}
      </div>
    </WaliShell>
  );
}

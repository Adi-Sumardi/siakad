"use client";

import { useEffect, useState } from "react";
import { Award, CheckCircle2, FileDown, RefreshCw, XCircle } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { API_BASE, api, ApiError } from "@/lib/api";
import { tanggal } from "@/lib/format";
import type { Achievement } from "@/lib/types/kesiswaan";

const STATUS_LABEL: Record<Achievement["status"], { label: string; variant: "good" | "warn" | "bad" }> = {
  verified: { label: "Terverifikasi", variant: "good" },
  pending: { label: "Menunggu Verifikasi", variant: "warn" },
  rejected: { label: "Ditolak", variant: "bad" },
};

function DecisionRow({ achievement, onDecided }: { achievement: Achievement; onDecided: () => void }) {
  const [mode, setMode] = useState<"verify" | "reject" | null>(null);
  const [points, setPoints] = useState("10");
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function verify() {
    setSubmitting(true);
    setError(null);
    try {
      await api.post(`/api/admin/achievements/${achievement.ulid}/verify`, {
        points_awarded: points ? Number(points) : undefined,
      });
      toast.success("Prestasi berhasil diverifikasi dan poin ditambahkan ke siswa.");
      onDecided();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal memverifikasi.");
    } finally {
      setSubmitting(false);
    }
  }

  async function reject() {
    if (!reason.trim()) {
      setError("Alasan penolakan wajib diisi.");
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      await api.post(`/api/admin/achievements/${achievement.ulid}/reject`, { reason });
      toast.success("Prestasi ditolak.");
      onDecided();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal menolak.");
    } finally {
      setSubmitting(false);
    }
  }

  if (mode === null) {
    return (
      <div className="flex items-center gap-2 pt-2 border-t border-border/60">
        <Button size="sm" onClick={() => setMode("verify")} className="gap-1.5 text-xs font-semibold">
          <CheckCircle2 className="size-3.5" />
          <span>Verifikasi & Beri Poin</span>
        </Button>
        <Button
          size="sm"
          variant="ghost"
          onClick={() => setMode("reject")}
          className="text-xs text-destructive hover:bg-destructive/10"
        >
          Tolak
        </Button>
      </div>
    );
  }

  return (
    <div className="flex w-full flex-col gap-3 border-t border-border/60 pt-3">
      {mode === "verify" && (
        <div className="flex flex-col sm:flex-row sm:items-center gap-2">
          <Label htmlFor="points_input" className="text-xs">Poin Apresiasi Diberikan:</Label>
          <Input
            id="points_input"
            value={points}
            onChange={(e) => setPoints(e.target.value)}
            type="number"
            min={0}
            className="w-32 h-8 text-xs font-bold"
            placeholder="misal: 10"
          />
        </div>
      )}

      {mode === "reject" && (
        <div>
          <Label htmlFor="reason_input" className="text-xs">Alasan Penolakan (Wajib):</Label>
          <Input
            id="reason_input"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Tulis alasan penolakan sertifikat..."
            className="mt-1 text-xs"
          />
        </div>
      )}

      {error && <p className="rounded-lg bg-destructive/10 p-2 text-xs text-destructive">{error}</p>}

      <div className="flex gap-2">
        <Button
          size="sm"
          variant={mode === "reject" ? "destructive" : "default"}
          onClick={mode === "verify" ? verify : reject}
          disabled={submitting}
          className="text-xs font-semibold"
        >
          {submitting ? "Memproses…" : mode === "verify" ? "Konfirmasi Verifikasi" : "Tolak Pengajuan"}
        </Button>
        <Button size="sm" variant="ghost" onClick={() => setMode(null)} disabled={submitting} className="text-xs">
          Batal
        </Button>
      </div>
    </div>
  );
}

export default function AdminAchievementsPage() {
  const [achievements, setAchievements] = useState<(Achievement & { student?: { nama_lengkap: string } })[] | null>(null);
  const [filter, setFilter] = useState<"pending" | "">("pending");

  function load() {
    const qs = filter ? `?status=${filter}` : "";
    api
      .get<{ achievements: (Achievement & { student?: { nama_lengkap: string } })[] }>(`/api/admin/achievements${qs}`)
      .then((d) => setAchievements(d.achievements))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat prestasi."));
  }

  useEffect(() => {
    load();
  }, [filter]); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Verifikasi Prestasi Siswa</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Daftar pengajuan piagam dan kejuaraan dari wali murid atau guru yang menunggu verifikasi.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <select
            value={filter}
            onChange={(e) => setFilter(e.target.value as "pending" | "")}
            className="rounded-lg border border-input bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-2xs"
          >
            <option value="pending">Menunggu Verifikasi</option>
            <option value="">Semua Status</option>
          </select>
          <Button variant="outline" size="sm" onClick={load} className="gap-1.5 text-xs">
            <RefreshCw className="size-3.5" />
            <span>Segarkan</span>
          </Button>
        </div>
      </div>

      {/* List */}
      {achievements === null && (
        <div className="space-y-3">
          <Skeleton className="h-28 w-full rounded-2xl" />
          <Skeleton className="h-28 w-full rounded-2xl" />
        </div>
      )}

      {achievements?.length === 0 && (
        <Card className="p-8 text-center text-sm text-muted-foreground">
          Tidak ada pengajuan prestasi untuk filter ini.
        </Card>
      )}

      <div className="grid grid-cols-1 gap-4">
        {achievements?.map((a) => {
          const status = STATUS_LABEL[a.status];

          return (
            <Card key={a.ulid} className="p-5 sm:p-6 border-border/80 hover:border-primary/40 transition-colors">
              <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                <div>
                  <div className="flex items-center gap-2 flex-wrap">
                    <p className="font-bold text-foreground text-lg">{a.nama_prestasi}</p>
                    <Badge variant={status.variant}>{status.label}</Badge>
                  </div>

                  <p className="text-sm font-medium text-foreground mt-1">
                    Siswa: <strong className="text-primary">{a.student?.nama_lengkap}</strong> · {a.kategori} · Tingkat {a.tingkat}
                    {a.juara && ` · Juara ${a.juara}`}
                  </p>

                  {a.nama_event && (
                    <p className="text-xs text-muted-foreground mt-1">
                      Event: {a.nama_event}{a.tanggal_event && ` · Dilaksanakan pada ${tanggal(a.tanggal_event)}`}
                    </p>
                  )}
                </div>

                {a.has_sertifikat && (
                  <a
                    href={`${API_BASE}/api/files/achievements/${a.ulid}/sertifikat`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1.5 rounded-lg bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary/20 transition-colors self-start"
                  >
                    <FileDown className="size-3.5" />
                    <span>Lihat Piagam / Sertifikat</span>
                  </a>
                )}
              </div>

              {a.status === "pending" && <DecisionRow achievement={a} onDecided={load} />}
              {a.status === "rejected" && a.rejection_reason && (
                <p className="mt-3 text-xs text-bad bg-bad-soft/40 p-2.5 rounded-lg">
                  Alasan Penolakan: {a.rejection_reason}
                </p>
              )}
              {a.point_awarded && (
                <p className="mt-3 text-xs font-bold text-good bg-good-soft/30 p-2 rounded-lg inline-block">
                  +{a.point_awarded} poin apresiasi telah ditambahkan ke siswa
                </p>
              )}
            </Card>
          );
        })}
      </div>
    </div>
  );
}

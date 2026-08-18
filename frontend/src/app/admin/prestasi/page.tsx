"use client";

import { useEffect, useState } from "react";
import { FileDown } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { API_BASE, api, ApiError } from "@/lib/api";
import { tanggal } from "@/lib/format";
import type { Achievement } from "@/lib/types/kesiswaan";

const STATUS_LABEL: Record<Achievement["status"], { label: string; variant: "good" | "warn" | "bad" }> = {
  verified: { label: "Terverifikasi", variant: "good" },
  pending: { label: "Menunggu", variant: "warn" },
  rejected: { label: "Ditolak", variant: "bad" },
};

function DecisionRow({ achievement, onDecided }: { achievement: Achievement; onDecided: () => void }) {
  const [mode, setMode] = useState<"verify" | "reject" | null>(null);
  const [points, setPoints] = useState("");
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
      toast.success("Prestasi diverifikasi.");
      onDecided();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal memverifikasi.");
    } finally {
      setSubmitting(false);
    }
  }

  async function reject() {
    if (!reason.trim()) { setError("Alasan wajib diisi."); return; }
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
      <div className="flex gap-2">
        <Button size="sm" onClick={() => setMode("verify")}>Verifikasi</Button>
        <Button size="sm" variant="ghost" onClick={() => setMode("reject")}>Tolak</Button>
      </div>
    );
  }

  return (
    <div className="flex w-full flex-col gap-2 border-t border-border pt-3">
      {mode === "verify" && (
        <Input value={points} onChange={(e) => setPoints(e.target.value)} type="number" min={1} className="w-48" placeholder="Beri poin (opsional)" />
      )}
      {mode === "reject" && (
        <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Alasan penolakan (wajib)" />
      )}
      {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
      <div className="flex gap-2">
        <Button size="sm" variant={mode === "reject" ? "destructive" : "default"} onClick={mode === "verify" ? verify : reject} disabled={submitting}>
          {submitting ? "Memproses…" : mode === "verify" ? "Verifikasi" : "Tolak"}
        </Button>
        <Button size="sm" variant="ghost" onClick={() => setMode(null)} disabled={submitting}>Batal</Button>
      </div>
    </div>
  );
}

export default function AdminAchievementsPage() {
  const [achievements, setAchievements] = useState<(Achievement & { student?: { nama_lengkap: string } })[] | null>(null);
  const [filter, setFilter] = useState<"pending" | "">("pending");

  function load() {
    const qs = filter ? `?status=${filter}` : "";
    api.get<{ achievements: (Achievement & { student?: { nama_lengkap: string } })[] }>(`/api/admin/achievements${qs}`)
      .then((d) => setAchievements(d.achievements));
  }

  useEffect(() => { load(); }, [filter]); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold tracking-tight">Prestasi</h1>
          <p className="mt-1 text-sm text-muted-foreground">Pengajuan dari wali murid menunggu konfirmasi.</p>
        </div>
        <select value={filter} onChange={(e) => setFilter(e.target.value as "pending" | "")} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
          <option value="pending">Menunggu</option>
          <option value="">Semua</option>
        </select>
      </div>

      {achievements === null && <Skeleton className="h-64 w-full" />}
      {achievements?.length === 0 && <Card className="p-6 text-sm text-muted-foreground">Tidak ada pengajuan.</Card>}

      <div className="flex flex-col gap-3">
        {achievements?.map((a) => {
          const status = STATUS_LABEL[a.status];

          return (
            <Card key={a.ulid} className="flex flex-col gap-2 p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="font-semibold">{a.nama_prestasi}</p>
                  <p className="text-sm text-muted-foreground">
                    {a.student?.nama_lengkap} · {a.kategori} · {a.tingkat}
                    {a.juara && ` · Juara ${a.juara}`}
                  </p>
                  {a.nama_event && (
                    <p className="text-sm text-muted-foreground">
                      {a.nama_event}{a.tanggal_event && ` · ${tanggal(a.tanggal_event)}`}
                    </p>
                  )}
                </div>
                <Badge variant={status.variant}>{status.label}</Badge>
              </div>

              {a.has_sertifikat && (
                <a
                  href={`${API_BASE}/api/files/achievements/${a.ulid}/sertifikat`}
                  target="_blank" rel="noopener noreferrer"
                  className="inline-flex w-fit items-center gap-1 text-xs text-primary"
                >
                  <FileDown className="size-3" />
                  Lihat sertifikat
                </a>
              )}

              {a.status === "pending" && <DecisionRow achievement={a} onDecided={load} />}
              {a.status === "rejected" && a.rejection_reason && (
                <p className="text-sm text-bad">Alasan: {a.rejection_reason}</p>
              )}
              {a.point_awarded && <p className="text-sm font-medium text-good">+{a.point_awarded} poin diberikan</p>}
            </Card>
          );
        })}
      </div>
    </div>
  );
}

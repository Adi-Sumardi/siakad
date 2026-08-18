"use client";

import { use, useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, FileDown, Plus, X } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { PointMeter } from "@/components/point-meter";
import { API_BASE, api, ApiError } from "@/lib/api";
import { tanggal } from "@/lib/format";
import {
  JUARA_OPTIONS,
  KATEGORI_OPTIONS,
  TINGKAT_OPTIONS,
  type Achievement,
  type PointSummary,
} from "@/lib/types/kesiswaan";

const STATUS_LABEL: Record<Achievement["status"], { label: string; variant: "good" | "warn" | "bad" }> = {
  verified: { label: "Terverifikasi", variant: "good" },
  pending: { label: "Menunggu verifikasi", variant: "warn" },
  rejected: { label: "Ditolak", variant: "bad" },
};

function PointHistory({ points }: { points: PointSummary }) {
  if (points.records.length === 0) {
    return <p className="p-5 text-sm text-muted-foreground">Belum ada catatan poin semester ini.</p>;
  }

  return (
    <div className="flex flex-col">
      {points.records.map((record) => (
        <div
          key={record.ulid}
          className={`flex items-start justify-between gap-4 border-b border-border px-5 py-3 last:border-b-0 ${record.status === "revoked" ? "opacity-50" : ""}`}
        >
          <div className="min-w-0">
            <p className="text-sm font-medium">
              {record.description}
              {record.status === "revoked" && <span className="ml-2 text-xs text-muted-foreground">(dibatalkan)</span>}
            </p>
            <p className="text-xs text-muted-foreground">
              {tanggal(record.occurred_on)}
              {record.rule && ` · ${record.rule.category}`}
              {record.recorded_by && ` · dicatat ${record.recorded_by}`}
            </p>
            {record.status === "revoked" && record.revoke_reason && (
              <p className="mt-1 text-xs text-muted-foreground">Alasan: {record.revoke_reason}</p>
            )}
          </div>
          <span className={`tabular shrink-0 text-sm font-semibold ${record.points > 0 ? "text-good" : "text-bad"}`}>
            {record.points > 0 ? `+${record.points}` : record.points}
          </span>
        </div>
      ))}
    </div>
  );
}

function AchievementCard({ achievement }: { achievement: Achievement }) {
  const status = STATUS_LABEL[achievement.status];

  return (
    <Card className="flex flex-col gap-2 p-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="font-semibold">{achievement.nama_prestasi}</p>
          <p className="text-sm text-muted-foreground">
            {achievement.kategori} · {achievement.tingkat}
            {achievement.juara && ` · Juara ${achievement.juara}`}
          </p>
        </div>
        <Badge variant={status.variant}>{status.label}</Badge>
      </div>

      {achievement.nama_event && (
        <p className="text-sm text-muted-foreground">
          {achievement.nama_event}
          {achievement.tanggal_event && ` · ${tanggal(achievement.tanggal_event)}`}
        </p>
      )}

      {achievement.status === "rejected" && achievement.rejection_reason && (
        <p className="text-sm text-bad">Alasan ditolak: {achievement.rejection_reason}</p>
      )}

      {achievement.point_awarded && (
        <p className="text-sm font-medium text-good">+{achievement.point_awarded} poin diberikan</p>
      )}

      {achievement.has_sertifikat && (
        <a
          href={`${API_BASE}/api/files/achievements/${achievement.ulid}/sertifikat`}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex w-fit items-center gap-1 text-xs text-primary"
        >
          <FileDown className="size-3" />
          Lihat sertifikat
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
      toast.success("Prestasi diajukan. Menunggu verifikasi dari sekolah.");
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
      <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
        <Plus className="size-4" />
        Ajukan prestasi
      </Button>
    );
  }

  return (
    <Card className="p-5">
      <div className="mb-3 flex items-center justify-between">
        <p className="font-semibold">Ajukan prestasi</p>
        <button type="button" onClick={() => setOpen(false)} className="text-muted-foreground hover:text-foreground">
          <X className="size-4" />
        </button>
      </div>

      <form onSubmit={submit} className="flex flex-col gap-3" noValidate>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="nama_prestasi">Nama prestasi</Label>
          <Input id="nama_prestasi" name="nama_prestasi" required placeholder="Juara 1 Lomba Tahfidz" />
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="kategori">Kategori</Label>
            <select id="kategori" name="kategori" required className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
              {KATEGORI_OPTIONS.map((k) => <option key={k} value={k}>{k}</option>)}
            </select>
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="tingkat">Tingkat</Label>
            <select id="tingkat" name="tingkat" required className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
              {TINGKAT_OPTIONS.map((t) => <option key={t} value={t}>{t}</option>)}
            </select>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="juara">Juara (opsional)</Label>
            <select id="juara" name="juara" className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
              <option value="">—</option>
              {JUARA_OPTIONS.map((j) => <option key={j} value={j}>{j}</option>)}
            </select>
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="tanggal_event">Tanggal</Label>
            <Input id="tanggal_event" name="tanggal_event" type="date" max={new Date().toISOString().slice(0, 10)} />
          </div>
        </div>

        <div className="flex flex-col gap-1.5">
          <Label htmlFor="nama_event">Nama acara (opsional)</Label>
          <Input id="nama_event" name="nama_event" placeholder="Lomba Tahfidz Kecamatan" />
        </div>

        <div className="flex flex-col gap-1.5">
          <Label htmlFor="sertifikat">Sertifikat (opsional)</Label>
          <input id="sertifikat" name="sertifikat" type="file" accept=".jpg,.jpeg,.png,.pdf" className="text-sm" />
        </div>

        {error && <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}

        <Button type="submit" disabled={submitting}>{submitting ? "Mengirim…" : "Kirim pengajuan"}</Button>
      </form>
    </Card>
  );
}

export default function StudentDetailPage({ params }: { params: Promise<{ ulid: string }> }) {
  const { ulid } = use(params);

  const [points, setPoints] = useState<PointSummary | null>(null);
  const [achievements, setAchievements] = useState<Achievement[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  function loadAchievements() {
    api.get<{ achievements: Achievement[] }>(`/api/wali/students/${ulid}/achievements`).then((d) => setAchievements(d.achievements));
  }

  useEffect(() => {
    api
      .get<PointSummary>(`/api/wali/students/${ulid}/points`)
      .then(setPoints)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Tidak dapat memuat data anak."));
    loadAchievements();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ulid]);

  if (error) {
    return (
      <main className="mx-auto max-w-2xl p-6">
        <Card className="p-6">
          <p className="text-sm text-muted-foreground">{error}</p>
          <Link href="/" className="mt-4 inline-block text-sm text-primary">Kembali ke beranda</Link>
        </Card>
      </main>
    );
  }

  return (
    <div className="min-h-dvh bg-canvas">
      <header className="border-b border-border bg-card">
        <div className="mx-auto max-w-2xl px-6 py-3.5">
          <Link href="/" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
            <ArrowLeft className="size-4" />
            Beranda
          </Link>
        </div>
      </header>

      <main className="mx-auto max-w-2xl px-6 py-8">
        <h1 className="text-xl font-bold tracking-tight">Poin &amp; Prestasi</h1>
        {points?.term && <p className="mt-1 text-sm text-muted-foreground">Semester {points.term}</p>}

        <Card className="mt-6 overflow-hidden p-0">
          <div className="flex items-center justify-between border-b border-border px-5 py-3">
            <h2 className="text-sm font-semibold">Poin</h2>
          </div>
          {points === null ? (
            <div className="p-5"><Skeleton className="h-10 w-40" /></div>
          ) : (
            <>
              <div className="p-5">
                <PointMeter balance={points.balance} threshold={points.threshold} />
                {points.threshold?.action && (
                  <p className="mt-2 text-sm text-muted-foreground">{points.threshold.action}</p>
                )}
              </div>
              <PointHistory points={points} />
            </>
          )}
        </Card>

        <div className="mt-8 flex items-center justify-between">
          <h2 className="text-sm font-semibold text-muted-foreground">Prestasi</h2>
          <SubmitAchievementForm studentUlid={ulid} onSubmitted={loadAchievements} />
        </div>

        <div className="mt-3 flex flex-col gap-2">
          {achievements === null && <Skeleton className="h-24 w-full" />}
          {achievements?.length === 0 && (
            <Card className="p-5 text-sm text-muted-foreground">Belum ada prestasi tercatat.</Card>
          )}
          {achievements?.map((a) => <AchievementCard key={a.ulid} achievement={a} />)}
        </div>
      </main>
    </div>
  );
}

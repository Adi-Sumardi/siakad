"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";

type Threshold = {
  ulid: string; school_unit: string | null; min_points: number; max_points: number;
  label: string; action: string | null; color: string | null; notify_guardian: boolean;
};
type Unit = { ulid: string; code: string; label: string };

function NewThresholdForm({ units, isCentral, onCreated }: { units: Unit[]; isCentral: boolean; onCreated: () => void }) {
  const [minPoints, setMinPoints] = useState("-49");
  const [maxPoints, setMaxPoints] = useState("-25");
  const [label, setLabel] = useState("");
  const [action, setAction] = useState("");
  const [color, setColor] = useState<"warn" | "bad" | "good">("warn");
  const [notifyGuardian, setNotifyGuardian] = useState(true);
  const [unitCode, setUnitCode] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      await api.post("/api/admin/point-thresholds", {
        min_points: Number(minPoints), max_points: Number(maxPoints), label,
        action: action || undefined, color, notify_guardian: notifyGuardian,
        school_unit_code: isCentral && unitCode ? unitCode : undefined,
      });
      toast.success("Ambang ditambahkan.");
      setLabel(""); setAction("");
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal menyimpan ambang.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
      <div className="flex flex-col gap-1.5">
        <Label>Dari</Label>
        <Input value={minPoints} onChange={(e) => setMinPoints(e.target.value)} type="number" required className="w-24" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Sampai</Label>
        <Input value={maxPoints} onChange={(e) => setMaxPoints(e.target.value)} type="number" required className="w-24" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Label</Label>
        <Input value={label} onChange={(e) => setLabel(e.target.value)} required className="w-40" placeholder="Peringatan 1" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Tindakan (opsional)</Label>
        <Input value={action} onChange={(e) => setAction(e.target.value)} className="w-56" placeholder="Surat pemberitahuan wali" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Warna</Label>
        <select value={color} onChange={(e) => setColor(e.target.value as typeof color)} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
          <option value="good">Hijau</option>
          <option value="warn">Kuning</option>
          <option value="bad">Merah</option>
        </select>
      </div>
      {isCentral && (
        <div className="flex flex-col gap-1.5">
          <Label>Cakupan</Label>
          <select value={unitCode} onChange={(e) => setUnitCode(e.target.value)} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
            <option value="">Seluruh sekolah</option>
            {units.map((u) => <option key={u.code} value={u.code}>{u.label}</option>)}
          </select>
        </div>
      )}
      <label className="flex items-center gap-2 pb-2.5 text-sm">
        <input type="checkbox" checked={notifyGuardian} onChange={(e) => setNotifyGuardian(e.target.checked)} />
        Beri tahu wali murid
      </label>
      <Button type="submit" disabled={submitting}>{submitting ? "Menyimpan…" : "Tambah"}</Button>
      {error && <p className="w-full rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
    </form>
  );
}

export default function PointThresholdsPage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [thresholds, setThresholds] = useState<Threshold[] | null>(null);
  const [units, setUnits] = useState<Unit[]>([]);

  function load() {
    api
      .get<{ thresholds: Threshold[] }>("/api/admin/point-thresholds")
      .then((d) => setThresholds(d.thresholds))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat ambang poin."));
  }

  useEffect(() => {
    load();
    if (isCentral) {
      api
        .get<{ school_units: Unit[] }>("/api/admin/school-units")
        .then((d) => setUnits(d.school_units))
        .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar unit."));
    }
  }, [isCentral]);

  return (
    <div className="flex flex-col gap-5">
      <Link href="/admin/poin" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" />
        Poin siswa
      </Link>

      <div>
        <h1 className="text-xl font-bold tracking-tight">Ambang poin</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Rentang saldo dan artinya — dipakai untuk badge dan notifikasi otomatis ke wali murid.
        </p>
      </div>

      <Card className="p-5">
        {thresholds === null ? <Skeleton className="h-10 w-full" /> : <NewThresholdForm units={units} isCentral={isCentral} onCreated={load} />}
      </Card>

      <div className="flex flex-col gap-2">
        {thresholds === null && <Skeleton className="h-32 w-full" />}
        {thresholds
          ?.slice()
          .sort((a, b) => b.min_points - a.min_points)
          .map((t) => (
            <Card key={t.ulid} className="flex flex-wrap items-center justify-between gap-3 p-4">
              <div>
                <p className="font-medium">
                  {t.label} <span className="tabular text-xs text-muted-foreground">({t.min_points} s.d. {t.max_points})</span>
                </p>
                <p className="text-sm text-muted-foreground">
                  {t.school_unit ?? "Seluruh sekolah"}
                  {t.action && ` · ${t.action}`}
                </p>
              </div>
              <div className="flex items-center gap-2">
                {t.notify_guardian && <Badge variant="primary">Notifikasi wali</Badge>}
                <Badge variant={(t.color as "good" | "warn" | "bad") ?? "default"}>{t.color ?? "default"}</Badge>
              </div>
            </Card>
          ))}
      </div>
    </div>
  );
}

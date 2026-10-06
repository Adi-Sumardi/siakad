"use client";

import { useCallback, useEffect, useState } from "react";
import { SlidersHorizontal } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { api, ApiError } from "@/lib/api";

type Policy = {
  overridden: boolean;
  weight_tugas: number;
  weight_uts: number;
  weight_uas: number;
  kkm: number;
  alpa_threshold: number;
  grade_drop: number;
};

type Payload = {
  school_wide: Policy;
  units: ({ unit: { ulid: string; label: string } } & Policy)[];
  can_edit: boolean;
};

const FIELDS: { key: keyof Omit<Policy, "overridden">; label: string; suffix?: string }[] = [
  { key: "weight_tugas", label: "Bobot Tugas", suffix: "%" },
  { key: "weight_uts", label: "Bobot UTS", suffix: "%" },
  { key: "weight_uas", label: "Bobot UAS", suffix: "%" },
  { key: "kkm", label: "KKM" },
  { key: "alpa_threshold", label: "Alpa ≥ (hari)" },
  { key: "grade_drop", label: "Nilai turun ≥" },
];

/**
 * Grade weights + watchlist thresholds (audit 6 Okt 2026 #11-12). Central
 * admin edits the school-wide default and per-unit overrides; a unit admin
 * sees read-only what their dashboard is measured against.
 */
export function AcademicPolicyCard() {
  const [data, setData] = useState<Payload | null>(null);
  const [target, setTarget] = useState<string>(""); // "" = school-wide
  const [form, setForm] = useState<Omit<Policy, "overridden"> | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    api
      .get<Payload>("/api/admin/academic-policy")
      .then(setData)
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat kebijakan nilai."));
  }, []);

  useEffect(load, [load]);

  const current = data ? (target ? data.units.find((u) => u.unit.ulid === target) : data.school_wide) : undefined;
  const values = form ?? current ?? null;
  const sum = values ? values.weight_tugas + values.weight_uts + values.weight_uas : 0;

  async function save() {
    if (!values) return;
    setBusy(true);
    try {
      const updated = await api.put<Payload>("/api/admin/academic-policy", { unit: target || null, ...values });
      setData(updated);
      setForm(null);
      toast.success("Kebijakan nilai tersimpan.");
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyimpan.");
    } finally {
      setBusy(false);
    }
  }

  async function reset() {
    setBusy(true);
    try {
      const updated = await api.delete<Payload>(`/api/admin/academic-policy/${target}`);
      setData(updated);
      setForm(null);
      toast.success("Unit kembali mengikuti kebijakan seluruh sekolah.");
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengembalikan.");
    } finally {
      setBusy(false);
    }
  }

  if (!data || !values) return null;

  return (
    <Card className="p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-sm font-bold">
          <SlidersHorizontal className="size-4 text-primary" /> Bobot Nilai &amp; Ambang Perhatian
        </h2>
        {data.can_edit && (
          <select
            value={target}
            onChange={(e) => {
              setTarget(e.target.value);
              setForm(null);
            }}
            className="h-8 rounded-lg border border-input bg-card px-2 text-xs"
          >
            <option value="">Seluruh sekolah (bawaan)</option>
            {data.units.map((u) => (
              <option key={u.unit.ulid} value={u.unit.ulid}>
                {u.unit.label}
                {u.overridden ? " — khusus" : ""}
              </option>
            ))}
          </select>
        )}
      </div>

      {!data.can_edit && (
        <p className="mt-2 text-xs text-muted-foreground">
          Ditetapkan admin pusat. Nilai akhir rapor dan daftar &quot;Perlu Perhatian&quot; unit Anda dihitung dengan angka ini.
        </p>
      )}

      <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
        {FIELDS.map((f) => (
          <label key={f.key} className="flex flex-col gap-1 text-xs text-muted-foreground">
            {f.label}
            <Input
              type="number"
              min={0}
              max={f.key === "alpa_threshold" ? 365 : 100}
              value={values[f.key]}
              disabled={!data.can_edit}
              onChange={(e) => setForm({ ...values, [f.key]: Number(e.target.value) })}
              className="h-8"
            />
          </label>
        ))}
      </div>

      {data.can_edit && (
        <div className="mt-3 flex flex-wrap items-center gap-2">
          {sum !== 100 && <Badge className="bg-bad/10 text-bad">Total bobot {sum}% — harus 100%</Badge>}
          {target && current?.overridden && <Badge className="bg-info/10 text-info">Unit ini punya pengaturan khusus</Badge>}
          <div className="ml-auto flex gap-2">
            {target && current?.overridden && (
              <Button size="sm" variant="ghost" onClick={reset} disabled={busy}>
                Ikuti bawaan sekolah
              </Button>
            )}
            <Button size="sm" onClick={save} disabled={busy || sum !== 100}>
              Simpan
            </Button>
          </div>
        </div>
      )}
      <p className="mt-2 text-xs text-muted-foreground">
        Mengubah bobot langsung mengubah nilai akhir yang ditampilkan (rapor, rekap kelas). Nilai mentah Tugas/UTS/UAS tidak berubah.
      </p>
    </Card>
  );
}

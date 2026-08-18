"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { rupiah } from "@/lib/format";
import { useAuth } from "@/lib/auth/auth-context";

type FeeType = { ulid: string; code: string; name: string; recurrence: string; is_active: boolean; rate_count: number };
type Rate = {
  ulid: string; fee_type: { code: string; name: string }; unit: { code: string; label: string };
  academic_year: string; tingkat: number | null; amount: number; due_day: number | null;
};
type Option = { ulid: string; code?: string; label?: string; year?: string; is_active?: boolean };

const RECURRENCE_LABEL: Record<string, string> = { monthly: "Bulanan", per_term: "Per semester", once: "Sekali" };

function NewRateForm({ feeTypes, units, years, onCreated }: {
  feeTypes: FeeType[]; units: Option[]; years: Option[]; onCreated: () => void;
}) {
  const [feeTypeUlid, setFeeTypeUlid] = useState(feeTypes[0]?.ulid ?? "");
  const [unitUlid, setUnitUlid] = useState(units[0]?.ulid ?? "");
  const [yearUlid, setYearUlid] = useState(years.find((y) => y.is_active)?.ulid ?? years[0]?.ulid ?? "");
  const [tingkat, setTingkat] = useState("");
  const [amount, setAmount] = useState("");
  const [dueDay, setDueDay] = useState("10");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      await api.post("/api/admin/fee-rates", {
        fee_type_ulid: feeTypeUlid,
        school_unit_ulid: unitUlid,
        academic_year_ulid: yearUlid,
        tingkat: tingkat ? Number(tingkat) : null,
        amount: Number(amount),
        due_day: dueDay ? Number(dueDay) : null,
      });
      toast.success("Tarif ditambahkan.");
      setAmount("");
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal menyimpan tarif.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
      <div className="flex flex-col gap-1.5">
        <Label>Jenis biaya</Label>
        <select value={feeTypeUlid} onChange={(e) => setFeeTypeUlid(e.target.value)} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
          {feeTypes.map((t) => <option key={t.ulid} value={t.ulid}>{t.name}</option>)}
        </select>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Unit</Label>
        <select value={unitUlid} onChange={(e) => setUnitUlid(e.target.value)} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
          {units.map((u) => <option key={u.ulid} value={u.ulid}>{u.label}</option>)}
        </select>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Tahun ajaran</Label>
        <select value={yearUlid} onChange={(e) => setYearUlid(e.target.value)} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
          {years.map((y) => <option key={y.ulid} value={y.ulid}>{y.year}</option>)}
        </select>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Tingkat (kosongkan = semua)</Label>
        <Input value={tingkat} onChange={(e) => setTingkat(e.target.value)} type="number" className="w-28" placeholder="—" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Nominal</Label>
        <Input value={amount} onChange={(e) => setAmount(e.target.value)} type="number" required className="w-36" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Tgl jatuh tempo</Label>
        <Input value={dueDay} onChange={(e) => setDueDay(e.target.value)} type="number" className="w-24" />
      </div>
      <Button type="submit" disabled={submitting}>{submitting ? "Menyimpan…" : "Tambah tarif"}</Button>
      {error && <p className="w-full rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
    </form>
  );
}

export default function FeeRatesPage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [feeTypes, setFeeTypes] = useState<FeeType[] | null>(null);
  const [rates, setRates] = useState<Rate[] | null>(null);
  const [units, setUnits] = useState<Option[]>([]);
  const [years, setYears] = useState<Option[]>([]);

  function load() {
    api.get<{ fee_types: FeeType[] }>("/api/admin/fee-types").then((d) => setFeeTypes(d.fee_types));
    api.get<{ rates: Rate[] }>("/api/admin/fee-rates").then((d) => setRates(d.rates));
  }

  useEffect(() => {
    load();
    api.get<{ school_units: Option[] }>("/api/admin/school-units").then((d) => setUnits(d.school_units));
    api.get<{ academic_years: Option[] }>("/api/admin/academic-years").then((d) => setYears(d.academic_years));
  }, []);

  if (!isCentral) {
    return (
      <Card className="p-6 text-sm text-muted-foreground">
        Tarif hanya dikelola admin pusat — harga menyangkut ratusan keluarga, jadi satu tempat
        yang mengubahnya.
      </Card>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-xl font-bold tracking-tight">Tarif</h1>
        <p className="mt-1 text-sm text-muted-foreground">Jenis biaya dan nominal per unit, tingkat, tahun ajaran.</p>
      </div>

      <Card className="p-5">
        <p className="mb-2 text-sm font-semibold">Jenis biaya</p>
        <div className="flex flex-wrap gap-2">
          {feeTypes === null && <Skeleton className="h-8 w-40" />}
          {feeTypes?.map((t) => (
            <Badge key={t.ulid}>{t.name} · {RECURRENCE_LABEL[t.recurrence] ?? t.recurrence} · {t.rate_count} tarif</Badge>
          ))}
        </div>
      </Card>

      <Card className="p-5">
        <p className="mb-3 text-sm font-semibold">Tambah tarif</p>
        {feeTypes && units.length > 0 && years.length > 0 ? (
          <NewRateForm feeTypes={feeTypes} units={units} years={years} onCreated={load} />
        ) : (
          <Skeleton className="h-10 w-full" />
        )}
      </Card>

      <Card className="overflow-hidden p-0">
        <div className="border-b border-border px-5 py-3">
          <p className="text-sm font-semibold">Tarif berlaku</p>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-left text-xs text-muted-foreground">
                <th className="px-5 py-2 font-medium">Jenis</th>
                <th className="px-5 py-2 font-medium">Unit</th>
                <th className="px-5 py-2 font-medium">Tingkat</th>
                <th className="px-5 py-2 font-medium">Tahun ajaran</th>
                <th className="px-5 py-2 text-right font-medium">Nominal</th>
                <th className="px-5 py-2 font-medium">Jatuh tempo</th>
              </tr>
            </thead>
            <tbody>
              {rates?.map((r) => (
                <tr key={r.ulid} className="border-b border-border last:border-b-0">
                  <td className="px-5 py-2.5">{r.fee_type.name}</td>
                  <td className="px-5 py-2.5">{r.unit.label}</td>
                  <td className="px-5 py-2.5">{r.tingkat ?? "Semua"}</td>
                  <td className="px-5 py-2.5">{r.academic_year}</td>
                  <td className="tabular px-5 py-2.5 text-right">{rupiah(r.amount)}</td>
                  <td className="px-5 py-2.5">{r.due_day ? `Tgl ${r.due_day}` : "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {rates?.length === 0 && <p className="p-5 text-sm text-muted-foreground">Belum ada tarif.</p>}
          {rates === null && <div className="p-5"><Skeleton className="h-24 w-full" /></div>}
        </div>
      </Card>
    </div>
  );
}

"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { rupiah } from "@/lib/format";

type FeeType = { ulid: string; code: string; name: string; recurrence: string };

type Preview = {
  fee_type: string;
  unit: string;
  period_month: number | null;
  eligible: number;
  total_amount: number;
  discount_amount: number;
  skipped: { student: string; kelas: string | null; reason: string; detail: string }[];
};

const MONTHS = [
  "Januari", "Februari", "Maret", "April", "Mei", "Juni",
  "Juli", "Agustus", "September", "Oktober", "November", "Desember",
];

export default function GenerateBillsPage() {
  const [feeTypes, setFeeTypes] = useState<FeeType[] | null>(null);
  const [feeTypeCode, setFeeTypeCode] = useState("spp");
  const [month, setMonth] = useState(new Date().getMonth() + 1);

  const [preview, setPreview] = useState<Preview | null>(null);
  const [loading, setLoading] = useState(false);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<{ bills_created: number; total_amount: number } | null>(null);

  useEffect(() => {
    api.get<{ fee_types: FeeType[] }>("/api/admin/fee-types").then((d) => setFeeTypes(d.fee_types));
  }, []);

  const isMonthly = feeTypes?.find((t) => t.code === feeTypeCode)?.recurrence === "monthly";

  async function runPreview() {
    setLoading(true);
    setError(null);
    setResult(null);

    try {
      const body: Record<string, unknown> = { fee_type_code: feeTypeCode };
      if (isMonthly) body.month = month;
      const data = await api.post<Preview>("/api/admin/billing-runs/preview", body);
      setPreview(data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal memuat pratinjau.");
    } finally {
      setLoading(false);
    }
  }

  async function execute() {
    if (!preview || preview.eligible === 0) return;
    setRunning(true);
    setError(null);

    try {
      const body: Record<string, unknown> = { fee_type_code: feeTypeCode };
      if (isMonthly) body.month = month;
      const { run } = await api.post<{ run: { bills_created: number; total_amount: number } }>("/api/admin/billing-runs", body);
      setResult(run);
      setPreview(null);
      toast.success(`${run.bills_created} tagihan diterbitkan.`);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menerbitkan tagihan.");
    } finally {
      setRunning(false);
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-xl font-bold tracking-tight">Terbitkan tagihan</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Selalu pratinjau dulu — generate massal tanpa melihat dampaknya adalah cara termudah
          menagih ratusan keluarga secara salah.
        </p>
      </div>

      <Card className="flex flex-col gap-4 p-5">
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="fee_type">Jenis biaya</Label>
            {feeTypes === null ? (
              <Skeleton className="h-10 w-40" />
            ) : (
              <select
                id="fee_type"
                value={feeTypeCode}
                onChange={(e) => setFeeTypeCode(e.target.value)}
                className="h-10 rounded-lg border border-input bg-card px-3 text-sm"
              >
                {feeTypes.map((t) => <option key={t.code} value={t.code}>{t.name}</option>)}
              </select>
            )}
          </div>

          {isMonthly && (
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="month">Bulan</Label>
              <select
                id="month"
                value={month}
                onChange={(e) => setMonth(Number(e.target.value))}
                className="h-10 rounded-lg border border-input bg-card px-3 text-sm"
              >
                {MONTHS.map((name, i) => <option key={name} value={i + 1}>{name}</option>)}
              </select>
            </div>
          )}

          <Button onClick={runPreview} disabled={loading}>{loading ? "Memuat…" : "Pratinjau"}</Button>
        </div>

        {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
      </Card>

      {result && (
        <Card className="border-good bg-good-soft/40 p-5">
          <p className="font-semibold text-good">
            {result.bills_created} tagihan terbit, total {rupiah(result.total_amount)}
          </p>
        </Card>
      )}

      {preview && (
        <Card className="flex flex-col gap-4 p-5">
          <div className="grid grid-cols-3 gap-3">
            <div>
              <p className="text-xs text-muted-foreground">Akan terbit</p>
              <p className="tabular text-xl font-bold">{preview.eligible}</p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Total nominal</p>
              <p className="tabular text-xl font-bold">{rupiah(preview.total_amount)}</p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Dilewati</p>
              <p className={`tabular text-xl font-bold ${preview.skipped.length > 0 ? "text-warn" : ""}`}>
                {preview.skipped.length}
              </p>
            </div>
          </div>

          {preview.skipped.length > 0 && (
            <div className="border-t border-border pt-3">
              <p className="mb-2 text-sm font-semibold">Siswa yang dilewati</p>
              <div className="flex flex-col gap-1.5">
                {preview.skipped.map((row, i) => (
                  <div key={i} className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="font-medium">{row.student}</span>
                    <Badge variant="warn">{row.reason}</Badge>
                    <span className="text-muted-foreground">{row.detail}</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          <div className="border-t border-border pt-3">
            <Button onClick={execute} disabled={running || preview.eligible === 0}>
              {running ? "Menerbitkan…" : `Terbitkan ${preview.eligible} tagihan`}
            </Button>
          </div>
        </Card>
      )}
    </div>
  );
}

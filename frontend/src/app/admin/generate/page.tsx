"use client";

import { useCallback, useEffect, useState } from "react";
import { toast } from "sonner";
import { AlertCircle, CheckCircle2, Coins, Play, Sparkles, Users } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { rupiah, tanggal } from "@/lib/format";

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

type RunRow = {
  ulid: string;
  fee_type: string;
  unit: { code: string; label: string } | null;
  period_month: number | null;
  status: "pending" | "running" | "completed" | "failed";
  bills_created: number;
  bills_skipped: number;
  total_amount: number;
  run_by: string | null;
  started_at: string | null;
  finished_at: string | null;
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
  const [runs, setRuns] = useState<RunRow[] | null>(null);

  // .then() chains so setState only ever runs in an async callback - an
  // effect calling this synchronously must not trip set-state-in-effect.
  const loadRuns = useCallback(() => {
    api
      .get<{ runs: RunRow[] }>("/api/admin/billing-runs")
      .then((d) => setRuns(d.runs))
      .catch(() => setRuns([]));
  }, []);

  useEffect(() => {
    api
      .get<{ fee_types: FeeType[] }>("/api/admin/fee-types")
      .then((d) => setFeeTypes(d.fee_types))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat jenis biaya."));

    loadRuns();
  }, [loadRuns]);

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
      toast.success(`${run.bills_created} tagihan berhasil diterbitkan.`);
      loadRuns();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menerbitkan tagihan.");
    } finally {
      setRunning(false);
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">Terbitkan SPP & Tagihan Massal</h1>
        <p className="text-sm text-muted-foreground mt-0.5">
          Proses penerbitan tagihan otomatis bulanan berdasarkan tarif aktif dan skema beasiswa siswa.
        </p>
      </div>

      {/* Control Card */}
      <Card className="p-6 border-border/80">
        <h2 className="text-base font-bold text-foreground mb-4">Pilih Parameter Penagihan</h2>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
          <div>
            <Label htmlFor="fee_type" className="text-xs">Jenis Tagihan</Label>
            {feeTypes === null ? (
              <Skeleton className="h-10 w-full mt-1" />
            ) : (
              <select
                id="fee_type"
                value={feeTypeCode}
                onChange={(e) => setFeeTypeCode(e.target.value)}
                className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary"
              >
                {feeTypes.map((t) => <option key={t.code} value={t.code}>{t.name}</option>)}
              </select>
            )}
          </div>

          {isMonthly && (
            <div>
              <Label htmlFor="month" className="text-xs">Bulan Penagihan</Label>
              <select
                id="month"
                value={month}
                onChange={(e) => setMonth(Number(e.target.value))}
                className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary"
              >
                {MONTHS.map((name, i) => <option key={name} value={i + 1}>{name}</option>)}
              </select>
            </div>
          )}

          <div>
            <Button onClick={runPreview} disabled={loading} className="w-full gap-2 shadow-xs">
              <Sparkles className="size-4" />
              <span>{loading ? "Menghitung Pratinjau..." : "Hitung Pratinjau Tagihan"}</span>
            </Button>
          </div>
        </div>

        {error && (
          <div className="mt-4 rounded-xl bg-destructive/10 border border-destructive/20 p-3 text-sm text-destructive flex items-center gap-2">
            <AlertCircle className="size-4 shrink-0" />
            <span>{error}</span>
          </div>
        )}
      </Card>

      {/* Success Result */}
      {result && (
        <Card className="border-emerald-500/30 bg-emerald-500/10 p-6">
          <div className="flex items-center gap-3">
            <CheckCircle2 className="size-6 text-emerald-600" />
            <div>
              <h3 className="font-bold text-emerald-800 text-lg">Penerbitan Tagihan Selesai</h3>
              <p className="text-sm text-emerald-700 mt-0.5">
                Sebanyak <strong>{result.bills_created} tagihan</strong> telah diterbitkan dengan total akumulasi nominal{" "}
                <strong>{rupiah(result.total_amount)}</strong>. Tagihan dapat langsung dibayar oleh wali murid.
              </p>
            </div>
          </div>
        </Card>
      )}

      {/* Preview Calculations */}
      {preview && (
        <div className="space-y-4">
          <h2 className="text-lg font-bold text-foreground">Hasil Kalkulasi Pratinjau</h2>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <Card className="p-5 border-border/80">
              <div className="flex items-center justify-between">
                <span className="text-xs font-semibold text-muted-foreground uppercase">Tagihan Diterbitkan</span>
                <Users className="size-5 text-primary" />
              </div>
              <p className="mt-2 text-2xl font-bold">{preview.eligible} siswa</p>
              <p className="mt-1 text-xs text-muted-foreground">Siswa aktif yang belum memiliki tagihan periode ini</p>
            </Card>

            <Card className="p-5 border-border/80">
              <div className="flex items-center justify-between">
                <span className="text-xs font-semibold text-muted-foreground uppercase">Total Tagihan</span>
                <Coins className="size-5 text-emerald-600" />
              </div>
              <p className="mt-2 text-2xl font-bold">{rupiah(preview.total_amount)}</p>
              <p className="mt-1 text-xs text-muted-foreground">
                Potongan beasiswa: {rupiah(preview.discount_amount)}
              </p>
            </Card>

            <Card className="p-5 border-border/80">
              <div className="flex items-center justify-between">
                <span className="text-xs font-semibold text-muted-foreground uppercase">Dilewati / Sudah Terbit</span>
                <AlertCircle className="size-5 text-amber-600" />
              </div>
              <p className="mt-2 text-2xl font-bold">{preview.skipped.length} siswa</p>
              <p className="mt-1 text-xs text-muted-foreground">Tidak ditagih dobel / tidak ada tarif</p>
            </Card>
          </div>

          {preview.skipped.length > 0 && (
            <Card className="p-5 border-border/80">
              <p className="font-bold text-foreground text-sm mb-3">Daftar Siswa yang Dilewati</p>
              <div className="max-h-48 overflow-y-auto divide-y divide-border space-y-1">
                {preview.skipped.map((row, i) => (
                  <div key={i} className="flex flex-wrap items-center justify-between py-2 text-xs">
                    <span className="font-semibold text-foreground">{row.student} {row.kelas ? `(${row.kelas})` : ""}</span>
                    <div className="flex items-center gap-2">
                      <Badge variant="warn">{row.reason}</Badge>
                      <span className="text-muted-foreground">{row.detail}</span>
                    </div>
                  </div>
                ))}
              </div>
            </Card>
          )}

          <div className="flex justify-end">
            <Button
              size="lg"
              onClick={execute}
              disabled={running || preview.eligible === 0}
              className="gap-2 shadow-md text-sm font-bold"
            >
              <Play className="size-4" />
              <span>{running ? "Sedang Menerbitkan Tagihan..." : `Eksekusi Terbitkan ${preview.eligible} Tagihan`}</span>
            </Button>
          </div>
        </div>
      )}

      {/* Run history - what has already been issued, by whom, and how it went. */}
      <Card className="p-5 border-border/80">
        <h2 className="text-base font-bold text-foreground">Riwayat Penerbitan</h2>
        <p className="mt-0.5 text-xs text-muted-foreground">
          50 penerbitan terakhir. Admin unit melihat penerbitan unitnya dan penerbitan seluruh unit yang menjangkau
          siswanya.
        </p>

        {runs === null ? (
          <Skeleton className="mt-3 h-24 w-full rounded-lg" />
        ) : runs.length === 0 ? (
          <p className="mt-3 rounded-lg border border-dashed border-border p-3 text-xs text-muted-foreground">
            Belum ada penerbitan tagihan tercatat.
          </p>
        ) : (
          <div className="mt-3 overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="border-b border-border text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="py-2.5 pr-4">Waktu</th>
                  <th className="py-2.5 pr-4">Jenis</th>
                  <th className="py-2.5 pr-4">Unit</th>
                  <th className="py-2.5 pr-4">Periode</th>
                  <th className="py-2.5 pr-4">Status</th>
                  <th className="py-2.5 pr-4 text-right">Terbit / Lewati</th>
                  <th className="py-2.5 pr-4 text-right">Total</th>
                  <th className="py-2.5">Oleh</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border/60">
                {runs.map((r) => (
                  <tr key={r.ulid} className="hover:bg-muted/20">
                    <td className="py-2.5 pr-4 whitespace-nowrap">
                      {tanggal(r.finished_at ?? r.started_at ?? "")}
                    </td>
                    <td className="py-2.5 pr-4 font-semibold">{r.fee_type}</td>
                    <td className="py-2.5 pr-4 text-muted-foreground">{r.unit?.label ?? "Semua Unit"}</td>
                    <td className="py-2.5 pr-4 text-muted-foreground">
                      {r.period_month ? MONTHS[r.period_month - 1] : "—"}
                    </td>
                    <td className="py-2.5 pr-4">
                      {r.status === "completed" && <Badge variant="good">Selesai</Badge>}
                      {r.status === "failed" && <Badge variant="bad">Gagal</Badge>}
                      {(r.status === "running" || r.status === "pending") && (
                        <Badge variant="warn">{r.status === "running" ? "Berjalan" : "Menunggu"}</Badge>
                      )}
                    </td>
                    <td className="py-2.5 pr-4 text-right font-semibold tabular-nums">
                      {r.bills_created} <span className="text-muted-foreground">/ {r.bills_skipped}</span>
                    </td>
                    <td className="py-2.5 pr-4 text-right font-semibold tabular-nums text-primary">
                      {rupiah(r.total_amount)}
                    </td>
                    <td className="py-2.5 text-muted-foreground">{r.run_by ?? "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}

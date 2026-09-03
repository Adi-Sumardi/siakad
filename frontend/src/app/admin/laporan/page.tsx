"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";
import {
  ArrowDownRight,
  ArrowUpRight,
  Calendar,
  CreditCard,
  Download,
  FileSpreadsheet,
  Layers,
  PieChart,
  RefreshCw,
  TrendingDown,
  TrendingUp,
  Users,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { rupiah, todayJakarta } from "@/lib/format";

type Receivables = {
  summary: { outstanding: number; bills: number; families: number; overdue_bills: number };
  by_class: { kelas: string; students: number; bills: number; outstanding: number; overdue: number }[];
  by_fee_type: { fee_type: string; bills: number; outstanding: number }[];
};

type Collections = {
  period: { from: string; to: string };
  total: number;
  count: number;
  by_method: { method: string; count: number; total: number }[];
  by_fee_type: { fee_type: string; total: number }[];
};

/** The 1st of the current month, in Jakarta - see todayJakarta() for why UTC-based conversion loses a day near midnight WIB. */
function firstOfMonth(): string {
  return todayJakarta().slice(0, 7) + "-01";
}

export default function ReportsPage() {
  const [receivables, setReceivables] = useState<Receivables | null>(null);
  const [collections, setCollections] = useState<Collections | null>(null);
  const [from, setFrom] = useState(firstOfMonth());
  const [to, setTo] = useState(todayJakarta());
  const [loading, setLoading] = useState(false);

  async function loadData() {
    setLoading(true);
    try {
      const [recData, colData] = await Promise.all([
        api.get<Receivables>("/api/admin/reports/receivables"),
        api.get<Collections>(`/api/admin/reports/collections?from=${from}&to=${to}`),
      ]);
      setReceivables(recData);
      setCollections(colData);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memuat laporan.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadData();
  }, [from, to]);

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Laporan Keuangan & Piutang</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Analisis arus kas penerimaan pembayaran, tunggakan per kelas, dan rekonsiliasi transaksi.
          </p>
        </div>

        <Button variant="outline" size="sm" onClick={loadData} disabled={loading} className="gap-2">
          <RefreshCw className={`size-4 ${loading ? "animate-spin" : ""}`} />
          <span>Segarkan Laporan</span>
        </Button>
      </div>

      {/* SECTION 1: PENERIMAAN KAS */}
      <div className="space-y-4">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-muted/40 p-4 rounded-2xl border border-border">
          <div>
            <h2 className="text-base font-bold text-foreground flex items-center gap-2">
              <TrendingUp className="size-4.5 text-emerald-600" />
              <span>Arus Penerimaan Pembayaran</span>
            </h2>
            <p className="text-xs text-muted-foreground">Pilih rentang tanggal untuk melihat total penerimaan kas/gateway.</p>
          </div>

          <div className="flex items-center gap-2">
            <div className="flex items-center gap-1.5 bg-card px-2.5 py-1.5 rounded-lg border border-input shadow-2xs">
              <Calendar className="size-3.5 text-muted-foreground" />
              <Label className="text-xs text-muted-foreground">Dari:</Label>
              <Input
                type="date"
                value={from}
                onChange={(e) => setFrom(e.target.value)}
                className="h-7 border-0 p-0 text-xs focus-visible:ring-0 shadow-none font-semibold"
              />
            </div>
            <div className="flex items-center gap-1.5 bg-card px-2.5 py-1.5 rounded-lg border border-input shadow-2xs">
              <Calendar className="size-3.5 text-muted-foreground" />
              <Label className="text-xs text-muted-foreground">Sampai:</Label>
              <Input
                type="date"
                value={to}
                onChange={(e) => setTo(e.target.value)}
                className="h-7 border-0 p-0 text-xs focus-visible:ring-0 shadow-none font-semibold"
              />
            </div>
          </div>
        </div>

        {collections === null ? (
          <Skeleton className="h-32 w-full" />
        ) : (
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card className="p-6 border-border/80 lg:col-span-1 flex flex-col justify-between bg-linear-to-br from-emerald-500/10 via-card to-card">
              <div>
                <span className="text-xs font-semibold text-emerald-800 uppercase tracking-wider">Total Kas Diterima</span>
                <p className="mt-2 text-3xl font-black text-emerald-700">{rupiah(collections.total)}</p>
                <p className="mt-1 text-xs text-muted-foreground">
                  Akumulasi dari <strong>{collections.count} transaksi</strong> pembayaran yang berhasil diverifikasi.
                </p>
              </div>
            </Card>

            <Card className="p-5 border-border/80 lg:col-span-1">
              <h3 className="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-3 flex items-center gap-2">
                <CreditCard className="size-4 text-primary" />
                <span>Penerimaan per Metode</span>
              </h3>
              <div className="space-y-2 max-h-40 overflow-y-auto">
                {collections.by_method.map((m) => (
                  <div key={m.method} className="flex items-center justify-between p-2 rounded-lg bg-muted/40 text-xs">
                    <span className="font-semibold text-foreground uppercase">{m.method} ({m.count}×)</span>
                    <span className="font-bold text-primary tabular">{rupiah(m.total)}</span>
                  </div>
                ))}
                {collections.by_method.length === 0 && (
                  <p className="text-xs text-muted-foreground text-center py-4">Belum ada transaksi di periode ini.</p>
                )}
              </div>
            </Card>

            <Card className="p-5 border-border/80 lg:col-span-1">
              <h3 className="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-3 flex items-center gap-2">
                <Layers className="size-4 text-indigo-600" />
                <span>Penerimaan per Kategori</span>
              </h3>
              <div className="space-y-2 max-h-40 overflow-y-auto">
                {collections.by_fee_type.map((f) => (
                  <div key={f.fee_type} className="flex items-center justify-between p-2 rounded-lg bg-muted/40 text-xs">
                    <span className="font-semibold text-foreground">{f.fee_type}</span>
                    <span className="font-bold text-emerald-600 tabular">{rupiah(f.total)}</span>
                  </div>
                ))}
                {collections.by_fee_type.length === 0 && (
                  <p className="text-xs text-muted-foreground text-center py-4">Belum ada transaksi di periode ini.</p>
                )}
              </div>
            </Card>
          </div>
        )}
      </div>

      {/* SECTION 2: STATUS PIUTANG & TUNGGAKAN */}
      <div className="space-y-4 pt-4 border-t border-border">
        <h2 className="text-base font-bold text-foreground flex items-center gap-2">
          <TrendingDown className="size-4.5 text-destructive" />
          <span>Status Piutang & Tunggakan Berjalan</span>
        </h2>

        {receivables === null ? (
          <Skeleton className="h-40 w-full" />
        ) : (
          <>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
              <Card className="p-4 border-border/80">
                <span className="text-xs text-muted-foreground">Total Tunggakan</span>
                <p className="mt-1 text-xl font-bold text-foreground">{rupiah(receivables.summary.outstanding)}</p>
              </Card>
              <Card className="p-4 border-border/80">
                <span className="text-xs text-muted-foreground">Tagihan Terbuka</span>
                <p className="mt-1 text-xl font-bold text-foreground">{receivables.summary.bills}</p>
              </Card>
              <Card className="p-4 border-border/80">
                <span className="text-xs text-muted-foreground">Keluarga Menunggak</span>
                <p className="mt-1 text-xl font-bold text-foreground">{receivables.summary.families}</p>
              </Card>
              <Card className="p-4 border-border/80">
                <span className="text-xs text-muted-foreground">Lewat Jatuh Tempo</span>
                <p className="mt-1 text-xl font-bold text-destructive">{receivables.summary.overdue_bills}</p>
              </Card>
            </div>

            <Card className="overflow-hidden border-border/80">
              <div className="border-b border-border bg-muted/30 px-5 py-3 flex items-center justify-between">
                <p className="text-sm font-bold text-foreground">Rekapitulasi Tunggakan per Kelas</p>
                <span className="text-xs text-muted-foreground">{receivables.by_class.length} kelas terdata</span>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="border-b border-border bg-muted/40 text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="px-5 py-3.5">Rombel / Kelas</th>
                      <th className="px-5 py-3.5">Jumlah Siswa</th>
                      <th className="px-5 py-3.5">Jumlah Tagihan</th>
                      <th className="px-5 py-3.5 text-right">Total Tunggakan</th>
                      <th className="px-5 py-3.5 text-right">Lewat Tempo</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {receivables.by_class.map((row) => (
                      <tr key={row.kelas} className="hover:bg-muted/20 transition-colors">
                        <td className="px-5 py-3.5 font-bold text-foreground">{row.kelas}</td>
                        <td className="px-5 py-3.5 text-muted-foreground">{row.students} siswa</td>
                        <td className="px-5 py-3.5 text-muted-foreground">{row.bills} tagihan</td>
                        <td className="px-5 py-3.5 text-right font-bold text-foreground">{rupiah(row.outstanding)}</td>
                        <td className="px-5 py-3.5 text-right">
                          {row.overdue > 0 ? (
                            <span className="font-bold text-destructive">{rupiah(row.overdue)}</span>
                          ) : (
                            <span className="text-muted-foreground">—</span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          </>
        )}
      </div>
    </div>
  );
}

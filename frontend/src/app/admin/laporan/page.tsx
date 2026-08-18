"use client";

import { useEffect, useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api } from "@/lib/api";
import { rupiah } from "@/lib/format";

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

function firstOfMonth(): string {
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
}

export default function ReportsPage() {
  const [receivables, setReceivables] = useState<Receivables | null>(null);
  const [collections, setCollections] = useState<Collections | null>(null);
  const [from, setFrom] = useState(firstOfMonth());
  const [to, setTo] = useState(new Date().toISOString().slice(0, 10));

  useEffect(() => {
    api.get<Receivables>("/api/admin/reports/receivables").then(setReceivables);
  }, []);

  useEffect(() => {
    api.get<Collections>(`/api/admin/reports/collections?from=${from}&to=${to}`).then(setCollections);
  }, [from, to]);

  return (
    <div className="flex flex-col gap-8">
      <div>
        <h1 className="text-xl font-bold tracking-tight">Laporan</h1>
        <p className="mt-1 text-sm text-muted-foreground">Tunggakan berjalan dan penerimaan per periode.</p>
      </div>

      <section className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold text-muted-foreground">Tunggakan</h2>

        {receivables === null ? (
          <Skeleton className="h-24 w-full" />
        ) : (
          <>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <Card className="p-4">
                <p className="text-xs text-muted-foreground">Total tunggakan</p>
                <p className="tabular text-lg font-bold">{rupiah(receivables.summary.outstanding)}</p>
              </Card>
              <Card className="p-4">
                <p className="text-xs text-muted-foreground">Tagihan</p>
                <p className="tabular text-lg font-bold">{receivables.summary.bills}</p>
              </Card>
              <Card className="p-4">
                <p className="text-xs text-muted-foreground">Keluarga</p>
                <p className="tabular text-lg font-bold">{receivables.summary.families}</p>
              </Card>
              <Card className="p-4">
                <p className="text-xs text-muted-foreground">Lewat jatuh tempo</p>
                <p className="tabular text-lg font-bold text-bad">{receivables.summary.overdue_bills}</p>
              </Card>
            </div>

            <Card className="overflow-hidden p-0">
              <div className="border-b border-border px-5 py-3"><p className="text-sm font-semibold">Per kelas</p></div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-border text-left text-xs text-muted-foreground">
                      <th className="px-5 py-2 font-medium">Kelas</th>
                      <th className="px-5 py-2 font-medium">Siswa</th>
                      <th className="px-5 py-2 text-right font-medium">Tunggakan</th>
                      <th className="px-5 py-2 text-right font-medium">Lewat tempo</th>
                    </tr>
                  </thead>
                  <tbody>
                    {receivables.by_class.map((row) => (
                      <tr key={row.kelas} className="border-b border-border last:border-b-0">
                        <td className="px-5 py-2.5">{row.kelas}</td>
                        <td className="tabular px-5 py-2.5">{row.students}</td>
                        <td className="tabular px-5 py-2.5 text-right">{rupiah(row.outstanding)}</td>
                        <td className="tabular px-5 py-2.5 text-right">
                          {row.overdue > 0 ? <span className="text-bad">{rupiah(row.overdue)}</span> : "—"}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          </>
        )}
      </section>

      <section className="flex flex-col gap-3">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <h2 className="text-sm font-semibold text-muted-foreground">Penerimaan</h2>
          <div className="flex items-end gap-2">
            <div className="flex flex-col gap-1">
              <Label className="text-xs">Dari</Label>
              <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-9" />
            </div>
            <div className="flex flex-col gap-1">
              <Label className="text-xs">Sampai</Label>
              <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-9" />
            </div>
          </div>
        </div>

        {collections === null ? (
          <Skeleton className="h-24 w-full" />
        ) : (
          <>
            <Card className="p-4">
              <p className="text-xs text-muted-foreground">Total diterima · {collections.count} transaksi</p>
              <p className="tabular text-2xl font-bold text-good">{rupiah(collections.total)}</p>
            </Card>

            <div className="grid gap-3 sm:grid-cols-2">
              <Card className="p-4">
                <p className="mb-2 text-sm font-semibold">Per metode</p>
                <div className="flex flex-col gap-1.5">
                  {collections.by_method.map((m) => (
                    <div key={m.method} className="flex items-center justify-between text-sm">
                      <Badge>{m.method} · {m.count}×</Badge>
                      <span className="tabular font-medium">{rupiah(m.total)}</span>
                    </div>
                  ))}
                  {collections.by_method.length === 0 && <p className="text-sm text-muted-foreground">Tidak ada transaksi.</p>}
                </div>
              </Card>
              <Card className="p-4">
                <p className="mb-2 text-sm font-semibold">Per jenis biaya</p>
                <div className="flex flex-col gap-1.5">
                  {collections.by_fee_type.map((f) => (
                    <div key={f.fee_type} className="flex items-center justify-between text-sm">
                      <span>{f.fee_type}</span>
                      <span className="tabular font-medium">{rupiah(f.total)}</span>
                    </div>
                  ))}
                  {collections.by_fee_type.length === 0 && <p className="text-sm text-muted-foreground">Tidak ada transaksi.</p>}
                </div>
              </Card>
            </div>
          </>
        )}
      </section>
    </div>
  );
}

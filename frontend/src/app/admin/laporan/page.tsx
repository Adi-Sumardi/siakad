"use client";

import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import {
  Calendar,
  CreditCard,
  FileDown,
  Layers,
  Link2,
  RefreshCw,
  TrendingDown,
  TrendingUp,
  Undo2,
} from "lucide-react";

import { useAuth } from "@/lib/auth/auth-context";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { downloadApiFile } from "@/lib/download";
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

type TxnRow = {
  ulid: string;
  payment_number: string;
  reference_number: string;
  amount: number;
  method: string;
  channel: string | null;
  status: string;
  paid_at: string | null;
  created_at: string;
  receipt_url: string | null;
  bills: { bill_number: string; description: string; fee_type?: { name: string } | null; student?: { nama_lengkap: string; nis?: string | null; school_unit?: { label: string } | null } | null }[];
};

const TXN_STATUS: Record<string, { label: string; variant: "good" | "warn" | "bad" | "default" }> = {
  completed: { label: "Lunas", variant: "good" },
  pending: { label: "Menunggu", variant: "warn" },
  processing: { label: "Diproses", variant: "warn" },
  failed: { label: "Gagal", variant: "bad" },
  expired: { label: "Kedaluwarsa", variant: "bad" },
  cancelled: { label: "Dibatalkan", variant: "default" },
  refunded: { label: "Direfund", variant: "default" },
};

export default function ReportsPage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [receivables, setReceivables] = useState<Receivables | null>(null);
  const [collections, setCollections] = useState<Collections | null>(null);
  const [from, setFrom] = useState(firstOfMonth());
  const [to, setTo] = useState(todayJakarta());
  // Spinner state ONLY - set from the button click and cleared in the async
  // callback. First-load skeletons derive from the data states being null,
  // so refetching keeps the previous report on screen.
  const [refreshing, setRefreshing] = useState(false);

  // Riwayat Transaksi (Poin 11A): its own filter set + pagination.
  const [txns, setTxns] = useState<{ data: TxnRow[]; meta: { current_page: number; last_page: number; total: number } } | null>(null);
  const [txnQ, setTxnQ] = useState("");
  const [txnStatus, setTxnStatus] = useState("");
  const [txnUnit, setTxnUnit] = useState("");
  const [txnPage, setTxnPage] = useState(1);
  const [unitOptions, setUnitOptions] = useState<{ code: string; label: string }[]>([]);

  // The refund worklist (audit r2 2026-10-05): payments whose money arrived
  // for bills another payment had already covered. Two-step confirm per row
  // - a refund is a money decision, not a click.
  const [overpayments, setOverpayments] = useState<{ data: TxnRow[] } | null>(null);
  const [refundArmed, setRefundArmed] = useState<string | null>(null);

  // Out-of-order guard (audit 2026-09-28) for the transaction list.
  const txnRequestId = useRef(0);

  function loadOverpayments() {
    api
      .get<{ payments: { data: TxnRow[] } }>("/api/admin/payments/overpayments")
      .then((d) => setOverpayments({ data: d.payments.data }))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar overpayment."));
  }

  function refundOverpayment(row: TxnRow) {
    api
      .post<{ message: string }>(`/api/admin/payments/${row.ulid}/refund`, {})
      .then(({ message }) => {
        toast.success(message);
        setRefundArmed(null);
        loadOverpayments();
        loadTxns(1);
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal menandai refund."));
  }

  function loadTxns(page = txnPage) {
    const requestId = ++txnRequestId.current;
    const params = new URLSearchParams();
    if (txnQ.trim()) params.set("q", txnQ.trim());
    if (txnStatus) params.set("status", txnStatus);
    if (txnUnit) params.set("unit", txnUnit);
    params.set("page", String(page));
    api
      .get<{ payments: { data: TxnRow[]; meta: { current_page: number; last_page: number; total: number } } }>(`/api/admin/payments?${params}`)
      .then((d) => {
        if (requestId !== txnRequestId.current) return;
        setTxns(d.payments);
      })
      .catch((err) => {
        if (requestId !== txnRequestId.current) return;
        // A failed fetch is not an empty result (audit r2 2026-10-05): say
        // so, and keep whatever was on screen instead of wiping it.
        toast.error(err instanceof ApiError ? err.message : "Gagal memuat riwayat transaksi.");
        setTxns((prev) => prev ?? { data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
      });
  }

  useEffect(() => {
    loadTxns(1);
    loadOverpayments();
    if (isCentral) {
      api.get<{ school_units: { code: string; label: string }[] }>("/api/admin/school-units")
        .then((d) => setUnitOptions(d.school_units))
        .catch(() => {});
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [txnStatus, txnUnit, isCentral]);

  // .then() chains (not async/await) so setState only ever runs in an async
  // callback - the effect below calls this synchronously, and awaiting first
  // still trips react-hooks/set-state-in-effect's analysis.
  function loadData() {
    Promise.all([
      api.get<Receivables>("/api/admin/reports/receivables"),
      api.get<Collections>(`/api/admin/reports/collections?from=${from}&to=${to}`),
    ])
      .then(([recData, colData]) => {
        setReceivables(recData);
        setCollections(colData);
      })
      .catch((err) => {
        toast.error(err instanceof ApiError ? err.message : "Gagal memuat laporan.");
      })
      .finally(() => setRefreshing(false));
  }

  useEffect(() => {
    loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
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

        <Button
          variant="outline"
          size="sm"
          onClick={() => {
            setRefreshing(true);
            loadData();
          }}
          disabled={refreshing}
          className="gap-2"
        >
          <RefreshCw className={`size-4 ${refreshing ? "animate-spin" : ""}`} />
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

      {/* SECTION 1b: RIWAYAT TRANSAKSI (Poin 11A) */}
      <div className="space-y-4">
        <div className="bg-muted/40 p-4 rounded-2xl border border-border">
          <h2 className="text-base font-bold text-foreground flex items-center gap-2">
            <Layers className="size-4.5 text-primary" />
            <span>Riwayat Transaksi</span>
          </h2>
          <p className="text-xs text-muted-foreground mt-0.5">
            Setiap pembayaran dengan filter lengkap — cari nomor, siswa, unit, status, dan unduh kuitansi per transaksi.
          </p>

          <div className="mt-3 flex flex-wrap items-end gap-2.5">
            <div className="flex flex-col gap-1 min-w-[200px] flex-1">
              <Label className="text-[11px] font-semibold uppercase text-muted-foreground">Cari</Label>
              <Input
                value={txnQ}
                onChange={(e) => setTxnQ(e.target.value)}
                onKeyDown={(e) => { if (e.key === "Enter") { setTxnPage(1); loadTxns(1); } }}
                placeholder="No. pembayaran / tagihan / nama / NIS…"
                className="h-9 bg-card text-xs shadow-2xs"
              />
            </div>
            <div className="flex flex-col gap-1">
              <Label className="text-[11px] font-semibold uppercase text-muted-foreground">Status</Label>
              <select
                value={txnStatus}
                onChange={(e) => { setTxnStatus(e.target.value); setTxnPage(1); }}
                className="h-9 w-36 rounded-lg border border-input bg-card px-2.5 text-xs shadow-2xs"
              >
                <option value="">Semua</option>
                <option value="completed">Lunas</option>
                <option value="pending">Menunggu</option>
                <option value="processing">Diproses</option>
                <option value="failed">Gagal</option>
                <option value="expired">Kedaluwarsa</option>
                <option value="refunded">Direfund</option>
              </select>
            </div>
            {isCentral && (
              <div className="flex flex-col gap-1">
                <Label className="text-[11px] font-semibold uppercase text-muted-foreground">Unit</Label>
                <select
                  value={txnUnit}
                  onChange={(e) => { setTxnUnit(e.target.value); setTxnPage(1); }}
                  className="h-9 w-44 rounded-lg border border-input bg-card px-2.5 text-xs shadow-2xs"
                >
                  <option value="">Semua Unit</option>
                  {unitOptions.map((u) => <option key={u.code} value={u.code}>{u.label}</option>)}
                </select>
              </div>
            )}
            <Button size="sm" variant="outline" className="h-9 text-xs" onClick={() => { setTxnPage(1); loadTxns(1); }}>
              Cari
            </Button>
          </div>
        </div>

        {txns === null ? (
          <Skeleton className="h-56 w-full rounded-2xl" />
        ) : txns.data.length === 0 ? (
          <Card className="p-8 text-center text-sm text-muted-foreground">Tidak ada transaksi untuk filter ini.</Card>
        ) : (
          <>
            <Card className="overflow-x-auto p-0">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border bg-muted/30 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <th className="px-4 py-3">No. Referensi</th>
                    <th className="px-4 py-3">Tanggal Bayar</th>
                    <th className="px-4 py-3">Siswa</th>
                    <th className="px-4 py-3">Unit</th>
                    <th className="px-4 py-3">Jenis Tagihan</th>
                    <th className="px-4 py-3 text-right">Nominal</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {txns.data.map((t) => {
                    const first = t.bills[0];
                    const feeNames = [...new Set(t.bills.map((b) => b.fee_type?.name).filter(Boolean))].join(", ");
                    const status = TXN_STATUS[t.status] ?? { label: t.status, variant: "default" as const };

                    return (
                      <tr key={t.ulid} className="border-b border-border/60 last:border-0 hover:bg-muted/20 transition-colors">
                        <td className="px-4 py-3">
                          <p className="font-mono text-xs font-bold text-foreground">{t.reference_number}</p>
                          <p className="text-[11px] text-muted-foreground font-mono">{t.payment_number}</p>
                        </td>
                        <td className="px-4 py-3 text-xs text-muted-foreground">
                          {t.paid_at ? new Date(t.paid_at).toLocaleDateString("id-ID", { day: "2-digit", month: "short", year: "numeric" }) : "—"}
                        </td>
                        <td className="px-4 py-3">
                          <p className="text-xs font-semibold text-foreground">{first?.student?.nama_lengkap ?? "—"}</p>
                          {first?.student?.nis && <p className="text-[11px] text-muted-foreground">NIS {first.student.nis}</p>}
                        </td>
                        <td className="px-4 py-3 text-xs text-muted-foreground">
                          {first?.student?.school_unit?.label ?? "—"}
                        </td>
                        <td className="px-4 py-3 text-xs">{feeNames || first?.description || "—"}</td>
                        <td className="px-4 py-3 text-right font-bold tabular text-foreground">{rupiah(t.amount)}</td>
                        <td className="px-4 py-3"><Badge variant={status.variant}>{status.label}</Badge></td>
                        <td className="px-4 py-3">
                          <div className="flex items-center justify-end gap-1.5">
                            {t.status === "completed" && (
                              <>
                                <button
                                  type="button"
                                  title="Unduh kuitansi PDF"
                                  onClick={() => {
                                    // Poin 10: the receipt endpoint sits behind
                                    // Sanctum's session cookie - a plain <a href>
                                    // navigates without it and gets a JSON 401.
                                    // Fetch as a blob instead; the cookie rides along.
                                    downloadApiFile(
                                      `/api/admin/payments/${t.ulid}/receipt`,
                                      `Kuitansi-${t.reference_number.replace(/\//g, "-")}.pdf`,
                                    ).catch((err) => toast.error(err instanceof Error ? err.message : "Gagal mengunduh kuitansi."));
                                  }}
                                  className="rounded-lg border border-input bg-card p-1.5 text-muted-foreground transition-colors hover:text-primary"
                                >
                                  <FileDown className="size-3.5" />
                                </button>
                                <button
                                  type="button"
                                  title="Salin tautan struk publik"
                                  onClick={() => {
                                    api.post<{ url: string }>(`/api/admin/payments/${t.ulid}/share-link`, {})
                                      .then(({ url }) => {
                                        navigator.clipboard.writeText(window.location.origin + url);
                                        toast.success("Tautan struk publik disalin ke clipboard.");
                                      })
                                      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal membuat tautan."));
                                  }}
                                  className="rounded-lg border border-input bg-card p-1.5 text-muted-foreground transition-colors hover:text-primary"
                                >
                                  <Link2 className="size-3.5" />
                                </button>
                              </>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </Card>

            {txns.meta.last_page > 1 && (
              <div className="flex items-center justify-between gap-3 text-xs">
                <p className="text-muted-foreground">
                  Halaman {txns.meta.current_page} dari {txns.meta.last_page} · {txns.meta.total} transaksi
                </p>
                <div className="flex gap-2">
                  <Button size="sm" variant="outline" disabled={txns.meta.current_page <= 1} onClick={() => { const p = Math.max(1, txnPage - 1); setTxnPage(p); loadTxns(p); }}>
                    Sebelumnya
                  </Button>
                  <Button size="sm" variant="outline" disabled={txns.meta.current_page >= txns.meta.last_page} onClick={() => { const p = txnPage + 1; setTxnPage(p); loadTxns(p); }}>
                    Berikutnya
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {/* SECTION 1c: OVERPAYMENT / WORKLIST REFUND (audit r2 2026-10-05) */}
      {overpayments !== null && overpayments.data.length > 0 && (
        <div className="space-y-3 rounded-2xl border border-amber-500/40 bg-amber-500/5 p-4">
          <h2 className="text-base font-bold text-foreground flex items-center gap-2">
            <Undo2 className="size-4.5 text-amber-600" />
            <span>Pembayaran Ganda Menunggu Refund</span>
            <Badge variant="bad">{overpayments.data.length}</Badge>
          </h2>
          <p className="text-xs text-muted-foreground">
            Uang keluarga yang masuk untuk tagihan yang sudah lunas lewat Virtual Account lain. Tandai <b>Refund</b> hanya
            setelah transfer pengembalian dana benar-benar dikirim dari rekening sekolah — keluarga sudah diberi tahu lewat WhatsApp.
          </p>
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border bg-muted/30 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                  <th className="px-4 py-3">No. Referensi</th>
                  <th className="px-4 py-3">Tanggal Bayar</th>
                  <th className="px-4 py-3">Siswa</th>
                  <th className="px-4 py-3">Tagihan</th>
                  <th className="px-4 py-3 text-right">Nominal</th>
                  <th className="px-4 py-3 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {overpayments.data.map((t) => {
                  const first = t.bills[0];

                  return (
                    <tr key={t.ulid} className="border-b border-border/60 last:border-0 hover:bg-muted/20 transition-colors">
                      <td className="px-4 py-3">
                        <p className="font-mono text-xs font-bold text-foreground">{t.reference_number}</p>
                        <p className="text-[11px] text-muted-foreground font-mono">{t.payment_number}</p>
                      </td>
                      <td className="px-4 py-3 text-xs text-muted-foreground">
                        {t.paid_at ? new Date(t.paid_at).toLocaleDateString("id-ID", { day: "2-digit", month: "short", year: "numeric" }) : "—"}
                      </td>
                      <td className="px-4 py-3">
                        <p className="text-xs font-semibold text-foreground">{first?.student?.nama_lengkap ?? "—"}</p>
                        {first?.student?.school_unit?.label && <p className="text-[11px] text-muted-foreground">{first.student.school_unit.label}</p>}
                      </td>
                      <td className="px-4 py-3 text-xs">{first?.description ?? "—"}</td>
                      <td className="px-4 py-3 text-right font-bold tabular text-foreground">{rupiah(t.amount)}</td>
                      <td className="px-4 py-3 text-right">
                        {refundArmed === t.ulid ? (
                          <div className="flex items-center justify-end gap-1.5">
                            <Button size="sm" variant="ghost" className="h-8 text-xs" onClick={() => setRefundArmed(null)}>
                              Batal
                            </Button>
                            <Button size="sm" variant="destructive" className="h-8 text-xs" onClick={() => refundOverpayment(t)}>
                              Ya, Sudah Ditransfer
                            </Button>
                          </div>
                        ) : (
                          <Button size="sm" variant="outline" className="h-8 text-xs" onClick={() => setRefundArmed(t.ulid)}>
                            <Undo2 className="size-3.5" /> Tandai Refund
                          </Button>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </Card>
        </div>
      )}

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

      {/* SECTION 3: PRESENSI HARIAN - moved to the Presensi Harian tab
          (admin/presensi-harian), where an attendance recap belongs; this
          page stays purely financial (receivables, collections, transactions). */}
    </div>
  );
}

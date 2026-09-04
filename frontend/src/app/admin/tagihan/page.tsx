"use client";

import { useCallback, useEffect, useState } from "react";
import { Download, Filter, Receipt, RefreshCw, Search, ShieldAlert, Wallet } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Pagination } from "@/components/pagination";
import { api, ApiError } from "@/lib/api";
import { dueLabel, rupiah, tanggal } from "@/lib/format";
import { isOpen, type Bill } from "@/lib/types/billing";

type Paginated<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; total: number };
};

type Option = { ulid: string; code: string; label: string };

function statusBadge(bill: Bill) {
  if (bill.status === "paid") return <Badge variant="good">Lunas</Badge>;
  if (bill.status === "waived") return <Badge>Dibebaskan</Badge>;
  if (bill.status === "cancelled") return <Badge>Dibatalkan</Badge>;
  if (bill.status === "overdue") return <Badge variant="bad">{dueLabel(bill.days_to_due)}</Badge>;
  if (bill.status === "partial") return <Badge variant="warn">Kurang bayar</Badge>;
  return <Badge>{dueLabel(bill.days_to_due)}</Badge>;
}

type Action = { bill: Bill; kind: "bayar" | "bebaskan" | "batalkan" };

export default function AdminBillsPage() {
  const [bills, setBills] = useState<Paginated<Bill> | null>(null);
  const [status, setStatus] = useState("open");
  const [q, setQ] = useState("");
  const [unitCode, setUnitCode] = useState("");
  const [academicYear, setAcademicYear] = useState("");
  const [units, setUnits] = useState<Option[]>([]);
  const [years, setYears] = useState<{ ulid: string; year: string; is_active: boolean }[]>([]);
  const [action, setAction] = useState<Action | null>(null);
  const [page, setPage] = useState(1);

  // Form states
  const [submitting, setSubmitting] = useState(false);
  const [payAmount, setPayAmount] = useState("");
  const [payMethod, setPayMethod] = useState("cash");
  const [reason, setReason] = useState("");

  const load = useCallback(async () => {
    const params = new URLSearchParams();
    if (status) params.set("status", status);
    if (q) params.set("q", q);
    if (unitCode) params.set("unit", unitCode);
    if (academicYear) params.set("year", academicYear);
    if (page > 1) params.set("page", String(page));

    try {
      const d = await api.get<{ bills: Paginated<Bill> }>(`/api/admin/bills?${params}`);
      setBills(d.bills);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memuat tagihan.");
    }
  }, [status, q, unitCode, academicYear, page]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    api
      .get<{ school_units: Option[] }>("/api/admin/school-units")
      .then((d) => setUnits(d.school_units))
      .catch(() => {});

    api
      .get<{ academic_years: { ulid: string; year: string; is_active: boolean }[] }>("/api/admin/academic-years")
      .then((d) => setYears(d.academic_years))
      .catch(() => {});
  }, []);

  function openAction(bill: Bill, kind: Action["kind"]) {
    setAction({ bill, kind });
    setPayAmount(String(bill.remaining_amount));
    setPayMethod("cash");
    setReason("");
  }

  async function handleActionSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!action) return;

    if (action.kind !== "bayar" && !reason.trim()) {
      toast.error("Alasan wajib diisi.");
      return;
    }

    setSubmitting(true);
    try {
      if (action.kind === "bayar") {
        await api.post(`/api/admin/bills/${action.bill.ulid}/payments`, {
          amount: parseFloat(payAmount),
          method: payMethod,
          notes: reason || undefined,
        });
        toast.success("Pembayaran berhasil dicatat.");
      } else if (action.kind === "bebaskan") {
        await api.post(`/api/admin/bills/${action.bill.ulid}/waive`, { reason });
        toast.success("Tagihan berhasil dibebaskan.");
      } else {
        await api.post(`/api/admin/bills/${action.bill.ulid}/cancel`, { reason });
        toast.success("Tagihan berhasil dibatalkan.");
      }

      setAction(null);
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memproses aksi.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Tagihan & Transaksi Siswa</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Daftar tagihan SPP dan administrasi sekolah di seluruh unit.
          </p>
        </div>

        <Button variant="outline" size="sm" onClick={load} className="gap-2 self-start sm:self-auto">
          <RefreshCw className="size-4" />
          <span>Segarkan Data</span>
        </Button>
      </div>

      {/* Filter Toolbar */}
      <div className="flex flex-wrap items-center gap-3 bg-muted/40 p-3.5 rounded-2xl border border-border">
        <div className="relative min-w-[240px] flex-1">
          <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={q}
            onChange={(e) => { setQ(e.target.value); setPage(1); }}
            placeholder="Cari nama siswa atau no tagihan…"
            className="pl-9 bg-card text-xs shadow-2xs"
          />
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <div className="flex items-center gap-1 text-xs text-muted-foreground">
            <Filter className="size-3.5" />
            <span>Filter:</span>
          </div>

          <select
            value={academicYear}
            onChange={(e) => { setAcademicYear(e.target.value); setPage(1); }}
            className="rounded-lg border border-input bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-2xs"
          >
            <option value="">Semua Tahun Ajaran</option>
            {years.map((y) => (
              <option key={y.ulid} value={y.year}>
                Tahun {y.year} {y.is_active ? "(Aktif)" : ""}
              </option>
            ))}
          </select>

          <select
            value={unitCode}
            onChange={(e) => { setUnitCode(e.target.value); setPage(1); }}
            className="rounded-lg border border-input bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-2xs"
          >
            <option value="">Semua Unit Sekolah</option>
            {units.map((u) => (
              <option key={u.ulid} value={u.code}>{u.label}</option>
            ))}
          </select>

          <select
            value={status}
            onChange={(e) => { setStatus(e.target.value); setPage(1); }}
            className="rounded-lg border border-input bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-2xs"
          >
            <option value="open">Belum Lunas</option>
            <option value="overdue">Jatuh Tempo (Menunggak)</option>
            <option value="partial">Kurang Bayar (Cicilan)</option>
            <option value="paid">Lunas</option>
            <option value="waived">Dibebaskan</option>
            <option value="cancelled">Dibatalkan</option>
            <option value="">Semua Status</option>
          </select>
        </div>
      </div>

      {/* Bill List */}
      {bills === null && (
        <div className="space-y-3">
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-20 w-full" />
        </div>
      )}

      <div className="grid grid-cols-1 gap-3">
        {bills?.data.map((bill) => (
          <Card key={bill.ulid} className="p-4 sm:p-5 border-border/80 hover:border-primary/40 transition-colors">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <p className="font-bold text-foreground text-base">{bill.description}</p>
                  <Badge variant="default" className="font-mono text-[11px]">{bill.bill_number}</Badge>
                  {statusBadge(bill)}
                </div>

                <p className="text-sm font-medium text-muted-foreground mt-1">
                  Siswa: <strong className="text-foreground">{bill.student?.nama_lengkap}</strong> · Jatuh tempo {tanggal(bill.due_date)}
                </p>

                {bill.discount_amount > 0 && (
                  <p className="text-xs text-emerald-600 font-semibold mt-1">
                    Diskon/Beasiswa: {rupiah(bill.discount_amount)}
                  </p>
                )}
              </div>

              <div className="flex sm:flex-col sm:items-end justify-between items-center gap-1 border-t sm:border-t-0 pt-2 sm:pt-0 border-border/60">
                <span className="text-xs text-muted-foreground">Sisa Tagihan</span>
                <p className="tabular font-bold text-foreground text-lg sm:text-right">{rupiah(bill.remaining_amount)}</p>
                {bill.paid_amount > 0 && (
                  <p className="tabular text-xs text-muted-foreground sm:text-right">
                    Terbayar: {rupiah(bill.paid_amount)} dari {rupiah(bill.total_amount)}
                  </p>
                )}
              </div>
            </div>

            {isOpen(bill) && (
              <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-border/60 pt-3">
                <div className="flex items-center gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => openAction(bill, "bayar")}
                    className="gap-1.5 text-xs font-semibold"
                  >
                    <Wallet className="size-3.5" />
                    <span>Catat Bayar</span>
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => openAction(bill, "bebaskan")}
                    className="text-xs text-muted-foreground hover:text-foreground"
                  >
                    Bebaskan
                  </Button>
                  {bill.paid_amount === 0 && (
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => openAction(bill, "batalkan")}
                      className="text-xs text-destructive hover:bg-destructive/10"
                    >
                      Batalkan
                    </Button>
                  )}
                </div>

                <a
                  href={`/api/admin/bills/${bill.ulid}/pdf`}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center gap-1 text-xs text-primary font-medium hover:underline"
                >
                  <Download className="size-3.5" />
                  <span>Invoice PDF</span>
                </a>
              </div>
            )}
          </Card>
        ))}

        {bills?.data.length === 0 && (
          <Card className="p-8 text-center text-sm text-muted-foreground">
            Tidak ada tagihan yang sesuai kriteria pencarian.
          </Card>
        )}

        <Pagination
          currentPage={bills?.meta.current_page ?? 1}
          lastPage={bills?.meta.last_page ?? 1}
          onChange={setPage}
        />
      </div>

      {/* ACTION MODAL */}
      {action && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
          <div className="w-full max-w-md rounded-2xl bg-card p-6 shadow-2xl border border-border">
            <h2 className="text-lg font-bold text-foreground">
              {action.kind === "bayar" && "Catat Pembayaran Tagihan"}
              {action.kind === "bebaskan" && "Bebaskan Tagihan (Waiver)"}
              {action.kind === "batalkan" && "Batalkan Tagihan"}
            </h2>

            <div className="mt-3 p-3 bg-muted/40 rounded-xl text-xs space-y-1">
              <p><strong>Tagihan:</strong> {action.bill.description}</p>
              <p><strong>Siswa:</strong> {action.bill.student?.nama_lengkap}</p>
              <p><strong>Sisa Tagihan:</strong> {rupiah(action.bill.remaining_amount)}</p>
            </div>

            <form onSubmit={handleActionSubmit} className="mt-4 space-y-4">
              {action.kind === "bayar" && (
                <>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                      <Label htmlFor="pay_amount" className="text-xs">Jumlah Dibayar (Rp)</Label>
                      <Input
                        id="pay_amount"
                        type="number"
                        min="1"
                        max={action.bill.remaining_amount}
                        value={payAmount}
                        onChange={(e) => setPayAmount(e.target.value)}
                        required
                        className="mt-1 font-bold"
                      />
                    </div>
                    <div>
                      <Label htmlFor="pay_method" className="text-xs">Metode Pembayaran</Label>
                      <select
                        id="pay_method"
                        value={payMethod}
                        onChange={(e) => setPayMethod(e.target.value)}
                        className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary"
                      >
                        <option value="cash">Tunai (Front Desk)</option>
                        <option value="bank_transfer">Transfer Bank</option>
                        <option value="qris">QRIS</option>
                        <option value="other">Lainnya</option>
                      </select>
                    </div>
                  </div>

                  <div>
                    <Label htmlFor="pay_notes" className="text-xs">Catatan (Opsional)</Label>
                    <Input
                      id="pay_notes"
                      placeholder="Nomor struk, nama penyetor, dll"
                      value={reason}
                      onChange={(e) => setReason(e.target.value)}
                      className="mt-1"
                    />
                  </div>
                </>
              )}

              {(action.kind === "bebaskan" || action.kind === "batalkan") && (
                <div>
                  <Label htmlFor="reason" className="text-xs">Alasan (Wajib diisi)</Label>
                  <Input
                    id="reason"
                    placeholder="Alasan pembatalan atau pembebasan tagihan..."
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    required
                    className="mt-1"
                  />
                </div>
              )}

              <div className="flex justify-end gap-2.5 pt-2">
                <Button type="button" variant="outline" onClick={() => setAction(null)}>
                  Batal
                </Button>
                <Button
                  type="submit"
                  disabled={submitting}
                  variant={action.kind === "batalkan" ? "destructive" : "default"}
                >
                  {submitting ? "Memproses..." : "Konfirmasi"}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

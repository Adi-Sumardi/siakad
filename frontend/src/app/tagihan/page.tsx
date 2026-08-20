"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import {
  AlertCircle,
  Check,
  CheckCircle2,
  ChevronRight,
  Coins,
  CreditCard,
  Download,
  ExternalLink,
  Receipt,
  Sparkles,
  Wallet,
} from "lucide-react";
import { toast } from "sonner";
import { WaliShell } from "@/components/layout/wali-shell";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useRequireRole } from "@/lib/auth/use-require-role";
import { dueLabel, rupiah } from "@/lib/format";
import { isOpen, type Bill, type BillSummary, type Payment } from "@/lib/types/billing";
import { cn } from "@/lib/utils";

function statusBadge(bill: Bill) {
  if (bill.status === "paid") return <Badge variant="good">Lunas</Badge>;
  if (bill.status === "waived") return <Badge>Dibebaskan</Badge>;
  if (bill.status === "cancelled") return <Badge>Dibatalkan</Badge>;
  if (bill.status === "overdue") return <Badge variant="bad">{dueLabel(bill.days_to_due)}</Badge>;
  if (bill.status === "partial") return <Badge variant="warn">Kurang bayar (Cicilan)</Badge>;

  const soon = bill.days_to_due !== null && bill.days_to_due <= 7;
  return <Badge variant={soon ? "warn" : "default"}>{dueLabel(bill.days_to_due)}</Badge>;
}

export default function BillsPage() {
  const { user, loading } = useRequireRole("orangtua");
  const router = useRouter();

  const [bills, setBills] = useState<Bill[] | null>(null);
  const [summary, setSummary] = useState<BillSummary | null>(null);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [paying, setPaying] = useState(false);

  // Custom Payment Modal State
  const [customBill, setCustomBill] = useState<Bill | null>(null);
  const [customAmount, setCustomAmount] = useState("");
  const [submittingCustom, setSubmittingCustom] = useState(false);

  const load = useCallback(async () => {
    try {
      const data = await api.get<{ bills: Bill[]; summary: BillSummary }>("/api/wali/bills");
      setBills(data.bills);
      setSummary(data.summary);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memuat tagihan. Muat ulang halaman.");
    }
  }, []);

  useEffect(() => {
    if (user?.role === "orangtua") load();
  }, [user, load]);

  const openBills = useMemo(() => bills?.filter(isOpen) ?? [], [bills]);
  const paidBills = useMemo(() => bills?.filter((b) => !isOpen(b)) ?? [], [bills]);

  const selectedBills = useMemo(
    () => openBills.filter((bill) => selected.has(bill.ulid)),
    [openBills, selected],
  );
  const selectedTotal = selectedBills.reduce((sum, bill) => sum + bill.remaining_amount, 0);

  const childrenInCart = new Set(selectedBills.map((b) => b.student?.ulid)).size;

  function toggle(ulid: string) {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(ulid)) next.delete(ulid);
      else next.add(ulid);
      return next;
    });
  }

  function selectAllOpen() {
    if (selected.size === openBills.length) {
      setSelected(new Set());
    } else {
      setSelected(new Set(openBills.map((b) => b.ulid)));
    }
  }

  async function checkoutMulti() {
    if (selected.size === 0) return;
    setPaying(true);

    try {
      const { payment } = await api.post<{ payment: Payment }>("/api/wali/checkout", {
        bill_ulids: [...selected],
        method: "virtual_account",
      });

      if (payment.invoice_url) {
        window.location.href = payment.invoice_url;
        return;
      }

      toast.success("Pembayaran berhasil dibuat.");
      setSelected(new Set());
      await load();
      router.push("/pembayaran");
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Tidak dapat memproses pembayaran.");
      await load();
    } finally {
      setPaying(false);
    }
  }

  async function checkoutCustom(e: React.FormEvent) {
    e.preventDefault();
    if (!customBill) return;

    const amount = parseFloat(customAmount);
    if (isNaN(amount) || amount <= 0 || amount > customBill.remaining_amount) {
      toast.error("Nominal pembayaran tidak valid.");
      return;
    }

    setSubmittingCustom(true);
    try {
      const { payment } = await api.post<{ payment: Payment }>("/api/wali/checkout", {
        bill_ulids: [customBill.ulid],
        method: "virtual_account",
        custom_amounts: {
          [customBill.ulid]: amount,
        },
      });

      if (payment.invoice_url) {
        window.location.href = payment.invoice_url;
        return;
      }

      toast.success("Pembayaran kustom berhasil dibuat.");
      setCustomBill(null);
      await load();
      router.push("/pembayaran");
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal membuat pembayaran kustom.");
    } finally {
      setSubmittingCustom(false);
    }
  }

  if (loading || !user || bills === null) {
    return (
      <WaliShell>
        <div className="space-y-4">
          <Skeleton className="h-10 w-64" />
          <Skeleton className="h-48 w-full" />
        </div>
      </WaliShell>
    );
  }

  return (
    <WaliShell>
      <div className="space-y-8 pb-28">
        {/* Header Section */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-foreground">
              Tagihan SPP & Administrasi
            </h1>
            <p className="text-sm text-muted-foreground mt-0.5">
              {summary?.open_count
                ? `${summary.open_count} tagihan belum lunas · Total kewajiban ${rupiah(summary.outstanding)}`
                : "Alhamdulillah, semua tagihan ananda sudah lunas."}
            </p>
          </div>

          <div className="flex items-center gap-2">
            {openBills.length > 0 && (
              <Button
                variant="outline"
                size="sm"
                onClick={selectAllOpen}
                className="gap-2 text-xs font-semibold"
              >
                <Check className="size-4" />
                <span>
                  {selected.size === openBills.length ? "Batalkan Semua" : "Pilih Semua Tagihan"}
                </span>
              </Button>
            )}
            <Link href="/pembayaran">
              <Button variant="ghost" size="sm" className="gap-2 text-xs text-primary">
                <CreditCard className="size-4" />
                <span>Riwayat Bayar</span>
              </Button>
            </Link>
          </div>
        </div>

        {/* SECTION 1: TAGIHAN BELUM LUNAS */}
        <section className="space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
              <Receipt className="size-5 text-primary" />
              <span>Tagihan Belum Lunas ({openBills.length})</span>
            </h2>
            <span className="text-xs text-muted-foreground">Centang untuk bayar sekaligus (Multi-Payment)</span>
          </div>

          {openBills.length === 0 && (
            <Card className="p-8 text-center text-sm border-dashed border-emerald-500/40 bg-emerald-500/5">
              <CheckCircle2 className="size-8 text-emerald-600 mx-auto mb-2" />
              <p className="font-bold text-emerald-800 text-base">Tidak ada tagihan tertunggak</p>
              <p className="text-xs text-muted-foreground mt-1">Semua kewajiban pembayaran SPP ananda telah diselesaikan.</p>
            </Card>
          )}

          <div className="grid grid-cols-1 gap-3.5">
            {openBills.map((bill) => {
              const checked = selected.has(bill.ulid);

              return (
                <Card
                  key={bill.ulid}
                  className={cn(
                    "relative p-5 border-border/80 transition-all duration-200 cursor-pointer hover:border-primary/60",
                    checked && "border-primary bg-primary/5 ring-1 ring-primary shadow-xs",
                  )}
                  onClick={() => toggle(bill.ulid)}
                >
                  <div className="flex items-start gap-4">
                    {/* Checkbox */}
                    <span
                      role="checkbox"
                      aria-checked={checked}
                      aria-label={`Pilih ${bill.description}`}
                      className={cn(
                        "mt-1 grid size-5.5 shrink-0 place-items-center rounded-lg border transition-all",
                        checked
                          ? "border-primary bg-primary text-primary-foreground shadow-xs"
                          : "border-input bg-card",
                      )}
                    >
                      {checked && <Check className="size-3.5" strokeWidth={3} />}
                    </span>

                    <div className="min-w-0 flex-1 space-y-2">
                      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                        <div>
                          <p className="font-bold text-foreground text-base">{bill.description}</p>
                          <p className="text-xs text-muted-foreground mt-0.5">
                            Siswa: <strong className="text-foreground">{bill.student?.nama_lengkap}</strong> · No: <span className="font-mono">{bill.bill_number}</span>
                          </p>
                        </div>

                        <div className="sm:text-right">
                          <p className="tabular font-black text-lg text-primary">{rupiah(bill.remaining_amount)}</p>
                          {bill.paid_amount > 0 && (
                            <p className="tabular text-xs text-muted-foreground">
                              sudah dicicil {rupiah(bill.paid_amount)} dari {rupiah(bill.total_amount)}
                            </p>
                          )}
                        </div>
                      </div>

                      <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border/60 pt-3">
                        <div className="flex flex-wrap items-center gap-2">
                          {statusBadge(bill)}
                          {bill.discount_amount > 0 && (
                            <Badge variant="primary">Diskon {rupiah(bill.discount_amount)}</Badge>
                          )}
                        </div>

                        <div className="flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => {
                              setCustomBill(bill);
                              setCustomAmount(String(bill.remaining_amount));
                            }}
                            className="text-xs font-semibold gap-1.5 h-8"
                          >
                            <Coins className="size-3.5" />
                            <span>Bayar Cicilan / Custom</span>
                          </Button>

                          <Link
                            href={`/tagihan/${bill.ulid}`}
                            className="inline-flex items-center gap-1 text-xs text-primary font-semibold hover:underline px-2 py-1"
                          >
                            <span>Rincian</span>
                            <ExternalLink className="size-3" />
                          </Link>
                        </div>
                      </div>
                    </div>
                  </div>
                </Card>
              );
            })}
          </div>
        </section>

        {/* SECTION 2: RIWAYAT SUDAH SELESAI */}
        {paidBills.length > 0 && (
          <section className="space-y-3 pt-6 border-t border-border">
            <h2 className="text-base font-bold text-muted-foreground">Sudah Selesai / Lunas ({paidBills.length})</h2>
            <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
              {paidBills.map((bill) => (
                <Card key={bill.ulid} className="flex items-center justify-between p-4 bg-muted/20 border-border/60">
                  <div className="min-w-0 flex-1 pr-2">
                    <p className="font-semibold text-foreground text-sm truncate">{bill.description}</p>
                    <p className="text-xs text-muted-foreground truncate">{bill.student?.nama_lengkap}</p>
                  </div>
                  <div className="flex items-center gap-2.5 shrink-0">
                    <span className="tabular text-xs font-bold text-foreground">{rupiah(bill.total_amount)}</span>
                    {statusBadge(bill)}
                  </div>
                </Card>
              ))}
            </div>
          </section>
        )}

        {/* FLOATING MULTI-PAYMENT BASKET BAR */}
        {selected.size > 0 && (
          <div className="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-card/95 backdrop-blur-md shadow-2xl">
            <div className="mx-auto flex max-w-7xl 2xl:max-w-full flex-wrap items-center justify-between gap-4 px-4 sm:px-6 lg:px-8 py-3.5">
              <div>
                <p className="tabular font-black text-foreground text-base">
                  {selected.size} Tagihan Dipilih · <span className="text-primary text-lg">{rupiah(selectedTotal)}</span>
                </p>
                <p className="text-xs text-muted-foreground">
                  {childrenInCart > 1
                    ? `Mencakup ${childrenInCart} anak · Diproses dalam 1x transaksi SendagoPay (hemat biaya admin)`
                    : "Diproses dalam 1x transaksi SendagoPay"}
                </p>
              </div>

              <div className="flex items-center gap-2.5">
                <Button variant="ghost" size="sm" onClick={() => setSelected(new Set())} disabled={paying}>
                  Batal
                </Button>
                <Button onClick={checkoutMulti} disabled={paying} size="lg" className="gap-2 font-bold shadow-md">
                  <Wallet className="size-4" />
                  <span>{paying ? "Membuat Tagihan..." : "Bayar Sekarang Sekaligus"}</span>
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* MODAL: CUSTOM / PARTIAL PAYMENT */}
        {customBill && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
            <div className="w-full max-w-md rounded-2xl bg-card p-6 shadow-2xl border border-border">
              <h2 className="text-lg font-bold text-foreground">Pembayaran Cicilan / Kustom</h2>
              <p className="text-xs text-muted-foreground mt-1">
                Masukkan jumlah uang yang ingin dibayarkan untuk tagihan ini.
              </p>

              <div className="mt-4 p-3 bg-muted/40 rounded-xl text-xs space-y-1">
                <p><strong>Tagihan:</strong> {customBill.description}</p>
                <p><strong>Siswa:</strong> {customBill.student?.nama_lengkap}</p>
                <p><strong>Total Tagihan:</strong> {rupiah(customBill.total_amount)}</p>
                <p><strong>Sisa Tagihan:</strong> <span className="font-bold text-primary">{rupiah(customBill.remaining_amount)}</span></p>
              </div>

              <form onSubmit={checkoutCustom} className="mt-4 space-y-4">
                <div>
                  <Label htmlFor="custom_input" className="text-xs font-semibold">Nominal Pembayaran (Rp)</Label>
                  <Input
                    id="custom_input"
                    type="number"
                    min="10000"
                    max={customBill.remaining_amount}
                    value={customAmount}
                    onChange={(e) => setCustomAmount(e.target.value)}
                    required
                    className="mt-1 font-bold text-base"
                    placeholder="misal: 250000"
                  />
                  <p className="text-[11px] text-muted-foreground mt-1">
                    Maksimal pembayaran: {rupiah(customBill.remaining_amount)}
                  </p>
                </div>

                <div className="flex justify-end gap-2.5 pt-2">
                  <Button type="button" variant="outline" onClick={() => setCustomBill(null)}>
                    Batal
                  </Button>
                  <Button type="submit" disabled={submittingCustom} className="gap-2 font-bold">
                    <Wallet className="size-4" />
                    <span>{submittingCustom ? "Memproses..." : "Lanjutkan Pembayaran"}</span>
                  </Button>
                </div>
              </form>
            </div>
          </div>
        )}
      </div>
    </WaliShell>
  );
}

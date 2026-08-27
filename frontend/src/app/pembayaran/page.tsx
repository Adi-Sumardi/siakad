"use client";

import { Suspense, useEffect, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import {
  AlertCircle,
  ArrowLeft,
  Building2,
  Check,
  CheckCircle2,
  Clock,
  Copy,
  Download,
  ExternalLink,
  Info,
  QrCode,
  Receipt,
  RefreshCw,
  ShieldCheck,
  Sparkles,
  Wallet,
  X,
  XCircle,
} from "lucide-react";
import { toast } from "sonner";
import { WaliShell } from "@/components/layout/wali-shell";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useRequireRole } from "@/lib/auth/use-require-role";
import { rupiah, tanggal } from "@/lib/format";
import type { Payment } from "@/lib/types/billing";

const STATUS_LABEL: Record<string, { label: string; variant: "good" | "warn" | "bad" | "default" }> = {
  completed: { label: "Lunas / Berhasil", variant: "good" },
  pending: { label: "Menunggu Pembayaran", variant: "warn" },
  processing: { label: "Menunggu Pembayaran", variant: "warn" },
  expired: { label: "Kedaluwarsa", variant: "default" },
  failed: { label: "Gagal", variant: "bad" },
  cancelled: { label: "Dibatalkan", variant: "default" },
  refunded: { label: "Dikembalikan", variant: "default" },
};

function PaymentsContent() {
  const { user, loading } = useRequireRole("orangtua");
  const searchParams = useSearchParams();
  const highlightPaymentUlid = searchParams.get("payment");

  const [payments, setPayments] = useState<Payment[] | null>(null);
  const [selectedPayment, setSelectedPayment] = useState<Payment | null>(null);
  const [simulating, setSimulating] = useState(false);
  const [copiedText, setCopiedText] = useState<string | null>(null);

  function load() {
    api
      .get<{ payments: Payment[] }>("/api/wali/payments")
      .then((d) => {
        setPayments(d.payments);
        if (highlightPaymentUlid) {
          const match = d.payments.find((p) => p.ulid === highlightPaymentUlid);
          if (match) setSelectedPayment(match);
        }
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat riwayat pembayaran."));
  }

  useEffect(() => {
    if (user?.role === "orangtua") {
      load();
    }
  }, [user, highlightPaymentUlid]);

  function copyToClipboard(text: string, label: string) {
    navigator.clipboard.writeText(text);
    setCopiedText(label);
    toast.success(`${label} berhasil disalin ke clipboard!`);
    setTimeout(() => setCopiedText(null), 2000);
  }

  async function handleSimulateSettle(payment: Payment) {
    setSimulating(true);
    try {
      const res = await api.post<{ message: string; payment: Payment }>(
        `/api/wali/payments/${payment.ulid}/simulate-settle`
      );
      toast.success(res.message);
      setSelectedPayment(res.payment);
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memproses simulasi.");
    } finally {
      setSimulating(false);
    }
  }

  if (loading || !user || user.role !== "orangtua") {
    return (
      <WaliShell>
        <div className="space-y-4">
          <Skeleton className="h-8 w-48" />
          <Skeleton className="h-32 w-full rounded-2xl" />
        </div>
      </WaliShell>
    );
  }

  return (
    <WaliShell>
      <div className="space-y-6 pb-24">
        {/* Page Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-2xl sm:text-3xl font-black tracking-tight text-foreground">
              Riwayat Pembayaran & Tagihan
            </h1>
            <p className="text-xs sm:text-sm text-muted-foreground mt-0.5">
              Histori seluruh transaksi pembayaran SPP & Tagihan Sekolah melalui Virtual Account Bank Muamalat (BMI) maupun loket administrasi YAPI.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={load} className="gap-2 text-xs font-semibold">
              <RefreshCw className="size-3.5" />
              <span>Segarkan</span>
            </Button>
            <Link href="/tagihan">
              <Button size="sm" className="gap-2 text-xs font-bold">
                <Receipt className="size-3.5" />
                <span>Lihat Tagihan</span>
              </Button>
            </Link>
          </div>
        </div>

        {/* Loading State */}
        {payments === null && (
          <div className="space-y-3">
            <Skeleton className="h-28 w-full rounded-2xl" />
            <Skeleton className="h-28 w-full rounded-2xl" />
          </div>
        )}

        {/* Empty State */}
        {payments?.length === 0 && (
          <Card className="p-12 text-center space-y-3 border-dashed">
            <Receipt className="size-10 text-muted-foreground mx-auto" />
            <p className="font-bold text-foreground">Belum ada transaksi pembayaran</p>
            <p className="text-xs text-muted-foreground max-w-sm mx-auto">
              Silakan buka menu Tagihan SPP untuk melakukan pembayaran tagihan ananda.
            </p>
            <Link href="/tagihan" className="inline-block mt-2">
              <Button size="sm" className="gap-2 font-bold">
                <Wallet className="size-4" />
                <span>Buka Tagihan SPP</span>
              </Button>
            </Link>
          </Card>
        )}

        {/* Payments List */}
        <div className="grid grid-cols-1 gap-4">
          {payments?.map((payment) => {
            const status = STATUS_LABEL[payment.status] ?? { label: payment.status, variant: "default" as const };
            const isCompleted = payment.status === "completed";
            const gatewayResp = (payment as unknown as { gateway_response?: Record<string, unknown> })?.gateway_response;
            const uniqueCode = typeof gatewayResp?.unique_code === "number" ? gatewayResp.unique_code : 0;
            const totalWithUnique = typeof gatewayResp?.total_with_code === "number" ? gatewayResp.total_with_code : payment.amount;

            return (
              <Card
                key={payment.ulid}
                className="p-5 sm:p-6 border-border hover:border-primary/50 transition-all bg-card shadow-xs rounded-2xl"
              >
                <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                  <div className="space-y-1.5 min-w-0 flex-1">
                    <div className="flex items-center gap-2.5 flex-wrap">
                      <p className="tabular font-black text-xl text-foreground">{rupiah(payment.amount)}</p>
                      <Badge variant={status.variant}>{status.label}</Badge>
                      {uniqueCode > 0 && !isCompleted && (
                        <span className="text-[11px] font-mono bg-amber-500/10 text-amber-700 dark:text-amber-300 font-bold px-2 py-0.5 rounded-md">
                          Kode Unik: +{uniqueCode}
                        </span>
                      )}
                    </div>

                    <p className="text-xs text-muted-foreground">
                      No. Pembayaran: <span className="font-mono font-bold text-foreground">{payment.payment_number}</span> · Dibuat: {tanggal(payment.created_at)}
                    </p>

                    <p className="text-xs text-muted-foreground">
                      Metode: <strong className="uppercase text-foreground">{payment.method ?? "Transfer Bank / QRIS"}</strong>
                      {payment.paid_at && ` · Diselesaikan: ${tanggal(payment.paid_at)}`}
                    </p>
                  </div>

                  <div className="flex items-center gap-2 shrink-0">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => setSelectedPayment(payment)}
                      className="gap-1.5 text-xs font-semibold"
                    >
                      <Info className="size-3.5" />
                      <span>Rincian & Cara Bayar</span>
                    </Button>

                    {!isCompleted && (
                      <Button
                        size="sm"
                        onClick={() => setSelectedPayment(payment)}
                        className="gap-1.5 text-xs font-bold shadow-xs bg-primary hover:bg-primary/90"
                      >
                        <Wallet className="size-3.5" />
                        <span>Bayar Sekarang</span>
                      </Button>
                    )}
                  </div>
                </div>

                {/* Covered Bills Breakdown */}
                {payment.bills && payment.bills.length > 0 && (
                  <div className="mt-4 border-t border-border/60 pt-3">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground mb-2">
                      Tagihan yang Dicakup ({payment.bills.length}):
                    </p>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                      {payment.bills.map((bill) => (
                        <div
                          key={bill.ulid}
                          className="p-3 rounded-xl bg-muted/40 text-xs flex justify-between items-center border border-border/50"
                        >
                          <div>
                            <span className="font-bold text-foreground block">{bill.description}</span>
                            <span className="text-muted-foreground text-[11px]">
                              Siswa: <strong className="text-foreground">{bill.student?.nama_lengkap}</strong>
                            </span>
                          </div>
                          <Link href={`/tagihan/${bill.ulid}`} className="text-primary hover:underline font-semibold text-[11px] shrink-0 ml-2">
                            Lihat Rincian
                          </Link>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </Card>
            );
          })}
        </div>

        {/* DETAILED INVOICE & PAYMENT MODAL */}
        {selectedPayment && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
            <div className="w-full max-w-lg rounded-2xl bg-card p-6 shadow-2xl border border-border space-y-5 my-8">
              {/* Modal Header */}
              <div className="flex items-center justify-between border-b border-border/80 pb-3.5">
                <div className="flex items-center gap-2.5">
                  <div className="size-9 rounded-xl bg-primary/10 flex items-center justify-center text-primary font-bold">
                    <Building2 className="size-5" />
                  </div>
                  <div>
                    <h2 className="text-base font-black text-foreground">Invoice & Rincian Pembayaran</h2>
                    <p className="text-[11px] text-muted-foreground">Yayasan Asrama Pelajar Islam (YAPI) Jakarta</p>
                  </div>
                </div>

                <button
                  onClick={() => setSelectedPayment(null)}
                  className="rounded-lg p-1.5 text-muted-foreground hover:bg-accent"
                >
                  <X className="size-5" />
                </button>
              </div>

              {/* Status & Amount Banner */}
              <div className="bg-primary/5 border border-primary/20 rounded-2xl p-4 space-y-2">
                <div className="flex justify-between items-center text-xs">
                  <span className="text-muted-foreground">Nomor Referensi:</span>
                  <span className="font-mono font-bold text-foreground">{selectedPayment.payment_number}</span>
                </div>
                <div className="flex justify-between items-center">
                  <span className="text-xs text-muted-foreground">Status Pembayaran:</span>
                  <Badge variant={STATUS_LABEL[selectedPayment.status]?.variant ?? "default"}>
                    {STATUS_LABEL[selectedPayment.status]?.label ?? selectedPayment.status}
                  </Badge>
                </div>
                <div className="flex justify-between items-center border-t border-primary/20 pt-2">
                  <span className="text-xs font-bold text-foreground">Total Tagihan:</span>
                  <div className="text-right">
                    <span className="tabular text-xl font-black text-primary">{rupiah(selectedPayment.amount)}</span>
                    <button
                      onClick={() => copyToClipboard(String(selectedPayment.amount), "Nominal")}
                      className="ml-2 inline-flex items-center text-xs text-muted-foreground hover:text-foreground"
                      title="Salin Nominal"
                    >
                      <Copy className="size-3" />
                    </button>
                  </div>
                </div>
              </div>

              {/* VIRTUAL ACCOUNT BANK MUAMALAT (BMI) SECTION */}
              {selectedPayment.status !== "completed" && (selectedPayment.virtual_account?.va_number || selectedPayment.gateway_response?.va_number) ? (
                <div className="space-y-4">
                  {/* VA Card */}
                  <div className="p-4 rounded-2xl bg-emerald-500/10 border-2 border-emerald-500/30 space-y-3">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <span className="size-2 rounded-full bg-emerald-500 animate-pulse" />
                        <span className="font-bold text-xs text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">
                          Virtual Account Bank Muamalat (BMI)
                        </span>
                      </div>
                      <Badge variant="default" className="text-[10px] bg-emerald-600 hover:bg-emerald-700 text-white">
                        Kode Bank: 147
                      </Badge>
                    </div>

                    <div className="bg-card p-3.5 rounded-xl border border-emerald-500/20 flex items-center justify-between gap-2">
                      <div>
                        <p className="text-[11px] text-muted-foreground font-medium">Nomor Virtual Account:</p>
                        <p className="font-mono text-lg sm:text-xl font-black text-foreground tracking-wider mt-0.5">
                          {selectedPayment.virtual_account?.va_number || selectedPayment.gateway_response?.va_number}
                        </p>
                      </div>
                      <Button
                        size="sm"
                        onClick={() =>
                          copyToClipboard(
                            selectedPayment.virtual_account?.va_number || selectedPayment.gateway_response?.va_number || "",
                            "Nomor Virtual Account"
                          )
                        }
                        className="h-9 gap-1.5 font-bold shrink-0 bg-emerald-600 hover:bg-emerald-700 text-white"
                      >
                        <Copy className="size-3.5" />
                        <span>Salin VA</span>
                      </Button>
                    </div>

                    {(selectedPayment.virtual_account?.due_date || selectedPayment.gateway_response?.due_date) && (
                      <p className="text-[11px] text-emerald-900 dark:text-emerald-200">
                        ⏳ Batas Waktu Pembayaran:{" "}
                        <strong>{tanggal(selectedPayment.virtual_account?.due_date || selectedPayment.gateway_response?.due_date || "")}</strong>
                      </p>
                    )}
                  </div>

                  {/* Payment Instructions Accordion/Guide */}
                  <div className="p-3.5 rounded-xl bg-muted/50 border border-border text-xs space-y-2">
                    <p className="font-bold text-foreground flex items-center gap-1.5">
                      <Info className="size-4 text-primary shrink-0" />
                      <span>Petunjuk Cara Pembayaran:</span>
                    </p>
                    <div className="space-y-1.5 text-[11px] text-muted-foreground leading-relaxed pl-5 list-decimal">
                      <div>
                        <strong>1. Aplikasi Muamalat DIN:</strong> Pilih menu <em>Bayar/Beli</em> &rarr; <em>Virtual Account</em> &rarr; Masukkan Nomor VA di atas &rarr; Periksa nama tagihan &rarr; Konfirmasi PIN.
                      </div>
                      <div>
                        <strong>2. ATM Bank Muamalat:</strong> Pilih <em>Transaksi Lainnya</em> &rarr; <em>Pembayaran</em> &rarr; <em>Virtual Account</em> &rarr; Masukkan Nomor VA &rarr; Konfirmasi.
                      </div>
                      <div>
                        <strong>3. Transfer Antar Bank (BCA/Mandiri/BRI/BSI/dll):</strong> Pilih <em>Transfer Antar Bank</em> &rarr; Pilih <strong>Bank Muamalat (Kode: 147)</strong> &rarr; Masukkan Nomor VA sebagai rekening tujuan &rarr; Masukkan nominal tepat ({rupiah(selectedPayment.amount)}) &rarr; Konfirmasi.
                      </div>
                    </div>
                  </div>
                </div>
              ) : selectedPayment.status !== "completed" ? (
                /* No VA registered for this payment (e.g. a transaction from
                   before the switch to Bank Muamalat VA). This used to show
                   three hardcoded "official" bank accounts as a manual-
                   transfer fallback - unverified placeholder numbers that
                   were never confirmed as YAPI's real accounts, carried
                   unchanged across three gateway migrations. Bank Muamalat
                   VA is the only payment method in use for now, so rather
                   than risk a family transferring to an account nobody
                   confirmed is real, this points them to the school instead. */
                <div className="p-4 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-900 dark:text-amber-200 text-xs flex gap-2.5">
                  <AlertCircle className="size-4 shrink-0 mt-0.5 text-amber-600" />
                  <div className="space-y-1">
                    <p className="font-semibold">Nomor Virtual Account belum tersedia untuk pembayaran ini.</p>
                    <p className="text-[11px] text-amber-800 dark:text-amber-300">
                      Silakan hubungi admin/tata usaha sekolah dengan menyebutkan Nomor Pembayaran{" "}
                      <strong>{selectedPayment.payment_number}</strong> untuk bantuan penyelesaian.
                    </p>
                  </div>
                </div>
              ) : null}

              {/* Simulation Mode Action for Test Flow - dev/staging only.
                  The matching endpoint 404s outside local/testing on the
                  backend now too; this keeps a real family from ever seeing
                  a "confirm payment" button that can't do anything, and keeps
                  it from being the free lunas-without-paying button it was
                  before that backend guard existed. */}
              {process.env.NODE_ENV !== "production" && selectedPayment.status !== "completed" && (
                <div className="pt-2 border-t border-border space-y-2">
                  <Button
                    onClick={() => handleSimulateSettle(selectedPayment)}
                    disabled={simulating}
                    className="w-full gap-2 font-bold shadow-md bg-emerald-600 hover:bg-emerald-700 text-white"
                  >
                    <CheckCircle2 className="size-4" />
                    <span>{simulating ? "Memverifikasi..." : "Konfirmasi Pembayaran Selesai (Simulasi Uji Coba)"}</span>
                  </Button>
                  <p className="text-[11px] text-center text-muted-foreground">
                    ⚡ Mode pengujian: Klik tombol di atas untuk menyelesaikan verifikasi pembayaran otomatis.
                  </p>
                </div>
              )}

              {/* Close Button */}
              <div className="flex justify-end pt-1">
                <Button variant="outline" size="sm" onClick={() => setSelectedPayment(null)}>
                  Tutup
                </Button>
              </div>
            </div>
          </div>
        )}
      </div>
    </WaliShell>
  );
}

export default function PaymentsPage() {
  return (
    <Suspense
      fallback={
        <WaliShell>
          <div className="space-y-4">
            <Skeleton className="h-8 w-48" />
            <Skeleton className="h-32 w-full rounded-2xl" />
          </div>
        </WaliShell>
      }
    >
      <PaymentsContent />
    </Suspense>
  );
}

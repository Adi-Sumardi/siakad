"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, CheckCircle2, Clock, CreditCard, ExternalLink, RefreshCw, XCircle } from "lucide-react";
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
  completed: { label: "Berhasil / Lunas", variant: "good" },
  pending: { label: "Menunggu Pembayaran", variant: "warn" },
  processing: { label: "Sedang Diproses", variant: "warn" },
  expired: { label: "Kedaluwarsa", variant: "default" },
  failed: { label: "Gagal", variant: "bad" },
  cancelled: { label: "Dibatalkan", variant: "default" },
  refunded: { label: "Dikembalikan", variant: "default" },
};

export default function PaymentsPage() {
  const { user, loading } = useRequireRole("orangtua");
  const [payments, setPayments] = useState<Payment[] | null>(null);

  function load() {
    api
      .get<{ payments: Payment[] }>("/api/wali/payments")
      .then((d) => setPayments(d.payments))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat riwayat pembayaran."));
  }

  useEffect(() => {
    if (user?.role === "orangtua") {
      load();
    }
  }, [user]);

  if (loading || !user || user.role !== "orangtua") {
    return (
      <WaliShell>
        <div className="space-y-4">
          <Skeleton className="h-8 w-48" />
          <Skeleton className="h-32 w-full" />
        </div>
      </WaliShell>
    );
  }

  return (
    <WaliShell>
      <div className="space-y-6">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-foreground">Riwayat Pembayaran & Transaksi</h1>
            <p className="text-sm text-muted-foreground mt-0.5">
              Histori seluruh pembayaran SPP dan administrasi sekolah via SendagoPay atau loket front desk.
            </p>
          </div>

          <Button variant="outline" size="sm" onClick={load} className="gap-2 self-start sm:self-auto">
            <RefreshCw className="size-4" />
            <span>Segarkan</span>
          </Button>
        </div>

        {payments === null && (
          <div className="space-y-3">
            <Skeleton className="h-24 w-full rounded-2xl" />
            <Skeleton className="h-24 w-full rounded-2xl" />
          </div>
        )}

        {payments?.length === 0 && (
          <Card className="p-8 text-center text-sm text-muted-foreground">
            Belum ada catatan transaksi pembayaran.
          </Card>
        )}

        <div className="grid grid-cols-1 gap-3.5">
          {payments?.map((payment) => {
            const status = STATUS_LABEL[payment.status] ?? { label: payment.status, variant: "default" as const };

            return (
              <Card key={payment.ulid} className="p-5 border-border/80 hover:border-primary/40 transition-colors">
                <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                  <div>
                    <div className="flex items-center gap-2 flex-wrap">
                      <p className="tabular font-black text-xl text-foreground">{rupiah(payment.amount)}</p>
                      <Badge variant={status.variant}>{status.label}</Badge>
                    </div>
                    <p className="text-xs text-muted-foreground mt-1">
                      No Transaksi: <span className="font-mono font-semibold text-foreground">{payment.payment_number}</span> · {tanggal(payment.paid_at ?? payment.created_at)} · Metode: <span className="uppercase font-semibold">{payment.method}</span>
                    </p>
                  </div>

                  {payment.invoice_url && payment.status !== "completed" && (
                    <Button
                      size="sm"
                      onClick={() => window.open(payment.invoice_url!, "_blank")}
                      className="gap-2 font-bold shadow-xs"
                    >
                      <span>Lanjutkan Bayar</span>
                      <ExternalLink className="size-3.5" />
                    </Button>
                  )}
                </div>

                {payment.bills && payment.bills.length > 0 && (
                  <div className="mt-4 border-t border-border/60 pt-3">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground mb-1.5">
                      Tagihan yang Dibayar ({payment.bills.length}):
                    </p>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                      {payment.bills.map((bill) => (
                        <div key={bill.ulid} className="p-2.5 rounded-lg bg-muted/40 text-xs flex justify-between items-center">
                          <span className="font-medium text-foreground">{bill.description}</span>
                          <span className="text-muted-foreground">
                            {bill.student && (bill.student.nama_panggilan ?? bill.student.nama_lengkap)}
                          </span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </Card>
            );
          })}
        </div>
      </div>
    </WaliShell>
  );
}

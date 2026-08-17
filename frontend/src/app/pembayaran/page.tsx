"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api } from "@/lib/api";
import { rupiah, tanggal } from "@/lib/format";
import type { Payment } from "@/lib/types/billing";

const STATUS_LABEL: Record<string, { label: string; variant: "good" | "warn" | "bad" | "default" }> = {
  completed: { label: "Berhasil", variant: "good" },
  pending: { label: "Menunggu pembayaran", variant: "warn" },
  processing: { label: "Menunggu pembayaran", variant: "warn" },
  expired: { label: "Kedaluwarsa", variant: "default" },
  failed: { label: "Gagal", variant: "bad" },
  cancelled: { label: "Dibatalkan", variant: "default" },
  refunded: { label: "Dikembalikan", variant: "default" },
};

export default function PaymentsPage() {
  const [payments, setPayments] = useState<Payment[] | null>(null);

  useEffect(() => {
    api.get<{ payments: Payment[] }>("/api/wali/payments").then((d) => setPayments(d.payments));
  }, []);

  return (
    <div className="min-h-dvh bg-canvas">
      <header className="border-b border-border bg-card">
        <div className="mx-auto max-w-2xl px-6 py-3.5">
          <Link href="/tagihan" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
            <ArrowLeft className="size-4" />
            Tagihan
          </Link>
        </div>
      </header>

      <main className="mx-auto max-w-2xl px-6 py-8">
        <h1 className="text-xl font-bold tracking-tight">Riwayat pembayaran</h1>

        {payments === null && <Skeleton className="mt-6 h-32 w-full" />}

        {payments?.length === 0 && (
          <Card className="mt-6 p-6 text-sm text-muted-foreground">Belum ada pembayaran.</Card>
        )}

        <div className="mt-6 flex flex-col gap-3">
          {payments?.map((payment) => {
            const status = STATUS_LABEL[payment.status] ?? { label: payment.status, variant: "default" as const };

            return (
              <Card key={payment.ulid} className="flex flex-col gap-3 p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p className="tabular font-semibold">{rupiah(payment.amount)}</p>
                    <p className="text-sm text-muted-foreground">
                      {payment.payment_number} · {tanggal(payment.paid_at ?? payment.created_at)}
                    </p>
                  </div>
                  <Badge variant={status.variant}>{status.label}</Badge>
                </div>

                {payment.bills && payment.bills.length > 0 && (
                  <ul className="flex flex-col gap-1 border-t border-border pt-3 text-sm">
                    {payment.bills.map((bill) => (
                      <li key={bill.ulid} className="flex justify-between gap-3">
                        <span className="text-muted-foreground">
                          {bill.description}
                          {bill.student && ` · ${bill.student.nama_panggilan ?? bill.student.nama_lengkap}`}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}

                {/* A pending invoice is only useful if the parent can get back
                    to it - closing the tab must not strand the payment. */}
                {payment.invoice_url && payment.status !== "completed" && (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => window.open(payment.invoice_url!, "_blank")}
                    className="self-start"
                  >
                    Lanjutkan pembayaran
                  </Button>
                )}
              </Card>
            );
          })}
        </div>
      </main>
    </div>
  );
}

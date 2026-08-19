"use client";

import { use, useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, FileDown } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { API_BASE, api, ApiError } from "@/lib/api";
import { useRequireRole } from "@/lib/auth/use-require-role";
import { rupiah, tanggal } from "@/lib/format";
import type { Bill, Payment } from "@/lib/types/billing";

export default function BillDetailPage({ params }: { params: Promise<{ ulid: string }> }) {
  const { ulid } = use(params);
  const { user, loading } = useRequireRole("orangtua");

  const [bill, setBill] = useState<Bill | null>(null);
  const [payments, setPayments] = useState<Payment[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (user?.role !== "orangtua") return;

    api
      .get<{ bill: Bill; payments: Payment[] }>(`/api/wali/bills/${ulid}`)
      .then((data) => {
        setBill(data.bill);
        setPayments(data.payments);
      })
      .catch((err) =>
        setError(err instanceof ApiError ? err.message : "Tidak dapat memuat tagihan."),
      );
  }, [ulid, user]);

  if (loading || !user || user.role !== "orangtua") {
    return (
      <main className="mx-auto flex max-w-2xl flex-col gap-3 p-6">
        <Skeleton className="h-8 w-40" />
        <Skeleton className="h-48 w-full" />
      </main>
    );
  }

  if (error) {
    return (
      <main className="mx-auto max-w-2xl p-6">
        <Card className="p-6">
          <p className="text-sm text-muted-foreground">{error}</p>
          <Link href="/tagihan" className="mt-4 inline-block text-sm text-primary">
            Kembali ke daftar tagihan
          </Link>
        </Card>
      </main>
    );
  }

  if (!bill) {
    return (
      <main className="mx-auto flex max-w-2xl flex-col gap-3 p-6">
        <Skeleton className="h-8 w-40" />
        <Skeleton className="h-48 w-full" />
      </main>
    );
  }

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
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-xl font-bold tracking-tight">{bill.description}</h1>
            <p className="mt-1 text-sm text-muted-foreground">
              {bill.student?.nama_lengkap} · {bill.bill_number}
            </p>
          </div>
          <div className="flex items-center gap-2">
            {bill.status === "paid" ? (
              <Badge variant="good">Lunas</Badge>
            ) : bill.status === "overdue" ? (
              <Badge variant="bad">Lewat jatuh tempo</Badge>
            ) : (
              <Badge>Belum lunas</Badge>
            )}
          </div>
        </div>

        {/* Plain <a>, not fetch: the browser handles the PDF response itself -
            preview inline or save, whichever it is set up to do - and the
            session cookie already rides along on a same-origin navigation. */}
        <a
          href={`${API_BASE}/api/wali/bills/${bill.ulid}/pdf`}
          target="_blank"
          rel="noopener noreferrer"
          className="mt-3 inline-block"
        >
          <Button variant="outline" size="sm">
            <FileDown className="size-4" />
            {bill.status === "paid" ? "Unduh kuitansi" : "Unduh tagihan"}
          </Button>
        </a>

        <Card className="mt-6 overflow-hidden p-0">
          <div className="border-b border-border px-5 py-3">
            <h2 className="text-sm font-semibold">Rincian</h2>
          </div>
          <div className="flex flex-col">
            {/* The lines always sum to the total, discounts included as negative
                rows - so this table never needs a footnote to reconcile. */}
            {bill.lines?.map((line, i) => (
              <div key={i} className="flex items-center justify-between gap-4 border-b border-border px-5 py-3 last:border-b-0">
                <div>
                  <p className="text-sm font-medium">{line.name}</p>
                  {line.qty > 1 && (
                    <p className="tabular text-xs text-muted-foreground">
                      {line.qty} × {rupiah(line.unit_price)}
                      {line.size_option && ` · ukuran ${line.size_option}`}
                    </p>
                  )}
                </div>
                <span className={`tabular text-sm font-medium ${line.amount < 0 ? "text-good" : ""}`}>
                  {rupiah(line.amount)}
                </span>
              </div>
            ))}
          </div>
          <div className="flex flex-col gap-1.5 bg-canvas px-5 py-4 text-sm">
            <div className="flex justify-between">
              <span className="text-muted-foreground">Total tagihan</span>
              <span className="tabular font-semibold">{rupiah(bill.total_amount)}</span>
            </div>
            {bill.paid_amount > 0 && (
              <div className="flex justify-between">
                <span className="text-muted-foreground">Sudah dibayar</span>
                <span className="tabular text-good">− {rupiah(bill.paid_amount)}</span>
              </div>
            )}
            <div className="flex justify-between border-t border-border pt-1.5">
              <span className="font-semibold">Sisa</span>
              <span className="tabular font-bold">{rupiah(bill.remaining_amount)}</span>
            </div>
            <p className="mt-1 text-xs text-muted-foreground">Jatuh tempo {tanggal(bill.due_date)}</p>
          </div>
        </Card>

        {payments.length > 0 && (
          <Card className="mt-4 p-5">
            <h2 className="text-sm font-semibold">Pembayaran untuk tagihan ini</h2>
            <div className="mt-3 flex flex-col gap-2">
              {payments.map((payment) => (
                <div key={payment.ulid} className="flex items-center justify-between gap-3 text-sm">
                  <div>
                    <p className="font-medium">{payment.payment_number}</p>
                    <p className="text-xs text-muted-foreground">
                      {payment.method ?? "—"} · {tanggal(payment.paid_at ?? payment.created_at)}
                    </p>
                  </div>
                  <div className="text-right">
                    <p className="tabular font-medium">{rupiah(payment.amount)}</p>
                    <p className="text-xs text-muted-foreground">{payment.status}</p>
                  </div>
                </div>
              ))}
            </div>
          </Card>
        )}
      </main>
    </div>
  );
}

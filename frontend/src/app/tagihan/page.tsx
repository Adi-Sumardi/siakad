"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { ArrowLeft, Check, ExternalLink } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
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
  if (bill.status === "partial") return <Badge variant="warn">Kurang bayar</Badge>;

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

  // How many children the basket spans — the sentence under the total says so,
  // because paying for two children at once is the thing parents do not expect
  // to be possible.
  const childrenInCart = new Set(selectedBills.map((b) => b.student?.ulid)).size;

  function toggle(ulid: string) {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(ulid)) next.delete(ulid);
      else next.add(ulid);
      return next;
    });
  }

  async function checkout() {
    setPaying(true);

    try {
      const { payment } = await api.post<{ payment: Payment }>("/api/wali/checkout", {
        bill_ulids: [...selected],
        method: "virtual_account",
      });

      if (payment.invoice_url) {
        // Straight to the gateway; coming back is handled by its redirect.
        window.location.href = payment.invoice_url;
        return;
      }

      toast.success("Pembayaran dibuat. Instruksi menyusul di halaman Pembayaran.");
      setSelected(new Set());
      await load();
      router.push("/pembayaran");
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Tidak dapat memproses pembayaran.");
      // The list may be stale — a bill could have been settled at the front
      // desk between load and click.
      await load();
    } finally {
      setPaying(false);
    }
  }

  if (loading || !user || bills === null) {
    return (
      <main className="mx-auto flex max-w-3xl flex-col gap-4 p-6">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-40 w-full" />
      </main>
    );
  }

  return (
    <div className="min-h-dvh bg-canvas pb-28">
      <header className="border-b border-border bg-card">
        <div className="mx-auto flex max-w-3xl items-center justify-between gap-4 px-6 py-3.5">
          <Link href="/dashboard" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
            <ArrowLeft className="size-4" />
            Beranda
          </Link>
          <Link href="/pembayaran" className="text-sm text-primary">
            Riwayat pembayaran
          </Link>
        </div>
      </header>

      <main className="mx-auto max-w-3xl px-6 py-8">
        <h1 className="text-xl font-bold tracking-tight">Tagihan</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {summary?.open_count
            ? `${summary.open_count} tagihan belum lunas · total ${rupiah(summary.outstanding)}`
            : "Semua tagihan sudah lunas."}
        </p>

        {openBills.length > 0 && (
          <section className="mt-6 flex flex-col gap-2">
            {openBills.map((bill) => {
              const checked = selected.has(bill.ulid);

              return (
                <Card
                  key={bill.ulid}
                  className={cn(
                    "cursor-pointer p-4 transition-colors",
                    checked && "border-primary bg-accent/40",
                  )}
                  onClick={() => toggle(bill.ulid)}
                >
                  <div className="flex items-start gap-3">
                    <span
                      role="checkbox"
                      aria-checked={checked}
                      aria-label={`Pilih ${bill.description}`}
                      className={cn(
                        "mt-0.5 grid size-5 shrink-0 place-items-center rounded border",
                        checked ? "border-primary bg-primary text-primary-foreground" : "border-input bg-card",
                      )}
                    >
                      {checked && <Check className="size-3.5" strokeWidth={3} />}
                    </span>

                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                        <div>
                          <p className="font-semibold">{bill.description}</p>
                          <p className="text-sm text-muted-foreground">
                            {bill.student?.nama_panggilan ?? bill.student?.nama_lengkap}
                            <span className="text-muted-foreground/70"> · {bill.bill_number}</span>
                          </p>
                        </div>
                        <div className="text-right">
                          <p className="tabular font-semibold">{rupiah(bill.remaining_amount)}</p>
                          {bill.paid_amount > 0 && (
                            <p className="tabular text-xs text-muted-foreground">
                              sudah dibayar {rupiah(bill.paid_amount)}
                            </p>
                          )}
                        </div>
                      </div>

                      <div className="mt-2 flex flex-wrap items-center gap-2">
                        {statusBadge(bill)}
                        {bill.discount_amount > 0 && (
                          <Badge variant="primary">Potongan {rupiah(bill.discount_amount)}</Badge>
                        )}
                        <Link
                          href={`/tagihan/${bill.ulid}`}
                          onClick={(e) => e.stopPropagation()}
                          className="inline-flex items-center gap-1 text-xs text-primary"
                        >
                          Rincian
                          <ExternalLink className="size-3" />
                        </Link>
                      </div>
                    </div>
                  </div>
                </Card>
              );
            })}
          </section>
        )}

        {paidBills.length > 0 && (
          <section className="mt-8">
            <h2 className="text-sm font-semibold text-muted-foreground">Sudah selesai</h2>
            <div className="mt-2 flex flex-col gap-2">
              {paidBills.map((bill) => (
                <Card key={bill.ulid} className="flex flex-wrap items-center justify-between gap-2 p-4">
                  <div>
                    <p className="font-medium">{bill.description}</p>
                    <p className="text-sm text-muted-foreground">
                      {bill.student?.nama_panggilan ?? bill.student?.nama_lengkap}
                    </p>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className="tabular text-sm text-muted-foreground">{rupiah(bill.total_amount)}</span>
                    {statusBadge(bill)}
                  </div>
                </Card>
              ))}
            </div>
          </section>
        )}
      </main>

      {/* The basket. This bar is the UI form of payment_allocations: without it
          a parent pays three times and carries three bank admin fees. */}
      {selected.size > 0 && (
        <div className="fixed inset-x-0 bottom-0 border-t border-border bg-card/95 backdrop-blur">
          <div className="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-3 px-6 py-3.5">
            <div>
              <p className="tabular font-semibold">
                {selected.size} tagihan dipilih · {rupiah(selectedTotal)}
              </p>
              <p className="text-xs text-muted-foreground">
                {childrenInCart > 1
                  ? `${childrenInCart} anak, dibayar dalam satu transaksi`
                  : "Dibayar dalam satu transaksi"}
              </p>
            </div>
            <div className="flex items-center gap-2">
              <Button variant="ghost" onClick={() => setSelected(new Set())} disabled={paying}>
                Batal
              </Button>
              <Button onClick={checkout} disabled={paying}>
                {paying ? "Memproses…" : "Bayar sekarang"}
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

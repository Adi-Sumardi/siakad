"use client";

import { useCallback, useEffect, useState } from "react";
import { Search } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { dueLabel, rupiah, tanggal } from "@/lib/format";
import { isOpen, type Bill } from "@/lib/types/billing";

type Paginated<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number } };

function statusBadge(bill: Bill) {
  if (bill.status === "paid") return <Badge variant="good">Lunas</Badge>;
  if (bill.status === "waived") return <Badge>Dibebaskan</Badge>;
  if (bill.status === "cancelled") return <Badge>Dibatalkan</Badge>;
  if (bill.status === "overdue") return <Badge variant="bad">{dueLabel(bill.days_to_due)}</Badge>;
  if (bill.status === "partial") return <Badge variant="warn">Kurang bayar</Badge>;
  return <Badge>{dueLabel(bill.days_to_due)}</Badge>;
}

type Action = { billUlid: string; kind: "bayar" | "bebaskan" | "batalkan" };

function ActionForm({ action, bill, onDone, onCancel }: { action: Action["kind"]; bill: Bill; onDone: () => void; onCancel: () => void }) {
  const [submitting, setSubmitting] = useState(false);
  const [amount, setAmount] = useState(String(bill.remaining_amount));
  const [method, setMethod] = useState("cash");
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    if (action !== "bayar" && !reason.trim()) {
      setError("Alasan wajib diisi.");
      return;
    }

    setSubmitting(true);
    setError(null);

    try {
      if (action === "bayar") {
        await api.post(`/api/admin/bills/${bill.ulid}/payments`, { amount: Number(amount), method, notes: reason || undefined });
        toast.success("Pembayaran dicatat.");
      } else if (action === "bebaskan") {
        await api.post(`/api/admin/bills/${bill.ulid}/waive`, { reason });
        toast.success("Tagihan dibebaskan.");
      } else {
        await api.post(`/api/admin/bills/${bill.ulid}/cancel`, { reason });
        toast.success("Tagihan dibatalkan.");
      }
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal memproses.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="mt-3 flex flex-col gap-2 border-t border-border pt-3">
      {action === "bayar" && (
        <div className="flex flex-wrap gap-2">
          <Input value={amount} onChange={(e) => setAmount(e.target.value)} type="number" className="w-40" placeholder="Jumlah" />
          <select value={method} onChange={(e) => setMethod(e.target.value)} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
            <option value="cash">Tunai</option>
            <option value="bank_transfer">Transfer bank</option>
            <option value="qris">QRIS</option>
            <option value="other">Lainnya</option>
          </select>
        </div>
      )}
      {(action === "bebaskan" || action === "batalkan") && (
        <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Alasan (wajib)" />
      )}
      {action === "bayar" && (
        <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Catatan (opsional)" />
      )}
      {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
      <div className="flex gap-2">
        <Button size="sm" variant={action === "batalkan" ? "destructive" : "default"} onClick={submit} disabled={submitting}>
          {submitting ? "Memproses…" : "Simpan"}
        </Button>
        <Button size="sm" variant="ghost" onClick={onCancel} disabled={submitting}>Batal</Button>
      </div>
    </div>
  );
}

export default function AdminBillsPage() {
  const [bills, setBills] = useState<Paginated<Bill> | null>(null);
  const [status, setStatus] = useState("open");
  const [q, setQ] = useState("");
  const [action, setAction] = useState<Action | null>(null);

  const load = useCallback(() => {
    const params = new URLSearchParams();
    if (status) params.set("status", status);
    if (q) params.set("q", q);
    api
      .get<{ bills: Paginated<Bill> }>(`/api/admin/bills?${params}`)
      .then((d) => setBills(d.bills))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat tagihan."));
  }, [status, q]);

  useEffect(() => { load(); }, [load]);

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-xl font-bold tracking-tight">Tagihan</h1>
        <p className="mt-1 text-sm text-muted-foreground">{bills ? `${bills.meta.total} tagihan` : "Memuat…"}</p>
      </div>

      <div className="flex flex-wrap gap-2">
        <div className="relative">
          <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari nama siswa…" className="w-56 pl-9" />
        </div>
        <select value={status} onChange={(e) => setStatus(e.target.value)} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
          <option value="open">Belum lunas</option>
          <option value="overdue">Lewat jatuh tempo</option>
          <option value="partial">Kurang bayar</option>
          <option value="paid">Lunas</option>
          <option value="waived">Dibebaskan</option>
          <option value="cancelled">Dibatalkan</option>
          <option value="">Semua</option>
        </select>
      </div>

      {bills === null && <Skeleton className="h-64 w-full" />}

      <div className="flex flex-col gap-2">
        {bills?.data.map((bill) => (
          <Card key={bill.ulid} className="p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="font-semibold">{bill.description}</p>
                <p className="text-sm text-muted-foreground">
                  {bill.student?.nama_lengkap} · {bill.bill_number} · jatuh tempo {tanggal(bill.due_date)}
                </p>
              </div>
              <div className="text-right">
                <p className="tabular font-semibold">{rupiah(bill.remaining_amount)}</p>
                {bill.paid_amount > 0 && (
                  <p className="tabular text-xs text-muted-foreground">dari {rupiah(bill.total_amount)}</p>
                )}
              </div>
            </div>

            <div className="mt-2 flex flex-wrap items-center gap-2">
              {statusBadge(bill)}
              {isOpen(bill) && (
                <div className="ml-auto flex gap-1.5">
                  <Button size="sm" variant="outline" onClick={() => setAction({ billUlid: bill.ulid, kind: "bayar" })}>
                    Catat bayar
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setAction({ billUlid: bill.ulid, kind: "bebaskan" })}>
                    Bebaskan
                  </Button>
                  {bill.paid_amount === 0 && (
                    <Button size="sm" variant="ghost" onClick={() => setAction({ billUlid: bill.ulid, kind: "batalkan" })}>
                      Batalkan
                    </Button>
                  )}
                </div>
              )}
            </div>

            {action?.billUlid === bill.ulid && (
              <ActionForm
                action={action.kind}
                bill={bill}
                onCancel={() => setAction(null)}
                onDone={() => { setAction(null); load(); }}
              />
            )}
          </Card>
        ))}

        {bills?.data.length === 0 && (
          <Card className="p-6 text-sm text-muted-foreground">Tidak ada tagihan untuk filter ini.</Card>
        )}
      </div>
    </div>
  );
}

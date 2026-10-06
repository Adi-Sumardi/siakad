"use client";

import { useState } from "react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { api, ApiError } from "@/lib/api";
import { rupiah, tanggal, todayJakarta } from "@/lib/format";
import type { Bill, InstallmentRow } from "@/lib/types/billing";

const STATUS: Record<InstallmentRow["status"], { label: string; className: string }> = {
  paid: { label: "Lunas", className: "bg-good/10 text-good" },
  partial: { label: "Sebagian", className: "bg-warn/10 text-warn" },
  unpaid: { label: "Belum", className: "" },
  overdue: { label: "Terlambat", className: "bg-bad/10 text-bad" },
};

/** The plan's rows (audit 6 Okt 2026 #6) - shared by the admin list and the guardian's bill page. */
export function InstallmentTable({ rows }: { rows: InstallmentRow[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-border text-left text-xs text-muted-foreground">
            <th className="py-1.5 pr-3 font-medium">Cicilan</th>
            <th className="py-1.5 pr-3 font-medium">Jatuh tempo</th>
            <th className="py-1.5 pr-3 text-right font-medium">Nominal</th>
            <th className="py-1.5 pr-3 text-right font-medium">Terbayar</th>
            <th className="py-1.5 font-medium">Status</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.ulid} className="border-b border-border/60">
              <td className="py-1.5 pr-3">Ke-{row.sequence}</td>
              <td className="py-1.5 pr-3">{tanggal(row.due_date)}</td>
              <td className="tabular py-1.5 pr-3 text-right">{rupiah(row.amount)}</td>
              <td className="tabular py-1.5 pr-3 text-right">{rupiah(row.paid)}</td>
              <td className="py-1.5">
                <Badge className={STATUS[row.status].className}>{STATUS[row.status].label}</Badge>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** Admin control: set, replace or remove a bill's plan. */
export function InstallmentEditor({ bill, onChanged }: { bill: Bill; onChanged: () => void }) {
  const [count, setCount] = useState(3);
  const [firstDue, setFirstDue] = useState(todayJakarta());
  const [busy, setBusy] = useState(false);
  const rows = bill.installments ?? [];

  async function save() {
    setBusy(true);
    try {
      await api.put(`/api/admin/bills/${bill.ulid}/installments`, { count, first_due_date: firstDue });
      toast.success(`Rencana ${count}x cicilan disimpan.`);
      onChanged();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyimpan rencana cicilan.");
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    setBusy(true);
    try {
      await api.delete(`/api/admin/bills/${bill.ulid}/installments`);
      toast.success("Rencana cicilan dihapus.");
      onChanged();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus rencana cicilan.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="mt-3 flex flex-col gap-3 rounded-lg border border-border p-3">
      {rows.length > 0 && <InstallmentTable rows={rows} />}
      <div className="flex flex-wrap items-end gap-2 text-sm">
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          Jumlah cicilan
          <select
            value={count}
            onChange={(e) => setCount(Number(e.target.value))}
            className="h-8 rounded-lg border border-input bg-card px-2 text-sm text-foreground"
          >
            {Array.from({ length: 11 }, (_, i) => i + 2).map((n) => (
              <option key={n} value={n}>
                {n}x
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          Jatuh tempo cicilan pertama
          <Input type="date" value={firstDue} min={todayJakarta()} onChange={(e) => setFirstDue(e.target.value)} className="h-8 w-40" />
        </label>
        <Button size="sm" onClick={save} disabled={busy}>
          {rows.length > 0 ? "Ganti rencana" : "Simpan rencana"}
        </Button>
        {rows.length > 0 && (
          <Button size="sm" variant="ghost" onClick={remove} disabled={busy}>
            Hapus rencana
          </Button>
        )}
      </div>
      <p className="text-xs text-muted-foreground">
        Sisa {rupiah(bill.remaining_amount)} dibagi rata per bulan. Jatuh tempo tagihan pindah ke cicilan terakhir, jadi
        status &quot;lewat jatuh tempo&quot; dan denda mengikuti rencana ini.
      </p>
    </div>
  );
}

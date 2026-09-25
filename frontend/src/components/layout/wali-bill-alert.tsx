"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { AlertTriangle, Bell, CheckCircle2, ChevronRight } from "lucide-react";
import { useAuth } from "@/lib/auth/auth-context";
import { api } from "@/lib/api";
import { dueLabel, rupiah, tanggal } from "@/lib/format";
import type { Bill, BillSummary, Payment } from "@/lib/types/billing";
import { cn } from "@/lib/utils";

/** How long a settled payment stays listed as good news in the bell. */
const RECEIPT_WINDOW_DAYS = 7;

/**
 * The wali navbar's notification bell, left of the profile avatar. Two kinds
 * of news live here:
 *
 * - Tagihan: anything open, red-flagged when overdue - actionable, so it also
 *   drives the count badge. Renders nothing at all when there is nothing to
 *   act on and no recent receipt, so no news genuinely means no dues.
 * - Pembayaran diterima: payments settled within the last week (time-windowed,
 *   not read-tracked - a receipt needs no action, so a badge nagging over it
 *   would be noise), linking to the payment's own invoice modal.
 *
 * Both recompute their urgency from what the bills/payments pages already
 * return (status, days_to_due) rather than inventing their own idea of
 * "owing", and refresh every 60s so money the school just recorded shows up
 * without a reload - same cadence as the guru dashboard's schedule chips.
 */
export function WaliBillAlert() {
  const { user } = useAuth();
  const [open, setOpen] = useState(false);
  const [bills, setBills] = useState<Bill[] | null>(null);
  const [summary, setSummary] = useState<BillSummary | null>(null);
  const [receipts, setReceipts] = useState<Payment[]>([]);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (user?.role !== "orangtua") return;

    let cancelled = false;

    const load = () => {
      Promise.all([
        // status=open keeps the payload to the bill rows the bell can list;
        // summary is still computed over exactly those rows.
        api.get<{ bills: Bill[]; summary: BillSummary }>("/api/wali/bills?status=open").catch(() => null),
        // The first page is plenty for the bell's recent-receipts window -
        // it stays light while the history page paginates (audit T54-6).
        api.get<{ payments: { data: Payment[] } }>("/api/wali/payments?per_page=10").catch(() => null),
      ]).then(([freshBills, freshPayments]) => {
        if (cancelled) return;
        // A failed refresh keeps the last known state; the tagihan page
        // remains the authoritative surface for errors.
        if (freshBills) {
          setBills(freshBills.bills);
          setSummary(freshBills.summary);
        }

        if (freshPayments) {
          const cutoff = Date.now() - RECEIPT_WINDOW_DAYS * 86_400_000;

          setReceipts(
            freshPayments.payments.data
              .filter((p) => p.status === "completed" && p.paid_at && new Date(p.paid_at).getTime() >= cutoff)
              .slice(0, 3),
          );
        }
      });
    };

    load();
    const interval = setInterval(load, 60_000);

    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, [user]);

  useEffect(() => {
    function onClickOutside(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", onClickOutside);
    return () => document.removeEventListener("mousedown", onClickOutside);
  }, []);

  if (bills === null || summary === null) return null;

  const openCount = summary.open_count;
  if (openCount === 0 && receipts.length === 0) return null;

  // The API lists open bills first, most urgent due_date ahead - exactly the
  // order worth previewing.
  const preview = bills.slice(0, 3);
  const rest = bills.length - preview.length;

  function dueClass(bill: Bill): string {
    if (bill.status === "overdue" || (bill.days_to_due ?? 0) < 0) return "text-bad";
    if ((bill.days_to_due ?? Infinity) <= 7) return "text-warn";
    return "text-muted-foreground";
  }

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen((o) => !o)}
        className={cn(
          "relative flex size-9 items-center justify-center rounded-xl transition-colors",
          summary.overdue_count > 0
            ? "text-bad hover:bg-bad-soft"
            : openCount > 0
              ? "text-muted-foreground hover:bg-accent hover:text-foreground"
              : "text-good hover:bg-good-soft",
        )}
        aria-label={
          openCount > 0
            ? `Notifikasi: ${openCount} tagihan belum lunas`
            : "Notifikasi: pembayaran diterima"
        }
      >
        <Bell className="size-5" />
        {openCount > 0 && (
          <span
            className={cn(
              "absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-bold",
              summary.overdue_count > 0 ? "bg-bad text-white" : "bg-primary text-primary-foreground",
            )}
          >
            {openCount > 9 ? "9+" : openCount}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 top-full mt-2 w-80 rounded-xl border border-border bg-card shadow-2xl overflow-hidden z-40">
          <div className="flex items-center justify-between gap-2 border-b border-border px-3.5 py-3">
            <p className="text-sm font-bold text-foreground">Notifikasi</p>
            <span className="text-[10px] text-muted-foreground">update tiap 1 menit</span>
          </div>

          {summary.overdue_count > 0 && (
            <div className="flex items-center gap-2 border-b border-border bg-bad-soft px-3.5 py-2.5">
              <AlertTriangle className="size-4 shrink-0 text-bad" />
              <p className="text-xs font-bold text-bad">
                {summary.overdue_count} tagihan sudah melewati jatuh tempo
              </p>
            </div>
          )}

          {openCount > 0 && (
            <>
              <ul className="max-h-64 divide-y divide-border overflow-y-auto">
                {preview.map((bill) => (
                  <li key={bill.ulid} className="flex items-start justify-between gap-3 px-3.5 py-2.5">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-semibold text-foreground">{bill.description}</p>
                      <p className="truncate text-xs text-muted-foreground">{bill.student?.nama_lengkap}</p>
                      <p className={cn("text-[11px] font-semibold", dueClass(bill))}>
                        {dueLabel(bill.days_to_due)}
                      </p>
                    </div>
                    <p className="tabular shrink-0 text-sm font-bold text-foreground">
                      {rupiah(bill.remaining_amount)}
                    </p>
                  </li>
                ))}
              </ul>

              {rest > 0 && (
                <p className="border-t border-border px-3.5 py-2 text-xs text-muted-foreground">
                  +{rest} tagihan lainnya belum lunas
                </p>
              )}
            </>
          )}

          {receipts.length > 0 && (
            <div className={cn("overflow-hidden", openCount > 0 && "border-t border-border")}>
              <p className="bg-accent/40 px-3.5 py-1.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                Pembayaran diterima
              </p>
              <ul className="divide-y divide-border">
                {receipts.map((payment) => (
                  <li key={payment.ulid}>
                    <Link
                      href={`/pembayaran?payment=${payment.ulid}`}
                      onClick={() => setOpen(false)}
                      className="flex items-start justify-between gap-3 px-3.5 py-2.5 transition-colors hover:bg-accent/50"
                    >
                      <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-good">
                          <CheckCircle2 className="mr-1 inline size-3.5" />
                          {rupiah(payment.amount)}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                          {payment.bills?.[0]?.description ?? payment.payment_number}
                        </p>
                        <p className="truncate text-[11px] text-muted-foreground">
                          {tanggal(payment.paid_at)} · diterima sekolah
                        </p>
                      </div>
                      <ChevronRight className="mt-1 size-4 shrink-0 text-muted-foreground" />
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {openCount > 0 ? (
            <div className="border-t border-border bg-accent/30 px-3.5 py-3">
              <p className="text-xs text-muted-foreground">
                Total kewajiban:{" "}
                <span className="tabular text-sm font-black text-primary">{rupiah(summary.outstanding)}</span>
              </p>
              <Link
                href="/tagihan"
                onClick={() => setOpen(false)}
                className="mt-2 flex items-center justify-between rounded-lg bg-primary px-3 py-2 text-xs font-bold text-primary-foreground transition-opacity hover:opacity-90"
              >
                <span>Lihat &amp; Bayar Tagihan</span>
                <ChevronRight className="size-4" />
              </Link>
            </div>
          ) : (
            <Link
              href="/pembayaran"
              onClick={() => setOpen(false)}
              className="flex items-center justify-between border-t border-border bg-accent/30 px-3.5 py-3 text-xs font-bold text-primary transition-colors hover:bg-accent/60"
            >
              <span>Riwayat Pembayaran</span>
              <ChevronRight className="size-4" />
            </Link>
          )}
        </div>
      )}
    </div>
  );
}

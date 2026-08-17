export type BillStatus = "draft" | "unpaid" | "partial" | "paid" | "overdue" | "cancelled" | "waived";

export type Bill = {
  ulid: string;
  bill_number: string;
  description: string;
  fee_type?: { code: string; name: string };
  student?: { ulid: string; nama_lengkap: string; nama_panggilan: string | null };
  period_month: number | null;
  subtotal: number;
  discount_amount: number;
  late_fee: number;
  total_amount: number;
  paid_amount: number;
  remaining_amount: number;
  status: BillStatus;
  due_date: string | null;
  days_to_due: number | null;
  lines?: { name: string; qty: number; unit_price: number; amount: number; size_option: string | null }[];
};

export type BillSummary = {
  outstanding: number;
  open_count: number;
  overdue_count: number;
};

export type Payment = {
  ulid: string;
  payment_number: string;
  amount: number;
  method: string | null;
  channel: string | null;
  status: string;
  invoice_url: string | null;
  paid_at: string | null;
  created_at: string;
  bills?: Bill[];
};

/** Open statuses in one place, so no screen invents its own idea of "owing". */
export const OPEN_STATUSES: BillStatus[] = ["unpaid", "partial", "overdue"];

export function isOpen(bill: Bill): boolean {
  return OPEN_STATUSES.includes(bill.status);
}

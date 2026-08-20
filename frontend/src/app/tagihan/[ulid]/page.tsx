"use client";

import { use, useEffect, useState } from "react";
import Link from "next/link";
import {
  ArrowLeft,
  Banknote,
  Building2,
  Calendar,
  CheckCircle2,
  Clock,
  Download,
  FileText,
  GraduationCap,
  HelpCircle,
  QrCode,
  Receipt,
  ShieldCheck,
  User,
  Wallet,
} from "lucide-react";
import { toast } from "sonner";
import { WaliShell } from "@/components/layout/wali-shell";
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
  const [downloading, setDownloading] = useState(false);

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

  async function handleDownloadPdf() {
    if (!bill) return;
    setDownloading(true);

    try {
      const res = await fetch(`${API_BASE}/api/wali/bills/${bill.ulid}/pdf`, {
        credentials: "include",
      });

      if (!res.ok) {
        throw new Error("Gagal mengunduh dokumen PDF.");
      }

      const blob = await res.blob();
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      const prefix = bill.status === "paid" ? "Kuitansi" : "Tagihan";
      link.download = `${prefix}-${bill.bill_number.replace(/\//g, "-")}.pdf`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);
      toast.success("Dokumen PDF berhasil diunduh.");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Gagal mengunduh PDF");
    } finally {
      setDownloading(false);
    }
  }

  if (loading || !user || user.role !== "orangtua") {
    return (
      <WaliShell>
        <div className="space-y-4">
          <Skeleton className="h-10 w-48" />
          <Skeleton className="h-64 w-full rounded-2xl" />
        </div>
      </WaliShell>
    );
  }

  if (error || !bill) {
    return (
      <WaliShell>
        <div className="max-w-xl mx-auto py-12">
          <Card className="p-8 text-center space-y-4">
            <p className="text-sm text-destructive font-semibold">{error ?? "Tagihan tidak ditemukan."}</p>
            <Link href="/tagihan">
              <Button variant="outline" size="sm" className="gap-2">
                <ArrowLeft className="size-4" />
                <span>Kembali ke Daftar Tagihan</span>
              </Button>
            </Link>
          </Card>
        </div>
      </WaliShell>
    );
  }

  const isPaid = bill.status === "paid";

  return (
    <WaliShell>
      <div className="space-y-6 pb-20">
        {/* Back Link & Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-border/80 pb-4">
          <div className="space-y-1">
            <Link
              href="/tagihan"
              className="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground transition-colors mb-1"
            >
              <ArrowLeft className="size-3.5" />
              <span>Kembali ke Daftar Tagihan</span>
            </Link>
            <h1 className="text-2xl font-black tracking-tight text-foreground">{bill.description}</h1>
            <p className="text-xs text-muted-foreground">
              Nomor Tagihan: <span className="font-mono font-bold text-foreground">{bill.bill_number}</span> · Diterbitkan: {tanggal(bill.issued_at ?? null)}
            </p>
          </div>

          <div className="flex items-center gap-2.5">
            <Button
              variant="outline"
              size="sm"
              onClick={handleDownloadPdf}
              disabled={downloading}
              className="gap-2 text-xs font-semibold shadow-2xs"
            >
              <Download className="size-4 text-primary" />
              <span>{downloading ? "Mengunduh..." : isPaid ? "Unduh Kuitansi PDF" : "Unduh Invoice PDF"}</span>
            </Button>

            {!isPaid && (
              <Link href="/tagihan">
                <Button size="sm" className="gap-2 font-bold shadow-xs">
                  <Wallet className="size-4" />
                  <span>Bayar Sekarang</span>
                </Button>
              </Link>
            )}
          </div>
        </div>

        {/* Institution & Student Meta Card */}
        <Card className="p-6 border-border bg-card shadow-xs overflow-hidden relative">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            {/* School Info */}
            <div className="space-y-3 border-b md:border-b-0 md:border-r border-border/60 pb-4 md:pb-0 md:pr-6">
              <div className="flex items-center gap-3">
                <div className="size-12 rounded-xl bg-primary/10 flex items-center justify-center text-primary font-black shrink-0">
                  <Building2 className="size-6" />
                </div>
                <div>
                  <h3 className="font-extrabold text-sm text-foreground">YAYASAN ASRAMA PELAJAR ISLAM (YAPI)</h3>
                  <p className="text-xs text-primary font-bold">{bill.student?.schoolUnit?.label ?? "Unit Sekolah Rawamangun"}</p>
                  <p className="text-[11px] text-muted-foreground mt-0.5">Kompleks Pendidikan Rawamangun, Jakarta Timur</p>
                </div>
              </div>

              <div className="pt-2 text-xs text-muted-foreground space-y-1 bg-muted/30 p-3 rounded-xl">
                <div className="flex justify-between">
                  <span>Tahun Ajaran:</span>
                  <strong className="text-foreground">{bill.academicYear?.year ?? "2026/2027"}</strong>
                </div>
                <div className="flex justify-between">
                  <span>Jatuh Tempo:</span>
                  <strong className="text-destructive">{tanggal(bill.due_date)}</strong>
                </div>
              </div>
            </div>

            {/* Student Info */}
            <div className="space-y-3 flex flex-col justify-between">
              <div>
                <span className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Data Siswa / Santri</span>
                <p className="text-base font-black text-foreground mt-1">{bill.student?.nama_lengkap}</p>
                <div className="grid grid-cols-2 gap-2 mt-2 text-xs">
                  <div className="bg-muted/40 p-2 rounded-lg">
                    <span className="text-muted-foreground block text-[11px]">NIS:</span>
                    <strong className="font-mono text-foreground">{bill.student?.nis ?? "—"}</strong>
                  </div>
                  <div className="bg-muted/40 p-2 rounded-lg">
                    <span className="text-muted-foreground block text-[11px]">Status:</span>
                    {isPaid ? (
                      <Badge variant="good" className="mt-0.5">Lunas</Badge>
                    ) : (
                      <Badge variant="warn" className="mt-0.5">Belum Lunas</Badge>
                    )}
                  </div>
                </div>
              </div>

              <div className="flex items-center justify-between pt-2 border-t border-border/60">
                <span className="text-xs text-muted-foreground">Wali Murid:</span>
                <span className="text-xs font-bold text-foreground">{user?.name}</span>
              </div>
            </div>
          </div>
        </Card>

        {/* Detailed Fee Line Items Breakdown */}
        <Card className="overflow-hidden p-0 border-border shadow-xs">
          <div className="border-b border-border bg-muted/40 px-6 py-3.5 flex items-center justify-between">
            <h2 className="text-sm font-bold text-foreground flex items-center gap-2">
              <Receipt className="size-4 text-primary" />
              <span>Rincian Komponen Biaya Tagihan</span>
            </h2>
            <span className="text-xs text-muted-foreground">Mata Uang: IDR (Rupiah)</span>
          </div>

          <div className="divide-y divide-border/60">
            {bill.lines && bill.lines.length > 0 ? (
              bill.lines.map((line, i) => (
                <div key={i} className="flex items-center justify-between p-4 px-6 text-sm hover:bg-muted/20 transition-colors">
                  <div>
                    <p className="font-bold text-foreground">{line.name}</p>
                    {line.qty > 1 && (
                      <p className="text-xs text-muted-foreground mt-0.5">
                        {line.qty} × {rupiah(line.unit_price)}
                        {line.size_option && ` · Ukuran: ${line.size_option}`}
                      </p>
                    )}
                  </div>
                  <span className={`tabular font-bold ${line.amount < 0 ? "text-good font-black" : "text-foreground"}`}>
                    {rupiah(line.amount)}
                  </span>
                </div>
              ))
            ) : (
              <div className="flex items-center justify-between p-4 px-6 text-sm">
                <div>
                  <p className="font-bold text-foreground">{bill.description}</p>
                  <p className="text-xs text-muted-foreground mt-0.5">Tarif pokok bulanan</p>
                </div>
                <span className="tabular font-bold text-foreground">{rupiah(bill.subtotal)}</span>
              </div>
            )}
          </div>

          {/* Totals Summary */}
          <div className="bg-muted/20 border-t border-border p-6 space-y-2">
            {bill.discount_amount > 0 && (
              <>
                <div className="flex justify-between text-xs text-muted-foreground">
                  <span>Subtotal Tagihan</span>
                  <span className="tabular">{rupiah(bill.subtotal)}</span>
                </div>
                <div className="flex justify-between text-xs text-emerald-600 font-medium">
                  <span>Potongan Beasiswa / Diskon</span>
                  <span className="tabular">− {rupiah(bill.discount_amount)}</span>
                </div>
              </>
            )}

            {bill.late_fee > 0 && (
              <div className="flex justify-between text-xs text-destructive">
                <span>Denda Keterlambatan</span>
                <span className="tabular">+ {rupiah(bill.late_fee)}</span>
              </div>
            )}

            <div className="flex justify-between text-base font-black border-t border-border/80 pt-2 text-foreground">
              <span>Total Tagihan</span>
              <span className="tabular text-primary text-lg">{rupiah(bill.total_amount)}</span>
            </div>

            {bill.paid_amount > 0 && !isPaid && (
              <>
                <div className="flex justify-between text-xs text-emerald-600 font-semibold">
                  <span>Sudah Dibayar (Cicilan)</span>
                  <span className="tabular">− {rupiah(bill.paid_amount)}</span>
                </div>
                <div className="flex justify-between text-base font-black text-destructive border-t border-dashed border-border pt-1.5">
                  <span>Sisa yang Harus Dibayar</span>
                  <span className="tabular">{rupiah(bill.remaining_amount)}</span>
                </div>
              </>
            )}
          </div>
        </Card>

        {/* Payment History for this Bill */}
        {payments.length > 0 && (
          <Card className="p-6 border-border shadow-xs space-y-3">
            <h3 className="text-sm font-bold text-foreground flex items-center gap-2">
              <CheckCircle2 className="size-4 text-emerald-600" />
              <span>Riwayat Pembayaran untuk Tagihan Ini</span>
            </h3>
            <div className="divide-y divide-border/60">
              {payments.map((payment) => (
                <div key={payment.ulid} className="py-3 first:pt-0 last:pb-0 flex items-center justify-between text-sm">
                  <div>
                    <p className="font-bold text-foreground font-mono">{payment.payment_number}</p>
                    <p className="text-xs text-muted-foreground mt-0.5">
                      Metode: <strong className="uppercase">{payment.method}</strong> · {tanggal(payment.paid_at ?? payment.created_at)}
                    </p>
                  </div>
                  <div className="text-right">
                    <p className="tabular font-black text-foreground">{rupiah(payment.amount)}</p>
                    <span className="inline-block mt-0.5 text-xs font-semibold text-emerald-600 uppercase">
                      {payment.status}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </Card>
        )}

        {/* Official Bank Account and Payment Instructions Box */}
        <Card className="p-6 border-emerald-500/30 bg-emerald-500/5 shadow-xs space-y-4">
          <div className="flex items-center gap-2 text-emerald-900 dark:text-emerald-300 font-bold text-sm">
            <ShieldCheck className="size-5 text-emerald-600" />
            <span>Petunjuk & Rekening Resmi Pembayaran YAPI Jakarta</span>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
            <div className="p-3 bg-card rounded-xl border border-border shadow-2xs space-y-1">
              <p className="font-bold text-foreground">Bank Syariah Indonesia (BSI)</p>
              <p className="font-mono text-sm font-black text-primary">7001234567</p>
              <p className="text-[11px] text-muted-foreground">a.n. Yayasan Asrama Pelajar Islam</p>
            </div>

            <div className="p-3 bg-card rounded-xl border border-border shadow-2xs space-y-1">
              <p className="font-bold text-foreground">Bank Mandiri</p>
              <p className="font-mono text-sm font-black text-primary">1230009876543</p>
              <p className="text-[11px] text-muted-foreground">a.n. Yayasan Asrama Pelajar Islam</p>
            </div>

            <div className="p-3 bg-card rounded-xl border border-border shadow-2xs space-y-1">
              <p className="font-bold text-foreground">Bank Central Asia (BCA)</p>
              <p className="font-mono text-sm font-black text-primary">0089123456</p>
              <p className="text-[11px] text-muted-foreground">a.n. Yayasan Asrama Pelajar Islam</p>
            </div>
          </div>

          <p className="text-xs text-emerald-800 dark:text-emerald-400">
            💡 Untuk pembayaran instan otomatis tanpa perlu konfirmasi manual, silakan klik tombol <strong>Bayar Sekarang</strong> untuk membayar via <strong>QRIS Dinamis / SendagoPay</strong>.
          </p>
        </Card>
      </div>
    </WaliShell>
  );
}

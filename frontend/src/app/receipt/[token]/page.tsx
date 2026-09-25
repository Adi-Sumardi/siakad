"use client";

import { useEffect, useState } from "react";
import { BadgeCheck, Printer } from "lucide-react";
import { api } from "@/lib/api";
import { rupiah, tanggalWaktu } from "@/lib/format";

type Receipt = {
  reference_number: string;
  paid_at: string | null;
  amount: number;
  bank_name: string;
  students: { nama_lengkap: string; unit: string | null }[];
  items: { description: string | null; fee_type: string | null; amount: number }[];
  status: string;
};

/**
 * The public payment receipt (feature batch Poin 11C): a shareable,
 * login-free proof of payment a family can open from any device and print.
 * The 32-character token in the URL is the only credential (the school's
 * explicit choice), and the payload is deliberately minimal - no login
 * wall, no NIS, no contacts.
 */
export default function PublicReceiptPage({ params }: { params: Promise<{ token: string }> }) {
  const [receipt, setReceipt] = useState<Receipt | null>(null);
  const [notFound, setNotFound] = useState(false);

  useEffect(() => {
    params.then(({ token: t }) => {
      api
        .get<{ receipt: Receipt }>(`/api/receipt/${t}`)
        .then((d) => setReceipt(d.receipt))
        .catch(() => setNotFound(true));
    });
  }, [params]);

  if (notFound) {
    return (
      <main className="flex min-h-dvh items-center justify-center bg-canvas p-6">
        <div className="max-w-sm rounded-2xl border border-border bg-card p-8 text-center shadow-sm">
          <p className="text-lg font-bold text-foreground">Tautan tidak dikenali</p>
          <p className="mt-2 text-sm text-muted-foreground">
            Tautan struk ini tidak valid atau tidak lagi tersedia. Hubungi pihak sekolah bila ini merasa keliru.
          </p>
        </div>
      </main>
    );
  }

  if (receipt === null) {
    return (
      <main className="flex min-h-dvh items-center justify-center bg-canvas p-6">
        <div className="h-40 w-full max-w-sm animate-pulse rounded-2xl bg-muted/50" />
      </main>
    );
  }

  return (
    <main className="min-h-dvh bg-canvas px-4 py-8 print:bg-white print:py-0">
      <div className="mx-auto max-w-md space-y-5 print:space-y-3">
        {/* Kop */}
        <header className="flex items-center gap-3 border-b-2 border-[#14532D] pb-3">
          {/* eslint-disable-next-line @next/next/no-img-element -- logo lokal statis */}
          <img src="/images/logo-yapi.png" alt="YAPI" className="h-12" />
          <div>
            <h1 className="text-sm font-extrabold tracking-wide text-[#14532D]">
              YAYASAN PENDIDIKAN ISLAM AL-AZHAR (YAPI)
            </h1>
            <p className="text-[11px] text-muted-foreground">Kuitansi Pembayaran Siswa — Bukti Transaksi Sah</p>
          </div>
        </header>

        {/* Stamp */}
        <div className="rounded-lg bg-[#14532D] px-4 py-2 text-center text-base font-black tracking-widest text-white print:rounded-none">
          LUNAS
        </div>

        {/* Meta */}
        <dl className="space-y-1.5 text-sm">
          <div className="flex gap-2">
            <dt className="w-36 shrink-0 text-muted-foreground">No. Referensi</dt>
            <dd className="font-bold tabular">{receipt.reference_number}</dd>
          </div>
          <div className="flex gap-2">
            <dt className="w-36 shrink-0 text-muted-foreground">Tanggal Bayar</dt>
            <dd className="font-bold">{receipt.paid_at ? tanggalWaktu(receipt.paid_at) : "—"}</dd>
          </div>
          <div className="flex gap-2">
            <dt className="w-36 shrink-0 text-muted-foreground">Metode</dt>
            <dd className="font-bold">{receipt.bank_name}</dd>
          </div>
          {receipt.students.map((s) => (
            <div key={s.nama_lengkap} className="flex gap-2">
              <dt className="w-36 shrink-0 text-muted-foreground">Siswa</dt>
              <dd className="font-bold">
                {s.nama_lengkap}
                {s.unit ? ` (${s.unit})` : ""}
              </dd>
            </div>
          ))}
        </dl>

        {/* Items */}
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-[#ecfdf5] text-left text-xs text-[#14532D]">
              <th className="rounded-l-md px-3 py-2">Rincian Tagihan</th>
              <th className="rounded-r-md px-3 py-2 text-right">Nominal</th>
            </tr>
          </thead>
          <tbody>
            {receipt.items.map((item, i) => (
              <tr key={i} className="border-b border-border/60">
                <td className="px-3 py-2">
                  {item.description ?? item.fee_type ?? "Tagihan"}
                  {item.fee_type && <span className="block text-[11px] text-muted-foreground">{item.fee_type}</span>}
                </td>
                <td className="px-3 py-2 text-right font-semibold tabular">{rupiah(item.amount)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        {/* Total */}
        <div className="flex items-center justify-between rounded-lg bg-[#14532D] px-4 py-2.5 text-white print:rounded-none">
          <span className="text-sm font-semibold">TOTAL DIBAYAR</span>
          <span className="text-lg font-black tabular">{rupiah(receipt.amount)}</span>
        </div>

        <p className="flex items-start gap-1.5 text-[11px] leading-relaxed text-muted-foreground">
          <BadgeCheck className="mt-0.5 size-3.5 shrink-0 text-[#14532D]" />
          Kuitansi ini merupakan bukti pembayaran sah yang diterbitkan otomatis oleh Sistem Informasi Akademik
          YAPI Al-Azhar dan tidak memerlukan tanda tangan basah. Dokumen ini sah untuk dicetak.
        </p>

        <button
          type="button"
          onClick={() => window.print()}
          className="mx-auto flex items-center gap-2 rounded-lg border border-input bg-card px-4 py-2 text-sm font-semibold text-foreground shadow-2xs transition-colors hover:bg-accent print:hidden"
        >
          <Printer className="size-4" />
          Cetak / Simpan PDF
        </button>
      </div>
    </main>
  );
}

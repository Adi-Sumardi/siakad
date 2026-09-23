"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { AlertTriangle } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { api } from "@/lib/api";
import { tanggal } from "@/lib/format";

type FailureSummary = {
  failed_24h: number;
  failed_7d: number;
  exhausted_7d: number;
  last_failed_at: string | null;
  top_templates: Array<{ template: string; count: number }>;
};

/**
 * The dashboard's alerting half of the notification retry sweep (audit C2):
 * when Sendago emails/WhatsApp keep failing, this card is how a central
 * admin notices that OTPs, receipts, or reminders are silently not arriving.
 * The sweep retries on its own (max 3 attempts over 24 hours), so the card
 * only means "look into it", not "resend by hand" - except for the
 * exhausted count, which the sweep will never touch again.
 *
 * Rendered only for central admins (the parent gates on scope.is_central
 * strictly true); fetch errors stay silent on purpose - a network blip on
 * the dashboard is never worth a toast, the sweep is the one that must be
 * loud. The card links to the ruang kontrol (/admin/monitoring) for the
 * row-level list and the manual resend.
 */
export function NotificationFailureAlert() {
  const [failures, setFailures] = useState<FailureSummary | null>(null);

  useEffect(() => {
    let cancelled = false;

    api
      .get<{ failures: FailureSummary }>("/api/admin/notification-failures")
      .then((res) => {
        if (!cancelled) setFailures(res.failures);
      })
      .catch(() => {
        setFailures(null);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  if (!failures || failures.failed_24h === 0) {
    return null;
  }

  return (
    <Card className="p-6 border-bad/30 shadow-md">
      <div className="flex items-start justify-between gap-4 border-b border-border/70 pb-4">
        <div>
          <div className="flex items-center gap-2">
            <span className="flex size-8 items-center justify-center rounded-lg border text-bad bg-bad-soft border-bad/20">
              <AlertTriangle className="size-4" />
            </span>
            <h2 className="text-lg font-bold text-foreground">Notifikasi Gagal Terkirim</h2>
          </div>
          <p className="text-xs text-muted-foreground mt-0.5">
            {failures.failed_24h} notifikasi email/WhatsApp gagal dalam 24 jam terakhir — sistem otomatis mencoba ulang (maks 3x, jendela 24 jam).
          </p>
        </div>
        <Badge variant="bad">{failures.failed_24h} gagal</Badge>
      </div>

      <div className="mt-4 space-y-2 text-xs text-muted-foreground">
        <p>{failures.failed_7d} kegagalan dalam 7 hari terakhir.</p>
        {failures.exhausted_7d > 0 && (
          <p className="font-semibold text-bad">
            {failures.exhausted_7d} tidak akan dicoba lagi — perlu tindak lanjut manual (rincian di log aplikasi).
          </p>
        )}
        {failures.top_templates.length > 0 && (
          <div className="flex flex-wrap items-center gap-1.5 pt-1">
            <span>Paling sering:</span>
            {failures.top_templates.map((t) => (
              <Badge key={t.template} variant="default" className="text-[10px] font-bold">
                {t.template} · {t.count}
              </Badge>
            ))}
          </div>
        )}
        {failures.last_failed_at && <p>Kegagalan terakhir: {tanggal(failures.last_failed_at)}</p>}
      </div>

      <div className="mt-4 border-t border-border/70 pt-3">
        <Link href="/admin/monitoring" className="text-xs font-semibold text-primary hover:underline">
          Lihat detail &amp; kirim ulang →
        </Link>
      </div>
    </Card>
  );
}

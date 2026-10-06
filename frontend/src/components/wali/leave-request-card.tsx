"use client";

import { useCallback, useEffect, useState } from "react";
import { MailPlus } from "lucide-react";
import { toast } from "sonner";
import { leaveRange, type LeaveRequestRow } from "@/components/leave-review-panel";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api, ApiError } from "@/lib/api";
import { todayJakarta } from "@/lib/format";

const STATUS: Record<LeaveRequestRow["status"], { label: string; className: string }> = {
  pending: { label: "Menunggu", className: "bg-warn/10 text-warn" },
  approved: { label: "Disetujui", className: "bg-good/10 text-good" },
  rejected: { label: "Ditolak", className: "bg-bad/10 text-bad" },
  cancelled: { label: "Dibatalkan", className: "" },
};

/**
 * The guardian's izin/sakit form (audit 6 Okt 2026 #3) - replaces phoning
 * TU. The notice is reviewed by the homeroom teacher or TU; only approval
 * changes the attendance record.
 */
export function LeaveRequestCard({ studentUlid }: { studentUlid: string }) {
  const [rows, setRows] = useState<LeaveRequestRow[] | null>(null);
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [type, setType] = useState<"sakit" | "izin">("sakit");
  const [from, setFrom] = useState(todayJakarta());
  const [to, setTo] = useState(todayJakarta());

  const load = useCallback(() => {
    api
      .get<{ leave_requests: LeaveRequestRow[] }>(`/api/wali/students/${studentUlid}/leave-requests`)
      .then((res) => setRows(res.leave_requests))
      .catch(() => setRows([]));
  }, [studentUlid]);

  useEffect(load, [load]);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    try {
      const form = new FormData(event.currentTarget);
      await api.post(`/api/wali/students/${studentUlid}/leave-requests`, form);
      toast.success("Pengajuan terkirim — menunggu persetujuan wali kelas/TU.");
      setOpen(false);
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengirim pengajuan.");
    } finally {
      setBusy(false);
    }
  }

  async function cancel(row: LeaveRequestRow) {
    try {
      await api.post(`/api/wali/leave-requests/${row.ulid}/cancel`, {});
      toast.success("Pengajuan dibatalkan.");
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal membatalkan pengajuan.");
    }
  }

  return (
    <Card className="p-6 border-border/80">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-base font-bold text-foreground">
          <MailPlus className="size-5 text-primary" />
          Izin / Sakit
        </h2>
        <Button size="sm" variant={open ? "outline" : "default"} onClick={() => setOpen(!open)}>
          {open ? "Tutup" : "Ajukan izin/sakit"}
        </Button>
      </div>

      {open && (
        <form onSubmit={submit} className="mt-4 grid gap-3 rounded-lg border border-border p-4 sm:grid-cols-3">
          <div>
            <Label className="text-xs">Jenis</Label>
            <select
              name="type"
              value={type}
              onChange={(e) => setType(e.target.value as "sakit" | "izin")}
              className="mt-1.5 h-9 w-full rounded-lg border border-input bg-card px-2 text-sm"
            >
              <option value="sakit">Sakit</option>
              <option value="izin">Izin</option>
            </select>
          </div>
          <div>
            <Label className="text-xs">Dari tanggal</Label>
            <Input
              type="date"
              name="date_from"
              value={from}
              onChange={(e) => {
                setFrom(e.target.value);
                if (e.target.value > to) setTo(e.target.value);
              }}
              required
              className="mt-1.5 h-9"
            />
          </div>
          <div>
            <Label className="text-xs">Sampai tanggal</Label>
            <Input type="date" name="date_to" value={to} min={from} onChange={(e) => setTo(e.target.value)} required className="mt-1.5 h-9" />
          </div>
          <div className="sm:col-span-3">
            <Label className="text-xs">Keterangan</Label>
            <textarea
              name="reason"
              required
              minLength={5}
              maxLength={1000}
              rows={3}
              placeholder={type === "sakit" ? "misal: demam, istirahat di rumah sesuai saran dokter" : "misal: acara keluarga di luar kota"}
              className="mt-1.5 w-full rounded-lg border border-input bg-card p-2 text-sm"
            />
          </div>
          <div className="sm:col-span-2">
            <Label className="text-xs">Lampiran (opsional — surat dokter, foto; JPG/PNG/PDF maks 5 MB)</Label>
            <Input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf" className="mt-1.5 h-9" />
          </div>
          <div className="flex items-end justify-end">
            <Button type="submit" disabled={busy}>
              {busy ? "Mengirim…" : "Kirim pengajuan"}
            </Button>
          </div>
        </form>
      )}

      <div className="mt-4 flex flex-col gap-2">
        {rows?.length === 0 && !open && (
          <p className="text-sm text-muted-foreground">
            Anak berhalangan hadir? Ajukan di sini — tidak perlu menelepon sekolah.
          </p>
        )}
        {rows?.map((row) => (
          <div key={row.ulid} className="flex flex-wrap items-start justify-between gap-2 rounded-lg border border-border p-3 text-sm">
            <div>
              <p className="font-medium">
                {row.type === "sakit" ? "Sakit" : "Izin"} · {leaveRange(row)}
              </p>
              <p className="text-xs text-muted-foreground">{row.reason}</p>
              {row.review_note && (
                <p className="mt-1 text-xs">
                  Catatan sekolah{row.reviewed_by ? ` (${row.reviewed_by})` : ""}: {row.review_note}
                </p>
              )}
            </div>
            <div className="flex items-center gap-2">
              <Badge className={STATUS[row.status].className}>{STATUS[row.status].label}</Badge>
              {row.status === "pending" && (
                <Button size="sm" variant="ghost" onClick={() => cancel(row)}>
                  Batalkan
                </Button>
              )}
            </div>
          </div>
        ))}
      </div>
    </Card>
  );
}

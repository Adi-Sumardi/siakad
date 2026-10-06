"use client";

import { useCallback, useEffect, useState } from "react";
import { FileText, MailQuestion } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { api, ApiError } from "@/lib/api";
import { downloadApiFile } from "@/lib/download";
import { tanggal } from "@/lib/format";

export type LeaveRequestRow = {
  ulid: string;
  student?: { ulid: string; nama_lengkap: string; nis: string; classroom: string | null };
  type: "sakit" | "izin";
  date_from: string;
  date_to: string;
  reason: string;
  has_attachment: boolean;
  attachment_name: string | null;
  status: "pending" | "approved" | "rejected" | "cancelled";
  requested_by?: string | null;
  reviewed_by?: string | null;
  review_note: string | null;
  created_at: string;
};

export function leaveRange(row: Pick<LeaveRequestRow, "date_from" | "date_to">): string {
  return row.date_from === row.date_to ? tanggal(row.date_from) : `${tanggal(row.date_from)} – ${tanggal(row.date_to)}`;
}

/**
 * Pending izin/sakit notices from guardians (audit 6 Okt 2026 #3), for the
 * homeroom teacher's board and TU's. Approval marks the covered days on the
 * server; this panel only decides. Hidden entirely when nothing is pending.
 */
export function LeaveReviewPanel({ endpoint, query = "", onChanged }: { endpoint: string; query?: string; onChanged?: () => void }) {
  const [rows, setRows] = useState<LeaveRequestRow[] | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [rejecting, setRejecting] = useState<string | null>(null);
  const [note, setNote] = useState("");

  const load = useCallback(() => {
    api
      .get<{ leave_requests: LeaveRequestRow[] }>(`${endpoint}${query}`)
      .then((res) => setRows(res.leave_requests))
      .catch((err) => {
        toast.error(err instanceof ApiError ? err.message : "Gagal memuat pengajuan izin.");
        setRows([]);
      });
  }, [endpoint, query]);

  useEffect(load, [load]);

  const suffix = query ? query : "";

  async function approve(row: LeaveRequestRow) {
    setBusy(row.ulid);
    try {
      const res = await api.post<{ windows_marked: number }>(`${endpoint}/${row.ulid}/approve${suffix}`, {});
      toast.success(
        res.windows_marked > 0
          ? `Disetujui — ${res.windows_marked} hari presensi diperbarui.`
          : "Disetujui — akan dicatat otomatis pada hari yang bersangkutan.",
      );
      load();
      onChanged?.();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyetujui pengajuan.");
    } finally {
      setBusy(null);
    }
  }

  async function reject(row: LeaveRequestRow) {
    if (note.trim().length < 3) {
      toast.error("Tuliskan alasan penolakan.");
      return;
    }
    setBusy(row.ulid);
    try {
      await api.post(`${endpoint}/${row.ulid}/reject${suffix}`, { note: note.trim() });
      toast.success("Pengajuan ditolak.");
      setRejecting(null);
      setNote("");
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menolak pengajuan.");
    } finally {
      setBusy(null);
    }
  }

  if (!rows || rows.length === 0) return null;

  return (
    <Card className="p-5">
      <div className="flex items-center gap-2">
        <MailQuestion className="size-4 text-primary" />
        <h2 className="text-sm font-semibold">Pengajuan izin/sakit dari wali murid</h2>
        <Badge variant="default">{rows.length} menunggu</Badge>
      </div>
      <div className="mt-3 flex flex-col gap-2">
        {rows.map((row) => (
          <div key={row.ulid} className="rounded-lg border border-border p-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p className="font-medium">
                  {row.student?.nama_lengkap}
                  <span className="ml-2 text-xs font-normal text-muted-foreground">
                    {row.student?.nis}
                    {row.student?.classroom && ` · ${row.student.classroom}`}
                  </span>
                </p>
                <p className="text-xs text-muted-foreground">
                  <Badge className={row.type === "sakit" ? "bg-warn/10 text-warn" : "bg-info/10 text-info"}>
                    {row.type === "sakit" ? "Sakit" : "Izin"}
                  </Badge>{" "}
                  {leaveRange(row)}
                  {row.requested_by && ` · diajukan ${row.requested_by}`}
                </p>
                <p className="mt-1 whitespace-pre-line">{row.reason}</p>
                {row.has_attachment && (
                  <button
                    type="button"
                    className="mt-1 inline-flex items-center gap-1 text-xs text-primary underline"
                    onClick={() =>
                      downloadApiFile(`/api/files/leave-requests/${row.ulid}/attachment`, row.attachment_name ?? "lampiran").catch(
                        (err) => toast.error(err instanceof Error ? err.message : "Gagal mengunduh lampiran."),
                      )
                    }
                  >
                    <FileText className="size-3.5" /> {row.attachment_name ?? "Lampiran"}
                  </button>
                )}
              </div>
              <div className="flex gap-1.5">
                <Button size="sm" onClick={() => approve(row)} disabled={busy !== null}>
                  Setujui
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => {
                    setRejecting(rejecting === row.ulid ? null : row.ulid);
                    setNote("");
                  }}
                  disabled={busy !== null}
                >
                  Tolak
                </Button>
              </div>
            </div>
            {rejecting === row.ulid && (
              <div className="mt-2 flex gap-2">
                <Input
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  placeholder="Alasan penolakan (terlihat oleh wali murid)"
                  maxLength={500}
                  className="h-8 text-xs"
                />
                <Button size="sm" variant="outline" onClick={() => reject(row)} disabled={busy !== null}>
                  Kirim
                </Button>
              </div>
            )}
          </div>
        ))}
      </div>
    </Card>
  );
}

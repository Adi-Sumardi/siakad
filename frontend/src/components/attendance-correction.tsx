"use client";

import { useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { api, ApiError } from "@/lib/api";

const OPTIONS = [
  { value: "hadir", label: "Hadir" },
  { value: "terlambat", label: "Terlambat" },
  { value: "sakit", label: "Sakit" },
  { value: "izin", label: "Izin" },
  { value: "alpa", label: "Alpa" },
] as const;

type Row = { ulid: string; nama_lengkap: string; attendance_status: string | null; is_late: boolean };

/**
 * One row's re-mark control for the daily boards (audit 6 Okt 2026 #1).
 * Re-marking supersedes on the server - the old record stays on file as
 * revoked - so this is a correction, never a delete. A past day requires a
 * reason; the server enforces the same rule.
 */
export function CorrectionCell({
  session,
  row,
  requireReason,
  endpoint,
  onDone,
}: {
  session: { type: "masuk" | "pulang" };
  row: Row;
  requireReason: boolean;
  endpoint: string;
  onDone: () => void;
}) {
  const current = row.attendance_status === "hadir" && row.is_late ? "terlambat" : (row.attendance_status ?? "");
  const [status, setStatus] = useState("");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);

  // A pulang window only accepts hadir/terlambat (server rule T65-e).
  const options = session.type === "pulang" ? OPTIONS.filter((o) => o.value === "hadir" || o.value === "terlambat") : OPTIONS;
  const dirty = status !== "" && status !== current;

  async function submit() {
    if (requireReason && !reason.trim()) {
      toast.error("Isi alasan koreksi dulu.");
      return;
    }
    setBusy(true);
    try {
      await api.post(endpoint, { student_ulid: row.ulid, status, description: reason.trim() || undefined });
      toast.success(`Presensi ${row.nama_lengkap} diperbarui.`);
      setStatus("");
      setReason("");
      onDone();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyimpan koreksi.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <select
        value={status || current}
        onChange={(e) => setStatus(e.target.value)}
        disabled={busy}
        aria-label={`Ubah status ${row.nama_lengkap}`}
        className="h-8 rounded-lg border border-input bg-card px-1.5 text-xs"
      >
        {!current && <option value="">— pilih —</option>}
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
      {dirty && (
        <>
          <Input
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder={requireReason ? "Alasan (wajib)" : "Alasan (opsional)"}
            maxLength={500}
            className="h-8 w-40 text-xs"
          />
          <Button size="sm" onClick={submit} disabled={busy}>
            {busy ? "…" : "Simpan"}
          </Button>
        </>
      )}
    </div>
  );
}

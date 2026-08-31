"use client";

import { use, useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, CheckCircle2, X } from "lucide-react";
import { QRCodeSVG } from "qrcode.react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { tanggalWaktu } from "@/lib/format";
import { ATTENDANCE_STATUS_LABEL, type AttendanceRosterEntry, type AttendanceStatus } from "@/lib/types/kesiswaan";

type RosterResponse = {
  session: { ulid: string; is_open: boolean; expires_at: string };
  checkin_url: string;
  students: AttendanceRosterEntry[];
};

const MANUAL_STATUS_OPTIONS: AttendanceStatus[] = ["sakit", "izin", "alpa", "hadir"];

function RevokeDialog({
  record, onClose, onConfirm,
}: {
  record: AttendanceRosterEntry;
  onClose: () => void;
  onConfirm: (reason: string) => Promise<void>;
}) {
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <Card className="w-full max-w-sm p-5">
        <h3 className="text-sm font-semibold">Batalkan presensi {record.nama_lengkap}?</h3>
        <p className="mt-1 text-xs text-muted-foreground">
          Dipakai kalau NIS ini dimasukkan orang lain, atau siswanya sebenarnya tidak hadir. Wajib diisi alasannya.
        </p>
        <textarea
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder="Contoh: siswa tidak hadir secara fisik di kelas."
          className="mt-3 h-20 w-full rounded-lg border border-input bg-card p-2.5 text-sm"
        />
        <div className="mt-3 flex justify-end gap-2">
          <Button size="sm" variant="ghost" onClick={onClose}>Batal</Button>
          <Button
            size="sm"
            variant="destructive"
            disabled={!reason.trim() || submitting}
            onClick={async () => {
              setSubmitting(true);
              await onConfirm(reason);
              setSubmitting(false);
            }}
          >
            Batalkan
          </Button>
        </div>
      </Card>
    </div>
  );
}

export default function AttendanceSessionPanel({ params }: { params: Promise<{ sessionUlid: string }> }) {
  const { sessionUlid } = use(params);

  const [roster, setRoster] = useState<RosterResponse | null>(null);
  const [revokeTarget, setRevokeTarget] = useState<AttendanceRosterEntry | null>(null);
  const [completing, setCompleting] = useState(false);
  const [manualStatus, setManualStatus] = useState<Record<string, AttendanceStatus>>({});

  function loadRoster() {
    api
      .get<RosterResponse>(`/api/guru/attendance-sessions/${sessionUlid}/roster`)
      .then(setRoster)
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat presensi."));
  }

  useEffect(() => {
    loadRoster();
    const interval = setInterval(loadRoster, 4000);
    return () => clearInterval(interval);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sessionUlid]);

  async function revoke(record: AttendanceRosterEntry, reason: string) {
    if (!record.record_ulid) return;
    try {
      await api.patch(`/api/guru/attendance-sessions/${sessionUlid}/records/${record.record_ulid}/revoke`, { reason });
      toast.success("Presensi dibatalkan.");
      setRevokeTarget(null);
      loadRoster();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal membatalkan presensi.");
    }
  }

  async function complete() {
    setCompleting(true);
    const unmarked = (roster?.students ?? []).filter((s) => !s.attendance_status);
    const records = unmarked.map((s) => ({ student_ulid: s.ulid, status: manualStatus[s.ulid] ?? "alpa" }));

    try {
      await api.post(`/api/guru/attendance-sessions/${sessionUlid}/complete`, { records });
      toast.success("Presensi diselesaikan.");
      loadRoster();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyelesaikan presensi.");
    } finally {
      setCompleting(false);
    }
  }

  const students = roster?.students ?? [];
  const checkedIn = students.filter((s) => s.attendance_status);
  const notYet = students.filter((s) => !s.attendance_status);

  return (
    <div className="flex flex-col gap-5">
      <Link href="/guru" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" />
        Kelas saya
      </Link>

      <div>
        <h1 className="text-xl font-bold tracking-tight">Presensi berjalan</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {roster ? `${checkedIn.length} dari ${students.length} siswa sudah check-in` : "Memuat…"}
          {roster && !roster.session.is_open && " · Sesi sudah ditutup"}
        </p>
      </div>

      <Card className="flex flex-col items-center gap-3 p-6">
        <p className="text-xs font-medium text-muted-foreground">Siswa scan kode ini dengan HP mereka</p>
        {roster ? <QRCodeSVG value={roster.checkin_url} size={200} /> : <Skeleton className="size-50" />}
      </Card>

      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <h2 className="mb-2 text-sm font-semibold text-good">Sudah hadir ({checkedIn.length})</h2>
          <div className="flex flex-col gap-1.5">
            {roster === null && <Skeleton className="h-24 w-full" />}
            {checkedIn.map((s) => (
              <Card key={s.ulid} className="flex items-center justify-between gap-2 p-3">
                <div className="flex items-center gap-2">
                  <CheckCircle2 className="size-4 text-good shrink-0" />
                  <div>
                    <p className="text-sm font-medium">{s.nama_lengkap}</p>
                    <p className="text-xs text-muted-foreground">
                      {s.checked_in_at && tanggalWaktu(s.checked_in_at)} · {s.source === "self" ? "check-in sendiri" : "dicatat guru"}
                    </p>
                  </div>
                </div>
                <Button size="sm" variant="ghost" onClick={() => setRevokeTarget(s)}>
                  <X className="size-4" />
                </Button>
              </Card>
            ))}
            {roster !== null && checkedIn.length === 0 && (
              <p className="text-sm text-muted-foreground">Belum ada yang check-in.</p>
            )}
          </div>
        </div>

        <div>
          <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Belum check-in ({notYet.length})</h2>
          <div className="flex flex-col gap-1.5">
            {notYet.map((s) => (
              <Card key={s.ulid} className="flex items-center justify-between gap-2 p-3">
                <p className="text-sm font-medium">{s.nama_lengkap}</p>
                <select
                  value={manualStatus[s.ulid] ?? "alpa"}
                  onChange={(e) => setManualStatus((prev) => ({ ...prev, [s.ulid]: e.target.value as AttendanceStatus }))}
                  className="h-8 rounded-lg border border-input bg-card px-2 text-xs"
                >
                  {MANUAL_STATUS_OPTIONS.map((status) => (
                    <option key={status} value={status}>{ATTENDANCE_STATUS_LABEL[status]}</option>
                  ))}
                </select>
              </Card>
            ))}
            {roster !== null && notYet.length === 0 && (
              <p className="text-sm text-muted-foreground">Semua siswa sudah tercatat.</p>
            )}
          </div>
        </div>
      </div>

      {roster?.session.is_open && (
        <div className="fixed inset-x-0 bottom-0 border-t border-border bg-card/95 p-4 backdrop-blur-sm">
          <div className="mx-auto flex max-w-3xl items-center justify-between">
            <p className="text-sm text-muted-foreground">
              Sisa {notYet.length} siswa akan ditandai sesuai pilihan di atas.
            </p>
            <Button onClick={complete} disabled={completing}>
              {completing ? "Menyelesaikan…" : "Selesaikan Presensi"}
            </Button>
          </div>
        </div>
      )}

      {revokeTarget && (
        <RevokeDialog
          record={revokeTarget}
          onClose={() => setRevokeTarget(null)}
          onConfirm={(reason) => revoke(revokeTarget, reason)}
        />
      )}
    </div>
  );
}

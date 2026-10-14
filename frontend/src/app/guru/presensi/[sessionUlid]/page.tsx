"use client";

import { use, useEffect, useState, useSyncExternalStore } from "react";
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
  checkin_path: string;
  daily_enabled: boolean;
  students: AttendanceRosterEntry[];
};

const MANUAL_STATUS_OPTIONS: AttendanceStatus[] = ["sakit", "izin", "alpa", "hadir"];

/**
 * The API returns only the check-in PATH (/presensi/{token}): the server
 * cannot know which origin reaches the app for a given caller (localhost vs
 * an ngrok tunnel host in the 2026-09-15 field test). This page is already
 * on an origin that reaches the app, so it prefixes its own.
 */
function publicCheckinUrl(path: string): string {
  return window.location.origin + path;
}

/**
 * The roll-call screen's ONE QR - the same 30-second HMAC window the gate
 * uses (RotatingQrService), carrying the session URL too: scanning it with
 * the phone's own camera opens the check-in page AND delivers the fresh
 * window code in the URL hash, so there is no separate static URL QR to
 * share (a photographed one outlives the lesson; this one dies in ±a
 * minute). The 8 characters below are the manual-typing fallback.
 */
function RotatingQrPanel({ sessionUlid, checkinUrl }: { sessionUlid: string; checkinUrl: string }) {
  const [qr, setQr] = useState<{ code: string; rotates_in: number } | null>(null);
  const [closed, setClosed] = useState(false);

  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let cancelled = false;

    const tick = () => {
      api
        .get<{ code: string; rotates_in: number }>(`/api/guru/attendance-sessions/${sessionUlid}/rotating-qr`)
        .then((d) => {
          if (cancelled) return;
          setQr(d);
          timer = setTimeout(tick, Math.max(3, d.rotates_in) * 1000);
        })
        .catch((err) => {
          if (cancelled) return;
          if (err instanceof ApiError && err.status === 410) {
            setClosed(true);
            return;
          }
          timer = setTimeout(tick, 15000);
        });
    };

    tick();

    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [sessionUlid]);

  if (closed) {
    return (
      <div className="flex items-center justify-center rounded-lg border border-border p-6 text-sm text-muted-foreground">
        Sesi sudah ditutup — QR tidak lagi diterbitkan.
      </div>
    );
  }

  return (
    <Card className="flex flex-col items-center gap-2 p-6">
      <p className="text-xs font-medium text-muted-foreground">
        Siswa scan QR ini dengan kamera HP — halaman presensi terbuka langsung
      </p>
      {qr ? (
        <>
          <QRCodeSVG value={`${checkinUrl}#${qr.code}`} size={208} className="rounded-lg bg-white p-2" />
          <p className="font-mono text-lg font-bold tracking-[0.25em]">{qr.code}</p>
          <p className="text-xs text-muted-foreground">
            Berganti otomatis tiap ±{qr.rotates_in} detik — biarkan halaman ini terbuka. Kode 8 karakter di atas
            untuk siswa yang kamera HP-nya bermasalah.
          </p>
        </>
      ) : (
        <Skeleton className="h-52 w-52" />
      )}
    </Card>
  );
}

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

type Mode = "cepat" | "qr";

/**
 * The teacher's last-picked mode, remembered per device. Quick is the
 * default: the gate already proved who is at school, so the lesson roster
 * starts from that and the teacher only corrects who is not in the room -
 * no phones out, no queue at the start of every period. QR stays one tap
 * away for a teacher who wants proof-of-presence (an exam, a class that
 * skips).
 */
const MODE_KEY = "presensi-mapel-mode";
const modeListeners = new Set<() => void>();

function readMode(): Mode {
  try {
    return localStorage.getItem(MODE_KEY) === "qr" ? "qr" : "cepat";
  } catch {
    return "cepat";
  }
}

function writeMode(mode: Mode) {
  try {
    localStorage.setItem(MODE_KEY, mode);
  } catch {
    // Private mode / blocked storage: the pick just isn't remembered.
  }
  modeListeners.forEach((listener) => listener());
}

function subscribeMode(listener: () => void) {
  modeListeners.add(listener);
  return () => {
    modeListeners.delete(listener);
  };
}

const QUICK_OPTIONS: AttendanceStatus[] = ["hadir", "sakit", "izin", "alpa"];

const QUICK_ACTIVE: Record<AttendanceStatus, string> = {
  hadir: "bg-good text-white hover:bg-good/90",
  sakit: "bg-warn text-white hover:bg-warn/90",
  izin: "bg-info text-white hover:bg-info/90",
  alpa: "bg-bad text-white hover:bg-bad/90",
};

/** What the gate says about one child, in words a teacher scans in a glance. */
function gateLabel(s: AttendanceRosterEntry): string {
  if (!s.daily_status) return "belum tercatat di gerbang";
  if (s.daily_status === "hadir") return s.daily_is_late ? "gerbang: terlambat" : "gerbang: hadir";
  return `gerbang: ${ATTENDANCE_STATUS_LABEL[s.daily_status].toLowerCase()}`;
}

export default function AttendanceSessionPanel({ params }: { params: Promise<{ sessionUlid: string }> }) {
  const { sessionUlid } = use(params);

  const [roster, setRoster] = useState<RosterResponse | null>(null);
  const [revokeTarget, setRevokeTarget] = useState<AttendanceRosterEntry | null>(null);
  const [completing, setCompleting] = useState(false);
  const [manualStatus, setManualStatus] = useState<Record<string, AttendanceStatus>>({});
  // Quick mode's unsaved picks; anything not picked shows its saved status
  // or, when blank, the gate's pre-fill.
  const [picks, setPicks] = useState<Record<string, AttendanceStatus>>({});
  const mode = useSyncExternalStore(subscribeMode, readMode, (): Mode => "cepat");

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

  async function submit(records: { student_ulid: string; status: AttendanceStatus }[]) {
    setCompleting(true);
    try {
      await api.post(`/api/guru/attendance-sessions/${sessionUlid}/complete`, { records });
      toast.success("Presensi tersimpan.");
      setPicks({});
      loadRoster();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyimpan presensi.");
    } finally {
      setCompleting(false);
    }
  }

  const students = roster?.students ?? [];
  const isOpen = roster?.session.is_open ?? false;

  // --- Quick mode ---------------------------------------------------------
  // Blank rows start from the gate: hadir/terlambat -> hadir, sakit/izin/alpa
  // carried over. No gate mark means "not at school" only when the unit
  // actually runs the daily layer AND the gate has marked someone in this
  // class today; a class with no gate marks at all means the gate data is
  // simply not there (not run yet, or not used that day) - pre-filling the
  // whole room alpa made the teacher tap every child back to hadir.
  const gateHasData = students.some((s) => s.daily_status !== null);
  const gateDriven = !!roster?.daily_enabled && gateHasData;
  const suggest = (s: AttendanceRosterEntry): AttendanceStatus => s.daily_status ?? (gateDriven ? "alpa" : "hadir");
  const effective = (s: AttendanceRosterEntry): AttendanceStatus =>
    picks[s.ulid] ?? s.attendance_status ?? suggest(s);
  const pending = students.filter((s) => effective(s) !== s.attendance_status);
  const quickTally = QUICK_OPTIONS.map((status) => ({
    status,
    count: students.filter((s) => effective(s) === status).length,
  }));

  // --- QR mode ------------------------------------------------------------
  // A completed (or auto-closed) session gives everyone a status - only a
  // hadir belongs under "Sudah hadir", or an alpa shows up with a green tick.
  const checkedIn = students.filter((s) => s.attendance_status === "hadir");
  const absent = students.filter((s) => s.attendance_status && s.attendance_status !== "hadir");
  const notYet = students.filter((s) => !s.attendance_status);
  // The server accepts completion on a closed session too: that is how a
  // finished period ("Lengkapi") or a revoked auto-alpa gets re-marked.
  const canCompleteQr = roster !== null && (isOpen || notYet.length > 0);

  return (
    <div className="flex flex-col gap-5">
      <Link href="/guru" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" />
        Kelas saya
      </Link>

      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold tracking-tight">Presensi mapel</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {roster === null
              ? "Memuat…"
              : mode === "cepat"
                ? `${students.length} siswa · ${pending.length > 0 ? `${pending.length} belum disimpan` : "semua tersimpan"}`
                : `${checkedIn.length} dari ${students.length} siswa sudah check-in`}
            {roster && !isOpen && " · Sesi sudah ditutup"}
          </p>
        </div>
        <div role="tablist" aria-label="Cara presensi" className="inline-flex rounded-lg border border-border p-0.5">
          {(["cepat", "qr"] as const).map((m) => (
            <button
              key={m}
              type="button"
              role="tab"
              aria-selected={mode === m}
              onClick={() => writeMode(m)}
              className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                mode === m ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground"
              }`}
            >
              {m === "cepat" ? "Mode Cepat" : "Mode QR"}
            </button>
          ))}
        </div>
      </div>

      {roster === null && <Skeleton className="h-64 w-full" />}

      {roster !== null && mode === "cepat" && (
        <>
          <Card className="p-3 text-xs text-muted-foreground">
            {gateDriven
              ? "Status awal diambil dari presensi gerbang pagi ini. Lihat ke kelas, ubah siswa yang tidak ada, lalu simpan."
              : roster.daily_enabled
                ? "Belum ada data presensi gerbang untuk kelas ini hari ini, jadi semua siswa diawali Hadir. Ubah siswa yang tidak ada, lalu simpan."
                : "Presensi harian unit ini belum aktif, jadi semua siswa diawali Hadir. Ubah siswa yang tidak ada, lalu simpan."}
          </Card>

          <div className="flex flex-wrap gap-1.5 text-xs">
            {quickTally.map(({ status, count }) => (
              <span key={status} className="rounded-full bg-muted px-2.5 py-1 font-medium">
                {ATTENDANCE_STATUS_LABEL[status]} {count}
              </span>
            ))}
          </div>

          <div className="flex flex-col gap-1.5">
            {students.map((s) => {
              const current = effective(s);
              const unsaved = current !== s.attendance_status;
              return (
                <Card
                  key={s.ulid}
                  className={`flex flex-wrap items-center justify-between gap-2 p-2.5 ${unsaved ? "border-primary/40" : ""}`}
                >
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium">{s.nama_lengkap}</p>
                    <p className="text-xs text-muted-foreground">
                      {s.attendance_status
                        ? `tersimpan: ${ATTENDANCE_STATUS_LABEL[s.attendance_status].toLowerCase()}${s.source === "self" ? " (scan QR)" : ""}`
                        : gateLabel(s)}
                      {unsaved && <span className="text-primary"> · belum disimpan</span>}
                    </p>
                  </div>
                  <div className="flex gap-1" role="radiogroup" aria-label={`Status ${s.nama_lengkap}`}>
                    {QUICK_OPTIONS.map((status) => (
                      <button
                        key={status}
                        type="button"
                        role="radio"
                        aria-checked={current === status}
                        onClick={() => setPicks((prev) => ({ ...prev, [s.ulid]: status }))}
                        className={`h-8 min-w-11 rounded-md px-2 text-xs font-medium transition-colors ${
                          current === status ? QUICK_ACTIVE[status] : "bg-muted/60 text-muted-foreground hover:bg-muted"
                        }`}
                      >
                        {ATTENDANCE_STATUS_LABEL[status]}
                      </button>
                    ))}
                  </div>
                </Card>
              );
            })}
            {students.length === 0 && <p className="text-sm text-muted-foreground">Belum ada siswa aktif di kelas ini.</p>}
          </div>

          <div className="sticky bottom-3 z-10 rounded-xl border border-border bg-card/95 p-3 shadow-sm backdrop-blur-sm">
            <div className="flex items-center justify-between gap-3">
              <p className="text-sm text-muted-foreground">
                {pending.length > 0 ? `${pending.length} siswa akan disimpan.` : "Tidak ada perubahan."}
              </p>
              <Button
                onClick={() => submit(pending.map((s) => ({ student_ulid: s.ulid, status: effective(s) })))}
                disabled={completing || (!isOpen && pending.length === 0)}
              >
                {completing ? "Menyimpan…" : "Simpan Presensi"}
              </Button>
            </div>
          </div>
        </>
      )}

      {roster !== null && mode === "qr" && (
        <>
          {isOpen ? (
            <RotatingQrPanel sessionUlid={sessionUlid} checkinUrl={publicCheckinUrl(roster.checkin_path)} />
          ) : (
            <Card className="flex flex-col items-center gap-3 p-6">
              <p className="text-xs font-medium text-muted-foreground">Sesi ditutup — QR tidak lagi diterbitkan</p>
            </Card>
          )}

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <h2 className="mb-2 text-sm font-semibold text-good">Sudah hadir ({checkedIn.length})</h2>
              <div className="flex flex-col gap-1.5">
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
                    <Button size="sm" variant="ghost" onClick={() => setRevokeTarget(s)} aria-label={`Batalkan presensi ${s.nama_lengkap}`}>
                      <X className="size-4" />
                    </Button>
                  </Card>
                ))}
                {checkedIn.length === 0 && <p className="text-sm text-muted-foreground">Belum ada yang check-in.</p>}
              </div>
            </div>

            <div className="flex flex-col gap-4">
              {absent.length > 0 && (
                <div>
                  <h2 className="mb-2 text-sm font-semibold text-bad">Tidak hadir ({absent.length})</h2>
                  <div className="flex flex-col gap-1.5">
                    {absent.map((s) => (
                      <Card key={s.ulid} className="flex items-center justify-between gap-2 p-3">
                        <div>
                          <p className="text-sm font-medium">{s.nama_lengkap}</p>
                          <p className="text-xs text-muted-foreground">
                            {s.attendance_status && ATTENDANCE_STATUS_LABEL[s.attendance_status]} · batalkan untuk menandai ulang
                          </p>
                        </div>
                        <Button size="sm" variant="ghost" onClick={() => setRevokeTarget(s)} aria-label={`Batalkan status ${s.nama_lengkap}`}>
                          <X className="size-4" />
                        </Button>
                      </Card>
                    ))}
                  </div>
                </div>
              )}

              <div>
                <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Belum tercatat ({notYet.length})</h2>
                <div className="flex flex-col gap-1.5">
                  {notYet.map((s) => (
                    <Card key={s.ulid} className="flex items-center justify-between gap-2 p-3">
                      <p className="text-sm font-medium">{s.nama_lengkap}</p>
                      <select
                        value={manualStatus[s.ulid] ?? "alpa"}
                        onChange={(e) => setManualStatus((prev) => ({ ...prev, [s.ulid]: e.target.value as AttendanceStatus }))}
                        aria-label={`Status ${s.nama_lengkap}`}
                        className="h-8 rounded-lg border border-input bg-card px-2 text-xs"
                      >
                        {MANUAL_STATUS_OPTIONS.map((status) => (
                          <option key={status} value={status}>{ATTENDANCE_STATUS_LABEL[status]}</option>
                        ))}
                      </select>
                    </Card>
                  ))}
                  {notYet.length === 0 && <p className="text-sm text-muted-foreground">Semua siswa sudah tercatat.</p>}
                </div>
              </div>
            </div>
          </div>

          {canCompleteQr && (
            <div className="sticky bottom-3 z-10 rounded-xl border border-border bg-card/95 p-3 shadow-sm backdrop-blur-sm">
              <div className="flex items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">Sisa {notYet.length} siswa akan ditandai sesuai pilihan di atas.</p>
                <Button
                  onClick={() => submit(notYet.map((s) => ({ student_ulid: s.ulid, status: manualStatus[s.ulid] ?? "alpa" })))}
                  disabled={completing}
                >
                  {completing ? "Menyimpan…" : isOpen ? "Selesaikan Presensi" : "Simpan Presensi"}
                </Button>
              </div>
            </div>
          )}
        </>
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

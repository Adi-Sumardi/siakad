"use client";

import { use, useEffect, useRef, useState } from "react";
import { CheckCircle2, ImageUp, ScanLine, School } from "lucide-react";
import { decodeQrFromFile, deviceId, useQrScanner } from "@/lib/absen-qr";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";

/**
 * The per-lesson check-in (SMP/SMA mapel). Same shape as the gate page, on
 * purpose: the static token URL in the QR that OPENS this page is no longer
 * the credential - the rotating code on the teacher's roll-call screen is.
 * A URL shared to the class group gets you to the form; without seeing the
 * teacher's screen within the last ~60 seconds, the check-in refuses. The
 * NIS is typed first (no deadline), the rotating QR is scanned last and
 * submitted the instant it reads - the gate flow's field-tested order.
 */

type SessionInfo = { subject: string; classroom: string; start_time: string; end_time: string };
type LookupResult = { student: { nama_panggilan: string }; already_checked_in: boolean };

type Screen =
  | { step: "loading" }
  | { step: "closed" }
  | { step: "idle" }
  | { step: "confirm"; nis: string; name: string }
  | { step: "scan"; nis: string; name: string }
  | { step: "already"; name: string }
  | { step: "success"; name: string }
  | { step: "not_found" };

export default function PresensiPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = use(params);

  const [session, setSession] = useState<SessionInfo | null>(null);
  const [screen, setScreen] = useState<Screen>({ step: "loading" });
  const [nis, setNis] = useState("");
  const [manualCode, setManualCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const inputRef = useRef<HTMLInputElement>(null);

  // A native-camera scan of the teacher's rotating QR opens this page with
  // the window code in the URL hash - read it up front (it never affects
  // the first paint, both server and client render the loading screen), and
  // clear it below so a refresh never resubmits a code that has since
  // expired. A slow NIS typer may still outrun the ~60 s code life; that
  // path falls through to the in-page scanner.
  const [presetCode, setPresetCode] = useState<string | null>(() => {
    if (typeof window === "undefined") return null;
    const hash = window.location.hash.replace(/^#/, "").trim().toUpperCase();

    return /^[0-9A-F]{8}$/.test(hash) ? hash : null;
  });

  useEffect(() => {
    if (window.location.hash) {
      history.replaceState(null, "", window.location.pathname);
    }
  }, []);

  // Same stale-closure dance as the gate page: the scanner submits the
  // moment it reads a code, through a ref that always sees the latest screen.
  const screenRef = useRef(screen);
  const submitRef = useRef<(code: string) => Promise<void>>(async () => {});

  const { videoRef, cameraError, rearm } = useQrScanner(screen.step === "scan", (code) => {
    const current = screenRef.current;
    if (current.step === "scan") submitRef.current(code);
  });

  useEffect(() => {
    api
      .get<SessionInfo>(`/api/presensi/${token}`)
      .then((d) => {
        setSession(d);
        setScreen({ step: "idle" });
      })
      // 404 (unknown token) and 410 (closed/expired session) both land the
      // visitor on the same "can't check in here" screen - neither is
      // something a student can act on themselves.
      .catch(() => setScreen({ step: "closed" }));
  }, [token]);

  useEffect(() => {
    if (screen.step === "idle" || screen.step === "not_found") {
      inputRef.current?.focus();
    }
  }, [screen.step]);

  async function submitNis(e: React.FormEvent) {
    e.preventDefault();
    if (!nis.trim() || busy) return;
    setBusy(true);
    setError("");

    try {
      const result = await api.post<LookupResult>(`/api/presensi/${token}/lookup`, { nis });
      if (result.already_checked_in) {
        setScreen({ step: "already", name: result.student.nama_panggilan });
      } else {
        setScreen({ step: "confirm", nis, name: result.student.nama_panggilan });
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        setScreen({ step: "not_found" });
      } else {
        setError(err instanceof ApiError ? err.message : "Gagal memeriksa NIS.");
      }
    } finally {
      setBusy(false);
      setNis("");
    }
  }

  /** The one write - fires the instant a code arrives, from camera, gallery, keyboard or the native-scan hash. */
  async function submitCheckIn(code: string, confirmedNis: string, name: string) {
    setBusy(true);
    setError("");

    // The teacher's QR carries "{url}#{code}" - the in-page camera and the
    // gallery decoder hand that whole string back; only the code after the
    // last # is the credential.
    const normalized = code.includes("#") ? (code.split("#").pop() ?? code) : code;

    try {
      const result = await api.post<{ student: { nama_panggilan: string } }>(`/api/presensi/${token}/check-in`, {
        nis: confirmedNis,
        qr_code: normalized,
        device_id: deviceId(),
      });

      setScreen({ step: "success", name: result.student.nama_panggilan });
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setScreen({ step: "already", name });
      } else if (screenRef.current.step === "scan") {
        // Show it right on the scan step and re-arm the camera - an expired
        // window just means "point at the screen again".
        setError(err instanceof ApiError ? err.message : "Presensi gagal diproses. Coba lagi.");
        rearm();
      } else {
        // Most often a stale preset code from the native-camera scan: drop
        // to the in-page scanner so the student re-reads the screen.
        setPresetCode(null);
        setError(err instanceof ApiError ? err.message : "Presensi gagal diproses. Coba lagi.");
        setScreen({ step: "scan", nis: confirmedNis, name });
      }
    } finally {
      setBusy(false);
    }
  }

  useEffect(() => {
    screenRef.current = screen;
    submitRef.current = async (code: string) => {
      const current = screenRef.current;
      if (current.step === "scan") {
        await submitCheckIn(code, current.nis, current.name);
      }
    };
  });

  async function onPickPhoto(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file || busy || screen.step !== "scan") return;

    setBusy(true);
    setError("");

    const code = await decodeQrFromFile(file);

    if (!code) {
      setError("Kode QR tidak terbaca dari foto. Pastikan QR memenuhi bidang foto, jelas dan terang.");
      setBusy(false);
      e.target.value = "";
      return;
    }

    await submitCheckIn(code, screen.nis, screen.name);
    e.target.value = "";
  }

  const errorBox = error && (
    <p role="alert" className="rounded-lg bg-bad/10 px-3 py-2 text-sm text-bad">
      {error}
    </p>
  );

  return (
    <div className="flex min-h-svh flex-col items-center justify-center bg-canvas p-5">
      <Card className="w-full max-w-sm p-6 text-center">
        <div className="mb-4 flex items-center justify-center gap-2 text-muted-foreground">
          <School className="size-4" />
          <span className="text-xs font-medium">Presensi Kehadiran</span>
        </div>

        {screen.step === "loading" && <Skeleton className="h-32 w-full" />}

        {screen.step === "closed" && (
          <p className="py-8 text-sm text-muted-foreground">
            Sesi presensi ini sudah ditutup atau tidak ditemukan. Hubungi guru Anda.
          </p>
        )}

        {session && (screen.step === "idle" || screen.step === "not_found") && (
          <>
            <h1 className="text-lg font-bold">{session.subject}</h1>
            <p className="mb-5 text-sm text-muted-foreground">
              {session.classroom} · {session.start_time.slice(0, 5)}–{session.end_time.slice(0, 5)}
            </p>

            <form onSubmit={submitNis} className="flex flex-col gap-3">
              <input
                ref={inputRef}
                value={nis}
                onChange={(e) => setNis(e.target.value)}
                placeholder="Masukkan NIS Anda"
                inputMode="numeric"
                autoFocus
                className="h-12 rounded-lg border border-input bg-card px-4 text-center text-lg tracking-wide"
              />
              <Button type="submit" size="lg" disabled={busy || !nis.trim()}>
                {busy ? "Memeriksa…" : "Lanjutkan"}
              </Button>
            </form>

            {errorBox}

            {screen.step === "not_found" && (
              <p className="mt-3 text-sm text-bad">NIS tidak ditemukan di kelas ini.</p>
            )}
          </>
        )}

        {screen.step === "confirm" && (
          <div className="flex flex-col gap-4">
            <p className="text-sm text-muted-foreground">Konfirmasi kehadiran</p>
            <p className="text-2xl font-bold">{screen.name}</p>
            <p className="text-xs text-muted-foreground">NIS {screen.nis}</p>
            {presetCode && (
              <p className="text-xs text-muted-foreground">
                Kode QR dari kamera sudah terbawa — cukup konfirmasi, tidak perlu scan lagi.
              </p>
            )}
            <div className="flex gap-2">
              <Button variant="outline" className="flex-1" onClick={() => setScreen({ step: "idle" })} disabled={busy}>
                Bukan saya
              </Button>
              <Button
                className="flex-1"
                disabled={busy}
                onClick={() => {
                  if (presetCode) {
                    submitCheckIn(presetCode, screen.nis, screen.name);
                  } else {
                    setScreen({ step: "scan", nis: screen.nis, name: screen.name });
                  }
                }}
              >
                {busy ? (
                  "Menyimpan…"
                ) : presetCode ? (
                  "Ya, ini saya"
                ) : (
                  <>
                    <ScanLine className="size-4" /> Ya, scan QR
                  </>
                )}
              </Button>
            </div>
          </div>
        )}

        {screen.step === "scan" && (
          <div className="flex flex-col gap-3">
            <div className="text-xs text-muted-foreground">
              Presensi untuk <strong className="text-foreground">{screen.name}</strong> · arahkan kamera ke QR di
              layar guru — hadir tercatat otomatis begitu terbaca.
            </div>

            <div className="relative mx-auto aspect-square w-full max-w-64 overflow-hidden rounded-xl bg-black">
              <video ref={videoRef} playsInline muted className="size-full object-cover" />
              <div className="pointer-events-none absolute inset-6 rounded-lg border-2 border-primary/70" />
            </div>

            {cameraError && (
              <p className="text-xs text-muted-foreground">
                Kamera tidak dapat diakses — gunakan pilih foto atau ketik kode manual di bawah.
              </p>
            )}

            {errorBox}

            <label>
              <input type="file" accept="image/*" className="hidden" onChange={onPickPhoto} />
              <span className="flex h-9 w-full items-center justify-center gap-1.5 rounded-lg border border-input text-sm font-medium hover:bg-accent">
                <ImageUp className="size-3.5" /> Kamera bermasalah? Pilih foto QR
              </span>
            </label>

            <form
              onSubmit={(e) => {
                e.preventDefault();
                if (manualCode.trim().length >= 8 && !busy) {
                  submitCheckIn(manualCode.trim().toUpperCase(), screen.nis, screen.name);
                }
              }}
              className="flex gap-2"
            >
              <Input
                value={manualCode}
                onChange={(e) => setManualCode(e.target.value.toUpperCase())}
                placeholder="ATAU KETIK 8 KODE DI LAYAR GURU"
                maxLength={8}
                className="h-9 text-center tracking-[0.2em]"
              />
              <Button type="submit" size="sm" disabled={busy || manualCode.trim().length < 8}>
                {busy ? "…" : "Hadir"}
              </Button>
            </form>

            <button
              type="button"
              className="text-sm text-muted-foreground hover:text-foreground"
              onClick={() => setScreen({ step: "idle" })}
              disabled={busy}
            >
              ← Ganti NIS
            </button>
          </div>
        )}

        {screen.step === "success" && (
          <div className="flex flex-col items-center gap-3 py-6">
            <CheckCircle2 className="size-12 text-good" />
            <p className="text-lg font-bold">Berhasil dicatat hadir</p>
            <p className="text-sm text-muted-foreground">{screen.name}</p>
          </div>
        )}

        {screen.step === "already" && (
          <div className="flex flex-col items-center gap-3 py-6">
            <CheckCircle2 className="size-12 text-muted-foreground" />
            <p className="text-sm font-medium">Sudah tercatat hadir sebelumnya.</p>
            {screen.name && <p className="text-sm text-muted-foreground">{screen.name}</p>}
          </div>
        )}
      </Card>
    </div>
  );
}

"use client";

import { use, useEffect, useRef, useState } from "react";
import { CheckCircle2, School } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";

type SessionInfo = { subject: string; classroom: string; start_time: string; end_time: string };
type LookupResult = { student: { nama_panggilan: string }; already_checked_in: boolean };

type Screen =
  | { step: "loading" }
  | { step: "closed" }
  | { step: "idle" }
  | { step: "confirm"; nis: string; name: string }
  | { step: "already"; name: string }
  | { step: "success"; name: string }
  | { step: "not_found" };

export default function PresensiPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = use(params);

  const [session, setSession] = useState<SessionInfo | null>(null);
  const [screen, setScreen] = useState<Screen>({ step: "loading" });
  const [nis, setNis] = useState("");
  const [busy, setBusy] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

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
        setScreen({ step: "idle" });
      }
    } finally {
      setBusy(false);
      setNis("");
    }
  }

  async function confirmCheckIn(confirmedNis: string) {
    setBusy(true);
    try {
      const result = await api.post<{ student: { nama_panggilan: string } }>(`/api/presensi/${token}/check-in`, {
        nis: confirmedNis,
      });
      setScreen({ step: "success", name: result.student.nama_panggilan });
      setTimeout(() => setScreen({ step: "idle" }), 2500);
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setScreen({ step: "already", name: "" });
        setTimeout(() => setScreen({ step: "idle" }), 2000);
      } else {
        setScreen({ step: "idle" });
      }
    } finally {
      setBusy(false);
    }
  }

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

            {screen.step === "not_found" && (
              <p className="mt-3 text-sm text-bad">NIS tidak ditemukan di kelas ini.</p>
            )}
          </>
        )}

        {screen.step === "confirm" && (
          <div className="flex flex-col gap-4">
            <p className="text-sm text-muted-foreground">Konfirmasi kehadiran</p>
            <p className="text-2xl font-bold">{screen.name}</p>
            <div className="flex gap-2">
              <Button variant="outline" className="flex-1" onClick={() => setScreen({ step: "idle" })}>
                Bukan saya
              </Button>
              <Button className="flex-1" disabled={busy} onClick={() => confirmCheckIn(screen.nis)}>
                {busy ? "Menyimpan…" : "Ya, ini saya"}
              </Button>
            </div>
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
          </div>
        )}
      </Card>
    </div>
  );
}

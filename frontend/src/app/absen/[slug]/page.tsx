"use client";

import { use, useEffect, useRef, useState } from "react";
import { AlertTriangle, CheckCircle2, Clock, ImageUp, MapPin, QrCode, ScanLine, School } from "lucide-react";
import { decodeQrFromFile, deviceId, getPosition, useQrScanner } from "@/lib/absen-qr";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";

/**
 * The public gate check-in (DESAIN-PRESENSI-HARIAN.md §5D). No login: the
 * unit's slug in the URL is the credential.
 *
 * Flow order is deliberate: everything WITHOUT a deadline happens first
 * (NIS, confirming the nickname), and the rotating QR is scanned LAST and
 * submitted the instant it is read - the code only stays valid ~60 s, so the
 * earlier scan-first flow let a slow NIS typer time their own code out and
 * bounce back to the scanner. A gallery photo can be decoded the same way:
 * a shared photo dies with the rotation within a minute and the GPS radius
 * still pins the sender's friend to the gate, so it is a fallback for bad
 * cameras, not a hole.
 */

type GateInfo = {
  unit_label: string;
  state: "open" | "closed" | "inactive" | "wrong_mode" | "no_session";
  session: { type: string; opens_at: string; closes_at: string; late_after: string | null } | null;
  geo: { required: boolean; gate_lat: number; gate_lng: number; radius_m: number };
  qr_required: boolean;
};

type Screen =
  | { step: "loading" }
  | { step: "unavailable"; reason: string }
  | { step: "intro" }
  | { step: "locating" }
  | { step: "geo-denied" }
  | { step: "nis" }
  | { step: "confirm"; nis: string; name: string }
  | { step: "scan"; nis: string; name: string }
  | { step: "success"; name: string; late: boolean; jam: string }
  | { step: "already"; name: string };

export default function GateCheckInPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = use(params);

  const [info, setInfo] = useState<GateInfo | null>(null);
  const [screen, setScreen] = useState<Screen>({ step: "loading" });
  const [position, setPosition] = useState<{ lat: number; lng: number; accuracy: number } | null>(null);
  const [nis, setNis] = useState("");
  const [manualCode, setManualCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  // The scanner needs to submit the moment it reads a code, but that closure
  // goes stale the moment state changes - the hook keeps the latest callback
  // behind a ref instead of re-running the stream for every keystroke.
  const screenRef = useRef(screen);
  const submitRef = useRef<(code: string) => Promise<void>>(async () => {});

  const { videoRef, cameraError, rearm } = useQrScanner(screen.step === "scan", (code) => {
    const current = screenRef.current;
    if (current.step === "scan") submitRef.current(code);
  });

  useEffect(() => {
    api
      .get<GateInfo>(`/api/absen/${slug}`)
      .then((d) => {
        setInfo(d);

        if (d.state !== "open") {
          const reason =
            d.state === "closed"
              ? `Sesi absen masuk sudah ditutup${d.session ? ` pukul ${d.session.closes_at} WIB` : ""}.`
              : d.state === "inactive"
                ? "Presensi harian unit ini belum diaktifkan."
                : d.state === "wrong_mode"
                  ? "Unit ini tidak memakai absen gerbang."
                  : "Belum ada sesi absen hari ini.";
          setScreen({ step: "unavailable", reason });
        } else {
          setScreen({ step: "intro" });
        }
      })
      .catch(() => setScreen({ step: "unavailable", reason: "Link absen tidak dikenali atau sudah tidak berlaku." }));
  }, [slug]);

  async function start() {
    setError("");

    if (!info?.geo.required) {
      setScreen({ step: "nis" });
      return;
    }

    setScreen({ step: "locating" });
    const pos = await getPosition();

    if (!pos) {
      setScreen({ step: "geo-denied" });
      return;
    }

    setPosition(pos);
    setScreen({ step: "nis" });
  }

  async function lookup(e: React.FormEvent) {
    e.preventDefault();
    if (!nis.trim() || busy) return;
    setBusy(true);
    setError("");

    try {
      const result = await api.post<{ student: { nama_panggilan: string }; already_checked_in: boolean }>(
        `/api/absen/${slug}/lookup`,
        { nis },
      );

      if (result.already_checked_in) {
        setScreen({ step: "already", name: result.student.nama_panggilan });
      } else {
        setScreen({ step: "confirm", nis, name: result.student.nama_panggilan });
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        setError("NIS tidak ditemukan di unit ini.");
      } else {
        setError(err instanceof ApiError ? err.message : "Gagal memeriksa NIS.");
      }
    } finally {
      setBusy(false);
    }
  }

  /** The one write - fires the instant a code arrives, from camera, gallery or keyboard. */
  async function submitCheckIn(code: string, confirmedNis: string, name: string) {
    setBusy(true);
    setError("");

    try {
      const result = await api.post<{
        student: { nama_panggilan: string };
        is_late: boolean;
        checked_in_at: string;
      }>(`/api/absen/${slug}/check-in`, {
        nis: confirmedNis,
        qr_code: info?.qr_required && code ? code : undefined,
        lat: info?.geo.required && position ? position.lat : undefined,
        lng: info?.geo.required && position ? position.lng : undefined,
        accuracy: info?.geo.required && position ? position.accuracy : undefined,
        device_id: deviceId(),
      });

      navigator.vibrate?.(200);
      setScreen({ step: "success", name, late: result.is_late, jam: result.checked_in_at });
    } catch (err) {
      // Whatever went wrong is shown right here on the scan step - never a
      // silent bounce back to another screen (the bug that made confirmations
      // "do nothing" in the first field tests). A rejected code (wrong QR,
      // expired window) re-arms the camera for another go.
      if (err instanceof ApiError && err.status === 409 && err.message.includes("Sudah tercatat")) {
        setScreen({ step: "already", name });
      } else {
        setError(err instanceof ApiError ? err.message : "Absen gagal diproses. Coba lagi.");
        rearm();
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
    <div className="flex min-h-svh flex-col items-center justify-center bg-canvas p-4">
      <Card className="w-full max-w-sm p-6 text-center">
        <div className="mb-4 flex items-center justify-center gap-2 text-muted-foreground">
          <School className="size-4" />
          <span className="text-xs font-medium">{info?.unit_label ?? "Absen Gerbang"}</span>
        </div>

        {screen.step === "loading" && <Skeleton className="h-32 w-full" />}

        {screen.step === "unavailable" && (
          <div className="flex flex-col items-center gap-3 py-6">
            <Clock className="size-10 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">{screen.reason}</p>
          </div>
        )}

        {screen.step === "intro" && (
          <div className="flex flex-col gap-4">
            <QrCode className="mx-auto size-12 text-primary" />
            <div>
              <h1 className="text-lg font-bold">Absen Masuk</h1>
              <p className="text-sm text-muted-foreground">
                {info?.session && `Jendela absen ${info.session.opens_at}–${info.session.closes_at} WIB`}
                {info?.session?.late_after && ` · terlambat lewat ${info.session.late_after}`}
              </p>
            </div>
            <ol className="flex list-decimal flex-col gap-1 pl-5 text-left text-xs text-muted-foreground">
              <li>Ketik NIS Anda</li>
              <li>Kenali nama panggilan Anda</li>
              {info?.qr_required && <li>Scan QR yang tampil di gerbang</li>}
            </ol>
            <Button size="lg" onClick={start}>
              {info?.geo.required ? "Mulai Absen" : "Lanjutkan"}
            </Button>
          </div>
        )}

        {screen.step === "locating" && (
          <div className="flex flex-col items-center gap-3 py-6">
            <MapPin className="size-10 animate-pulse text-primary" />
            <p className="text-sm text-muted-foreground">Memeriksa lokasi Anda…</p>
          </div>
        )}

        {screen.step === "geo-denied" && (
          <div className="flex flex-col gap-3">
            <AlertTriangle className="mx-auto size-10 text-warn" />
            <p className="text-sm text-muted-foreground">
              Izin lokasi ditolak. Absen gerbang unit ini memerlukan lokasi — aktifkan izin lokasi
              untuk browser ini, lalu coba lagi.
            </p>
            <Button onClick={start}>Coba Lagi</Button>
          </div>
        )}

        {screen.step === "nis" && (
          <form onSubmit={lookup} className="flex flex-col gap-3">
            <h1 className="text-lg font-bold">Masukkan NIS</h1>
            <p className="text-sm text-muted-foreground">Satu langkah dulu — QR di-scan paling akhir.</p>
            {errorBox}
            <Input
              value={nis}
              onChange={(e) => setNis(e.target.value)}
              placeholder="NIS"
              inputMode="numeric"
              autoFocus
              className="h-12 text-center text-lg tracking-wide"
            />
            <Button type="submit" size="lg" disabled={busy || !nis.trim()}>
              {busy ? "Memeriksa…" : "Lanjutkan"}
            </Button>
          </form>
        )}

        {screen.step === "confirm" && (
          <div className="flex flex-col gap-4">
            <p className="text-sm text-muted-foreground">Konfirmasi — ini Anda?</p>
            <p className="text-2xl font-bold">{screen.name}</p>
            <p className="text-xs text-muted-foreground">NIS {screen.nis}</p>
            <div className="flex gap-2">
              <Button variant="outline" className="flex-1" onClick={() => setScreen({ step: "nis" })} disabled={busy}>
                Bukan saya
              </Button>
              <Button
                className="flex-1"
                disabled={busy}
                onClick={() =>
                  setScreen({ step: "scan", nis: screen.nis, name: screen.name })
                }
              >
                <ScanLine className="size-4" /> Ya, scan QR
              </Button>
            </div>
          </div>
        )}

        {screen.step === "scan" && (
          <div className="flex flex-col gap-3">
            <div className="text-xs text-muted-foreground">
              Absen untuk <strong className="text-foreground">{screen.name}</strong> · arahkan kamera ke QR di gerbang —
              hadir tercatat otomatis begitu terbaca.
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
                placeholder="ATAU KETIK 8 KODE DI GERBANG"
                maxLength={8}
                className="h-9 text-center tracking-[0.2em]"
              />
              <Button type="submit" size="sm" disabled={busy || manualCode.trim().length < 8}>
                {busy ? "…" : "Absen"}
              </Button>
            </form>

            <button
              type="button"
              className="text-sm text-muted-foreground hover:text-foreground"
              onClick={() => setScreen({ step: "nis" })}
              disabled={busy}
            >
              ← Ganti NIS
            </button>
          </div>
        )}

        {screen.step === "success" && (
          <div className="flex flex-col items-center gap-3 py-6">
            <CheckCircle2 className={`size-12 ${screen.late ? "text-warn" : "text-good"}`} />
            <p className="text-lg font-bold">{screen.late ? "Tercatat hadir — terlambat" : "Berhasil dicatat hadir"}</p>
            <p className="text-sm text-muted-foreground">
              {screen.name} · pukul {screen.jam} WIB
            </p>
          </div>
        )}

        {screen.step === "already" && (
          <div className="flex flex-col items-center gap-3 py-6">
            <CheckCircle2 className="size-12 text-muted-foreground" />
            <p className="text-sm font-medium">Sudah tercatat hadir sebelumnya hari ini.</p>
            {screen.name && <p className="text-sm text-muted-foreground">{screen.name}</p>}
          </div>
        )}
      </Card>
    </div>
  );
}

"use client";

import { use, useEffect, useRef, useState } from "react";
import jsQR from "jsqr";
import { AlertTriangle, CheckCircle2, Clock, ImageUp, MapPin, QrCode, ScanLine, School } from "lucide-react";
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

function deviceId(): string {
  // The device-once rule's token. localStorage survives visits; incognito
  // starts fresh - which is exactly what the IP-sharing flag on the TU board
  // is there to catch (§6 layer 5).
  let id = localStorage.getItem("absen-device-id");
  if (!id) {
    id = crypto.randomUUID();
    localStorage.setItem("absen-device-id", id);
  }
  return id;
}

function getPosition(): Promise<{ lat: number; lng: number } | null> {
  return new Promise((resolve) => {
    if (!("geolocation" in navigator)) return resolve(null);

    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 },
    );
  });
}

/** Decodes a QR from a gallery photo onto the same code string a live scan yields. */
function decodeQrFromFile(file: File): Promise<string | null> {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file);
    const img = new Image();

    img.onload = () => {
      URL.revokeObjectURL(url);

      const scale = Math.min(1, 1200 / Math.max(img.width, img.height));
      const canvas = document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(img.width * scale));
      canvas.height = Math.max(1, Math.round(img.height * scale));

      const ctx = canvas.getContext("2d", { willReadFrequently: true });
      if (!ctx) return resolve(null);

      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      const frame = ctx.getImageData(0, 0, canvas.width, canvas.height);
      const found = jsQR(frame.data, frame.width, frame.height, { inversionAttempts: "attemptBoth" });

      resolve(found?.data?.trim() || null);
    };

    img.onerror = () => {
      URL.revokeObjectURL(url);
      resolve(null);
    };

    img.src = url;
  });
}

export default function GateCheckInPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = use(params);

  const [info, setInfo] = useState<GateInfo | null>(null);
  const [screen, setScreen] = useState<Screen>({ step: "loading" });
  const [position, setPosition] = useState<{ lat: number; lng: number } | null>(null);
  const [nis, setNis] = useState("");
  const [manualCode, setManualCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const videoRef = useRef<HTMLVideoElement>(null);

  // The camera loop needs to call submitCheckIn the moment it reads a code,
  // but that closure goes stale the moment state changes - keep the latest
  // one behind a ref instead of re-running the stream for every keystroke.
  const submitRef = useRef<(code: string) => Promise<void>>(async () => {});
  const submitRefDone = useRef(false);

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

  // The camera scan loop: back camera, jsQR over canvas frames, and the
  // decode hands straight to submitRef - no intermediate screen, no delay
  // for the rotating code to go stale in. Runs only on the scan step and
  // tears the stream down the moment the step changes.
  useEffect(() => {
    if (screen.step !== "scan") return;

    let stream: MediaStream | null = null;
    let raf = 0;
    let stopped = false;
    const canvas = document.createElement("canvas");
    const ctx = canvas.getContext("2d", { willReadFrequently: true });

    (async () => {
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } });
        const video = videoRef.current;
        if (!video || stopped) return;
        video.srcObject = stream;
        await video.play();

        const tick = () => {
          if (stopped) return;

          if (video.readyState === video.HAVE_ENOUGH_DATA && ctx && !submitRefDone.current) {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0);
            const frame = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const found = jsQR(frame.data, frame.width, frame.height, { inversionAttempts: "dontInvert" });

            if (found?.data) {
              submitRefDone.current = true; // stop later frames from double-firing
              submitRef.current(found.data.trim());
              return;
            }
          }

          raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
      } catch {
        setError("Kamera tidak dapat diakses — gunakan pilih foto atau ketik kode manual di bawah.");
      }
    })();

    return () => {
      stopped = true;
      cancelAnimationFrame(raf);
      stream?.getTracks().forEach((t) => t.stop());
    };
  }, [screen.step]);

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
    submitRefDone.current = true; // one in-flight submission, whichever lane started it
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
        device_id: deviceId(),
      });

      navigator.vibrate?.(200);
      setScreen({ step: "success", name, late: result.is_late, jam: result.checked_in_at });
    } catch (err) {
      // Whatever went wrong is shown right here on the scan step - never a
      // silent bounce back to another screen (the bug that made confirmations
      // "do nothing" in the first field tests).
      if (err instanceof ApiError && err.status === 409 && err.message.includes("Sudah tercatat")) {
        setScreen({ step: "already", name });
      } else {
        setError(err instanceof ApiError ? err.message : "Absen gagal diproses. Coba lagi.");
      }
    } finally {
      setBusy(false);
      submitRefDone.current = false;
    }
  }

  useEffect(() => {
    submitRef.current = async (code: string) => {
      if (screen.step === "scan") {
        await submitCheckIn(code, screen.nis, screen.name);
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
                  info?.qr_required
                    ? setScreen({ step: "scan", nis: screen.nis, name: screen.name })
                    : submitCheckIn("", screen.nis, screen.name)
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

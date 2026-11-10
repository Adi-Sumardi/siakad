"use client";

import { use, useEffect, useState } from "react";
import { AlertTriangle, CheckCircle2, Clock, Eye, MapPin, School, ScanFace, UserCheck } from "lucide-react";
import { deviceId, getPosition } from "@/lib/absen-qr";
import { useBlinkCapture, warmUpFaceApi, type BlinkStatus, type FaceCapture } from "@/lib/face-check";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";

/**
 * The public gate check-in (DESAIN-PRESENSI-HARIAN.md §5D, docs/09). No
 * login: the unit's slug in the URL is the credential.
 *
 * Flow: location (when the unit asks) → NIS → "ini saya" → face + one blink.
 * The first face check-in registers the student's face; after that every
 * check-in must match it. The same page runs on a student's phone, a
 * borrowed phone, or the class iPad - "Absen siswa berikutnya" hands the
 * device to the next student without reloading.
 */

type GateInfo = {
  unit_label: string;
  state: "open" | "closed" | "inactive" | "wrong_mode" | "no_session";
  session: { type: string; opens_at: string; closes_at: string; late_after: string | null } | null;
  geo: { required: boolean; gate_lat: number; gate_lng: number; radius_m: number };
  face_required: boolean;
};

type Screen =
  | { step: "loading" }
  | { step: "unavailable"; reason: string }
  | { step: "intro" }
  | { step: "locating" }
  | { step: "geo-denied" }
  | { step: "nis" }
  | { step: "confirm"; nis: string; name: string; enrolled: boolean }
  | { step: "face"; nis: string; name: string; enrolled: boolean }
  | { step: "success"; name: string; late: boolean; jam: string; enrolledNow: boolean }
  | { step: "already"; name: string };

/** After this many refused faces the page stops offering retries and sends the student to their wali kelas. */
const MAX_FACE_TRIES = 3;

const FACE_HINT: Record<BlinkStatus, string> = {
  loading: "Menyiapkan kamera…",
  "no-face": "Wajah belum terlihat — hadapkan wajah ke kamera.",
  "many-faces": "Terlihat lebih dari satu wajah — pastikan hanya kamu di depan kamera.",
  "too-far": "Dekatkan wajah ke kamera.",
  ready: "Sekarang berkedip sekali.",
  "eyes-closed": "Kedipan terlihat — buka mata lagi.",
  captured: "Kedipan terdeteksi, memeriksa wajah…",
  "camera-error": "Kamera tidak bisa dibuka. Izinkan akses kamera untuk browser ini, lalu coba lagi.",
  "model-error": "Gagal memuat pengenal wajah. Periksa koneksi internet, lalu coba lagi.",
};

export default function GateCheckInPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = use(params);

  const [info, setInfo] = useState<GateInfo | null>(null);
  const [screen, setScreen] = useState<Screen>({ step: "loading" });
  const [position, setPosition] = useState<{ lat: number; lng: number; accuracy: number } | null>(null);
  const [nis, setNis] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [faceTries, setFaceTries] = useState(0);

  // The same public link serves the masuk window in the morning and the
  // pulang window in the afternoon - whichever the server says is live.
  const isPulang = info?.session?.type === "pulang";

  useEffect(() => {
    api
      .get<GateInfo>(`/api/absen/${slug}`)
      .then((d) => {
        setInfo(d);

        if (d.state !== "open") {
          // The state carries no reason text, so rebuild it here; the window
          // may be masuk or pulang now, and "closed" covers both "already
          // ended" and "not started yet" - the times disambiguate for humans.
          const jenis = d.session?.type === "pulang" ? "pulang" : "masuk";
          const reason =
            d.state === "closed"
              ? `Sesi absen ${jenis}${d.session ? ` ${d.session.opens_at}–${d.session.closes_at} WIB` : ""} sedang tidak terbuka.`
              : d.state === "inactive"
                ? "Presensi harian unit ini belum diaktifkan."
                : d.state === "wrong_mode"
                  ? "Unit ini tidak memakai absen mandiri."
                  : "Belum ada sesi absen hari ini.";
          setScreen({ step: "unavailable", reason });
        } else {
          setScreen({ step: "intro" });
          // Download and warm up the face models while the student reads
          // the intro and types their NIS, so the camera step starts
          // tracking at once instead of compiling shaders mid-blink.
          if (d.face_required) warmUpFaceApi();
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

  /** Hands the device to the next student: a fresh NIS form, location kept while it is recent. */
  function nextStudent() {
    setNis("");
    setError("");
    setFaceTries(0);
    start();
  }

  async function lookup(e: React.FormEvent) {
    e.preventDefault();
    if (!nis.trim() || busy) return;
    setBusy(true);
    setError("");

    try {
      const result = await api.post<{
        student: { nama_panggilan: string };
        already_checked_in: boolean;
        face_enrolled: boolean;
      }>(`/api/absen/${slug}/lookup`, { nis });

      if (result.already_checked_in) {
        setScreen({ step: "already", name: result.student.nama_panggilan });
      } else {
        setScreen({ step: "confirm", nis, name: result.student.nama_panggilan, enrolled: result.face_enrolled });
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

  function confirm(current: Extract<Screen, { step: "confirm" }>) {
    setError("");
    setFaceTries(0);

    if (info?.face_required) {
      setScreen({ step: "face", nis: current.nis, name: current.name, enrolled: current.enrolled });
    } else {
      submitCheckIn(current.nis, current.name, null);
    }
  }

  /** The one write. With faces on it fires the moment the blink capture lands. */
  async function submitCheckIn(confirmedNis: string, name: string, capture: FaceCapture | null) {
    setBusy(true);
    setError("");

    try {
      const result = await api.post<{
        student: { nama_panggilan: string };
        is_late: boolean;
        checked_in_at: string;
        face_enrolled_now: boolean;
      }>(`/api/absen/${slug}/check-in`, {
        nis: confirmedNis,
        lat: info?.geo.required && position ? position.lat : undefined,
        lng: info?.geo.required && position ? position.lng : undefined,
        accuracy: info?.geo.required && position ? position.accuracy : undefined,
        device_id: deviceId(),
        descriptor: capture?.descriptor,
        photo: capture?.photo,
      });

      navigator.vibrate?.(200);
      setScreen({
        step: "success",
        name,
        late: result.is_late,
        jam: result.checked_in_at,
        enrolledNow: result.face_enrolled_now,
      });
    } catch (err) {
      // Whatever went wrong is shown right here on the active step - never a
      // silent bounce back to another screen.
      if (err instanceof ApiError && err.status === 409 && err.message.includes("Sudah tercatat")) {
        setScreen({ step: "already", name });
      } else {
        setError(err instanceof ApiError ? err.message : "Absen gagal diproses. Coba lagi.");
        setFaceTries((n) => n + 1);
      }
    } finally {
      setBusy(false);
    }
  }

  // Declared after submitCheckIn: the capture callback calls it.
  const { videoRef, status, retry } = useBlinkCapture(screen.step === "face", (capture) => {
    if (screen.step === "face") {
      submitCheckIn(screen.nis, screen.name, capture);
    }
  });

  const errorBox = error && (
    <p role="alert" className="rounded-lg bg-bad/10 px-3 py-2 text-sm text-bad">
      {error}
    </p>
  );

  const faceGaveUp = faceTries >= MAX_FACE_TRIES;

  return (
    <div className="flex min-h-svh flex-col items-center justify-center bg-canvas p-4">
      <Card className="w-full max-w-sm p-6 text-center">
        <div className="mb-4 flex items-center justify-center gap-2 text-muted-foreground">
          <School className="size-4" />
          <span className="text-xs font-medium">{info?.unit_label ?? "Absen Siswa"}</span>
        </div>

        {screen.step === "loading" && (
          <div className="flex flex-col gap-3">
            <Skeleton className="h-24 w-full" />
            <p className="text-sm text-muted-foreground">Memuat halaman absen…</p>
            {/* Server-rendered and shown by CSS alone after 10 s: if the
                browser never runs this page's JavaScript, the student still
                learns why instead of staring at an empty card. */}
            <p className="reveal-late rounded-lg bg-warn/10 px-3 py-2 text-left text-xs text-warn">
              Halaman belum termuat. Periksa koneksi internet, lalu muat ulang. Jika tetap begini, perbarui browser
              (Safari/iPadOS atau Chrome versi terbaru) atau buka link ini di Chrome.
            </p>
          </div>
        )}

        {screen.step === "unavailable" && (
          <div className="flex flex-col items-center gap-3 py-6">
            <Clock className="size-10 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">{screen.reason}</p>
          </div>
        )}

        {screen.step === "intro" && (
          <div className="flex flex-col gap-4">
            <ScanFace className="mx-auto size-12 text-primary" />
            <div>
              <h1 className="text-lg font-bold">Absen {isPulang ? "Pulang" : "Masuk"}</h1>
              <p className="text-sm text-muted-foreground">
                {info?.session && `Jendela absen ${info.session.opens_at}–${info.session.closes_at} WIB`}
                {info?.session?.late_after && ` · terlambat lewat ${info.session.late_after}`}
              </p>
            </div>
            <ol className="flex list-decimal flex-col gap-1 pl-5 text-left text-xs text-muted-foreground">
              <li>Ketik NIS kamu</li>
              <li>Pastikan nama yang muncul benar</li>
              {info?.face_required && <li>Hadapkan wajah ke kamera, lalu berkedip sekali</li>}
            </ol>
            <Button size="lg" onClick={start}>
              Mulai Absen
            </Button>
          </div>
        )}

        {screen.step === "locating" && (
          <div className="flex flex-col items-center gap-3 py-6">
            <MapPin className="size-10 animate-pulse text-primary" />
            <p className="text-sm text-muted-foreground">Memeriksa lokasi…</p>
          </div>
        )}

        {screen.step === "geo-denied" && (
          <div className="flex flex-col gap-3">
            <AlertTriangle className="mx-auto size-10 text-warn" />
            <p className="text-sm text-muted-foreground">
              Lokasi tidak bisa dibaca. Absen unit ini hanya bisa dari area sekolah — izinkan akses lokasi untuk
              browser ini, lalu coba lagi.
            </p>
            <Button onClick={start}>Coba Lagi</Button>
          </div>
        )}

        {screen.step === "nis" && (
          <form onSubmit={lookup} className="flex flex-col gap-3">
            <h1 className="text-lg font-bold">Masukkan NIS</h1>
            {errorBox}
            <Input
              value={nis}
              onChange={(e) => setNis(e.target.value)}
              placeholder="NIS"
              inputMode="numeric"
              autoComplete="off"
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
            <p className="text-sm text-muted-foreground">Ini kamu?</p>
            <p className="text-2xl font-bold">{screen.name}</p>
            <p className="text-xs text-muted-foreground">NIS {screen.nis}</p>
            {info?.face_required && !screen.enrolled && (
              <p className="rounded-lg bg-info/10 px-3 py-2 text-left text-xs text-info">
                Ini absen wajah pertamamu. Wajahmu akan didaftarkan untuk NIS ini, jadi pastikan kamu sendiri yang
                ada di depan kamera.
              </p>
            )}
            {errorBox}
            <div className="flex gap-2">
              <Button variant="outline" className="flex-1" onClick={() => setScreen({ step: "nis" })} disabled={busy}>
                Bukan saya
              </Button>
              <Button className="flex-1" disabled={busy} onClick={() => confirm(screen)}>
                <UserCheck className="size-4" /> {busy ? "Mencatat…" : "Ya, ini saya"}
              </Button>
            </div>
          </div>
        )}

        {screen.step === "face" && (
          <div className="flex flex-col gap-3">
            <div className="text-xs text-muted-foreground">
              Absen untuk <strong className="text-foreground">{screen.name}</strong>
            </div>

            <div className="relative mx-auto aspect-[3/4] w-full max-w-64 overflow-hidden rounded-xl bg-black">
              {/* Mirrored like a selfie; the snapshot itself is taken unmirrored. */}
              <video ref={videoRef} playsInline muted className="size-full -scale-x-100 object-cover" />
              <div
                className={`pointer-events-none absolute inset-x-8 inset-y-10 rounded-[50%] border-4 ${
                  status === "ready" || status === "eyes-closed" || status === "captured" ? "border-good" : "border-white/60"
                }`}
              />
            </div>

            {!error && (
              <p className="flex items-center justify-center gap-1.5 text-sm font-medium" aria-live="polite">
                {(status === "ready" || status === "eyes-closed") && <Eye className="size-4 text-good" />}
                {FACE_HINT[status]}
              </p>
            )}

            {errorBox}

            {error && !faceGaveUp && (
              <Button
                onClick={() => {
                  setError("");
                  retry();
                }}
                disabled={busy}
              >
                Coba Lagi
              </Button>
            )}

            {(status === "camera-error" || status === "model-error") && !error && (
              <Button onClick={retry}>Coba Lagi</Button>
            )}

            {status === "camera-error" && (
              <p className="rounded-lg bg-warn/10 px-3 py-2 text-left text-xs text-warn">
                Kalau link ini dibuka dari dalam aplikasi lain (Instagram, Facebook, LINE, TikTok), kamera sering
                diblokir. Ketuk menu ⋮ lalu pilih <strong>Buka di Chrome</strong> / <strong>Buka di Safari</strong>.
              </p>
            )}

            {faceGaveUp && (
              <p className="rounded-lg bg-warn/10 px-3 py-2 text-sm text-warn">
                Wajah belum berhasil dikenali. Minta wali kelasmu mencatat absenmu.
              </p>
            )}

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
          <div className="flex flex-col items-center gap-3 py-4">
            <CheckCircle2 className={`size-12 ${screen.late ? "text-warn" : "text-good"}`} />
            <p className="text-lg font-bold">
              {isPulang
                ? "Berhasil dicatat pulang"
                : screen.late
                  ? "Tercatat hadir — terlambat"
                  : "Berhasil dicatat hadir"}
            </p>
            <p className="text-sm text-muted-foreground">
              {screen.name} · pukul {screen.jam} WIB
            </p>
            {screen.enrolledNow && <p className="text-xs text-muted-foreground">Wajahmu sudah terdaftar.</p>}
            <Button variant="outline" className="mt-2 w-full" onClick={nextStudent}>
              Absen siswa berikutnya
            </Button>
          </div>
        )}

        {screen.step === "already" && (
          <div className="flex flex-col items-center gap-3 py-4">
            <CheckCircle2 className="size-12 text-muted-foreground" />
            <p className="text-sm font-medium">
              {isPulang ? "Sudah tercatat pulang sebelumnya hari ini." : "Sudah tercatat hadir sebelumnya hari ini."}
            </p>
            {screen.name && <p className="text-sm text-muted-foreground">{screen.name}</p>}
            <Button variant="outline" className="mt-2 w-full" onClick={nextStudent}>
              Absen siswa berikutnya
            </Button>
          </div>
        )}
      </Card>
    </div>
  );
}

"use client";

import { Suspense, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "next/navigation";
import { QRCodeSVG } from "qrcode.react";
import { AlertTriangle, Printer } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";

/**
 * Printable QR poster for the unit's public absen link (docs/09). The QR is
 * static: it only holds the link, which stays the same until an admin
 * resets it - print once, tape it to every classroom wall for the year.
 *
 * One page per class name typed in the box (or one page without a class).
 * Staff only (the proxy sends a signed-out visitor to /login); each role
 * reads the link from the endpoint it already has.
 */

type LinkInfo = {
  unit: { ulid: string; label: string };
  enabled: boolean;
  intake_mode: string | null;
  public_path: string | null;
  public_url: string | null;
};

export default function QrAbsenPosterPage() {
  return (
    <Suspense fallback={<Skeleton className="m-6 h-96" />}>
      <Poster />
    </Suspense>
  );
}

function Poster() {
  const { user, loading } = useAuth();
  const unit = useSearchParams().get("unit");
  const [info, setInfo] = useState<LinkInfo | null>(null);
  const [error, setError] = useState("");
  const [classes, setClasses] = useState("");

  useEffect(() => {
    if (loading || !user) return;
    const endpoint =
      user.role === "guru"
        ? "/api/guru/daily-attendance/public-link"
        : `/api/admin/daily-attendance/public-link${unit ? `?unit=${encodeURIComponent(unit)}` : ""}`;

    api
      .get<LinkInfo>(endpoint)
      .then(setInfo)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Gagal memuat link absen."));
  }, [loading, user, unit]);

  // The official domain from the server (FRONTEND_URL) is what a poster
  // must carry. On a dev machine that is http://localhost, which a phone
  // cannot open - then fall back to the address this page is open on.
  const url = useMemo(() => {
    if (!info?.public_path) return null;
    if (info.public_url?.startsWith("https://")) return info.public_url;
    return typeof window !== "undefined" ? `${window.location.origin}${info.public_path}` : null;
  }, [info]);

  const temporary = !!url && !info?.public_url?.startsWith("https://");

  const pages = useMemo(() => {
    const names = classes
      .split(/[,\n]/)
      .map((c) => c.trim())
      .filter(Boolean);
    return names.length ? names : [null];
  }, [classes]);

  if (error) {
    return <p className="m-6 rounded-lg bg-bad/10 p-4 text-sm text-bad">{error}</p>;
  }

  if (!info) {
    return <Skeleton className="m-6 h-96" />;
  }

  if (!url) {
    return (
      <p className="m-6 rounded-lg bg-warn/10 p-4 text-sm text-warn">
        {info.unit.label} belum punya link absen aktif. Admin unit perlu mengaktifkan presensi harian dengan mode absen
        mandiri dan membuat link di halaman Presensi Harian.
      </p>
    );
  }

  return (
    <div className="min-h-dvh bg-canvas print:bg-white">
      <div className="mx-auto flex max-w-2xl flex-col gap-3 p-4 print:hidden">
        <h1 className="text-lg font-bold">Cetak QR Absen — {info.unit.label}</h1>
        <p className="text-sm text-muted-foreground">
          QR ini tetap, hanya berisi link absen. Cetak sekali dan tempel di tiap kelas. QR baru perlu dicetak ulang hanya
          kalau admin menekan &ldquo;Reset Link&rdquo;.
        </p>
        {temporary && (
          <p className="flex items-start gap-2 rounded-lg bg-warn/10 px-3 py-2 text-xs text-warn">
            <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
            Server ini belum memakai domain resmi (HTTPS), jadi QR memakai alamat sementara {window.location.origin}.
            Untuk ditempel di kelas, cetak dari aplikasi produksi.
          </p>
        )}
        <div>
          <Label className="text-xs">Nama kelas (opsional, pisahkan dengan koma — satu halaman per kelas)</Label>
          <Input
            value={classes}
            onChange={(e) => setClasses(e.target.value)}
            placeholder="contoh: 7A, 7B, 8A, 8B"
            className="mt-1.5"
          />
        </div>
        <Button className="w-fit" onClick={() => window.print()}>
          <Printer className="size-4" /> Cetak {pages.length > 1 ? `${pages.length} halaman` : ""}
        </Button>
      </div>

      {pages.map((kelas, i) => (
        <section
          key={`${kelas ?? "umum"}-${i}`}
          className="mx-auto my-4 flex aspect-[210/297] w-full max-w-2xl flex-col items-center justify-between rounded-xl border border-border bg-white p-10 text-center text-[#1c2321] shadow-sm break-after-page print:my-0 print:aspect-auto print:h-[277mm] print:max-w-none print:rounded-none print:border-0 print:p-6 print:shadow-none"
        >
          <div className="flex flex-col items-center gap-2">
            {/* eslint-disable-next-line @next/next/no-img-element -- printed page, a plain img prints most reliably */}
            <img src="/images/logo-yapi.png" alt="" className="h-16 w-auto" />
            <p className="text-sm font-semibold uppercase tracking-wide text-[#4b5a55]">{info.unit.label}</p>
            <h2 className="text-4xl font-extrabold tracking-tight">ABSEN SISWA</h2>
            {kelas && <p className="text-2xl font-bold">Kelas {kelas}</p>}
          </div>

          <div className="flex flex-col items-center gap-3">
            <QRCodeSVG value={url} size={300} level="M" marginSize={2} className="h-auto w-[70%] max-w-[300px] print:w-[110mm] print:max-w-none" />
            <p className="break-all font-mono text-sm text-[#4b5a55]">{url}</p>
          </div>

          <ol className="flex w-full max-w-md list-none flex-col gap-2 text-left text-base">
            <li><strong>1.</strong> Scan QR ini dengan kamera HP atau iPad.</li>
            <li><strong>2.</strong> Ketik NIS kamu, pastikan namamu yang muncul.</li>
            <li><strong>3.</strong> Hadapkan wajah ke kamera, lalu berkedip sekali.</li>
          </ol>

          <p className="text-xs text-[#4b5a55]">
            Gagal absen? Minta wali kelas mencatat absenmu. Buka di Chrome atau Safari, bukan dari dalam aplikasi lain.
          </p>
        </section>
      ))}
    </div>
  );
}

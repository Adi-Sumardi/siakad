"use client";

import { Suspense, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { ArrowLeft, Award, Mail, Megaphone, MessageCircle, Receipt } from "lucide-react";
import { toast } from "sonner";
import { BrandMark } from "@/components/brand-mark";
import { OtpInput } from "@/components/otp-input";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { ApiError } from "@/lib/api";
import { useAuth, type OtpChallenge } from "@/lib/auth/auth-context";

/**
 * One way in, for everyone.
 *
 * There is no password anywhere in this app - not for parents, not for staff -
 * so there is no second form to fall back to and no "forgot password" to
 * support. Two steps: who you are, then the code.
 */
function LoginForm() {
  const { requestOtp, verifyOtp } = useAuth();
  const router = useRouter();
  const params = useSearchParams();

  const [identifier, setIdentifier] = useState("");
  const [challenge, setChallenge] = useState<OtpChallenge | null>(null);
  const [code, setCode] = useState("");
  const [cooldown, setCooldown] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = setTimeout(() => setCooldown((seconds) => seconds - 1), 1000);
    return () => clearTimeout(timer);
  }, [cooldown]);

  function readError(err: unknown, field: string) {
    if (err instanceof ApiError) return err.fieldError(field) ?? err.message;
    return "Tidak dapat menghubungi server.";
  }

  async function sendCode(event?: React.FormEvent) {
    event?.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      const result = await requestOtp(identifier.trim());
      setChallenge(result);
      setCooldown(result.resend_after_seconds);
      setCode("");
      toast.success(
        result.channel === "email" ? "Kode dikirim ke email Anda." : "Kode dikirim lewat WhatsApp.",
      );
    } catch (err) {
      setError(readError(err, "identifier"));
    } finally {
      setSubmitting(false);
    }
  }

  async function submitCode(value: string) {
    setSubmitting(true);
    setError(null);

    try {
      const user = await verifyOtp(identifier.trim(), value);
      toast.success(`Selamat datang, ${user.name}`);
      router.replace(params.get("redirect") ?? "/dashboard");
    } catch (err) {
      setError(readError(err, "code"));
      setCode("");
    } finally {
      setSubmitting(false);
    }
  }

  const errorBox = error && (
    <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">
      {error}
    </p>
  );

  if (challenge) {
    return (
      <Card className="p-6">
        <button
          type="button"
          onClick={() => {
            setChallenge(null);
            setError(null);
          }}
          className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="size-4" />
          Ganti email / nomor
        </button>

        <h1 className="text-xl font-bold tracking-tight">Masukkan kode</h1>
        <p className="mt-1 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground">
          {challenge.channel === "email" ? (
            <Mail className="size-4 shrink-0" />
          ) : (
            <MessageCircle className="size-4 shrink-0" />
          )}
          Kode {challenge.expires_in_minutes} menit dikirim ke{" "}
          <span className="font-medium text-foreground">{challenge.identifier}</span>
        </p>

        <div className="mt-5 flex flex-col gap-4">
          <OtpInput
            value={code}
            onChange={setCode}
            onComplete={submitCode}
            disabled={submitting}
            invalid={Boolean(error)}
          />

          {errorBox}

          <Button
            type="button"
            size="full"
            disabled={submitting || code.length < 6}
            onClick={() => submitCode(code)}
          >
            {submitting ? "Memeriksa…" : "Masuk"}
          </Button>

          <button
            type="button"
            disabled={cooldown > 0 || submitting}
            onClick={() => sendCode()}
            className="text-sm text-primary disabled:text-muted-foreground"
          >
            {cooldown > 0 ? `Kirim ulang kode dalam ${cooldown} detik` : "Kirim ulang kode"}
          </button>
        </div>

        <p className="mt-5 border-t border-border pt-4 text-xs text-muted-foreground">
          Petugas sekolah tidak akan pernah meminta kode ini. Jangan berikan kepada siapa pun.
        </p>
      </Card>
    );
  }

  return (
    <Card className="p-6">
      <h1 className="text-xl font-bold tracking-tight">Masuk</h1>
      <p className="mt-1 text-sm text-muted-foreground">
        Masukkan email atau nomor HP yang terdaftar di sekolah. Kami kirimkan kode sekali pakai.
      </p>

      <form onSubmit={sendCode} className="mt-5 flex flex-col gap-4" noValidate>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="identifier">Email atau nomor HP</Label>
          <Input
            id="identifier"
            name="identifier"
            autoComplete="username"
            autoFocus
            required
            value={identifier}
            onChange={(e) => setIdentifier(e.target.value)}
            aria-invalid={Boolean(error)}
            placeholder="budi@example.com atau 0812…"
          />
        </div>

        {errorBox}

        <Button type="submit" size="full" disabled={submitting || !identifier.trim()}>
          {submitting ? "Memproses…" : "Kirim kode"}
        </Button>
      </form>
    </Card>
  );
}

const HIGHLIGHTS = [
  { icon: Receipt, text: "Tagihan & pembayaran SPP" },
  { icon: Award, text: "Poin & prestasi anak" },
  { icon: Megaphone, text: "Informasi sekolah, unit, dan kelas" },
];

export default function LoginPage() {
  return (
    <div className="min-h-svh grid lg:grid-cols-[1.1fr_1fr] bg-background">
      {/* Visual panel - same navy gradient PMB uses for its own login page */}
      <div className="hidden lg:flex flex-col p-11 relative overflow-hidden bg-linear-to-br from-[#13286B] to-[#2856E0] text-white">
        <div className="absolute -right-32 -bottom-32 size-96 rounded-full border border-white/10" />
        <div className="absolute -right-16 -bottom-44 size-96 rounded-full border border-white/10" />
        <div className="absolute -left-24 -top-24 size-72 rounded-full bg-white/5 blur-2xl" />

        <BrandMark variant="dark" className="relative z-10 text-[16.5px]" />

        <div className="relative z-10 flex-1 flex flex-col justify-center max-w-md">
          <h1 className="text-[30px] leading-[1.15] mb-3" style={{ fontFamily: "var(--font-heading)" }}>
            Satu portal untuk keluarga besar
            <span className="block italic text-white/90" style={{ fontFamily: "var(--font-brand)" }}>
              sekolah YAPI.
            </span>
          </h1>
          <p className="text-white/70 text-sm leading-relaxed">
            Masuk untuk memantau tagihan, poin, prestasi, dan informasi terbaru anak Anda.
          </p>

          <div className="flex flex-col gap-3 mt-8">
            {HIGHLIGHTS.map((item) => (
              <div key={item.text} className="flex items-center gap-3 text-sm text-white/85">
                <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-white/10">
                  <item.icon className="size-3.5" />
                </span>
                {item.text}
              </div>
            ))}
          </div>
        </div>

        <p className="relative z-10 text-xs text-white/45">
          Tidak ada kata sandi - masuk cukup dengan email atau nomor WhatsApp.
        </p>
      </div>

      {/* Form panel */}
      <div className="flex items-center justify-center p-6 sm:p-10">
        <div className="w-full max-w-sm flex flex-col gap-6">
          <BrandMark className="lg:hidden" />

          {/* useSearchParams reads the ?redirect= a guard appended, and needs a
              Suspense boundary or the route bails out of prerendering. */}
          <Suspense fallback={<Skeleton className="h-80 w-full" />}>
            <LoginForm />
          </Suspense>

          <p className="text-center text-xs text-muted-foreground">
            Akun wali murid dibuat otomatis setelah uang pangkal lunas. Petugas sekolah masuk
            dengan kode yang sama, dikirim ke email kedinasan.
          </p>
        </div>
      </div>
    </div>
  );
}

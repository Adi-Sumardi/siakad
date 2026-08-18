"use client";

import { Suspense, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { toast } from "sonner";
import { BrandMark } from "@/components/brand-mark";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useAuth, type User } from "@/lib/auth/auth-context";

type Invitation = {
  name: string;
  identifier: string;
  channel: "email" | "whatsapp";
  expires_at: string;
  students: { nama_lengkap: string; unit: string | null }[];
};

function ActivationCard() {
  const params = useSearchParams();
  const router = useRouter();
  const { adopt } = useAuth();
  const token = params.get("token") ?? "";

  const [invitation, setInvitation] = useState<Invitation | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  // Checked before anything is clicked, so a guardian who opened an expired
  // link is told immediately rather than after trying.
  useEffect(() => {
    if (!token) {
      setLoadError("Tautan aktivasi tidak lengkap.");
      return;
    }

    api
      .get<Invitation>(`/api/invitations/${token}`)
      .then(setInvitation)
      .catch((err) =>
        setLoadError(
          err instanceof ApiError ? err.message : "Tidak dapat memeriksa tautan aktivasi.",
        ),
      );
  }, [token]);

  async function activate() {
    setSubmitting(true);
    setError(null);

    try {
      const { user } = await api.post<{ user: User }>(`/api/invitations/${token}/activate`, {});

      // No password is chosen here: holding this link already proves the
      // guardian controls the address on file, and every later sign-in uses a
      // one-time code. The server starts the session, so the app adopts it.
      adopt(user);
      toast.success("Akun aktif. Selamat datang!");
      router.replace("/dashboard");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Tidak dapat menghubungi server.");
    } finally {
      setSubmitting(false);
    }
  }

  if (loadError) {
    return (
      <Card className="p-6">
        <h1 className="text-lg font-bold tracking-tight">Tautan tidak berlaku</h1>
        <p className="mt-2 text-sm text-muted-foreground">{loadError}</p>
        <p className="mt-4 text-sm text-muted-foreground">
          Tidak masalah — Anda tetap bisa masuk kapan saja dengan kode sekali pakai yang dikirim ke
          email atau WhatsApp Anda.
        </p>
        <Button className="mt-5" size="full" onClick={() => router.push("/login")}>
          Masuk dengan kode
        </Button>
      </Card>
    );
  }

  if (!invitation) {
    return (
      <Card className="flex flex-col gap-3 p-6">
        <Skeleton className="h-6 w-2/3" />
        <Skeleton className="h-4 w-1/2" />
        <Skeleton className="h-24 w-full" />
      </Card>
    );
  }

  return (
    <Card className="p-6">
      <h1 className="text-xl font-bold tracking-tight">Selamat bergabung</h1>
      <p className="mt-1 text-sm text-muted-foreground">
        {invitation.name}, akun aplikasi sekolah Anda sudah siap.
      </p>

      {invitation.students.length > 0 && (
        <div className="mt-4 rounded-lg border border-border bg-canvas p-3">
          <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
            Anak yang terhubung
          </p>
          <ul className="mt-2 flex flex-col gap-1">
            {invitation.students.map((student) => (
              <li key={student.nama_lengkap} className="text-sm">
                <span className="font-semibold">{student.nama_lengkap}</span>
                {student.unit && <span className="text-muted-foreground"> · {student.unit}</span>}
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="mt-4 rounded-lg border border-border p-3 text-sm text-muted-foreground">
        Tidak ada kata sandi yang perlu dibuat. Setiap kali masuk, kami kirimkan kode sekali pakai
        ke{" "}
        <span className="font-medium text-foreground">{invitation.identifier}</span>
        {invitation.channel === "whatsapp" ? " lewat WhatsApp." : " lewat email."}
      </div>

      {error && (
        <p role="alert" className="mt-4 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">
          {error}
        </p>
      )}

      <Button className="mt-5" size="full" onClick={activate} disabled={submitting}>
        {submitting ? "Memproses…" : "Masuk sekarang"}
      </Button>
    </Card>
  );
}

export default function ActivationPage() {
  return (
    <main className="grid min-h-dvh place-items-center bg-canvas px-4 py-10">
      <div className="w-full max-w-md">
        <div className="mb-6 flex justify-center">
          <BrandMark />
        </div>
        <Suspense fallback={<Skeleton className="h-80 w-full" />}>
          <ActivationCard />
        </Suspense>
      </div>
    </main>
  );
}

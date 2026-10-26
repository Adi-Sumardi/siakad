"use client";

import { useEffect } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowLeft, LogOut } from "lucide-react";
import { BrandMark } from "@/components/brand-mark";
import { Skeleton } from "@/components/ui/skeleton";
import { homePathFor, useAuth } from "@/lib/auth/auth-context";

const ROLE_LABEL: Record<string, string> = {
  direktur_pendidikan: "Direktur Pendidikan",
  bph: "Badan Pengurus Harian",
  admin: "Admin Pusat (pratinjau)",
};

/**
 * Yayasan shell: no sidebar and no menu - these accounts have one page.
 * Name, role and a labelled "Keluar" button are always in sight, because a
 * reader who rarely signs in should not have to hunt behind an avatar.
 */
export default function EksekutifLayout({ children }: { children: React.ReactNode }) {
  const { user, loading, logout } = useAuth();
  const router = useRouter();
  // The central admin may look in too, to preview what the yayasan sees.
  const allowed = user?.role === "direktur_pendidikan" || user?.role === "bph" || user?.role === "admin";

  useEffect(() => {
    if (loading) return;
    if (!user) {
      router.replace("/login");
      return;
    }
    if (!allowed) router.replace(homePathFor(user.role));
  }, [loading, user, allowed, router]);

  if (loading || !user || !allowed) {
    return (
      <div className="flex min-h-dvh items-center justify-center bg-canvas p-6">
        <Skeleton className="h-10 w-48" />
      </div>
    );
  }

  return (
    <div className="min-h-dvh bg-canvas">
      <header className="no-print sticky top-0 z-30 border-b border-border bg-card/95 backdrop-blur">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
          {user.role === "admin" ? (
            <Link href="/admin" className="inline-flex h-10 items-center gap-2 rounded-lg px-2 text-sm font-semibold text-primary hover:bg-accent">
              <ArrowLeft className="size-4" aria-hidden /> Kembali ke Admin
            </Link>
          ) : (
            <BrandMark textClassName="hidden sm:inline" />
          )}
          <div className="flex items-center gap-3">
            <div className="min-w-0 text-right">
              <p className="truncate text-sm font-semibold text-foreground">{user.name}</p>
              <p className="truncate text-xs text-muted-foreground">{ROLE_LABEL[user.role]}</p>
            </div>
            <button
              type="button"
              onClick={() => void logout()}
              className="inline-flex h-10 items-center gap-2 rounded-lg border border-border px-3 text-sm font-semibold text-foreground hover:bg-canvas"
            >
              <LogOut className="size-4" aria-hidden />
              Keluar
            </button>
          </div>
        </div>
      </header>
      <main className="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-8">{children}</main>
    </div>
  );
}

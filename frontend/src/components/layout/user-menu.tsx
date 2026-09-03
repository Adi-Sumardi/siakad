"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { LogOut, User as UserIcon } from "lucide-react";
import { toast } from "sonner";
import { useAuth } from "@/lib/auth/auth-context";

/**
 * Profil/Keluar, in one place instead of duplicated into every sidebar's
 * bottom user card - the sidebar keeps identity (avatar, name, role) for
 * visual anchoring, same as PMB's own AppSidebar footer; the actions live
 * here, in the navbar, same as PMB's AppTopbar.
 */
export function UserMenu({ subtitle }: { subtitle?: string }) {
  const { user, logout } = useAuth();
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function onClickOutside(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", onClickOutside);
    return () => document.removeEventListener("mousedown", onClickOutside);
  }, []);

  async function handleLogout() {
    try {
      await logout();
      toast.success("Berhasil logout.");
    } catch {
      toast.error("Terjadi kendala saat logout, sesi lokal tetap diakhiri.");
    } finally {
      router.push("/login");
    }
  }

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen((o) => !o)}
        className="flex items-center justify-center rounded-full transition-opacity hover:opacity-80"
        aria-label="Menu akun"
      >
        <span className="flex size-8 items-center justify-center rounded-full bg-primary/10 text-primary font-bold text-xs">
          {user?.name?.charAt(0) ?? "U"}
        </span>
      </button>

      {open && (
        <div className="absolute right-0 top-full mt-2 w-52 rounded-xl border border-border bg-card shadow-2xl overflow-hidden z-40">
          <div className="px-3.5 py-3 border-b border-border">
            <p className="truncate text-sm font-semibold text-foreground">{user?.name}</p>
            {subtitle && <p className="truncate text-xs text-muted-foreground">{subtitle}</p>}
          </div>
          <Link
            href="/profil"
            onClick={() => setOpen(false)}
            className="flex items-center gap-2 px-3.5 py-2.5 text-sm text-foreground hover:bg-accent transition-colors"
          >
            <UserIcon className="size-4" />
            Profil
          </Link>
          <button
            onClick={handleLogout}
            className="flex w-full items-center gap-2 px-3.5 py-2.5 text-sm text-destructive hover:bg-destructive/10 transition-colors"
          >
            <LogOut className="size-4" />
            Keluar
          </button>
        </div>
      )}
    </div>
  );
}

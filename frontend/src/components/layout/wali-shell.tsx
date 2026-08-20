"use client";

import { useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { CreditCard, GraduationCap, Home, LogOut, Megaphone, Menu, Receipt, User, X } from "lucide-react";
import { BrandMark } from "@/components/brand-mark";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/lib/auth/auth-context";
import { cn } from "@/lib/utils";

const WALI_NAV = [
  { href: "/dashboard", label: "Beranda", icon: Home },
  { href: "/tagihan", label: "Tagihan", icon: Receipt },
  { href: "/pembayaran", label: "Riwayat Bayar", icon: CreditCard },
  { href: "/informasi", label: "Informasi", icon: Megaphone },
];

export function WaliShell({
  children,
}: {
  children: React.ReactNode;
}) {
  const { user, logout } = useAuth();
  const pathname = usePathname();
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <div className="min-h-dvh bg-canvas flex flex-col">
      {/* Mobile Drawer Backdrop */}
      {mobileOpen && (
        <div
          className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs md:hidden"
          onClick={() => setMobileOpen(false)}
        />
      )}

      {/* Mobile Drawer */}
      <aside
        className={cn(
          "fixed inset-y-0 right-0 z-50 flex w-72 flex-col bg-card shadow-2xl transition-transform duration-300 ease-in-out md:hidden",
          mobileOpen ? "translate-x-0" : "translate-x-full",
        )}
      >
        <div className="flex items-center justify-between border-b border-border px-5 py-4">
          <BrandMark />
          <button
            onClick={() => setMobileOpen(false)}
            className="rounded-lg p-1.5 text-muted-foreground hover:bg-accent"
          >
            <X className="size-5" />
          </button>
        </div>

        <div className="p-4 border-b border-border bg-accent/30">
          <p className="text-xs text-muted-foreground">Masuk sebagai Wali Murid</p>
          <p className="text-sm font-bold text-foreground truncate mt-0.5">{user?.name}</p>
          {user?.email && <p className="text-xs text-muted-foreground truncate">{user.email}</p>}
        </div>

        <nav className="flex-1 overflow-y-auto px-3 py-4 flex flex-col gap-1">
          {WALI_NAV.map((item) => {
            const active = pathname === item.href || (item.href !== "/dashboard" && pathname.startsWith(`${item.href}`));
            const Icon = item.icon;

            return (
              <Link
                key={item.href}
                href={item.href}
                onClick={() => setMobileOpen(false)}
                className={cn(
                  "flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium transition-all",
                  active
                    ? "bg-primary text-primary-foreground font-semibold shadow-sm"
                    : "text-muted-foreground hover:bg-accent hover:text-foreground",
                )}
              >
                <Icon className="size-4.5 shrink-0" />
                <span>{item.label}</span>
              </Link>
            );
          })}
        </nav>

        <div className="border-t border-border p-4">
          <Button variant="ghost" size="sm" onClick={logout} className="w-full justify-start gap-2 text-destructive hover:bg-destructive/10">
            <LogOut className="size-4" />
            Keluar
          </Button>
        </div>
      </aside>

      {/* Main Top Header */}
      <header className="sticky top-0 z-30 border-b border-border bg-card/95 backdrop-blur">
        <div className="mx-auto flex w-full max-w-7xl 2xl:max-w-full items-center justify-between gap-4 px-4 sm:px-6 lg:px-8 py-3.5">
          <BrandMark />

          {/* Desktop Navigation Tabs */}
          <nav className="hidden md:flex items-center gap-1 bg-muted/60 p-1 rounded-xl border border-border/50">
            {WALI_NAV.map((item) => {
              const active = pathname === item.href || (item.href !== "/dashboard" && pathname.startsWith(`${item.href}`));
              const Icon = item.icon;

              return (
                <Link
                  key={item.href}
                  href={item.href}
                  className={cn(
                    "flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold transition-all",
                    active
                      ? "bg-card text-foreground shadow-xs font-bold"
                      : "text-muted-foreground hover:text-foreground hover:bg-card/50",
                  )}
                >
                  <Icon className="size-3.5" />
                  <span>{item.label}</span>
                </Link>
              );
            })}
          </nav>

          {/* Desktop User Info & Logout */}
          <div className="hidden md:flex items-center gap-3">
            <div className="text-right">
              <p className="text-xs font-bold text-foreground truncate">{user?.name}</p>
              <p className="text-[11px] text-muted-foreground">Wali Murid</p>
            </div>
            <Button variant="ghost" size="sm" onClick={logout} className="text-muted-foreground hover:text-destructive gap-1.5 text-xs px-2.5">
              <LogOut className="size-3.5" />
              Keluar
            </Button>
          </div>

          {/* Mobile Hamburger Button */}
          <div className="flex items-center gap-2 md:hidden">
            <button
              onClick={() => setMobileOpen(true)}
              className="flex items-center justify-center rounded-lg p-2 text-foreground hover:bg-accent"
              aria-label="Buka menu navigasi"
            >
              <Menu className="size-5" />
            </button>
          </div>
        </div>
      </header>

      {/* Main Fullspan Container */}
      <main className="w-full flex-1 max-w-7xl 2xl:max-w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 md:py-8">
        {children}
      </main>

      {/* Mobile Bottom Quick Navigation Bar */}
      <div className="sticky bottom-0 z-20 border-t border-border bg-card/95 backdrop-blur md:hidden">
        <div className="grid grid-cols-4 gap-1 p-1.5">
          {WALI_NAV.map((item) => {
            const active = pathname === item.href || (item.href !== "/dashboard" && pathname.startsWith(`${item.href}`));
            const Icon = item.icon;

            return (
              <Link
                key={item.href}
                href={item.href}
                className={cn(
                  "flex flex-col items-center justify-center gap-1 py-1.5 px-1 rounded-xl text-[11px] font-medium transition-colors",
                  active
                    ? "text-primary font-bold bg-primary/10"
                    : "text-muted-foreground hover:text-foreground",
                )}
              >
                <Icon className="size-4" />
                <span className="truncate">{item.label}</span>
              </Link>
            );
          })}
        </div>
      </div>
    </div>
  );
}

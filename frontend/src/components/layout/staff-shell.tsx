"use client";

import { useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { LogOut, Menu, ShieldCheck, X } from "lucide-react";
import { BrandMark } from "@/components/brand-mark";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/lib/auth/auth-context";
import { cn } from "@/lib/utils";

export type StaffNavItem = {
  href: string;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  centralOnly?: boolean;
};

export function StaffShell({
  nav,
  unitLabel,
  children,
}: {
  nav: StaffNavItem[];
  unitLabel?: string;
  children: React.ReactNode;
}) {
  const { user, logout } = useAuth();
  const pathname = usePathname();
  const [mobileOpen, setMobileOpen] = useState(false);

  const visibleNav = nav.filter((item) => !item.centralOnly || user?.role === "admin");

  return (
    <div className="min-h-dvh bg-canvas md:flex">
      {/* Mobile Backdrop & Drawer */}
      {mobileOpen && (
        <div
          className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs transition-opacity md:hidden"
          onClick={() => setMobileOpen(false)}
        />
      )}

      {/* Mobile Slide-over Drawer */}
      <aside
        className={cn(
          "fixed inset-y-0 left-0 z-50 flex w-72 flex-col bg-card shadow-2xl transition-transform duration-300 ease-in-out md:hidden",
          mobileOpen ? "translate-x-0" : "-translate-x-full",
        )}
      >
        <div className="flex items-center justify-between border-b border-border px-5 py-4">
          <BrandMark />
          <button
            onClick={() => setMobileOpen(false)}
            className="rounded-lg p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
            aria-label="Tutup menu"
          >
            <X className="size-5" />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto px-3 py-4">
          <div className="mb-3 px-3">
            <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Menu Utama</p>
          </div>
          <nav className="flex flex-col gap-1">
            {visibleNav.map((item) => {
              const active = pathname === item.href || (item.href !== "/admin" && item.href !== "/guru" && pathname.startsWith(`${item.href}/`));
              const Icon = item.icon;

              return (
                <Link
                  key={item.href}
                  href={item.href}
                  onClick={() => setMobileOpen(false)}
                  className={cn(
                    "flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium transition-all",
                    active
                      ? "bg-primary text-primary-foreground shadow-sm font-semibold"
                      : "text-muted-foreground hover:bg-accent hover:text-foreground",
                  )}
                >
                  <Icon className="size-4.5 shrink-0" />
                  <span>{item.label}</span>
                </Link>
              );
            })}
          </nav>
        </div>

        <div className="border-t border-border bg-card/60 p-4">
          <div className="flex items-center gap-3">
            <span className="flex size-9 items-center justify-center rounded-full bg-primary/10 text-primary font-bold text-sm">
              {user?.name?.charAt(0) ?? "U"}
            </span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-semibold">{user?.name}</p>
              <p className="truncate text-xs text-muted-foreground">
                {unitLabel ?? (user?.role === "admin" ? "Admin Pusat" : user?.role)}
              </p>
            </div>
          </div>
          <Button variant="ghost" size="sm" onClick={logout} className="mt-3 w-full justify-start gap-2 text-destructive hover:bg-destructive/10 hover:text-destructive">
            <LogOut className="size-4" />
            Keluar dari Akun
          </Button>
        </div>
      </aside>

      {/* Desktop Fixed Sidebar - sticky at full viewport height so it stays
          put while the (possibly much taller) main content scrolls; only the
          nav list itself scrolls internally if it ever overflows, the brand
          header and user card stay pinned. */}
      <aside className="hidden border-r border-border bg-card md:flex md:h-dvh md:sticky md:top-0 md:w-64 md:shrink-0 md:flex-col">
        <div className="border-b border-border/70 px-5 py-4.5 shrink-0">
          <BrandMark />
        </div>

        <div className="flex-1 overflow-y-auto px-3 py-4">
          <div className="mb-2 px-3">
            <p className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground/80">Menu Navigasi</p>
          </div>
          <nav className="flex flex-col gap-1">
            {visibleNav.map((item) => {
              const active = pathname === item.href || (item.href !== "/admin" && item.href !== "/guru" && pathname.startsWith(`${item.href}/`));
              const Icon = item.icon;

              return (
                <Link
                  key={item.href}
                  href={item.href}
                  className={cn(
                    "flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium transition-all",
                    active
                      ? "bg-primary text-primary-foreground shadow-sm font-semibold"
                      : "text-muted-foreground hover:bg-accent/70 hover:text-foreground",
                  )}
                >
                  <Icon className="size-4.5 shrink-0" />
                  <span>{item.label}</span>
                </Link>
              );
            })}
          </nav>
        </div>

        {/* User Card in Desktop Sidebar */}
        <div className="border-t border-border bg-card/40 p-4 shrink-0">
          <div className="flex items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary font-bold text-sm shadow-2xs">
              {user?.name?.charAt(0) ?? "U"}
            </span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-semibold text-foreground">{user?.name}</p>
              <div className="flex items-center gap-1 text-[11px] text-muted-foreground">
                <ShieldCheck className="size-3 text-primary shrink-0" />
                <span className="truncate">{unitLabel ?? (user?.role === "admin" ? "Admin Pusat" : user?.role)}</span>
              </div>
            </div>
          </div>
          <Button
            variant="ghost"
            size="sm"
            onClick={logout}
            className="mt-3 w-full justify-start gap-2 rounded-lg text-muted-foreground hover:bg-destructive/10 hover:text-destructive transition-colors"
          >
            <LogOut className="size-4" />
            <span>Keluar</span>
          </Button>
        </div>
      </aside>

      {/* Main Content Area */}
      <div className="min-w-0 flex-1 flex flex-col">
        {/* Mobile Header Bar */}
        <header className="sticky top-0 z-30 flex items-center justify-between border-b border-border bg-card/95 backdrop-blur px-4 py-3 md:hidden">
          <button
            onClick={() => setMobileOpen(true)}
            className="flex items-center justify-center rounded-lg p-2 text-foreground hover:bg-accent"
            aria-label="Buka menu navigasi"
          >
            <Menu className="size-5" />
          </button>
          <BrandMark />
          <div className="size-8 rounded-full bg-primary/10 text-primary flex items-center justify-center text-xs font-bold">
            {user?.name?.charAt(0) ?? "U"}
          </div>
        </header>

        {/* Fullspan Content Container */}
        <main className="w-full flex-1 max-w-7xl 2xl:max-w-full mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 md:py-8">
          {children}
        </main>
      </div>
    </div>
  );
}

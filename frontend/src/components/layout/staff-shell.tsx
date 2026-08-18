"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { LogOut } from "lucide-react";
import { BrandMark } from "@/components/brand-mark";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/lib/auth/auth-context";
import { cn } from "@/lib/utils";

export type StaffNavItem = {
  href: string;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  /** Central-admin-only items are hidden entirely for admin_unit, rather than shown disabled - they have no reason to know a screen exists that they can never open. */
  centralOnly?: boolean;
};

/**
 * The staff shell: a left sidebar plus a topbar, shared by /admin and /guru.
 *
 * One component rather than two near-identical ones, because the only real
 * difference between an admin's screen and a teacher's is which nav items
 * they get - the frame around them is the same app.
 */
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

  const visibleNav = nav.filter((item) => !item.centralOnly || user?.role === "admin");

  return (
    <div className="min-h-dvh bg-canvas md:flex">
      <aside className="border-b border-border bg-card md:w-56 md:shrink-0 md:border-b-0 md:border-r">
        <div className="flex items-center justify-between gap-3 px-4 py-3.5 md:flex-col md:items-start md:gap-4">
          <BrandMark />
          <span className="text-xs text-muted-foreground md:hidden">{user?.name}</span>
        </div>

        <nav className="flex gap-1 overflow-x-auto px-2 pb-2 md:flex-col md:gap-0.5 md:overflow-visible md:px-3 md:pb-4">
          {visibleNav.map((item) => {
            const active = pathname === item.href || pathname.startsWith(`${item.href}/`);
            const Icon = item.icon;

            return (
              <Link
                key={item.href}
                href={item.href}
                className={cn(
                  "flex shrink-0 items-center gap-2.5 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium transition-colors",
                  active ? "bg-accent text-accent-foreground" : "text-muted-foreground hover:bg-canvas hover:text-foreground",
                )}
              >
                <Icon className="size-4" />
                {item.label}
              </Link>
            );
          })}
        </nav>

        <div className="hidden border-t border-border p-3 md:block">
          <p className="truncate text-sm font-medium">{user?.name}</p>
          <p className="truncate text-xs text-muted-foreground">
            {unitLabel ?? (user?.role === "admin" ? "Admin pusat" : "")}
          </p>
          <Button variant="ghost" size="sm" onClick={logout} className="mt-2 w-full justify-start px-2">
            <LogOut className="size-4" />
            Keluar
          </Button>
        </div>
      </aside>

      <div className="min-w-0 flex-1">
        <header className="flex items-center justify-end border-b border-border bg-card px-4 py-2.5 md:hidden">
          <Button variant="ghost" size="sm" onClick={logout}>
            <LogOut className="size-4" />
            Keluar
          </Button>
        </header>

        <main className="mx-auto max-w-5xl px-4 py-6 md:px-8 md:py-8">{children}</main>
      </div>
    </div>
  );
}

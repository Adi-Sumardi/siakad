"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import {
  Award,
  BadgePercent,
  GraduationCap,
  LayoutDashboard,
  Megaphone,
  Percent,
  Receipt,
  ScrollText,
  SlidersHorizontal,
  Sparkles,
  Users,
  Wallet,
} from "lucide-react";
import { StaffShell, type StaffNavItem } from "@/components/layout/staff-shell";
import { Skeleton } from "@/components/ui/skeleton";
import { homePathFor, useAuth } from "@/lib/auth/auth-context";

const NAV: StaffNavItem[] = [
  { href: "/admin", label: "Ringkasan", icon: LayoutDashboard },
  { href: "/admin/siswa", label: "Data Siswa & SPP", icon: GraduationCap },
  { href: "/admin/tagihan", label: "Tagihan & Transaksi", icon: Receipt },
  { href: "/admin/generate", label: "Terbitkan SPP Massal", icon: Wallet },
  { href: "/admin/tarif", label: "Pengaturan Biaya & SPP", icon: SlidersHorizontal },
  { href: "/admin/diskon", label: "Kelola Diskon & Beasiswa", icon: BadgePercent },
  { href: "/admin/laporan", label: "Laporan Keuangan", icon: ScrollText },
  { href: "/admin/users", label: "Manajemen Pengguna", icon: Users, centralOnly: true },
  { href: "/admin/poin", label: "Poin & Tata Tertib", icon: Sparkles },
  { href: "/admin/prestasi", label: "Prestasi Siswa", icon: Award },
  { href: "/admin/informasi", label: "Pengumuman", icon: Megaphone },
];

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const { user, loading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (loading) return;
    if (!user) {
      router.replace("/login");
      return;
    }
    if (user.role !== "admin" && user.role !== "admin_unit") {
      router.replace(homePathFor(user.role));
    }
  }, [loading, user, router]);

  if (loading || !user || (user.role !== "admin" && user.role !== "admin_unit")) {
    return (
      <div className="flex min-h-dvh items-center justify-center bg-canvas p-6">
        <Skeleton className="h-10 w-48" />
      </div>
    );
  }

  return (
    <StaffShell nav={NAV} unitLabel={user.school_unit?.label}>
      {children}
    </StaffShell>
  );
}

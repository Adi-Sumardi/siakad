"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import {
  ArrowUpCircle,
  Award,
  BadgePercent,
  Building2,
  CalendarCheck2,
  CalendarClock,
  ClipboardList,
  GraduationCap,
  History,
  LayoutDashboard,
  Megaphone,
  Radar,
  Receipt,
  School,
  ScrollText,
  SlidersHorizontal,
  Sparkles,
  Trophy,
  Users,
  Wallet,
} from "lucide-react";
import { StaffShell, type StaffNavItem } from "@/components/layout/staff-shell";
import { Skeleton } from "@/components/ui/skeleton";
import { homePathFor, useAuth } from "@/lib/auth/auth-context";

// Grouped by what the admin is doing, not by when each page was built -
// the flat list had grown to 21 entries with finance, academics and system
// tools interleaved.
const NAV: StaffNavItem[] = [
  { href: "/admin", label: "Ringkasan", icon: LayoutDashboard },

  { href: "/admin/siswa", label: "Data Siswa & SPP", icon: GraduationCap, group: "Siswa & Kelas" },
  { href: "/admin/kelas", label: "Data Kelas", icon: School, group: "Siswa & Kelas" },
  { href: "/admin/kenaikan-kelas", label: "Kenaikan Kelas", icon: ArrowUpCircle, group: "Siswa & Kelas" },

  { href: "/admin/jadwal", label: "Jadwal Pelajaran", icon: CalendarClock, group: "Akademik" },
  { href: "/admin/presensi-harian", label: "Presensi Harian", icon: CalendarCheck2, group: "Akademik" },
  { href: "/admin/nilai", label: "Nilai & Rapor", icon: ClipboardList, group: "Akademik" },

  { href: "/admin/ekstrakurikuler", label: "Ekstrakurikuler", icon: Trophy, group: "Kesiswaan" },
  { href: "/admin/poin", label: "Poin & Tata Tertib", icon: Sparkles, group: "Kesiswaan" },
  { href: "/admin/prestasi", label: "Prestasi Siswa", icon: Award, group: "Kesiswaan" },
  { href: "/admin/informasi", label: "Pengumuman", icon: Megaphone, group: "Kesiswaan" },

  { href: "/admin/tagihan", label: "Tagihan & Transaksi", icon: Receipt, group: "Keuangan" },
  { href: "/admin/generate", label: "Terbitkan SPP Massal", icon: Wallet, group: "Keuangan" },
  { href: "/admin/laporan", label: "Laporan Keuangan", icon: ScrollText, group: "Keuangan" },
  { href: "/admin/tarif", label: "Pengaturan Biaya & SPP", icon: SlidersHorizontal, group: "Keuangan" },
  { href: "/admin/diskon", label: "Kelola Diskon & Beasiswa", icon: BadgePercent, group: "Keuangan" },

  // Not centralOnly: a per-unit admin onboards their own unit's guru
  // accounts here (create + CSV import); editing/deleting any account stays
  // central-only and the page hides those buttons for them.
  { href: "/admin/users", label: "Manajemen Pengguna", icon: Users, group: "Sistem" },
  { href: "/admin/unit", label: "Manajemen Unit", icon: Building2, centralOnly: true, group: "Sistem" },
  { href: "/admin/log-aktivitas", label: "Log Aktivitas", icon: History, centralOnly: true, group: "Sistem" },
  // The ruang kontrol (audit C7): failed notifications with manual resend,
  // the webhook inbox, and the queue's dead-letter shelf - central only,
  // same stance as the log viewer next to it.
  { href: "/admin/monitoring", label: "Monitoring", icon: Radar, centralOnly: true, group: "Sistem" },
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

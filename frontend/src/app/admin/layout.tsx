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
import { StaffShell, type StaffNavSection } from "@/components/layout/staff-shell";
import { Skeleton } from "@/components/ui/skeleton";
import { homePathFor, useAuth } from "@/lib/auth/auth-context";

// Grouped so the sidebar stays scannable (20 flat items was too busy):
// every group collapses; the one holding the active route opens on its own,
// and the user's manual open/collapsed choices persist per browser.
// Group ids feed localStorage + aria ids — keep them stable.
const NAV: StaffNavSection[] = [
  { href: "/admin", label: "Ringkasan", icon: LayoutDashboard },
  {
    id: "keuangan",
    label: "Keuangan",
    items: [
      { href: "/admin/tagihan", label: "Tagihan & Transaksi", icon: Receipt },
      { href: "/admin/generate", label: "Terbitkan SPP Massal", icon: Wallet },
      { href: "/admin/tarif", label: "Pengaturan Biaya & SPP", icon: SlidersHorizontal },
      { href: "/admin/diskon", label: "Kelola Diskon & Beasiswa", icon: BadgePercent },
      { href: "/admin/laporan", label: "Laporan Keuangan", icon: ScrollText },
    ],
  },
  {
    id: "akademik",
    label: "Akademik & Kesiswaan",
    items: [
      { href: "/admin/siswa", label: "Data Siswa & SPP", icon: GraduationCap },
      { href: "/admin/kelas", label: "Data Kelas", icon: School },
      { href: "/admin/jadwal", label: "Jadwal Pelajaran", icon: CalendarClock },
      { href: "/admin/presensi-harian", label: "Presensi Harian", icon: CalendarCheck2 },
      { href: "/admin/nilai", label: "Nilai & Rapor", icon: ClipboardList },
      { href: "/admin/kenaikan-kelas", label: "Kenaikan Kelas", icon: ArrowUpCircle },
    ],
  },
  {
    id: "pembinaan",
    label: "Pembinaan",
    items: [
      { href: "/admin/poin", label: "Poin & Tata Tertib", icon: Sparkles },
      { href: "/admin/prestasi", label: "Prestasi Siswa", icon: Award },
      { href: "/admin/ekstrakurikuler", label: "Ekstrakurikuler", icon: Trophy },
      { href: "/admin/informasi", label: "Pengumuman", icon: Megaphone },
    ],
  },
  {
    id: "sistem",
    label: "Sistem & Pengguna",
    items: [
      // Not centralOnly: a per-unit admin onboards their own unit's guru
      // accounts here (create + CSV import); editing/deleting any account stays
      // central-only and the page hides those buttons for them.
      { href: "/admin/users", label: "Manajemen Pengguna", icon: Users },
      { href: "/admin/unit", label: "Manajemen Unit", icon: Building2, centralOnly: true },
      { href: "/admin/log-aktivitas", label: "Log Aktivitas", icon: History, centralOnly: true },
      // The ruang kontrol (audit C7): failed notifications with manual resend,
      // the webhook inbox, and the queue's dead-letter shelf - central only,
      // same stance as the log viewer next to it.
      { href: "/admin/monitoring", label: "Monitoring", icon: Radar, centralOnly: true },
    ],
  },
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

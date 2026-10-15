"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { Award, CalendarCheck2, CheckSquare, ClipboardList, School, Trophy } from "lucide-react";
import { StaffShell, type StaffNavItem } from "@/components/layout/staff-shell";
import { Skeleton } from "@/components/ui/skeleton";
import { homePathFor, useAuth } from "@/lib/auth/auth-context";
import { usePendingApprovals } from "@/lib/use-pending-approvals";

const NAV: StaffNavItem[] = [
  { href: "/guru", label: "Kelas saya", icon: School, tour: "kelas" },
  { href: "/guru/presensi-harian", label: "Presensi Harian", icon: CalendarCheck2, tour: "presensi-harian" },
  { href: "/guru/nilai", label: "Nilai", icon: ClipboardList, tour: "nilai" },
  { href: "/guru/prestasi", label: "Catat prestasi", icon: Award, tour: "prestasi" },
  { href: "/guru/persetujuan-poin", label: "Persetujuan Poin", icon: CheckSquare, tour: "persetujuan-poin" },
  { href: "/guru/ekskul", label: "Ekskul Saya", icon: Trophy, tour: "ekskul" },
];

export default function GuruLayout({ children }: { children: React.ReactNode }) {
  const { user, loading } = useAuth();
  const router = useRouter();
  const pendingApprovals = usePendingApprovals(user?.role === "guru" ? "/api/guru" : null);

  useEffect(() => {
    if (loading) return;
    if (!user) {
      router.replace("/login");
      return;
    }
    if (user.role !== "guru") {
      router.replace(homePathFor(user.role));
    }
  }, [loading, user, router]);

  if (loading || !user || user.role !== "guru") {
    return (
      <div className="flex min-h-dvh items-center justify-center bg-canvas p-6">
        <Skeleton className="h-10 w-48" />
      </div>
    );
  }

  return (
    <StaffShell
      nav={NAV.map((item) => (item.href === "/guru/persetujuan-poin" ? { ...item, badge: pendingApprovals } : item))}
      unitLabel={user.school_unit?.label}
    >
      {children}
    </StaffShell>
  );
}

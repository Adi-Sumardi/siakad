"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { Award, School } from "lucide-react";
import { StaffShell, type StaffNavItem } from "@/components/layout/staff-shell";
import { Skeleton } from "@/components/ui/skeleton";
import { homePathFor, useAuth } from "@/lib/auth/auth-context";

const NAV: StaffNavItem[] = [
  { href: "/guru", label: "Kelas saya", icon: School },
  { href: "/guru/prestasi", label: "Catat prestasi", icon: Award },
];

export default function GuruLayout({ children }: { children: React.ReactNode }) {
  const { user, loading } = useAuth();
  const router = useRouter();

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
    <StaffShell nav={NAV} unitLabel={user.school_unit?.label}>
      {children}
    </StaffShell>
  );
}

"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Award, Receipt, Wallet } from "lucide-react";
import { toast } from "sonner";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuth } from "@/lib/auth/auth-context";
import { api, ApiError } from "@/lib/api";
import { rupiah } from "@/lib/format";

type ReceivablesSummary = {
  outstanding: number;
  bills: number;
  families: number;
  overdue_bills: number;
};

function Tile({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Card className="p-5">
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="tabular mt-1 text-2xl font-bold">{value}</p>
      {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
    </Card>
  );
}

export default function AdminHomePage() {
  const { user } = useAuth();
  const [summary, setSummary] = useState<ReceivablesSummary | null>(null);
  const [pendingAchievements, setPendingAchievements] = useState<number | null>(null);

  useEffect(() => {
    api
      .get<{ summary: ReceivablesSummary }>("/api/admin/reports/receivables")
      .then((d) => setSummary(d.summary))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat ringkasan tunggakan."));
    api
      .get<{ achievements: unknown[] }>("/api/admin/achievements?status=pending")
      .then((d) => setPendingAchievements(d.achievements.length))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat prestasi menunggu."));
  }, []);

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-xl font-bold tracking-tight">
          {user?.role === "admin" ? "Ringkasan sekolah" : `Ringkasan ${user?.school_unit?.label ?? "unit"}`}
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">Assalamu&apos;alaikum, {user?.name}.</p>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {summary ? (
          <>
            <Tile label="Tunggakan" value={rupiah(summary.outstanding)} hint={`${summary.families} keluarga`} />
            <Tile label="Tagihan belum lunas" value={String(summary.bills)} />
            <Tile label="Lewat jatuh tempo" value={String(summary.overdue_bills)} />
          </>
        ) : (
          <>
            <Skeleton className="h-24 w-full" />
            <Skeleton className="h-24 w-full" />
            <Skeleton className="h-24 w-full" />
          </>
        )}
        {pendingAchievements === null ? (
          <Skeleton className="h-24 w-full" />
        ) : (
          <Tile label="Prestasi menunggu" value={String(pendingAchievements)} hint="perlu diverifikasi" />
        )}
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <Link href="/admin/generate">
          <Card className="flex items-center gap-3 p-5 transition-colors hover:border-primary">
            <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-accent text-accent-foreground">
              <Wallet className="size-5" />
            </span>
            <div>
              <p className="font-semibold">Terbitkan SPP</p>
              <p className="text-sm text-muted-foreground">Pratinjau lalu jalankan</p>
            </div>
          </Card>
        </Link>
        <Link href="/admin/tagihan">
          <Card className="flex items-center gap-3 p-5 transition-colors hover:border-primary">
            <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-accent text-accent-foreground">
              <Receipt className="size-5" />
            </span>
            <div>
              <p className="font-semibold">Kelola tagihan</p>
              <p className="text-sm text-muted-foreground">Bebaskan, batalkan, catat tunai</p>
            </div>
          </Card>
        </Link>
        <Link href="/admin/prestasi">
          <Card className="flex items-center gap-3 p-5 transition-colors hover:border-primary">
            <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-accent text-accent-foreground">
              <Award className="size-5" />
            </span>
            <div>
              <p className="font-semibold">Verifikasi prestasi</p>
              <p className="text-sm text-muted-foreground">Pengajuan dari wali murid</p>
            </div>
          </Card>
        </Link>
      </div>
    </div>
  );
}

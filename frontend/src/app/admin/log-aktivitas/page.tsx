"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";
import { tanggalWaktu } from "@/lib/format";

type LogRow = {
  ulid: string;
  user: { ulid: string; name: string; role: string } | null;
  action: string;
  subject_type: string | null;
  subject_ulid: string | null;
  meta: Record<string, unknown> | null;
  created_at: string | null;
};
type Paginated<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number } };

// The badge answers "which part of the school does this row touch" at a
// glance; the raw action next to it carries the precision. Point colors stay
// away from the payment status tokens (R11 in PROGRESS-MAGANG) - poin uses
// primary, money uses warn.
const CATEGORY: Record<string, { label: string; variant: "default" | "primary" | "good" | "warn" }> = {
  bill: { label: "Keuangan", variant: "warn" },
  payment: { label: "Keuangan", variant: "warn" },
  billing_run: { label: "Keuangan", variant: "warn" },
  fee_type: { label: "Tarif", variant: "warn" },
  fee_rate: { label: "Tarif", variant: "warn" },
  fee_rates: { label: "Tarif", variant: "warn" },
  discount_scheme: { label: "Diskon", variant: "warn" },
  student_discount: { label: "Diskon", variant: "warn" },
  point: { label: "Poin", variant: "primary" },
  point_rule: { label: "Poin", variant: "primary" },
  point_threshold: { label: "Poin", variant: "primary" },
  achievement: { label: "Prestasi", variant: "good" },
};

const ROLE_LABEL: Record<string, string> = {
  admin: "Admin",
  admin_unit: "Admin Unit",
  guru: "Guru",
  orangtua: "Wali",
};

function categoryFor(action: string) {
  return CATEGORY[action.split(".")[0]] ?? { label: "Lainnya", variant: "default" as const };
}

function metaText(meta: LogRow["meta"]): string {
  if (!meta) return "—";
  const entries = Object.entries(meta).filter(([, v]) => v !== null && v !== "");
  if (entries.length === 0) return "—";
  return entries
    .map(([k, v]) => `${k}: ${typeof v === "object" ? JSON.stringify(v) : String(v)}`)
    .join(" · ");
}

export default function ActivityLogPage() {
  const { user } = useAuth();

  // Draft filter inputs; committed only by the Terapkan button so typing
  // doesn't fire a request per keystroke.
  const [draftAction, setDraftAction] = useState("");
  const [draftUser, setDraftUser] = useState("");
  const [draftFrom, setDraftFrom] = useState("");
  const [draftTo, setDraftTo] = useState("");
  const [action, setAction] = useState("");
  const [userName, setUserName] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const [logs, setLogs] = useState<Paginated<LogRow> | null>(null);

  const load = useCallback(async () => {
    const params = new URLSearchParams();
    if (action) params.set("action", action);
    if (userName) params.set("user", userName);
    if (from) params.set("from", from);
    if (to) params.set("to", to);
    params.set("page", String(page));

    try {
      const d = await api.get<{ logs: Paginated<LogRow> }>(`/api/admin/activity-logs?${params}`);
      setLogs(d.logs);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memuat log aktivitas.");
    }
  }, [action, userName, from, to, page]);

  useEffect(() => {
    if (user?.role === "admin") load();
  }, [load, user]);

  function applyFilters() {
    setAction(draftAction.trim());
    setUserName(draftUser.trim());
    setFrom(draftFrom);
    setTo(draftTo);
    setPage(1);
  }

  function resetFilters() {
    setDraftAction(""); setDraftUser(""); setDraftFrom(""); setDraftTo("");
    setAction(""); setUserName(""); setFrom(""); setTo("");
    setPage(1);
  }

  if (user && user.role !== "admin") {
    return (
      <div className="flex flex-col gap-5">
        <Link href="/admin" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" />
          Ringkasan
        </Link>
        <Card className="p-6 text-sm text-muted-foreground">
          Log aktivitas hanya dapat dilihat oleh admin pusat.
        </Card>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <Link href="/admin" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" />
        Ringkasan
      </Link>

      <div>
        <h1 className="text-xl font-bold tracking-tight">Log aktivitas</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Jejak "siapa melakukan apa, kapan" — setiap aksi uang dan poin tercatat otomatis. Hanya bisa dibaca, tidak bisa diubah.
        </p>
      </div>

      <Card className="flex flex-wrap items-end gap-3 p-5">
        <div className="flex flex-col gap-1.5">
          <Label>Aksi</Label>
          <Input value={draftAction} onChange={(e) => setDraftAction(e.target.value)} className="w-44" placeholder="mis. point. atau bill." />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label>Pelaku</Label>
          <Input value={draftUser} onChange={(e) => setDraftUser(e.target.value)} className="w-44" placeholder="Nama staf/guru" />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label>Dari</Label>
          <Input value={draftFrom} onChange={(e) => setDraftFrom(e.target.value)} type="date" className="w-40" />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label>Sampai</Label>
          <Input value={draftTo} onChange={(e) => setDraftTo(e.target.value)} type="date" className="w-40" />
        </div>
        <Button onClick={applyFilters}>Terapkan</Button>
        <Button variant="ghost" onClick={resetFilters}>Reset</Button>
      </Card>

      <div className="flex flex-col gap-2">
        {logs === null && <Skeleton className="h-40 w-full" />}
        {logs?.data.length === 0 && (
          <Card className="p-6 text-sm text-muted-foreground">Tidak ada aktivitas yang cocok dengan filter.</Card>
        )}
        {logs?.data.map((row) => {
          const cat = categoryFor(row.action);
          return (
            <Card key={row.ulid} className="flex flex-wrap items-start justify-between gap-3 p-4">
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant={cat.variant}>{cat.label}</Badge>
                  <span className="font-medium">{row.action}</span>
                </div>
                <p className="mt-1 text-sm text-muted-foreground">
                  {row.subject_type
                    ? `${row.subject_type}${row.subject_ulid ? ` · ${row.subject_ulid}` : " · (sudah dihapus)"}`
                    : "—"}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">{metaText(row.meta)}</p>
              </div>
              <div className="text-right text-sm">
                <p className="font-medium">{row.user?.name ?? "Sistem"}</p>
                <p className="text-xs text-muted-foreground">{row.user ? ROLE_LABEL[row.user.role] ?? row.user.role : "—"}</p>
                <p className="mt-0.5 text-xs text-muted-foreground">{tanggalWaktu(row.created_at)}</p>
              </div>
            </Card>
          );
        })}
      </div>

      {logs && logs.data.length > 0 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">
            Halaman {logs.meta.current_page} dari {logs.meta.last_page} · {logs.meta.total} aktivitas
          </p>
          <div className="flex gap-2">
            <Button size="sm" variant="outline" disabled={logs.meta.current_page <= 1} onClick={() => setPage((p) => p - 1)}>
              Sebelumnya
            </Button>
            <Button size="sm" variant="outline" disabled={logs.meta.current_page >= logs.meta.last_page} onClick={() => setPage((p) => p + 1)}>
              Berikutnya
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

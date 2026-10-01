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
import { Pagination } from "@/components/ui/pagination";

type LogRow = {
  ulid: string;
  user: { ulid: string; name: string; role: string } | null;
  // As they were when it happened (2026-10-01).
  user_name: string | null;
  role: string | null;
  unit: string | null;
  action: string;
  label: string;
  category: string;
  status: number | null;
  success: boolean;
  subject_type: string | null;
  subject_ulid: string | null;
  meta: Record<string, unknown> | null;
  created_at: string | null;
};
type Paginated<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; total: number };
  options?: { units: { id: number; label: string }[]; categories: string[] };
};

// The badge answers "which part of the school does this row touch" at a
// glance. Point colors stay away from the payment status tokens (R11 in
// PROGRESS-MAGANG) - poin uses primary, money uses warn.
const CATEGORY_VARIANT: Record<string, "default" | "primary" | "good" | "warn"> = {
  Keuangan: "warn",
  "Tarif & Diskon": "warn",
  Poin: "primary",
  Prestasi: "good",
};

const SELECT = "h-9 rounded-md border border-input bg-transparent px-2 text-sm";

const ROLE_LABEL: Record<string, string> = {
  admin: "Admin pusat",
  admin_unit: "Admin Unit",
  guru: "Guru",
  orangtua: "Wali",
};

function metaText(meta: LogRow["meta"]): string {
  if (!meta) return "—";
  const entries = Object.entries(meta).filter(([k, v]) => v !== null && v !== "" && k !== "data" && k !== "fields");
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
  const [draftRole, setDraftRole] = useState("");
  const [draftUnit, setDraftUnit] = useState("");
  const [draftCategory, setDraftCategory] = useState("");
  const [draftStatus, setDraftStatus] = useState("");
  const [role, setRole] = useState("");
  const [unit, setUnit] = useState("");
  const [category, setCategory] = useState("");
  const [status, setStatus] = useState("");
  const [action, setAction] = useState("");
  const [userName, setUserName] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const [logs, setLogs] = useState<Paginated<LogRow> | null>(null);

  // .then() chains (not async/await) so setState only ever runs in an async
  // callback - the effect below calls this synchronously, and awaiting first
  // still trips react-hooks/set-state-in-effect's analysis.
  const load = useCallback(() => {
    const params = new URLSearchParams();
    if (action) params.set("action", action);
    if (userName) params.set("user", userName);
    if (from) params.set("from", from);
    if (to) params.set("to", to);
    if (role) params.set("role", role);
    if (unit) params.set("unit", unit);
    if (category) params.set("category", category);
    if (status) params.set("status", status);
    params.set("page", String(page));

    api
      .get<{ logs: Paginated<LogRow> }>(`/api/admin/activity-logs?${params}`)
      .then((d) => setLogs(d.logs))
      .catch((err) => {
        toast.error(err instanceof ApiError ? err.message : "Gagal memuat log aktivitas.");
      });
  }, [action, userName, from, to, role, unit, category, status, page]);

  useEffect(() => {
    if (user?.role === "admin") load();
  }, [load, user]);

  function applyFilters() {
    setAction(draftAction.trim());
    setUserName(draftUser.trim());
    setFrom(draftFrom);
    setTo(draftTo);
    setRole(draftRole);
    setUnit(draftUnit);
    setCategory(draftCategory);
    setStatus(draftStatus);
    setPage(1);
  }

  function resetFilters() {
    setDraftAction(""); setDraftUser(""); setDraftFrom(""); setDraftTo("");
    setDraftRole(""); setDraftUnit(""); setDraftCategory(""); setDraftStatus("");
    setAction(""); setUserName(""); setFrom(""); setTo("");
    setRole(""); setUnit(""); setCategory(""); setStatus("");
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
          Jejak &quot;siapa melakukan apa, kapan, dari unit mana&quot; — setiap perubahan data dan unduhan oleh admin pusat dan
          admin unit, serta aksi guru, tercatat otomatis. Hanya bisa dibaca, tidak bisa diubah.
        </p>
      </div>

      <Card className="flex flex-wrap items-end gap-3 p-5">
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="log-role">Role</Label>
          <select id="log-role" value={draftRole} onChange={(e) => setDraftRole(e.target.value)} className={SELECT}>
            <option value="">Semua</option>
            <option value="admin">Admin pusat</option>
            <option value="admin_unit">Admin unit</option>
            <option value="guru">Guru</option>
            <option value="orangtua">Wali</option>
          </select>
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="log-unit">Unit</Label>
          <select id="log-unit" value={draftUnit} onChange={(e) => setDraftUnit(e.target.value)} className={SELECT}>
            <option value="">Semua unit</option>
            <option value="pusat">Pusat (tanpa unit)</option>
            {logs?.options?.units.map((u) => (
              <option key={u.id} value={u.id}>{u.label}</option>
            ))}
          </select>
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="log-category">Kategori</Label>
          <select id="log-category" value={draftCategory} onChange={(e) => setDraftCategory(e.target.value)} className={SELECT}>
            <option value="">Semua</option>
            {logs?.options?.categories.map((c) => (
              <option key={c} value={c}>{c}</option>
            ))}
          </select>
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="log-status">Status</Label>
          <select id="log-status" value={draftStatus} onChange={(e) => setDraftStatus(e.target.value)} className={SELECT}>
            <option value="">Semua</option>
            <option value="success">Berhasil</option>
            <option value="failed">Gagal / ditolak</option>
          </select>
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="log-action">Kode aksi</Label>
          <Input id="log-action" value={draftAction} onChange={(e) => setDraftAction(e.target.value)} className="w-40" placeholder="mis. bill." />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="log-user">Pelaku</Label>
          <Input id="log-user" value={draftUser} onChange={(e) => setDraftUser(e.target.value)} className="w-44" placeholder="Nama admin/guru" />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="log-from">Dari</Label>
          <Input id="log-from" value={draftFrom} onChange={(e) => setDraftFrom(e.target.value)} type="date" className="w-40" />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="log-to">Sampai</Label>
          <Input id="log-to" value={draftTo} onChange={(e) => setDraftTo(e.target.value)} type="date" className="w-40" />
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
          const what = (row.meta?.data as string | undefined)
            ?? (row.subject_type ? `${row.subject_type}${row.subject_ulid ? "" : " (sudah dihapus)"}` : null);
          const fields = row.meta?.fields as string[] | undefined;
          const extra = metaText(row.meta);
          return (
            <Card key={row.ulid} className="flex flex-wrap items-start justify-between gap-3 p-4">
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant={CATEGORY_VARIANT[row.category] ?? "default"}>{row.category}</Badge>
                  <span className="font-medium">{row.label}</span>
                  {!row.success && (
                    <Badge variant="bad" title={row.status ? `HTTP ${row.status}` : undefined}>
                      {row.status === 403 ? "Ditolak" : "Gagal"}
                    </Badge>
                  )}
                </div>
                {what && <p className="mt-1 text-sm text-muted-foreground">{what}</p>}
                {fields && fields.length > 0 && (
                  <p className="mt-0.5 text-xs text-muted-foreground">Field: {fields.join(", ")}</p>
                )}
                {extra !== "—" && <p className="mt-0.5 text-xs text-muted-foreground">{extra}</p>}
                <p className="mt-0.5 text-[11px] text-muted-foreground/80">{row.action}</p>
              </div>
              <div className="text-right text-sm">
                <p className="font-medium">{row.user_name ?? "Sistem"}</p>
                <p className="text-xs text-muted-foreground">
                  {row.role ? ROLE_LABEL[row.role] ?? row.role : "—"} · {row.unit ?? "Pusat"}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">{tanggalWaktu(row.created_at)}</p>
              </div>
            </Card>
          );
        })}
      </div>

      {logs && logs.data.length > 0 && <Pagination meta={logs.meta} onPage={setPage} label="aktivitas" />}
    </div>
  );
}

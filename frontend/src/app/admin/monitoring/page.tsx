"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, RefreshCw } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";
import { tanggalWaktu } from "@/lib/format";
import { cn } from "@/lib/utils";
import { Pagination, type PageMeta } from "@/components/ui/pagination";

type Paginated<T> = { data: T[]; meta: PageMeta };

type NotificationRow = {
  ulid: string;
  channel: "email" | "whatsapp";
  template: string;
  recipient: string;
  status: "queued" | "sent" | "failed";
  attempts: number;
  error: string | null;
  sent_at: string | null;
  created_at: string | null;
};

type EventRow = {
  ulid: string;
  source: string;
  event_type: string;
  event_id: string;
  status: "received" | "processed" | "failed";
  attempts: number;
  student_ulid: string | null;
  error: string | null;
  processed_at: string | null;
  created_at: string | null;
};

type JobRow = {
  uuid: string;
  queue: string;
  display_name: string | null;
  exception: string;
  failed_at: string;
};

const TEMPLATE_LABEL: Record<string, string> = {
  school_account_invite: "Undangan akun",
  bill_reminder: "Pengingat tagihan",
  payment_receipt: "Kuitansi pembayaran",
  daily_masuk: "Presensi masuk",
  daily_pulang: "Presensi pulang",
  daily_absent: "Presensi alpa",
  point_threshold: "Ambang poin",
  login_otp: "OTP login",
};

const SOURCE_LABEL: Record<string, string> = {
  pmb: "PMB",
  billing_api: "e-SPP",
};

const STATUS_LABEL: Record<string, string> = {
  queued: "Menunggu",
  sent: "Terkirim",
  failed: "Gagal",
  received: "Diterima",
  processed: "Diproses",
};

const SELECT_CLASS = "h-10 rounded-lg border border-input bg-card px-3 text-sm text-foreground shadow-2xs";

type Tab = "notifikasi" | "events" | "jobs";

const TABS: Array<{ key: Tab; label: string }> = [
  { key: "notifikasi", label: "Notifikasi" },
  { key: "events", label: "Webhook & Integrasi" },
  { key: "jobs", label: "Antrian Gagal" },
];

/**
 * The ruang kontrol (audit C7 / P4-15): the one screen a central admin
 * watches when things quietly stop working. Three panels, one visible at a
 * time - panels stay mounted after their first visit so filters and
 * pagination survive tab switches. The dashboard's failure card links here.
 */
export default function MonitoringPage() {
  const { user } = useAuth();
  const [tab, setTab] = useState<Tab>("notifikasi");
  // The notifikasi panel is the landing tab; the others mount lazily on
  // first visit and then stay alive behind `hidden`.
  const [opened, setOpened] = useState<Record<Tab, boolean>>({ notifikasi: true, events: false, jobs: false });

  function switchTab(next: Tab) {
    setTab(next);
    setOpened((prev) => ({ ...prev, [next]: true }));
  }

  if (user && user.role !== "admin") {
    return (
      <div className="flex flex-col gap-5">
        <Link href="/admin" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" />
          Ringkasan
        </Link>
        <Card className="p-6 text-sm text-muted-foreground">
          Halaman monitoring hanya dapat dilihat oleh admin pusat.
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
        <h1 className="text-xl font-bold tracking-tight">Ruang Kontrol</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Satu tempat memantau notifikasi yang gagal terkirim (dengan kirim ulang manual), event webhook masuk, dan
          pekerjaan antrian yang menyerah.
        </p>
      </div>

      <div className="flex items-center gap-1 p-1 rounded-xl bg-muted/60 border border-border/60 w-fit">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => switchTab(t.key)}
            className={cn(
              "rounded-lg px-3 py-1.5 text-xs font-bold transition-colors",
              tab === t.key ? "bg-card text-foreground shadow-2xs" : "text-muted-foreground hover:text-foreground",
            )}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* Mounted only once the role is known, so no panel fires a doomed
          request while auth is still loading. */}
      {user?.role === "admin" && (
        <>
          {opened.notifikasi && (
            <div className={tab === "notifikasi" ? "flex flex-col gap-5" : "hidden"}>
              <NotificationPanel />
            </div>
          )}
          {opened.events && (
            <div className={tab === "events" ? "flex flex-col gap-5" : "hidden"}>
              <EventPanel />
            </div>
          )}
          {opened.jobs && (
            <div className={tab === "jobs" ? "flex flex-col gap-5" : "hidden"}>
              <JobPanel />
            </div>
          )}
        </>
      )}
    </div>
  );
}

function PanelHeader({ title, description, onReload }: { title: string; description: string; onReload: () => void }) {
  return (
    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h2 className="text-lg font-bold tracking-tight">{title}</h2>
        <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>
      </div>
      <Button variant="outline" size="sm" onClick={onReload} className="gap-2 self-start sm:self-auto">
        <RefreshCw className="size-4" />
        <span>Muat ulang</span>
      </Button>
    </div>
  );
}

function Pager({ meta, onPage }: { meta: PageMeta; onPage: (next: number) => void }) {
  return <Pagination meta={meta} onPage={onPage} label="baris" />;
}

function NotificationPanel() {
  const [status, setStatus] = useState<"failed" | "sent" | "queued" | "all">("failed");
  const [template, setTemplate] = useState("");
  const [channel, setChannel] = useState("");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [data, setData] = useState<Paginated<NotificationRow> | null>(null);
  const [resending, setResending] = useState<string | null>(null);

  // The admin/page.tsx effect shape (direct promise chain + cancelled guard)
  // rather than log-aktivitas' load-in-effect wrapper: same behaviour, but
  // it satisfies react-hooks/set-state-in-effect and never sets state on an
  // unmounted panel. Manual reloads bump reloadKey instead of calling a
  // wrapper.
  useEffect(() => {
    let cancelled = false;

    const params = new URLSearchParams();
    if (status !== "all") params.set("status", status);
    if (template) params.set("template", template);
    if (channel) params.set("channel", channel);
    params.set("page", String(page));

    api
      .get<{ notifications: Paginated<NotificationRow> }>(`/api/admin/notification-logs?${params}`)
      .then((d) => {
        if (!cancelled) setData(d.notifications);
      })
      .catch((err: unknown) => {
        toast.error(err instanceof ApiError ? err.message : "Gagal memuat notifikasi.");
      });

    return () => {
      cancelled = true;
    };
  }, [status, template, channel, page, reloadKey]);

  const load = useCallback(() => setReloadKey((k) => k + 1), []);

  async function resend(row: NotificationRow) {
    if (!confirm(`Kirim ulang ${TEMPLATE_LABEL[row.template] ?? row.template} ke ${row.recipient}?`)) return;

    setResending(row.ulid);
    try {
      const d = await api.post<{ result: { success: boolean; message: string | null } }>(
        `/api/admin/notification-logs/${row.ulid}/resend`,
      );
      if (d.result.success) {
        toast.success("Notifikasi berhasil dikirim ulang.");
      } else {
        toast.error(`Masih gagal: ${d.result.message ?? "penyebab tidak diketahui"}.`);
      }
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengirim ulang.");
    } finally {
      setResending(null);
    }
  }

  return (
    <>
      <PanelHeader
        title="Notifikasi Email & WhatsApp"
        description="Baris gagal dicoba ulang otomatis maks 3x dalam 24 jam; tombol di bawah adalah kirim ulang manual."
        onReload={load}
      />

      <Card className="flex flex-wrap items-end gap-3 p-5">
        <div className="flex flex-col gap-1.5">
          <Label>Status</Label>
          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value as typeof status);
              setPage(1);
            }}
            className={SELECT_CLASS}
          >
            <option value="failed">Gagal</option>
            <option value="sent">Terkirim</option>
            <option value="queued">Menunggu</option>
            <option value="all">Semua</option>
          </select>
        </div>
        <div className="flex flex-col gap-1.5">
          <Label>Jenis</Label>
          <select
            value={template}
            onChange={(e) => {
              setTemplate(e.target.value);
              setPage(1);
            }}
            className={SELECT_CLASS}
          >
            <option value="">Semua</option>
            {Object.entries(TEMPLATE_LABEL).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </div>
        <div className="flex flex-col gap-1.5">
          <Label>Kanal</Label>
          <select
            value={channel}
            onChange={(e) => {
              setChannel(e.target.value);
              setPage(1);
            }}
            className={SELECT_CLASS}
          >
            <option value="">Semua</option>
            <option value="email">Email</option>
            <option value="whatsapp">WhatsApp</option>
          </select>
        </div>
      </Card>

      <div className="flex flex-col gap-2">
        {data === null && <Skeleton className="h-40 w-full" />}
        {data?.data.length === 0 && (
          <Card className="p-6 text-sm text-muted-foreground">Tidak ada notifikasi yang cocok dengan filter.</Card>
        )}
        {data?.data.map((row) => (
          <Card key={row.ulid} className="flex flex-wrap items-start justify-between gap-3 p-4">
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <Badge variant={row.status === "failed" ? "bad" : row.status === "sent" ? "good" : "default"}>
                  {STATUS_LABEL[row.status]}
                </Badge>
                <Badge variant={row.channel === "whatsapp" ? "primary" : "default"}>
                  {row.channel === "whatsapp" ? "WhatsApp" : "Email"}
                </Badge>
                <span className="font-medium">{TEMPLATE_LABEL[row.template] ?? row.template}</span>
                <span className="text-xs text-muted-foreground">percobaan ke-{row.attempts}</span>
              </div>
              <p className="mt-1 text-sm text-muted-foreground">{row.recipient}</p>
              {row.error && <p className="mt-0.5 text-xs text-bad break-words">{row.error}</p>}
            </div>
            <div className="flex flex-col items-end gap-2 shrink-0">
              <p className="text-xs text-muted-foreground">
                {tanggalWaktu(row.sent_at ?? row.created_at)}
                {row.sent_at ? " (terkirim)" : ""}
              </p>
              {/* login_otp is deliberately excluded: an expired code is
                  worthless on a resend - the user just asks again. */}
              {row.status === "failed" && row.template !== "login_otp" && (
                <Button size="sm" variant="outline" disabled={resending === row.ulid} onClick={() => resend(row)}>
                  {resending === row.ulid ? "Mengirim…" : "Kirim ulang"}
                </Button>
              )}
            </div>
          </Card>
        ))}
      </div>

      {data && data.data.length > 0 && <Pager meta={data.meta} onPage={setPage} />}
    </>
  );
}

function EventPanel() {
  const [source, setSource] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [data, setData] = useState<Paginated<EventRow> | null>(null);
  const [processing, setProcessing] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    const params = new URLSearchParams();
    if (source) params.set("source", source);
    if (status) params.set("status", status);
    params.set("page", String(page));

    api
      .get<{ events: Paginated<EventRow> }>(`/api/admin/integration-events?${params}`)
      .then((d) => {
        if (!cancelled) setData(d.events);
      })
      .catch((err: unknown) => {
        toast.error(err instanceof ApiError ? err.message : "Gagal memuat event integrasi.");
      });

    return () => {
      cancelled = true;
    };
  }, [source, status, page, reloadKey]);

  const load = useCallback(() => setReloadKey((k) => k + 1), []);

  async function reprocess(row: EventRow) {
    if (
      !confirm(
        "Proses ulang event PMB ini? Pastikan penyebab kegagalan sudah diperbaiki (mis. unit sekolah yang belum terdaftar sudah ditambahkan).",
      )
    ) {
      return;
    }

    setProcessing(row.ulid);
    try {
      await api.post(`/api/admin/integration-events/${row.ulid}/reprocess`);
      toast.success("Event dimasukkan kembali ke antrian — muat ulang untuk melihat hasilnya.");
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memproses ulang.");
    } finally {
      setProcessing(null);
    }
  }

  return (
    <>
      <PanelHeader
        title="Webhook & Integrasi"
        description="Kotak masuk event dari sistem luar (PMB, e-SPP). Event PMB yang gagal bisa diproses ulang."
        onReload={load}
      />

      <Card className="flex flex-wrap items-end gap-3 p-5">
        <div className="flex flex-col gap-1.5">
          <Label>Sumber</Label>
          <select
            value={source}
            onChange={(e) => {
              setSource(e.target.value);
              setPage(1);
            }}
            className={SELECT_CLASS}
          >
            <option value="">Semua</option>
            {Object.entries(SOURCE_LABEL).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </div>
        <div className="flex flex-col gap-1.5">
          <Label>Status</Label>
          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            className={SELECT_CLASS}
          >
            <option value="">Semua</option>
            <option value="received">Diterima</option>
            <option value="processed">Diproses</option>
            <option value="failed">Gagal</option>
          </select>
        </div>
      </Card>

      <div className="flex flex-col gap-2">
        {data === null && <Skeleton className="h-40 w-full" />}
        {data?.data.length === 0 && (
          <Card className="p-6 text-sm text-muted-foreground">Tidak ada event yang cocok dengan filter.</Card>
        )}
        {data?.data.map((row) => (
          <Card key={row.ulid} className="flex flex-wrap items-start justify-between gap-3 p-4">
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <Badge variant={row.source === "pmb" ? "default" : "warn"}>
                  {SOURCE_LABEL[row.source] ?? row.source}
                </Badge>
                <Badge variant={row.status === "failed" ? "bad" : row.status === "processed" ? "good" : "default"}>
                  {STATUS_LABEL[row.status]}
                </Badge>
                <span className="font-medium">{row.event_type}</span>
                <span className="text-xs text-muted-foreground">
                  percobaan ke-{Math.max(row.attempts, 1)}
                </span>
              </div>
              <p className="mt-1 font-mono text-xs text-muted-foreground break-all">{row.event_id}</p>
              {row.error && <p className="mt-0.5 text-xs text-bad break-words">{row.error}</p>}
            </div>
            <div className="flex flex-col items-end gap-2 shrink-0">
              <p className="text-xs text-muted-foreground">{tanggalWaktu(row.processed_at ?? row.created_at)}</p>
              {row.source === "pmb" && row.status !== "processed" && (
                <Button size="sm" variant="outline" disabled={processing === row.ulid} onClick={() => reprocess(row)}>
                  {processing === row.ulid ? "Memproses…" : "Proses ulang"}
                </Button>
              )}
            </div>
          </Card>
        ))}
      </div>

      {data && data.data.length > 0 && <Pager meta={data.meta} onPage={setPage} />}
    </>
  );
}

function JobPanel() {
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [data, setData] = useState<Paginated<JobRow> | null>(null);

  useEffect(() => {
    let cancelled = false;

    const params = new URLSearchParams();
    params.set("page", String(page));

    api
      .get<{ jobs: Paginated<JobRow> }>(`/api/admin/failed-jobs?${params}`)
      .then((d) => {
        if (!cancelled) setData(d.jobs);
      })
      .catch((err: unknown) => {
        toast.error(err instanceof ApiError ? err.message : "Gagal memuat antrian gagal.");
      });

    return () => {
      cancelled = true;
    };
  }, [page, reloadKey]);

  const load = useCallback(() => setReloadKey((k) => k + 1), []);

  return (
    <>
      <PanelHeader
        title="Antrian Gagal"
        description="Pekerjaan latar belakang yang menyerah setelah semua percobaannya habis."
        onReload={load}
      />

      <Card className="p-4 text-xs text-muted-foreground">
        Bersifat baca-saja: perbaiki dulu penyebabnya, lalu jalankan{" "}
        <code className="rounded bg-muted px-1.5 py-0.5 font-mono">php artisan queue:retry {"{uuid}"}</code> di server
        untuk menjalankan ulang satu pekerjaan.
      </Card>

      <div className="flex flex-col gap-2">
        {data === null && <Skeleton className="h-40 w-full" />}
        {data?.data.length === 0 && (
          <Card className="p-6 text-sm text-muted-foreground">Tidak ada pekerjaan yang menyerah. Alhamdulillah.</Card>
        )}
        {data?.data.map((row) => (
          <Card key={row.uuid} className="flex flex-wrap items-start justify-between gap-3 p-4">
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <Badge variant="warn">{row.queue}</Badge>
                <span className="font-medium">{row.display_name ?? "Pekerjaan tidak dikenal"}</span>
              </div>
              <p className="mt-1 font-mono text-xs text-muted-foreground break-all">{row.uuid}</p>
              <p className="mt-0.5 text-xs text-bad break-words">{row.exception}</p>
            </div>
            <div className="text-xs text-muted-foreground shrink-0">{tanggalWaktu(row.failed_at)}</div>
          </Card>
        ))}
      </div>

      {data && data.data.length > 0 && <Pager meta={data.meta} onPage={setPage} />}
    </>
  );
}

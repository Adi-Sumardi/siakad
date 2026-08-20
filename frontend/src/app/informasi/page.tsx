"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { FileDown, Megaphone, Pin, RefreshCw } from "lucide-react";
import { toast } from "sonner";
import { WaliShell } from "@/components/layout/wali-shell";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { API_BASE, api, ApiError } from "@/lib/api";
import { useRequireRole } from "@/lib/auth/use-require-role";
import { tanggal } from "@/lib/format";
import type { Announcement } from "@/lib/types/kesiswaan";

const SCOPE_LABEL: Record<Announcement["scope"], string> = {
  school: "Seluruh Sekolah",
  unit: "Unit Sekolah",
  classroom: "Kelas Khusus",
};

export default function AnnouncementsPage() {
  const { user, loading } = useRequireRole("orangtua");
  const [announcements, setAnnouncements] = useState<Announcement[] | null>(null);

  function load() {
    api
      .get<{ announcements: Announcement[] }>("/api/wali/announcements")
      .then((d) => setAnnouncements(d.announcements))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat informasi."));
  }

  useEffect(() => {
    if (user?.role === "orangtua") {
      load();
    }
  }, [user]);

  if (loading || !user || user.role !== "orangtua") {
    return (
      <WaliShell>
        <div className="space-y-4">
          <Skeleton className="h-8 w-48" />
          <Skeleton className="h-32 w-full" />
        </div>
      </WaliShell>
    );
  }

  return (
    <WaliShell>
      <div className="space-y-6">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-foreground">Pusat Informasi & Pengumuman</h1>
            <p className="text-sm text-muted-foreground mt-0.5">
              Kabar terbaru, agenda kegiatan, dan pengumuman resmi dari yayasan, unit, atau wali kelas.
            </p>
          </div>

          <Button variant="outline" size="sm" onClick={load} className="gap-2 self-start sm:self-auto">
            <RefreshCw className="size-4" />
            <span>Segarkan</span>
          </Button>
        </div>

        {announcements === null && (
          <div className="space-y-3">
            <Skeleton className="h-32 w-full rounded-2xl" />
            <Skeleton className="h-32 w-full rounded-2xl" />
          </div>
        )}

        {announcements?.length === 0 && (
          <Card className="p-8 text-center text-sm text-muted-foreground">
            Belum ada pengumuman untuk unit atau kelas ananda saat ini.
          </Card>
        )}

        <div className="grid grid-cols-1 gap-4">
          {announcements?.map((a) => (
            <Card key={a.ulid} className="p-6 border-border/80 hover:border-primary/40 transition-colors">
              <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-2 flex-wrap">
                  <h2 className="text-lg font-bold text-foreground">{a.title}</h2>
                  {a.is_pinned && (
                    <Badge variant="primary" className="gap-1 font-bold text-[10px]">
                      <Pin className="size-3" />
                      <span>DIPASANG</span>
                    </Badge>
                  )}
                </div>
                {a.published_at && (
                  <span className="text-xs font-medium text-muted-foreground shrink-0">
                    {tanggal(a.published_at)}
                  </span>
                )}
              </div>

              <div className="mt-3 text-sm text-muted-foreground whitespace-pre-line leading-relaxed">
                {a.body}
              </div>

              <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-border/60 pt-3">
                <Badge variant="default">
                  {SCOPE_LABEL[a.scope]}
                  {a.classroom && ` · Kelas ${a.classroom}`}
                  {!a.classroom && a.school_unit && ` · ${a.school_unit}`}
                </Badge>

                {a.has_file && (
                  <a
                    href={`${API_BASE}/api/files/announcements/${a.ulid}/file`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1.5 rounded-lg bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary/20 transition-colors"
                  >
                    <FileDown className="size-3.5" />
                    <span>{a.file_name ?? "Unduh Lampiran Dokumen"}</span>
                  </a>
                )}
              </div>
            </Card>
          ))}
        </div>
      </div>
    </WaliShell>
  );
}

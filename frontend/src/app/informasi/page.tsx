"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, FileDown, Pin } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { API_BASE, api } from "@/lib/api";
import { tanggal } from "@/lib/format";
import type { Announcement } from "@/lib/types/kesiswaan";

const SCOPE_LABEL: Record<Announcement["scope"], string> = {
  school: "Seluruh sekolah",
  unit: "Unit",
  classroom: "Kelas",
};

export default function AnnouncementsPage() {
  const [announcements, setAnnouncements] = useState<Announcement[] | null>(null);

  useEffect(() => {
    api.get<{ announcements: Announcement[] }>("/api/wali/announcements").then((d) => setAnnouncements(d.announcements));
  }, []);

  return (
    <div className="min-h-dvh bg-canvas">
      <header className="border-b border-border bg-card">
        <div className="mx-auto max-w-2xl px-6 py-3.5">
          <Link href="/dashboard" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
            <ArrowLeft className="size-4" />
            Beranda
          </Link>
        </div>
      </header>

      <main className="mx-auto max-w-2xl px-6 py-8">
        <h1 className="text-xl font-bold tracking-tight">Informasi</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Pengumuman untuk sekolah, unit, atau kelas anak Anda.
        </p>

        <div className="mt-6 flex flex-col gap-3">
          {announcements === null && <Skeleton className="h-28 w-full" />}

          {announcements?.length === 0 && (
            <Card className="p-6 text-sm text-muted-foreground">Belum ada pengumuman.</Card>
          )}

          {announcements?.map((a) => (
            <Card key={a.ulid} className="flex flex-col gap-2 p-5">
              <div className="flex items-start justify-between gap-3">
                <p className="font-semibold">{a.title}</p>
                {a.is_pinned && <Pin className="size-4 shrink-0 text-primary" />}
              </div>

              <p className="whitespace-pre-line text-sm text-muted-foreground">{a.body}</p>

              <div className="mt-1 flex flex-wrap items-center gap-2">
                <Badge>
                  {SCOPE_LABEL[a.scope]}
                  {a.classroom && ` · ${a.classroom}`}
                  {!a.classroom && a.school_unit && ` · ${a.school_unit}`}
                </Badge>
                {a.published_at && <span className="text-xs text-muted-foreground">{tanggal(a.published_at)}</span>}
              </div>

              {a.has_file && (
                <a
                  href={`${API_BASE}/api/files/announcements/${a.ulid}/file`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="mt-1 inline-flex w-fit items-center gap-1 text-xs text-primary"
                >
                  <FileDown className="size-3" />
                  {a.file_name ?? "Lampiran"}
                </a>
              )}
            </Card>
          ))}
        </div>
      </main>
    </div>
  );
}

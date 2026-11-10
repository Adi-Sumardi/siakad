"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Copy, Link2, Printer } from "lucide-react";
import { toast } from "sonner";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api";
import { absenLinkUrl } from "@/lib/absen-link";

/**
 * The unit's public absen link to share with the class (WA group, class
 * iPad, printed QR poster). Read-only here - issuing and resetting stay
 * with the admin unit.
 */
export function AbsenLinkCard({ endpoint, printHref }: { endpoint: string; printHref: string }) {
  const [link, setLink] = useState<{ public_path: string | null; public_url?: string | null } | null | undefined>(
    undefined,
  );

  useEffect(() => {
    api
      .get<{ public_path: string | null; public_url?: string | null }>(endpoint)
      .then(setLink)
      .catch(() => setLink(null));
  }, [endpoint]);

  if (link === undefined) return null;

  const url = absenLinkUrl(link?.public_url, link?.public_path);

  async function copy() {
    if (!url) return;
    try {
      await navigator.clipboard.writeText(url);
      toast.success("Link tersalin — bagikan ke grup kelas atau buka di iPad kelas.");
    } catch {
      toast.error("Gagal menyalin — salin manual dari teks link.");
    }
  }

  return (
    <Card className="p-4">
      <div className="flex items-center gap-2">
        <Link2 className="size-4 text-primary" />
        <h2 className="text-sm font-semibold">Link Absen Siswa</h2>
      </div>
      {url ? (
        <>
          <div className="mt-2 flex flex-wrap gap-2">
            <Input readOnly value={url} className="h-9 min-w-0 flex-1 text-xs" />
            <Button variant="outline" size="sm" onClick={copy}>
              <Copy className="size-3.5" /> Salin
            </Button>
            <Link href={printHref} target="_blank" className={buttonVariants({ variant: "outline", size: "sm" })}>
              <Printer className="size-3.5" /> Cetak QR
            </Link>
          </div>
          <p className="mt-2 text-xs text-muted-foreground">
            Siswa membuka link ini (atau scan QR yang ditempel di kelas), mengetik NIS, lalu berkedip di depan kamera.
            Boleh dibuka di iPad kelas atau HP teman — yang dicocokkan wajahnya.
          </p>
        </>
      ) : (
        <p className="mt-2 text-sm text-muted-foreground">
          Link absen unit belum dibuat. Minta admin unit membuatnya di halaman Presensi Harian.
        </p>
      )}
    </Card>
  );
}

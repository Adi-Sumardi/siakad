"use client";

import { useEffect, useState } from "react";
import { Copy, Link2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api";

/**
 * The unit's public absen link to share with the class (WA group, class
 * iPad). Read-only here - issuing and resetting stay with the admin unit.
 */
export function AbsenLinkCard({ endpoint }: { endpoint: string }) {
  const [path, setPath] = useState<string | null | undefined>(undefined);

  useEffect(() => {
    api
      .get<{ public_path: string | null }>(endpoint)
      .then((d) => setPath(d.public_path))
      .catch(() => setPath(null));
  }, [endpoint]);

  if (path === undefined) return null;

  const url = path && typeof window !== "undefined" ? `${window.location.origin}${path}` : null;

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
          <div className="mt-2 flex gap-2">
            <Input readOnly value={url} className="h-9 text-xs" />
            <Button variant="outline" size="sm" onClick={copy}>
              <Copy className="size-3.5" /> Salin
            </Button>
          </div>
          <p className="mt-2 text-xs text-muted-foreground">
            Siswa membuka link ini, mengetik NIS, lalu berkedip di depan kamera. Boleh dibuka di iPad kelas atau HP
            teman — yang dicocokkan wajahnya.
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


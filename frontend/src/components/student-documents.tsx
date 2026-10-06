"use client";

import { useCallback, useEffect, useState } from "react";
import { CheckCircle2, Clock, FileText, Trash2, Upload } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { api, ApiError } from "@/lib/api";
import { downloadApiFile } from "@/lib/download";
import { tanggal } from "@/lib/format";

type StudentDocument = {
  ulid: string;
  document_type: string;
  type_label: string;
  file_name: string;
  verified: boolean;
  verified_by: string | null;
  uploaded_by_me: boolean;
  created_at: string;
};

/**
 * A student's documents - akta, KK, ijazah, ... (audit 6 Okt 2026 #7).
 * `base` is "/api/admin" for staff (uploads arrive verified, can verify and
 * delete) or "/api/wali" for a guardian (uploads wait for TU).
 */
export function StudentDocuments({ base, studentUlid }: { base: "/api/admin" | "/api/wali"; studentUlid: string }) {
  const isStaff = base === "/api/admin";
  const [docs, setDocs] = useState<StudentDocument[] | null>(null);
  const [types, setTypes] = useState<{ value: string; label: string }[]>([]);
  const [type, setType] = useState("akta");
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    api
      .get<{ documents: StudentDocument[]; types: { value: string; label: string }[] }>(`${base}/students/${studentUlid}/documents`)
      .then((res) => {
        setDocs(res.documents);
        setTypes(res.types);
      })
      .catch((err) => {
        toast.error(err instanceof ApiError ? err.message : "Gagal memuat dokumen.");
        setDocs([]);
      });
  }, [base, studentUlid]);

  useEffect(load, [load]);

  async function upload(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setBusy(true);
    try {
      await api.post(`${base}/students/${studentUlid}/documents`, form);
      toast.success(isStaff ? "Dokumen tersimpan." : "Dokumen terkirim — menunggu verifikasi sekolah.");
      event.currentTarget?.reset();
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengunggah dokumen.");
    } finally {
      setBusy(false);
    }
  }

  async function verify(doc: StudentDocument) {
    try {
      await api.post(`/api/admin/student-documents/${doc.ulid}/verify`, {});
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memverifikasi.");
    }
  }

  async function remove(doc: StudentDocument) {
    if (!window.confirm(`Hapus ${doc.type_label} (${doc.file_name})?`)) return;
    try {
      await api.delete(`${base}/student-documents/${doc.ulid}`);
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus dokumen.");
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <form onSubmit={upload} className="flex flex-wrap items-end gap-2 rounded-lg border border-border p-3">
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          Jenis dokumen
          <select
            name="document_type"
            value={type}
            onChange={(e) => setType(e.target.value)}
            className="h-9 rounded-lg border border-input bg-card px-2 text-sm text-foreground"
          >
            {types.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </select>
        </label>
        <label className="flex min-w-0 flex-1 flex-col gap-1 text-xs text-muted-foreground">
          File (JPG/PNG/PDF, maks 5 MB)
          <Input type="file" name="file" accept=".jpg,.jpeg,.png,.pdf" required className="h-9" />
        </label>
        <Button type="submit" size="sm" disabled={busy} className="gap-1.5">
          <Upload className="size-3.5" /> {busy ? "Mengunggah…" : "Unggah"}
        </Button>
      </form>

      {docs?.length === 0 && <p className="text-sm text-muted-foreground">Belum ada dokumen.</p>}
      <div className="flex flex-col divide-y divide-border/60">
        {docs?.map((doc) => (
          <div key={doc.ulid} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
            <button
              type="button"
              className="flex min-w-0 items-center gap-2 text-left hover:underline"
              onClick={() =>
                downloadApiFile(`/api/files/student-documents/${doc.ulid}`, doc.file_name).catch((err) =>
                  toast.error(err instanceof Error ? err.message : "Gagal mengunduh."),
                )
              }
            >
              <FileText className="size-4 shrink-0 text-primary" />
              <span className="font-medium">{doc.type_label}</span>
              <span className="truncate text-xs text-muted-foreground">
                {doc.file_name} · {tanggal(doc.created_at)}
              </span>
            </button>
            <div className="flex items-center gap-1.5">
              {doc.verified ? (
                <Badge className="gap-1 bg-good/10 text-good">
                  <CheckCircle2 className="size-3" /> Terverifikasi
                </Badge>
              ) : (
                <Badge className="gap-1 bg-warn/10 text-warn">
                  <Clock className="size-3" /> Menunggu verifikasi
                </Badge>
              )}
              {isStaff && !doc.verified && (
                <Button size="sm" variant="outline" onClick={() => verify(doc)}>
                  Verifikasi
                </Button>
              )}
              {(isStaff || (doc.uploaded_by_me && !doc.verified)) && (
                <Button size="sm" variant="ghost" onClick={() => remove(doc)} aria-label="Hapus dokumen">
                  <Trash2 className="size-3.5" />
                </Button>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

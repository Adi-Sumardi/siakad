"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, ShoppingBag } from "lucide-react";
import { toast } from "sonner";
import { WaliShell } from "@/components/layout/wali-shell";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useRequireRole } from "@/lib/auth/use-require-role";
import type { FeeSelectionEntry } from "@/lib/types/billing";

type StudentOption = { ulid: string; nama_lengkap: string; nama_panggilan: string | null };

type Row = { student: StudentOption; entry: FeeSelectionEntry };

export default function FeeSelectionListPage() {
  const { user, loading } = useRequireRole("orangtua");
  const [rows, setRows] = useState<Row[] | null>(null);

  useEffect(() => {
    if (user?.role !== "orangtua") return;

    (async () => {
      try {
        const { students } = await api.get<{ students: StudentOption[] }>("/api/wali/students");
        const perStudent = await Promise.all(
          students.map((s) =>
            api
              .get<{ fee_selections: FeeSelectionEntry[] }>(`/api/wali/students/${s.ulid}/fee-selections`)
              .then((d) => d.fee_selections.map((entry) => ({ student: s, entry }))),
          ),
        );
        setRows(perStudent.flat());
      } catch (err) {
        toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar pilihan.");
        setRows([]);
      }
    })();
  }, [user]);

  if (loading || !user) {
    return (
      <WaliShell>
        <Skeleton className="h-48 w-full" />
      </WaliShell>
    );
  }

  return (
    <WaliShell>
      <div className="space-y-6">
        <div>
          <Link href="/tagihan" className="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground">
            <ArrowLeft className="size-4" />
            <span>Kembali ke Tagihan</span>
          </Link>
          <h1 className="text-2xl font-extrabold tracking-tight text-foreground mt-2">Pemilihan Item &amp; Ukuran</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Beberapa jenis biaya (seperti seragam) menunggu Anda memilih ukuran sebelum tagihannya bisa terbit.
          </p>
        </div>

        {rows === null && <Skeleton className="h-32 w-full" />}

        {rows?.length === 0 && (
          <Card className="p-8 text-center text-sm text-muted-foreground">
            Tidak ada pemilihan yang perlu diisi saat ini.
          </Card>
        )}

        <div className="grid grid-cols-1 gap-3">
          {rows?.map(({ student, entry }) => {
            const status = entry.selection?.locked_at
              ? { label: "Sudah ditagih", variant: "good" as const }
              : entry.selection?.submitted_at
                ? { label: "Sudah diisi", variant: "default" as const }
                : { label: "Belum diisi", variant: "warn" as const };

            return (
              <Card key={`${student.ulid}-${entry.fee_rate.ulid}`} className="flex items-center justify-between gap-3 p-5">
                <div className="flex items-center gap-3">
                  <span className="flex size-9 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                    <ShoppingBag className="size-4" />
                  </span>
                  <div>
                    <p className="font-bold text-foreground text-sm">{entry.fee_type.name}</p>
                    <p className="text-xs text-muted-foreground">
                      {student.nama_panggilan ?? student.nama_lengkap} · <Badge variant={status.variant}>{status.label}</Badge>
                    </p>
                  </div>
                </div>
                <Link href={`/tagihan/pilihan/${student.ulid}/${entry.fee_rate.ulid}`}>
                  <Button size="sm" variant={entry.selection?.locked_at ? "outline" : "default"}>
                    {entry.selection?.locked_at ? "Lihat" : entry.selection?.submitted_at ? "Ubah" : "Isi Pilihan"}
                  </Button>
                </Link>
              </Card>
            );
          })}
        </div>
      </div>
    </WaliShell>
  );
}

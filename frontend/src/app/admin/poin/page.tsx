"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ScrollText, Sparkles } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PointMeter } from "@/components/point-meter";
import { api } from "@/lib/api";

type Row = {
  student: { ulid: string; nama_lengkap: string; unit: string | null };
  balance: number;
  threshold: { ulid: string; label: string; color: string | null } | null;
};

export default function AdminPointsPage() {
  const [term, setTerm] = useState<string | null>(null);
  const [rows, setRows] = useState<Row[] | null>(null);

  useEffect(() => {
    api.get<{ term: string | null; students: Row[] }>("/api/admin/points").then((d) => {
      setTerm(d.term);
      setRows(d.students);
    });
  }, []);

  const flagged = rows?.filter((r) => r.threshold) ?? [];

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold tracking-tight">Poin siswa</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {term ? `Semester ${term}` : "Belum ada semester aktif"} · {rows?.length ?? "…"} siswa,{" "}
            {flagged.length} dalam ambang tertentu
          </p>
        </div>
        <div className="flex gap-2">
          <Link href="/admin/poin/aturan">
            <Button variant="outline" size="sm"><Sparkles className="size-4" />Aturan poin</Button>
          </Link>
          <Link href="/admin/poin/ambang">
            <Button variant="outline" size="sm"><ScrollText className="size-4" />Ambang</Button>
          </Link>
        </div>
      </div>

      {rows === null && <Skeleton className="h-64 w-full" />}

      {rows && rows.length === 0 && (
        <Card className="p-6 text-sm text-muted-foreground">Belum ada siswa dalam cakupan Anda.</Card>
      )}

      <div className="flex flex-col gap-2">
        {rows
          ?.slice()
          .sort((a, b) => a.balance - b.balance)
          .map((row) => (
            <Card key={row.student.ulid} className="flex flex-wrap items-center justify-between gap-3 p-4">
              <div>
                <p className="font-medium">{row.student.nama_lengkap}</p>
                <p className="text-sm text-muted-foreground">{row.student.unit}</p>
              </div>
              <PointMeter balance={row.balance} threshold={row.threshold} size="sm" />
            </Card>
          ))}
      </div>
    </div>
  );
}

"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { AlertCircle, Plus, ScrollText, Search, Sparkles } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { PointMeter } from "@/components/point-meter";
import { api, ApiError } from "@/lib/api";

type Row = {
  student: { ulid: string; nama_lengkap: string; unit: string | null };
  balance: number;
  threshold: { ulid: string; label: string; color: string | null } | null;
};

export default function AdminPointsPage() {
  const [term, setTerm] = useState<string | null>(null);
  const [rows, setRows] = useState<Row[] | null>(null);
  const [search, setSearch] = useState("");
  const [filterThresholdOnly, setFilterThresholdOnly] = useState(false);

  useEffect(() => {
    api
      .get<{ term: string | null; students: Row[] }>("/api/admin/points")
      .then((d) => {
        setTerm(d.term);
        setRows(d.students);
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat data poin."));
  }, []);

  const flagged = rows?.filter((r) => r.threshold) ?? [];

  const filteredRows = rows?.filter((r) => {
    if (search && !r.student.nama_lengkap.toLowerCase().includes(search.toLowerCase())) return false;
    if (filterThresholdOnly && !r.threshold) return false;
    return true;
  });

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Buku Rekap Poin & Tata Tertib Siswa</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            {term ? `Semester Aktif: ${term}` : "Belum ada semester aktif"} · Total {rows?.length ?? 0} siswa terdata
          </p>
        </div>

        <div className="flex items-center gap-2">
          <Link href="/admin/poin/aturan">
            <Button variant="outline" size="sm" className="gap-1.5 shadow-2xs">
              <Sparkles className="size-4" />
              <span>Aturan Poin Pelanggaran</span>
            </Button>
          </Link>
          <Link href="/admin/poin/ambang">
            <Button variant="outline" size="sm" className="gap-1.5 shadow-2xs">
              <ScrollText className="size-4" />
              <span>Ambang Batas SP / Pembinaan</span>
            </Button>
          </Link>
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Card className="p-5 border-border/80">
          <span className="text-xs font-semibold text-muted-foreground uppercase">Total Siswa Terdaftar</span>
          <p className="mt-2 text-2xl font-bold text-foreground">{rows?.length ?? <Skeleton className="h-8 w-16" />}</p>
          <p className="mt-1 text-xs text-muted-foreground">Dalam cakupan unit sekolah</p>
        </Card>

        <Card className="p-5 border-border/80">
          <span className="text-xs font-semibold text-muted-foreground uppercase">Siswa Terkena Ambang SP</span>
          <p className="mt-2 text-2xl font-bold text-destructive">{flagged.length} siswa</p>
          <p className="mt-1 text-xs text-muted-foreground">Perlu pembinaan guru BK / wali kelas</p>
        </Card>

        <Card className="p-5 border-border/80">
          <span className="text-xs font-semibold text-muted-foreground uppercase">Kondisi Tertib / Aman</span>
          <p className="mt-2 text-2xl font-bold text-emerald-600">
            {rows ? `${rows.length - flagged.length} siswa` : <Skeleton className="h-8 w-16" />}
          </p>
          <p className="mt-1 text-xs text-muted-foreground">Tidak memiliki poin pelanggaran kritis</p>
        </Card>
      </div>

      {/* Search & Filter Bar */}
      <div className="flex flex-wrap items-center gap-3 bg-muted/40 p-3.5 rounded-2xl border border-border">
        <div className="relative min-w-[240px] flex-1">
          <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Cari nama siswa..."
            className="pl-9 bg-card text-xs shadow-2xs"
          />
        </div>

        <button
          onClick={() => setFilterThresholdOnly(!filterThresholdOnly)}
          className={`flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold border transition-all ${
            filterThresholdOnly
              ? "bg-destructive text-destructive-foreground border-destructive"
              : "bg-card text-muted-foreground border-border hover:bg-accent"
          }`}
        >
          <AlertCircle className="size-3.5" />
          <span>Hanya Tampilkan Yang Terkena Ambang</span>
        </button>
      </div>

      {/* List */}
      {rows === null && (
        <div className="space-y-3">
          <Skeleton className="h-20 w-full rounded-xl" />
          <Skeleton className="h-20 w-full rounded-xl" />
        </div>
      )}

      {filteredRows?.length === 0 && (
        <Card className="p-8 text-center text-sm text-muted-foreground">
          Tidak ada data siswa yang cocok dengan filter pencarian.
        </Card>
      )}

      <div className="grid grid-cols-1 gap-3">
        {filteredRows
          ?.slice()
          .sort((a, b) => a.balance - b.balance)
          .map((row) => (
            <Card key={row.student.ulid} className="p-4 sm:p-5 border-border/80 hover:border-primary/40 transition-colors">
              <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                  <p className="font-bold text-foreground text-base">{row.student.nama_lengkap}</p>
                  <p className="text-xs text-muted-foreground mt-0.5">{row.student.unit ?? "Unit Sekolah"}</p>
                </div>
                <div className="sm:text-right">
                  <PointMeter balance={row.balance} threshold={row.threshold} size="sm" />
                </div>
              </div>
            </Card>
          ))}
      </div>
    </div>
  );
}

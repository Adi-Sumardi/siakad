"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { AlertCircle, Award, ScrollText, Search, ShieldAlert, Sparkles } from "lucide-react";
import { toast } from "sonner";
import { useAuth } from "@/lib/auth/auth-context";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { JenjangSelect } from "@/components/ui/jenjang-select";
import { Skeleton } from "@/components/ui/skeleton";
import { PointMeter } from "@/components/point-meter";
import { api, ApiError } from "@/lib/api";

type Row = {
  student: { ulid: string; nama_lengkap: string; unit: string | null };
  balance: number;
  threshold: { ulid: string; label: string; color: string | null } | null;
};

type LeaderRow = { student: { ulid: string; nama_lengkap: string; unit: string | null }; total: number };

export default function AdminPointsPage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [term, setTerm] = useState<string | null>(null);
  const [rows, setRows] = useState<Row[] | null>(null);
  const [search, setSearch] = useState("");
  const [filterThresholdOnly, setFilterThresholdOnly] = useState(false);

  // Poin 6: one shared unit+jenjang filter pair driving BOTH boards.
  const [boardUnit, setBoardUnit] = useState("");
  const [boardJenjang, setBoardJenjang] = useState("");
  const [unitOptions, setUnitOptions] = useState<{ code: string; label: string }[]>([]);
  const [merit, setMerit] = useState<LeaderRow[] | null>(null);
  const [violation, setViolation] = useState<LeaderRow[] | null>(null);

  function loadLeaderboard(unit: string, jenjang: string) {
    const params = new URLSearchParams();
    if (unit) params.set("unit", unit);
    if (jenjang) params.set("jenjang", jenjang);
    const query = params.toString();
    api
      .get<{ merit: LeaderRow[]; violation: LeaderRow[] }>(`/api/admin/points/leaderboard${query ? `?${query}` : ""}`)
      .then((d) => {
        setMerit(d.merit);
        setViolation(d.violation);
      })
      .catch(() => {
        setMerit([]);
        setViolation([]);
      });
  }

  useEffect(() => {
    api
      .get<{ term: string | null; students: Row[] }>("/api/admin/points")
      .then((d) => {
        setTerm(d.term);
        setRows(d.students);
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat data poin."));

    loadLeaderboard("", "");

    if (isCentral) {
      api.get<{ school_units: { code: string; label: string }[] }>("/api/admin/school-units")
        .then((d) => setUnitOptions(d.school_units))
        .catch(() => {});
    }
  }, [isCentral]); // eslint-disable-line react-hooks/exhaustive-deps

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
          <div className="mt-2 text-2xl font-bold text-foreground">{rows?.length ?? <Skeleton className="h-8 w-16" />}</div>
          <p className="mt-1 text-xs text-muted-foreground">Dalam cakupan unit sekolah</p>
        </Card>

        <Card className="p-5 border-border/80">
          <span className="text-xs font-semibold text-muted-foreground uppercase">Siswa Terkena Ambang SP</span>
          <p className="mt-2 text-2xl font-bold text-destructive">{flagged.length} siswa</p>
          <p className="mt-1 text-xs text-muted-foreground">Perlu pembinaan guru BK / wali kelas</p>
        </Card>

        <Card className="p-5 border-border/80">
          <span className="text-xs font-semibold text-muted-foreground uppercase">Kondisi Tertib / Aman</span>
          <div className="mt-2 text-2xl font-bold text-emerald-600">
            {rows ? `${rows.length - flagged.length} siswa` : <Skeleton className="h-8 w-16" />}
          </div>
          <p className="mt-1 text-xs text-muted-foreground">Tidak memiliki poin pelanggaran kritis</p>
        </Card>
      </div>

      {/* Leaderboard (Poin 6): one shared filter pair, both boards. */}
      <Card className="p-5 border-border/80">
        <div className="flex flex-wrap items-end gap-3 mb-4">
          <div>
            <h2 className="text-sm font-bold">Leaderboard Poin</h2>
            <p className="text-xs text-muted-foreground mt-0.5">
              Hanya poin terverifikasi (tercatat di buku besar semester {term ?? "—"}).
            </p>
          </div>
          {isCentral && (
            <div className="flex flex-col gap-1 ml-auto">
              <span className="text-[11px] font-semibold text-muted-foreground uppercase">Unit</span>
              <select
                value={boardUnit}
                onChange={(e) => { setBoardUnit(e.target.value); loadLeaderboard(e.target.value, boardJenjang); }}
                className="h-9 w-48 rounded-lg border border-input bg-card px-3 text-xs"
              >
                <option value="">Semua Unit</option>
                {unitOptions.map((u) => <option key={u.code} value={u.code}>{u.label}</option>)}
              </select>
            </div>
          )}
          <div className="flex flex-col gap-1">
            <span className="text-[11px] font-semibold text-muted-foreground uppercase">Jenjang</span>
            <JenjangSelect
              value={boardJenjang}
              onChange={(key) => { setBoardJenjang(key); loadLeaderboard(boardUnit, key); }}
              className="h-9 w-52 rounded-lg border border-input bg-card px-3 text-xs"
            />
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {/* Merit board */}
          <div className="rounded-2xl border border-good/30 bg-good-soft/40 p-4">
            <div className="flex items-center gap-2 mb-3">
              <span className="flex size-8 items-center justify-center rounded-lg bg-good/15 text-good">
                <Award className="size-4" />
              </span>
              <div>
                <p className="text-sm font-bold text-foreground">Leaderboard Poin Penghargaan (+)</p>
                <Badge variant="good" className="mt-0.5">Paling Berprestasi</Badge>
              </div>
            </div>
            {merit === null ? (
              <Skeleton className="h-40 w-full" />
            ) : merit.length === 0 ? (
              <p className="text-xs text-muted-foreground py-6 text-center">Belum ada poin penghargaan terverifikasi.</p>
            ) : (
              <ol className="space-y-2">
                {merit.map((row, i) => (
                  <li key={row.student.ulid} className="flex items-center gap-3 rounded-xl bg-card px-3 py-2 border border-border/60">
                    <span className={`flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-black ${i === 0 ? "bg-good text-good-foreground" : "bg-muted text-muted-foreground"}`}>
                      {i + 1}
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold text-foreground">{row.student.nama_lengkap}</p>
                      <p className="text-[11px] text-muted-foreground truncate">{row.student.unit ?? "—"}</p>
                    </div>
                    <span className="shrink-0 text-sm font-black tabular text-good">+{row.total} Poin</span>
                  </li>
                ))}
              </ol>
            )}
          </div>

          {/* Violation board */}
          <div className="rounded-2xl border border-bad/30 bg-bad-soft/40 p-4">
            <div className="flex items-center gap-2 mb-3">
              <span className="flex size-8 items-center justify-center rounded-lg bg-bad/15 text-bad">
                <ShieldAlert className="size-4" />
              </span>
              <div>
                <p className="text-sm font-bold text-foreground">Leaderboard Poin Pelanggaran (−)</p>
                <Badge variant="bad" className="mt-0.5">Dalam Pemantauan</Badge>
              </div>
            </div>
            {violation === null ? (
              <Skeleton className="h-40 w-full" />
            ) : violation.length === 0 ? (
              <p className="text-xs text-muted-foreground py-6 text-center">Tidak ada poin pelanggaran terverifikasi.</p>
            ) : (
              <ol className="space-y-2">
                {violation.map((row, i) => (
                  <li key={row.student.ulid} className="flex items-center gap-3 rounded-xl bg-card px-3 py-2 border border-border/60">
                    <span className={`flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-black ${i === 0 ? "bg-bad text-bad-foreground" : "bg-muted text-muted-foreground"}`}>
                      {i + 1}
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold text-foreground">{row.student.nama_lengkap}</p>
                      <p className="text-[11px] text-muted-foreground truncate">{row.student.unit ?? "—"}</p>
                    </div>
                    <span className="shrink-0 text-sm font-black tabular text-bad">−{row.total} Poin</span>
                  </li>
                ))}
              </ol>
            )}
          </div>
        </div>
      </Card>

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

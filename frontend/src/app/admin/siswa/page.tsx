"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import {
  BadgePercent,
  BookOpen,
  ChevronRight,
  Filter,
  GraduationCap,
  Percent,
  Phone,
  RefreshCw,
  Search,
  Sparkles,
  User,
  Users,
  Wallet,
} from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { rupiah } from "@/lib/format";

type StudentItem = {
  ulid: string;
  nis: string | null;
  nisn: string | null;
  nama_lengkap: string;
  nama_panggilan: string | null;
  jenis_kelamin: "L" | "P";
  status: string;
  unit: {
    ulid: string;
    code: string;
    label: string;
    jenjang: string;
  } | null;
  classroom: {
    ulid: string;
    name: string;
    tingkat: number;
    wali_kelas: string | null;
  } | null;
  guardian: {
    name: string;
    relationship: string;
    phone: string | null;
  } | null;
  pricing: {
    has_rate: boolean;
    base_spp: number;
    discount_amount: number;
    net_spp: number;
    discounts: Array<{
      name: string;
      type: string;
      value: number;
      amount: number;
    }>;
  };
};

type SchoolUnit = { ulid: string; code: string; label: string; jenjang_group: string };

export default function AdminStudentsPage() {
  const [students, setStudents] = useState<StudentItem[] | null>(null);
  const [units, setUnits] = useState<SchoolUnit[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeYear, setActiveYear] = useState<string>("");

  // Filters
  const [search, setSearch] = useState("");
  const [unitFilter, setUnitFilter] = useState("");
  const [jenjangFilter, setJenjangFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("active");

  function loadStudents() {
    setLoading(true);
    const params = new URLSearchParams();
    if (search) params.set("search", search);
    if (unitFilter) params.set("unit", unitFilter);
    if (jenjangFilter) params.set("jenjang", jenjangFilter);
    if (statusFilter) params.set("status", statusFilter);

    api
      .get<{ students: { data: StudentItem[]; meta: { active_academic_year: string } } }>(
        `/api/admin/students?${params.toString()}`,
      )
      .then((d) => {
        setStudents(d.students.data);
        if (d.students.meta?.active_academic_year) {
          setActiveYear(d.students.meta.active_academic_year);
        }
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat data siswa."))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    loadStudents();
    api
      .get<{ school_units: SchoolUnit[] }>("/api/admin/school-units")
      .then((d) => setUnits(d.school_units))
      .catch(() => {});
  }, [unitFilter, jenjangFilter, statusFilter]); // eslint-disable-line react-hooks/exhaustive-deps

  function handleSearchSubmit(e: React.FormEvent) {
    e.preventDefault();
    loadStudents();
  }

  // Summary Metrics
  const totalStudents = students?.length ?? 0;
  const totalBaseSPP = students?.reduce((acc, s) => acc + (s.pricing?.base_spp ?? 0), 0) ?? 0;
  const totalDiscount = students?.reduce((acc, s) => acc + (s.pricing?.discount_amount ?? 0), 0) ?? 0;
  const totalNetSPP = students?.reduce((acc, s) => acc + (s.pricing?.net_spp ?? 0), 0) ?? 0;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Data Siswa & Tarif SPP</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Daftar lengkap seluruh siswa per jenjang & unit sekolah beserta kalkulasi SPP dan potongan beasiswa ({activeYear || "Tahun Ajaran Aktif"}).
          </p>
        </div>

        <div className="flex items-center gap-2">
          <Link href="/admin/diskon">
            <Button variant="outline" size="sm" className="gap-1.5 font-semibold text-xs h-9">
              <BadgePercent className="size-4" />
              <span>Kelola Diskon Siswa</span>
            </Button>
          </Link>
          <Link href="/admin/tarif">
            <Button size="sm" className="gap-1.5 font-semibold text-xs h-9 shadow-xs">
              <Percent className="size-4" />
              <span>Atur Tarif SPP</span>
            </Button>
          </Link>
        </div>
      </div>

      {/* KPI Cards Summary */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <Card className="p-4.5 border-border/80 shadow-xs flex items-center gap-3.5">
          <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary">
            <GraduationCap className="size-5.5" />
          </span>
          <div>
            <p className="text-xs font-medium text-muted-foreground">Total Siswa Terdata</p>
            <p className="text-xl font-bold text-foreground mt-0.5">{totalStudents} Siswa</p>
          </div>
        </Card>

        <Card className="p-4.5 border-border/80 shadow-xs flex items-center gap-3.5">
          <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-accent text-accent-foreground">
            <Wallet className="size-5.5" />
          </span>
          <div>
            <p className="text-xs font-medium text-muted-foreground">Total SPP Pokok / Bulan</p>
            <p className="text-lg font-bold text-foreground mt-0.5">{rupiah(totalBaseSPP)}</p>
          </div>
        </Card>

        <Card className="p-4.5 border-border/80 shadow-xs flex items-center gap-3.5">
          <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-good/10 text-good">
            <BadgePercent className="size-5.5" />
          </span>
          <div>
            <p className="text-xs font-medium text-muted-foreground">Total Beasiswa & Diskon</p>
            <p className="text-lg font-bold text-good mt-0.5">− {rupiah(totalDiscount)}</p>
          </div>
        </Card>

        <Card className="p-4.5 border-primary/40 bg-primary/5 shadow-xs flex items-center gap-3.5">
          <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-primary text-primary-foreground">
            <Sparkles className="size-5.5" />
          </span>
          <div>
            <p className="text-xs font-bold text-primary">Penerimaan SPP Net / Bulan</p>
            <p className="text-lg font-extrabold text-foreground mt-0.5">{rupiah(totalNetSPP)}</p>
          </div>
        </Card>
      </div>

      {/* Filter & Search Bar */}
      <Card className="p-4 border-border/80 shadow-xs">
        <form onSubmit={handleSearchSubmit} className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <div className="lg:col-span-2">
            <Label className="text-xs">Pencarian Siswa</Label>
            <div className="relative mt-1">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Cari nama, NIS, atau NISN..."
                className="pl-9 text-xs h-9"
              />
            </div>
          </div>

          <div>
            <Label className="text-xs">Jenjang Sekolah</Label>
            <select
              value={jenjangFilter}
              onChange={(e) => setJenjangFilter(e.target.value)}
              className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-medium shadow-2xs"
            >
              <option value="">Semua Jenjang</option>
              <option value="tk">TK / PAUD / RA</option>
              <option value="sd">SD</option>
              <option value="smp">SMP</option>
              <option value="sma">SMA</option>
            </select>
          </div>

          <div>
            <Label className="text-xs">Unit Sekolah</Label>
            <select
              value={unitFilter}
              onChange={(e) => setUnitFilter(e.target.value)}
              className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-medium shadow-2xs"
            >
              <option value="">Semua Unit</option>
              {units.map((u) => (
                <option key={u.ulid} value={u.code}>
                  {u.label}
                </option>
              ))}
            </select>
          </div>

          <div className="flex items-end gap-2">
            <div className="flex-1">
              <Label className="text-xs">Status Siswa</Label>
              <select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
                className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-medium shadow-2xs"
              >
                <option value="">Semua Status</option>
                <option value="active">Aktif</option>
                <option value="graduated">Lulus</option>
                <option value="transferred">Mutasi / Keluar</option>
              </select>
            </div>
            <Button type="submit" size="sm" variant="outline" className="h-9 px-3 text-xs">
              <RefreshCw className="size-3.5" />
            </Button>
          </div>
        </form>
      </Card>

      {/* Student List Table */}
      <Card className="border-border/80 shadow-xs overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-muted/40 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
              <tr>
                <th className="px-5 py-3.5">Nama & Identitas Siswa</th>
                <th className="px-5 py-3.5">Jenjang & Unit Sekolah</th>
                <th className="px-5 py-3.5">Kelas & Wali Kelas</th>
                <th className="px-5 py-3.5">Wali Murid</th>
                <th className="px-5 py-3.5 text-right">Tarif Pokok</th>
                <th className="px-5 py-3.5">Diskon / Beasiswa</th>
                <th className="px-5 py-3.5 text-right">SPP Net / Bulan</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border/60">
              {loading && (
                <tr>
                  <td colSpan={7} className="p-5">
                    <Skeleton className="h-24 w-full rounded-xl" />
                  </td>
                </tr>
              )}

              {!loading && students?.length === 0 && (
                <tr>
                  <td colSpan={7} className="p-8 text-center text-muted-foreground">
                    Tidak ada data siswa yang ditemukan untuk kriteria filter ini.
                  </td>
                </tr>
              )}

              {!loading &&
                students?.map((s) => {
                  const hasDiscounts = (s.pricing?.discounts?.length ?? 0) > 0;

                  return (
                    <tr key={s.ulid} className="hover:bg-accent/30 transition-colors">
                      <td className="px-5 py-4">
                        <div>
                          <div className="flex items-center gap-1.5">
                            <p className="font-bold text-foreground text-sm">{s.nama_lengkap}</p>
                            <Badge variant={s.jenis_kelamin === "L" ? "primary" : "default"} className="text-[10px] px-1.5 py-0">
                              {s.jenis_kelamin}
                            </Badge>
                          </div>
                          <p className="text-xs text-muted-foreground mt-0.5 font-mono">
                            NIS: {s.nis ?? "—"} {s.nisn ? `· NISN: ${s.nisn}` : ""}
                          </p>
                        </div>
                      </td>

                      <td className="px-5 py-4">
                        <div>
                          <p className="font-semibold text-foreground">{s.unit?.label ?? "—"}</p>
                          <Badge variant="default" className="text-[10px] uppercase mt-0.5">
                            Jenjang {s.unit?.jenjang}
                          </Badge>
                        </div>
                      </td>

                      <td className="px-5 py-4">
                        <div>
                          <p className="font-semibold text-foreground">
                            {s.classroom ? `Kelas ${s.classroom.name}` : "Belum diplot"}
                          </p>
                          {s.classroom?.wali_kelas && (
                            <p className="text-xs text-muted-foreground">Wali: {s.classroom.wali_kelas}</p>
                          )}
                        </div>
                      </td>

                      <td className="px-5 py-4">
                        {s.guardian ? (
                          <div>
                            <p className="font-medium text-foreground">{s.guardian.name}</p>
                            <p className="text-xs text-muted-foreground flex items-center gap-1 mt-0.5">
                              <Phone className="size-3" />
                              <span>{s.guardian.phone || "—"}</span>
                            </p>
                          </div>
                        ) : (
                          <span className="text-muted-foreground italic">Belum terhubung</span>
                        )}
                      </td>

                      <td className="px-5 py-4 text-right font-medium text-foreground">
                        {s.pricing?.has_rate ? (
                          rupiah(s.pricing.base_spp)
                        ) : (
                          <span className="text-muted-foreground italic text-[11px]">Belum diatur</span>
                        )}
                      </td>

                      <td className="px-5 py-4">
                        {hasDiscounts ? (
                          <div className="space-y-1">
                            {s.pricing.discounts.map((d, i) => (
                              <div key={i} className="flex items-center gap-1.5">
                                <Badge variant="good" className="text-[10px] font-bold">
                                  {d.name}
                                </Badge>
                                <span className="text-[11px] font-semibold text-good">
                                  −{rupiah(d.amount)}
                                </span>
                              </div>
                            ))}
                          </div>
                        ) : (
                          <span className="text-muted-foreground text-xs">—</span>
                        )}
                      </td>

                      <td className="px-5 py-4 text-right">
                        <div className="flex flex-col items-end">
                          <span className="font-mono text-sm font-extrabold text-foreground">
                            {rupiah(s.pricing?.net_spp ?? 0)}
                          </span>
                          {hasDiscounts && (
                            <span className="text-[10px] font-bold text-good">
                              Hemat {rupiah(s.pricing.discount_amount)}
                            </span>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  );
}

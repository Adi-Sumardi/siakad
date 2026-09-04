"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import {
  BadgePercent,
  Calendar,
  ChevronRight,
  Download,
  Edit2,
  FileSpreadsheet,
  Filter,
  GraduationCap,
  Percent,
  Phone,
  Plus,
  RefreshCw,
  Search,
  Sparkles,
  Trash2,
  UploadCloud,
  User,
  Users,
  Wallet,
  X,
} from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Pagination } from "@/components/pagination";
import { useAuth } from "@/lib/auth/auth-context";
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
type AcademicYear = { ulid: string; year: string; is_active: boolean };

export default function AdminStudentsPage() {
  const { user } = useAuth();
  const isAdministrator = user?.role === "admin";

  const [students, setStudents] = useState<StudentItem[] | null>(null);
  const [meta, setMeta] = useState<{ current_page: number; last_page: number } | null>(null);
  const [page, setPage] = useState(1);
  const [units, setUnits] = useState<SchoolUnit[]>([]);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [selectedYear, setSelectedYear] = useState<string>("");
  const [loading, setLoading] = useState(true);

  // Filters
  const [search, setSearch] = useState("");
  const [unitFilter, setUnitFilter] = useState("");
  const [jenjangFilter, setJenjangFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("active");

  // Import Modal
  const [showImportModal, setShowImportModal] = useState(false);
  const [importFile, setImportFile] = useState<File | null>(null);
  const [importYearUlid, setImportYearUlid] = useState("");
  const [importing, setImporting] = useState(false);
  const [importResult, setImportResult] = useState<{ message: string; imported: number; updated: number; errors: string[] } | null>(null);

  // Edit/Delete (administrator only)
  const [editingStudent, setEditingStudent] = useState<StudentItem | null>(null);
  const [deletingStudent, setDeletingStudent] = useState<StudentItem | null>(null);
  const [deleteConfirmInput, setDeleteConfirmInput] = useState("");
  const [formNamaLengkap, setFormNamaLengkap] = useState("");
  const [formNamaPanggilan, setFormNamaPanggilan] = useState("");
  const [formNis, setFormNis] = useState("");
  const [formNisn, setFormNisn] = useState("");
  const [formJenisKelamin, setFormJenisKelamin] = useState<"L" | "P">("L");
  const [formSchoolUnitUlid, setFormSchoolUnitUlid] = useState("");
  const [formStatus, setFormStatus] = useState("active");
  const [submitting, setSubmitting] = useState(false);

  function loadStudents() {
    setLoading(true);
    const params = new URLSearchParams();
    if (search) params.set("search", search);
    if (unitFilter) params.set("unit", unitFilter);
    if (jenjangFilter) params.set("jenjang", jenjangFilter);
    if (statusFilter) params.set("status", statusFilter);
    if (selectedYear) params.set("academic_year", selectedYear);
    if (page > 1) params.set("page", String(page));

    api
      .get<{
        students: {
          data: StudentItem[];
          meta: { selected_academic_year: string; current_page: number; last_page: number };
        };
      }>(`/api/admin/students?${params.toString()}`)
      .then((d) => {
        setStudents(d.students.data);
        setMeta(d.students.meta);
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat data siswa."))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    api
      .get<{ academic_years: AcademicYear[] }>("/api/admin/academic-years")
      .then((d) => {
        setYears(d.academic_years);
        const active = d.academic_years.find((y) => y.is_active) ?? d.academic_years[0];
        if (active) {
          setSelectedYear(active.year);
          setImportYearUlid(active.ulid);
        }
      })
      .catch(() => {});

    api
      .get<{ school_units: SchoolUnit[] }>("/api/admin/school-units")
      .then((d) => setUnits(d.school_units))
      .catch(() => {});
  }, []);

  useEffect(() => {
    if (selectedYear) {
      loadStudents();
    }
  }, [selectedYear, unitFilter, jenjangFilter, statusFilter, page]); // eslint-disable-line react-hooks/exhaustive-deps

  function handleSearchSubmit(e: React.FormEvent) {
    e.preventDefault();
    setPage(1);
    loadStudents();
  }

  async function handleImportSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!importFile) {
      toast.error("Silakan pilih file CSV terlebih dahulu.");
      return;
    }

    setImporting(true);
    setImportResult(null);

    const form = new FormData();
    form.set("file", importFile);
    if (importYearUlid) form.set("academic_year_ulid", importYearUlid);

    try {
      const res = await api.post<{
        message: string;
        imported_count: number;
        updated_count: number;
        errors: string[];
      }>("/api/admin/import/students", form);

      toast.success(res.message);
      setImportResult({
        message: res.message,
        imported: res.imported_count,
        updated: res.updated_count,
        errors: res.errors || [],
      });
      loadStudents();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengimpor data siswa.");
    } finally {
      setImporting(false);
    }
  }

  function openEdit(s: StudentItem) {
    setEditingStudent(s);
    setFormNamaLengkap(s.nama_lengkap);
    setFormNamaPanggilan(s.nama_panggilan ?? "");
    setFormNis(s.nis ?? "");
    setFormNisn(s.nisn ?? "");
    setFormJenisKelamin(s.jenis_kelamin);
    setFormSchoolUnitUlid(s.unit?.ulid ?? "");
    setFormStatus(s.status);
  }

  async function handleUpdate(e: React.FormEvent) {
    e.preventDefault();
    if (!editingStudent) return;
    setSubmitting(true);
    try {
      await api.patch(`/api/admin/students/${editingStudent.ulid}`, {
        nama_lengkap: formNamaLengkap,
        nama_panggilan: formNamaPanggilan || null,
        nis: formNis || null,
        nisn: formNisn || null,
        jenis_kelamin: formJenisKelamin,
        school_unit_ulid: formSchoolUnitUlid,
        status: formStatus,
      });
      toast.success("Data siswa berhasil diperbarui.");
      setEditingStudent(null);
      loadStudents();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memperbarui data siswa.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete() {
    if (!deletingStudent) return;
    setSubmitting(true);
    try {
      await api.delete(`/api/admin/students/${deletingStudent.ulid}`);
      toast.success("Data siswa berhasil dihapus.");
      setDeletingStudent(null);
      loadStudents();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus data siswa.");
    } finally {
      setSubmitting(false);
    }
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
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Data Siswa & Nilai SPP</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Daftar siswa per jenjang & unit sekolah beserta kalkulasi SPP dan potongan beasiswa ({selectedYear || "Tahun Ajaran Aktif"}).
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {/* Academic Year Selector */}
          <div className="flex items-center gap-1.5 bg-card border border-input px-3 py-1.5 rounded-lg shadow-2xs">
            <Calendar className="size-3.5 text-primary shrink-0" />
            <span className="text-xs font-semibold text-muted-foreground">Tahun:</span>
            <select
              value={selectedYear}
              onChange={(e) => { setSelectedYear(e.target.value); setPage(1); }}
              className="bg-transparent text-xs font-bold text-foreground focus:outline-hidden"
            >
              {years.map((y) => (
                <option key={y.ulid} value={y.year}>
                  {y.year} {y.is_active ? "(Aktif)" : ""}
                </option>
              ))}
            </select>
          </div>

          <Button
            onClick={() => {
              setImportFile(null);
              setImportResult(null);
              setShowImportModal(true);
            }}
            variant="outline"
            size="sm"
            className="gap-1.5 font-semibold text-xs h-9"
          >
            <UploadCloud className="size-4 text-primary" />
            <span>Import Siswa (CSV/Excel)</span>
          </Button>

          <a href="/api/admin/students/dapodik-export" download title="Unduh CSV Formulir Peserta Didik (Dapodik) - untuk mempercepat entry manual, bukan impor otomatis ke Dapodik.">
            <Button variant="outline" size="sm" className="gap-1.5 font-semibold text-xs h-9">
              <FileSpreadsheet className="size-4 text-primary" />
              <span>Ekspor Dapodik</span>
            </Button>
          </a>

          <Link href="/admin/diskon">
            <Button variant="outline" size="sm" className="gap-1.5 font-semibold text-xs h-9">
              <BadgePercent className="size-4" />
              <span>Kelola Diskon</span>
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
            <p className="text-xs font-medium text-muted-foreground">Total Siswa ({selectedYear})</p>
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
            <p className="text-xs font-medium text-muted-foreground">Total Potongan Beasiswa</p>
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
              onChange={(e) => { setJenjangFilter(e.target.value); setPage(1); }}
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
              onChange={(e) => { setUnitFilter(e.target.value); setPage(1); }}
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
                onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
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
                {isAdministrator && <th className="px-5 py-3.5 text-right">Aksi</th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-border/60">
              {loading && (
                <tr>
                  <td colSpan={isAdministrator ? 8 : 7} className="p-5">
                    <Skeleton className="h-24 w-full rounded-xl" />
                  </td>
                </tr>
              )}

              {!loading && students?.length === 0 && (
                <tr>
                  <td colSpan={isAdministrator ? 8 : 7} className="p-8 text-center text-muted-foreground">
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

                      {isAdministrator && (
                        <td className="px-5 py-4 text-right">
                          <div className="flex items-center justify-end gap-1.5">
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => openEdit(s)}
                              className="h-8 px-2.5 text-xs font-semibold gap-1"
                            >
                              <Edit2 className="size-3.5" />
                              <span>Edit</span>
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => { setDeletingStudent(s); setDeleteConfirmInput(""); }}
                              className="h-8 px-2 text-destructive hover:bg-destructive/10 hover:text-destructive"
                            >
                              <Trash2 className="size-3.5" />
                            </Button>
                          </div>
                        </td>
                      )}
                    </tr>
                  );
                })}
            </tbody>
          </table>
        </div>

        <div className="px-4 pb-4">
          <Pagination
            currentPage={meta?.current_page ?? 1}
            lastPage={meta?.last_page ?? 1}
            onChange={setPage}
          />
        </div>
      </Card>

      {/* MODAL: IMPORT SISWA */}
      {showImportModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
          <Card className="w-full max-w-lg p-6 border-border shadow-2xl space-y-4 my-8">
            <div className="flex items-center justify-between border-b border-border pb-3">
              <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
                <UploadCloud className="size-5 text-primary" />
                <span>Import Data Siswa Massal (CSV/Excel)</span>
              </h2>
              <button onClick={() => setShowImportModal(false)} className="text-muted-foreground hover:text-foreground">
                <X className="size-5" />
              </button>
            </div>

            <div className="bg-primary/5 border border-primary/20 rounded-xl p-3.5 text-xs text-muted-foreground space-y-2">
              <p className="font-semibold text-foreground">Format File yang Didukung:</p>
              <p>
                File spreadsheet (.CSV). Pastikan berisi kolom: <code>nama_lengkap</code>, <code>nis</code>, <code>nisn</code>, <code>jenis_kelamin</code> (L/P), <code>unit_code</code> (contoh: <code>sd</code>, <code>smp</code>, <code>sma</code>), <code>kelas</code>, <code>wali_nama</code>, <code>wali_phone</code>, <code>wali_email</code>.
              </p>
              <a
                href="/api/admin/import/students/template"
                download
                className="inline-flex items-center gap-1.5 font-bold text-primary hover:underline pt-1"
              >
                <Download className="size-3.5" />
                <span>Unduh Format Template CSV Siswa</span>
              </a>
            </div>

            <form onSubmit={handleImportSubmit} className="space-y-4 text-xs">
              <div>
                <Label className="text-xs font-semibold">Tahun Ajaran Penempatan Kelas:</Label>
                <select
                  value={importYearUlid}
                  onChange={(e) => setImportYearUlid(e.target.value)}
                  className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-2xs"
                >
                  {years.map((y) => (
                    <option key={y.ulid} value={y.ulid}>
                      Tahun Ajaran {y.year} {y.is_active ? "(Aktif Saat Ini)" : ""}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <Label className="text-xs font-semibold">Pilih File CSV Siswa:</Label>
                <input
                  type="file"
                  accept=".csv,.txt"
                  required
                  onChange={(e) => setImportFile(e.target.files?.[0] || null)}
                  className="mt-1 block w-full text-xs text-muted-foreground file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                />
              </div>

              {importResult && (
                <div className="rounded-xl border border-good/30 bg-good/10 p-3 text-xs space-y-1">
                  <p className="font-bold text-good">{importResult.message}</p>
                  {importResult.errors.length > 0 && (
                    <div className="mt-2 text-destructive font-mono text-[11px] max-h-24 overflow-y-auto">
                      {importResult.errors.map((err, i) => (
                        <p key={i}>⚠️ {err}</p>
                      ))}
                    </div>
                  )}
                </div>
              )}

              <div className="flex justify-end gap-2 border-t border-border pt-4">
                <Button type="button" variant="ghost" onClick={() => setShowImportModal(false)} disabled={importing}>
                  Tutup
                </Button>
                <Button type="submit" disabled={importing || !importFile} className="font-bold shadow-xs">
                  {importing ? "Mengimpor Data…" : "Mulai Import Siswa"}
                </Button>
              </div>
            </form>
          </Card>
        </div>
      )}

      {/* MODAL: EDIT SISWA (Administrator saja) */}
      {editingStudent && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
          <Card className="w-full max-w-lg p-6 border-border shadow-2xl space-y-4 my-8">
            <div className="flex items-center justify-between border-b border-border pb-3">
              <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
                <Edit2 className="size-5 text-primary" />
                <span>Edit Data Siswa</span>
              </h2>
              <button onClick={() => setEditingStudent(null)} className="text-muted-foreground hover:text-foreground">
                <X className="size-5" />
              </button>
            </div>

            <form onSubmit={handleUpdate} className="space-y-3.5 text-xs">
              <div>
                <Label className="text-xs">Nama Lengkap</Label>
                <Input value={formNamaLengkap} onChange={(e) => setFormNamaLengkap(e.target.value)} required className="mt-1" />
              </div>

              <div>
                <Label className="text-xs">Nama Panggilan</Label>
                <Input value={formNamaPanggilan} onChange={(e) => setFormNamaPanggilan(e.target.value)} className="mt-1" />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <Label className="text-xs">NIS</Label>
                  <Input value={formNis} onChange={(e) => setFormNis(e.target.value)} className="mt-1" />
                </div>
                <div>
                  <Label className="text-xs">NISN</Label>
                  <Input value={formNisn} onChange={(e) => setFormNisn(e.target.value)} className="mt-1" />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <Label className="text-xs">Jenis Kelamin</Label>
                  <select
                    value={formJenisKelamin}
                    onChange={(e) => setFormJenisKelamin(e.target.value as "L" | "P")}
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-2xs"
                  >
                    <option value="L">Laki-laki</option>
                    <option value="P">Perempuan</option>
                  </select>
                </div>
                <div>
                  <Label className="text-xs">Status Siswa</Label>
                  <select
                    value={formStatus}
                    onChange={(e) => setFormStatus(e.target.value)}
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-2xs"
                  >
                    <option value="prospective">Calon Siswa</option>
                    <option value="active">Aktif</option>
                    <option value="graduated">Lulus</option>
                    <option value="transferred">Mutasi / Keluar</option>
                    <option value="dropped_out">Berhenti</option>
                  </select>
                </div>
              </div>

              <div>
                <Label className="text-xs">Unit Sekolah</Label>
                <select
                  value={formSchoolUnitUlid}
                  onChange={(e) => setFormSchoolUnitUlid(e.target.value)}
                  required
                  className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-2xs"
                >
                  {units.map((u) => (
                    <option key={u.ulid} value={u.ulid}>
                      {u.label}
                    </option>
                  ))}
                </select>
              </div>

              <div className="flex justify-end gap-2 border-t border-border pt-4">
                <Button type="button" variant="ghost" onClick={() => setEditingStudent(null)} disabled={submitting}>
                  Batal
                </Button>
                <Button type="submit" disabled={submitting} className="font-bold shadow-xs">
                  {submitting ? "Menyimpan…" : "Simpan Perubahan"}
                </Button>
              </div>
            </form>
          </Card>
        </div>
      )}

      {/* MODAL: KONFIRMASI HAPUS SISWA (Administrator saja) */}
      {deletingStudent && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4">
          <Card className="w-full max-w-md p-6 border-border shadow-2xl space-y-4">
            <div className="flex items-center gap-3 text-destructive">
              <div className="size-10 rounded-full bg-destructive/10 grid place-items-center">
                <Trash2 className="size-5" />
              </div>
              <div>
                <h3 className="font-bold text-base text-foreground">Hapus Data Siswa</h3>
                <p className="text-xs text-muted-foreground">Riwayat tagihan &amp; nilai tetap tersimpan, hanya siswa yang disembunyikan.</p>
              </div>
            </div>

            <p className="text-xs text-muted-foreground">
              Apakah Anda yakin ingin menghapus data siswa <strong className="text-foreground">{deletingStudent.nama_lengkap}</strong>
              {deletingStudent.nis ? ` (NIS: ${deletingStudent.nis})` : ""}?
            </p>

            <div>
              <Label className="text-xs">
                Ketik <strong className="font-mono text-foreground">{deletingStudent.nama_lengkap}</strong> untuk konfirmasi
              </Label>
              <Input
                value={deleteConfirmInput}
                onChange={(e) => setDeleteConfirmInput(e.target.value)}
                placeholder={deletingStudent.nama_lengkap}
                className="mt-1"
                autoFocus
              />
            </div>

            <div className="flex justify-end gap-2 border-t border-border pt-4">
              <Button variant="ghost" size="sm" onClick={() => setDeletingStudent(null)} disabled={submitting}>
                Batal
              </Button>
              <Button
                variant="destructive"
                size="sm"
                onClick={handleDelete}
                disabled={submitting || deleteConfirmInput.trim() !== deletingStudent.nama_lengkap}
                className="font-bold"
              >
                {submitting ? "Menghapus…" : "Ya, Hapus Siswa"}
              </Button>
            </div>
          </Card>
        </div>
      )}
    </div>
  );
}

"use client";

import { useCallback, useEffect, useState } from "react";
import { toast } from "sonner";
import {
  BadgePercent,
  CheckCircle2,
  Filter,
  Plus,
  Search,
  Trash2,
  UserCheck,
  Users,
  XCircle,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { rupiah } from "@/lib/format";

type FeeType = { ulid: string; code: string; name: string };
type SchoolUnit = { ulid: string; code: string; label: string };
type AcademicYear = { ulid: string; year: string; is_active: boolean };

type DiscountScheme = {
  ulid: string;
  code: string;
  name: string;
  type: "percent" | "nominal";
  value: number;
  fee_type: { ulid: string; code: string; name: string } | null;
  school_unit: { ulid: string; code: string; label: string } | null;
  is_active: boolean;
  notes: string | null;
  student_count: number;
};

type StudentDiscount = {
  ulid: string;
  student: {
    ulid: string;
    nama_lengkap: string;
    nis: string | null;
    unit: string | null;
  };
  scheme: {
    ulid: string;
    code: string;
    name: string;
    type: "percent" | "nominal";
    value: number;
    fee_type: string;
  };
  academic_year: string;
  effective_from: string;
  effective_to: string | null;
  reason: string | null;
};

export default function AdminDiscountPage() {
  const [activeTab, setActiveTab] = useState<"schemes" | "students">("schemes");
  const [schemes, setSchemes] = useState<DiscountScheme[] | null>(null);
  const [studentDiscounts, setStudentDiscounts] = useState<StudentDiscount[] | null>(null);
  const [feeTypes, setFeeTypes] = useState<FeeType[]>([]);
  const [units, setUnits] = useState<SchoolUnit[]>([]);
  const [years, setYears] = useState<AcademicYear[]>([]);

  // Modals state
  const [showSchemeModal, setShowSchemeModal] = useState(false);
  const [showAssignModal, setShowAssignModal] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  // Scheme Form
  const [schemeForm, setSchemeForm] = useState({
    code: "",
    name: "",
    type: "percent" as "percent" | "nominal",
    value: "",
    fee_type_ulid: "",
    school_unit_ulid: "",
    is_active: true,
    notes: "",
  });

  // Assign Form
  const [assignForm, setAssignForm] = useState({
    student_search: "",
    student_ulid: "",
    discount_scheme_ulid: "",
    academic_year_ulid: "",
    effective_from: new Date().toISOString().split("T")[0],
    effective_to: "",
    reason: "",
  });

  const [studentResults, setStudentResults] = useState<Array<{ ulid: string; nama_lengkap: string; nis: string | null; unit: string }>>([]);
  const [searchingStudents, setSearchingStudents] = useState(false);

  const loadData = useCallback(async () => {
    try {
      const [schRes, stRes, ftRes, unRes, yrRes] = await Promise.all([
        api.get<{ schemes: DiscountScheme[] }>("/api/admin/discount-schemes"),
        api.get<{ student_discounts: StudentDiscount[] }>("/api/admin/student-discounts"),
        api.get<{ fee_types: FeeType[] }>("/api/admin/fee-types"),
        api.get<{ school_units: SchoolUnit[] }>("/api/admin/school-units"),
        api.get<{ academic_years: AcademicYear[] }>("/api/admin/academic-years"),
      ]);

      setSchemes(schRes.schemes);
      setStudentDiscounts(stRes.student_discounts);
      setFeeTypes(ftRes.fee_types);
      setUnits(unRes.school_units);
      setYears(yrRes.academic_years);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memuat data diskon.");
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // Student search for assignment
  useEffect(() => {
    if (assignForm.student_search.length >= 2) {
      setSearchingStudents(true);
      const timer = setTimeout(async () => {
        try {
          const res = await api.get<{ students: Array<{ ulid: string; nama_lengkap: string; nis: string | null; school_unit: { label: string } | null }> }>(
            `/api/admin/bills?student=${encodeURIComponent(assignForm.student_search)}&limit=8`
          );
          // Extract unique students
          const list = (res.students || []).map((s) => ({
            ulid: s.ulid,
            nama_lengkap: s.nama_lengkap,
            nis: s.nis,
            unit: s.school_unit?.label ?? "-",
          }));
          setStudentResults(list);
        } catch {
          // fallback
        } finally {
          setSearchingStudents(false);
        }
      }, 300);
      return () => clearTimeout(timer);
    } else {
      setStudentResults([]);
    }
  }, [assignForm.student_search]);

  async function handleCreateScheme(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);

    try {
      await api.post("/api/admin/discount-schemes", {
        code: schemeForm.code,
        name: schemeForm.name,
        type: schemeForm.type,
        value: parseFloat(schemeForm.value),
        fee_type_ulid: schemeForm.fee_type_ulid || null,
        school_unit_ulid: schemeForm.school_unit_ulid || null,
        is_active: schemeForm.is_active,
        notes: schemeForm.notes || null,
      });

      toast.success("Skema diskon baru berhasil disimpan.");
      setShowSchemeModal(false);
      setSchemeForm({
        code: "",
        name: "",
        type: "percent",
        value: "",
        fee_type_ulid: "",
        school_unit_ulid: "",
        is_active: true,
        notes: "",
      });
      loadData();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyimpan skema diskon.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDeleteScheme(ulid: string, name: string) {
    if (!confirm(`Hapus skema diskon "${name}"?`)) return;

    try {
      await api.delete(`/api/admin/discount-schemes/${ulid}`);
      toast.success("Skema diskon berhasil dihapus.");
      loadData();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus skema diskon.");
    }
  }

  async function handleAssignDiscount(e: React.FormEvent) {
    e.preventDefault();
    if (!assignForm.student_ulid) {
      toast.error("Pilih siswa terlebih dahulu.");
      return;
    }

    setSubmitting(true);
    try {
      await api.post("/api/admin/student-discounts", {
        student_ulid: assignForm.student_ulid,
        discount_scheme_ulid: assignForm.discount_scheme_ulid,
        academic_year_ulid: assignForm.academic_year_ulid,
        effective_from: assignForm.effective_from,
        effective_to: assignForm.effective_to || null,
        reason: assignForm.reason || null,
      });

      toast.success("Diskon berhasil ditetapkan untuk siswa.");
      setShowAssignModal(false);
      setAssignForm({
        student_search: "",
        student_ulid: "",
        discount_scheme_ulid: "",
        academic_year_ulid: "",
        effective_from: new Date().toISOString().split("T")[0],
        effective_to: "",
        reason: "",
      });
      loadData();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menetapkan diskon siswa.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleRevokeStudentDiscount(ulid: string, studentName: string) {
    if (!confirm(`Cabut diskon untuk siswa ${studentName}?`)) return;

    try {
      await api.delete(`/api/admin/student-discounts/${ulid}`);
      toast.success("Diskon siswa berhasil dicabut.");
      loadData();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mencabut diskon siswa.");
    }
  }

  return (
    <div className="space-y-6">
      {/* Header section */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Kelola Diskon & Beasiswa</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Atur skema potongan biaya, beasiswa prestasi, subsidi yatim, dan penetapan diskon per siswa.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          {activeTab === "schemes" ? (
            <Button onClick={() => setShowSchemeModal(true)} className="gap-2 shadow-xs">
              <Plus className="size-4" />
              <span>Tambah Skema Diskon</span>
            </Button>
          ) : (
            <Button onClick={() => setShowAssignModal(true)} className="gap-2 shadow-xs">
              <UserCheck className="size-4" />
              <span>Tetapkan Diskon Siswa</span>
            </Button>
          )}
        </div>
      </div>

      {/* Top Stat Cards */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Card className="p-5 border-border/80">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Total Skema</span>
            <BadgePercent className="size-5 text-primary" />
          </div>
          <p className="mt-2 text-2xl font-bold">{schemes?.length ?? <Skeleton className="h-8 w-16" />}</p>
          <p className="mt-1 text-xs text-muted-foreground">Kategori beasiswa & potongan</p>
        </Card>

        <Card className="p-5 border-border/80">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Skema Aktif</span>
            <CheckCircle2 className="size-5 text-emerald-600" />
          </div>
          <p className="mt-2 text-2xl font-bold">
            {schemes?.filter((s) => s.is_active).length ?? <Skeleton className="h-8 w-16" />}
          </p>
          <p className="mt-1 text-xs text-muted-foreground">Dapat diterapkan pada tagihan</p>
        </Card>

        <Card className="p-5 border-border/80">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Siswa Penerima</span>
            <Users className="size-5 text-amber-600" />
          </div>
          <p className="mt-2 text-2xl font-bold">{studentDiscounts?.length ?? <Skeleton className="h-8 w-16" />}</p>
          <p className="mt-1 text-xs text-muted-foreground">Siswa aktif penerima beasiswa</p>
        </Card>
      </div>

      {/* Tab Switcher */}
      <div className="flex border-b border-border gap-4">
        <button
          onClick={() => setActiveTab("schemes")}
          className={`pb-3 text-sm font-semibold border-b-2 transition-all ${
            activeTab === "schemes"
              ? "border-primary text-primary"
              : "border-transparent text-muted-foreground hover:text-foreground"
          }`}
        >
          Daftar Skema Diskon ({schemes?.length ?? 0})
        </button>
        <button
          onClick={() => setActiveTab("students")}
          className={`pb-3 text-sm font-semibold border-b-2 transition-all ${
            activeTab === "students"
              ? "border-primary text-primary"
              : "border-transparent text-muted-foreground hover:text-foreground"
          }`}
        >
          Penerima Diskon Siswa ({studentDiscounts?.length ?? 0})
        </button>
      </div>

      {/* TAB 1: SKEMA DISKON */}
      {activeTab === "schemes" && (
        <Card className="overflow-hidden border-border/80">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border bg-muted/40 text-xs font-bold uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-5 py-3.5">Kode & Nama Skema</th>
                  <th className="px-5 py-3.5">Besaran Potongan</th>
                  <th className="px-5 py-3.5">Jenis Tagihan</th>
                  <th className="px-5 py-3.5">Unit Sekolah</th>
                  <th className="px-5 py-3.5">Penerima</th>
                  <th className="px-5 py-3.5">Status</th>
                  <th className="px-5 py-3.5 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {schemes === null && (
                  <tr>
                    <td colSpan={7} className="p-6 text-center text-muted-foreground">
                      Memuat skema diskon...
                    </td>
                  </tr>
                )}
                {schemes?.length === 0 && (
                  <tr>
                    <td colSpan={7} className="p-8 text-center text-muted-foreground">
                      Belum ada skema diskon. Klik tombol &quot;Tambah Skema Diskon&quot; untuk membuat.
                    </td>
                  </tr>
                )}
                {schemes?.map((s) => (
                  <tr key={s.ulid} className="hover:bg-muted/20 transition-colors">
                    <td className="px-5 py-4">
                      <p className="font-bold text-foreground">{s.name}</p>
                      <p className="text-xs font-mono text-muted-foreground">{s.code}</p>
                      {s.notes && <p className="text-xs text-muted-foreground/80 mt-0.5">{s.notes}</p>}
                    </td>
                    <td className="px-5 py-4">
                      <span className="font-semibold text-primary">
                        {s.type === "percent" ? `${s.value}%` : rupiah(s.value)}
                      </span>
                    </td>
                    <td className="px-5 py-4">
                      <Badge variant="default">{s.fee_type?.name ?? "Semua Tagihan"}</Badge>
                    </td>
                    <td className="px-5 py-4">
                      <span className="text-xs font-medium text-muted-foreground">
                        {s.school_unit?.label ?? "Semua Unit"}
                      </span>
                    </td>
                    <td className="px-5 py-4">
                      <span className="font-semibold text-foreground">{s.student_count} siswa</span>
                    </td>
                    <td className="px-5 py-4">
                      <Badge variant={s.is_active ? "good" : "default"}>
                        {s.is_active ? "Aktif" : "Non-aktif"}
                      </Badge>
                    </td>
                    <td className="px-5 py-4 text-right">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleDeleteScheme(s.ulid, s.name)}
                        className="text-destructive hover:bg-destructive/10"
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {/* TAB 2: PENERIMA DISKON SISWA */}
      {activeTab === "students" && (
        <Card className="overflow-hidden border-border/80">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border bg-muted/40 text-xs font-bold uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-5 py-3.5">Siswa</th>
                  <th className="px-5 py-3.5">Unit Sekolah</th>
                  <th className="px-5 py-3.5">Skema Diskon</th>
                  <th className="px-5 py-3.5">Potongan</th>
                  <th className="px-5 py-3.5">Tahun Ajaran</th>
                  <th className="px-5 py-3.5">Masa Berlaku</th>
                  <th className="px-5 py-3.5 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {studentDiscounts === null && (
                  <tr>
                    <td colSpan={7} className="p-6 text-center text-muted-foreground">
                      Memuat penerima diskon...
                    </td>
                  </tr>
                )}
                {studentDiscounts?.length === 0 && (
                  <tr>
                    <td colSpan={7} className="p-8 text-center text-muted-foreground">
                      Belum ada siswa yang ditetapkan mendapat diskon/beasiswa.
                    </td>
                  </tr>
                )}
                {studentDiscounts?.map((sd) => (
                  <tr key={sd.ulid} className="hover:bg-muted/20 transition-colors">
                    <td className="px-5 py-4">
                      <p className="font-bold text-foreground">{sd.student.nama_lengkap}</p>
                      <p className="text-xs font-mono text-muted-foreground">NIS: {sd.student.nis ?? "-"}</p>
                      {sd.reason && <p className="text-xs text-muted-foreground/80 mt-0.5">{sd.reason}</p>}
                    </td>
                    <td className="px-5 py-4">
                      <span className="text-xs text-muted-foreground">{sd.student.unit ?? "-"}</span>
                    </td>
                    <td className="px-5 py-4">
                      <p className="font-semibold text-foreground">{sd.scheme.name}</p>
                      <span className="text-xs text-muted-foreground">{sd.scheme.fee_type}</span>
                    </td>
                    <td className="px-5 py-4">
                      <span className="font-bold text-primary">
                        {sd.scheme.type === "percent" ? `${sd.scheme.value}%` : rupiah(sd.scheme.value)}
                      </span>
                    </td>
                    <td className="px-5 py-4">
                      <Badge variant="default">{sd.academic_year}</Badge>
                    </td>
                    <td className="px-5 py-4">
                      <p className="text-xs font-medium text-foreground">Mulai: {sd.effective_from}</p>
                      {sd.effective_to && <p className="text-xs text-muted-foreground">Sampai: {sd.effective_to}</p>}
                    </td>
                    <td className="px-5 py-4 text-right">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleRevokeStudentDiscount(sd.ulid, sd.student.nama_lengkap)}
                        className="text-destructive hover:bg-destructive/10"
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {/* MODAL: TAMBAH SKEMA DISKON */}
      {showSchemeModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
          <div className="w-full max-w-lg rounded-2xl bg-card p-6 shadow-2xl border border-border">
            <h2 className="text-lg font-bold text-foreground">Tambah Skema Diskon Baru</h2>
            <p className="text-xs text-muted-foreground mt-1">
              Buat aturan potongan biaya atau beasiswa baru.
            </p>

            <form onSubmit={handleCreateScheme} className="mt-5 space-y-4">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <Label htmlFor="code" className="text-xs">Kode Unik</Label>
                  <Input
                    id="code"
                    placeholder="misal: beasiswa_prestasi"
                    value={schemeForm.code}
                    onChange={(e) => setSchemeForm({ ...schemeForm, code: e.target.value.toLowerCase().replace(/\s+/g, "_") })}
                    required
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label htmlFor="name" className="text-xs">Nama Skema</Label>
                  <Input
                    id="name"
                    placeholder="misal: Beasiswa Prestasi Akademik"
                    value={schemeForm.name}
                    onChange={(e) => setSchemeForm({ ...schemeForm, name: e.target.value })}
                    required
                    className="mt-1"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <Label htmlFor="type" className="text-xs">Tipe Potongan</Label>
                  <select
                    id="type"
                    value={schemeForm.type}
                    onChange={(e) => setSchemeForm({ ...schemeForm, type: e.target.value as "percent" | "nominal" })}
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary"
                  >
                    <option value="percent">Persentase (%)</option>
                    <option value="nominal">Nominal Tetap (Rp)</option>
                  </select>
                </div>
                <div>
                  <Label htmlFor="value" className="text-xs">Nilai Potongan ({schemeForm.type === "percent" ? "%" : "Rp"})</Label>
                  <Input
                    id="value"
                    type="number"
                    min="0"
                    step="any"
                    placeholder={schemeForm.type === "percent" ? "contoh: 50" : "contoh: 250000"}
                    value={schemeForm.value}
                    onChange={(e) => setSchemeForm({ ...schemeForm, value: e.target.value })}
                    required
                    className="mt-1"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <Label htmlFor="fee_type" className="text-xs">Berlaku untuk Tagihan</Label>
                  <select
                    id="fee_type"
                    value={schemeForm.fee_type_ulid}
                    onChange={(e) => setSchemeForm({ ...schemeForm, fee_type_ulid: e.target.value })}
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary"
                  >
                    <option value="">Semua Tagihan</option>
                    {feeTypes.map((ft) => (
                      <option key={ft.ulid} value={ft.ulid}>{ft.name}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <Label htmlFor="unit" className="text-xs">Unit Sekolah</Label>
                  <select
                    id="unit"
                    value={schemeForm.school_unit_ulid}
                    onChange={(e) => setSchemeForm({ ...schemeForm, school_unit_ulid: e.target.value })}
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary"
                  >
                    <option value="">Semua Unit</option>
                    {units.map((u) => (
                      <option key={u.ulid} value={u.ulid}>{u.label}</option>
                    ))}
                  </select>
                </div>
              </div>

              <div>
                <Label htmlFor="notes" className="text-xs">Catatan & Keterangan</Label>
                <Input
                  id="notes"
                  placeholder="Keterangan opsional..."
                  value={schemeForm.notes}
                  onChange={(e) => setSchemeForm({ ...schemeForm, notes: e.target.value })}
                  className="mt-1"
                />
              </div>

              <div className="flex justify-end gap-2.5 pt-3">
                <Button type="button" variant="outline" onClick={() => setShowSchemeModal(false)}>
                  Batal
                </Button>
                <Button type="submit" disabled={submitting}>
                  {submitting ? "Menyimpan..." : "Simpan Skema"}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL: TETAPKAN DISKON SISWA */}
      {showAssignModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
          <div className="w-full max-w-lg rounded-2xl bg-card p-6 shadow-2xl border border-border">
            <h2 className="text-lg font-bold text-foreground">Tetapkan Diskon ke Siswa</h2>
            <p className="text-xs text-muted-foreground mt-1">
              Pilih siswa penerima beasiswa atau diskon khusus.
            </p>

            <form onSubmit={handleAssignDiscount} className="mt-5 space-y-4">
              <div>
                <Label htmlFor="student_search" className="text-xs">Cari Nama / NIS Siswa</Label>
                <Input
                  id="student_search"
                  placeholder="Ketik minimal 2 huruf nama siswa..."
                  value={assignForm.student_search}
                  onChange={(e) => setAssignForm({ ...assignForm, student_search: e.target.value, student_ulid: "" })}
                  className="mt-1"
                />
                {studentResults.length > 0 && !assignForm.student_ulid && (
                  <div className="mt-1 max-h-36 overflow-y-auto rounded-md border border-border bg-card shadow-lg divide-y divide-border">
                    {studentResults.map((s) => (
                      <button
                        type="button"
                        key={s.ulid}
                        onClick={() => {
                          setAssignForm({
                            ...assignForm,
                            student_ulid: s.ulid,
                            student_search: `${s.nama_lengkap} (${s.unit})`,
                          });
                          setStudentResults([]);
                        }}
                        className="w-full text-left px-3 py-2 text-xs hover:bg-muted/40 flex justify-between items-center"
                      >
                        <span className="font-semibold">{s.nama_lengkap}</span>
                        <span className="text-muted-foreground">{s.unit}</span>
                      </button>
                    ))}
                  </div>
                )}
              </div>

              <div>
                <Label htmlFor="scheme" className="text-xs">Pilih Skema Diskon</Label>
                <select
                  id="scheme"
                  value={assignForm.discount_scheme_ulid}
                  onChange={(e) => setAssignForm({ ...assignForm, discount_scheme_ulid: e.target.value })}
                  required
                  className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary"
                >
                  <option value="">Pilih Skema...</option>
                  {schemes?.filter((s) => s.is_active).map((s) => (
                    <option key={s.ulid} value={s.ulid}>
                      {s.name} ({s.type === "percent" ? `${s.value}%` : rupiah(s.value)})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <Label htmlFor="year" className="text-xs">Tahun Ajaran</Label>
                <select
                  id="year"
                  value={assignForm.academic_year_ulid}
                  onChange={(e) => setAssignForm({ ...assignForm, academic_year_ulid: e.target.value })}
                  required
                  className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary"
                >
                  <option value="">Pilih Tahun Ajaran...</option>
                  {years.map((y) => (
                    <option key={y.ulid} value={y.ulid}>{y.year}</option>
                  ))}
                </select>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <Label htmlFor="eff_from" className="text-xs">Berlaku Mulai</Label>
                  <Input
                    id="eff_from"
                    type="date"
                    value={assignForm.effective_from}
                    onChange={(e) => setAssignForm({ ...assignForm, effective_from: e.target.value })}
                    required
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label htmlFor="eff_to" className="text-xs">Sampai (Opsional)</Label>
                  <Input
                    id="eff_to"
                    type="date"
                    value={assignForm.effective_to}
                    onChange={(e) => setAssignForm({ ...assignForm, effective_to: e.target.value })}
                    className="mt-1"
                  />
                </div>
              </div>

              <div>
                <Label htmlFor="reason" className="text-xs">Alasan / No SK Beasiswa</Label>
                <Input
                  id="reason"
                  placeholder="misal: SK Beasiswa Tahfidz No. 123/YAPI/2026"
                  value={assignForm.reason}
                  onChange={(e) => setAssignForm({ ...assignForm, reason: e.target.value })}
                  className="mt-1"
                />
              </div>

              <div className="flex justify-end gap-2.5 pt-3">
                <Button type="button" variant="outline" onClick={() => setShowAssignModal(false)}>
                  Batal
                </Button>
                <Button type="submit" disabled={submitting}>
                  {submitting ? "Menetapkan..." : "Tetapkan Diskon"}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

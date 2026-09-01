"use client";

import { useCallback, useEffect, useState } from "react";
import { toast } from "sonner";
import {
  Building2,
  Calendar,
  CheckCircle2,
  Download,
  Filter,
  Layers,
  Plus,
  RefreshCw,
  Search,
  Sparkles,
  Trash2,
  UploadCloud,
  X,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { rupiah } from "@/lib/format";
import { useAuth } from "@/lib/auth/auth-context";

type FeeType = {
  ulid: string;
  code: string;
  name: string;
  recurrence: string;
  allow_installment: boolean;
  requires_selection: boolean;
  requires_roster_membership: boolean;
  is_active: boolean;
  rate_count: number;
};

type Component = {
  ulid?: string;
  name: string;
  amount: number | string;
  default_qty: number;
  is_optional: boolean;
  has_size_option: boolean;
  size_options: string | null;
};

type Rate = {
  ulid: string;
  fee_type: { code: string; name: string };
  unit: { code: string; label: string };
  academic_year: string;
  tingkat: number | null;
  amount: number;
  due_day: number | null;
  late_fee_amount: number;
  is_active: boolean;
  components: Component[];
};

type Option = { ulid: string; code?: string; label?: string; year?: string; is_active?: boolean; starts_on?: string; ends_on?: string };

const RECURRENCE_LABEL: Record<string, string> = {
  monthly: "Bulanan (SPP)",
  per_term: "Per Semester",
  once: "Sekali Bayar",
};

export default function FeeRatesPage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [activeTab, setActiveTab] = useState<"rates" | "types">("rates");
  const [feeTypes, setFeeTypes] = useState<FeeType[] | null>(null);
  const [rates, setRates] = useState<Rate[] | null>(null);
  const [units, setUnits] = useState<Option[]>([]);
  const [years, setYears] = useState<Option[]>([]);

  // Filters
  const [filterUnit, setFilterUnit] = useState<string>("");
  const [filterType, setFilterType] = useState<string>("");
  const [filterYear, setFilterYear] = useState<string>("");

  // Modals
  const [showRateModal, setShowRateModal] = useState(false);
  const [showTypeModal, setShowTypeModal] = useState(false);
  const [showImportModal, setShowImportModal] = useState(false);
  const [showYearModal, setShowYearModal] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  // Forms
  const [rateForm, setRateForm] = useState({
    fee_type_ulid: "",
    school_unit_ulid: "",
    academic_year_ulid: "",
    tingkat: "",
    amount: "",
    due_day: "10",
    late_fee_amount: "0",
    notes: "",
  });

  const [typeForm, setTypeForm] = useState({
    code: "",
    name: "",
    recurrence: "monthly" as "monthly" | "per_term" | "once",
    allow_installment: false,
    requires_selection: false,
    requires_roster_membership: false,
  });

  const emptyComponent = (): Component => ({
    name: "", amount: "", default_qty: 1, is_optional: false, has_size_option: false, size_options: "",
  });
  const [rateComponents, setRateComponents] = useState<Component[]>([]);

  const [newYearName, setNewYearName] = useState("2027/2028");
  const [newYearStarts, setNewYearStarts] = useState("2027-07-01");
  const [newYearEnds, setNewYearEnds] = useState("2028-06-30");

  const [importFile, setImportFile] = useState<File | null>(null);
  const [importing, setImporting] = useState(false);
  const [importResult, setImportResult] = useState<{ message: string; imported: number; updated: number; errors: string[] } | null>(null);

  const loadData = useCallback(async () => {
    try {
      const queryParams = new URLSearchParams();
      if (filterUnit) queryParams.set("unit", filterUnit);
      if (filterType) queryParams.set("type", filterType);
      if (filterYear) queryParams.set("year", filterYear);

      const [ftRes, rRes, uRes, yRes] = await Promise.all([
        api.get<{ fee_types: FeeType[] }>("/api/admin/fee-types"),
        api.get<{ rates: Rate[] }>(`/api/admin/fee-rates?${queryParams.toString()}`),
        api.get<{ school_units: Option[] }>("/api/admin/school-units"),
        api.get<{ academic_years: Option[] }>("/api/admin/academic-years"),
      ]);

      setFeeTypes(ftRes.fee_types);
      setRates(rRes.rates);
      setUnits(uRes.school_units);
      setYears(yRes.academic_years);

      if (ftRes.fee_types.length > 0 && !rateForm.fee_type_ulid) {
        setRateForm((f) => ({
          ...f,
          fee_type_ulid: ftRes.fee_types[0].ulid,
          school_unit_ulid: uRes.school_units[0]?.ulid ?? "",
          academic_year_ulid: yRes.academic_years.find((y) => y.is_active)?.ulid ?? yRes.academic_years[0]?.ulid ?? "",
        }));
      }
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memuat data tarif.");
    }
  }, [filterUnit, filterType, filterYear, rateForm.fee_type_ulid]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  async function handleCreateRate(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);

    try {
      await api.post("/api/admin/fee-rates", {
        fee_type_ulid: rateForm.fee_type_ulid,
        school_unit_ulid: rateForm.school_unit_ulid,
        academic_year_ulid: rateForm.academic_year_ulid,
        tingkat: rateForm.tingkat ? parseInt(rateForm.tingkat) : null,
        amount: parseFloat(rateForm.amount || "0"),
        due_day: rateForm.due_day ? parseInt(rateForm.due_day) : null,
        late_fee_amount: parseFloat(rateForm.late_fee_amount || "0"),
        notes: rateForm.notes || null,
        components: rateComponents
          .filter((c) => c.name.trim())
          .map((c) => ({
            name: c.name,
            amount: parseFloat(String(c.amount) || "0"),
            default_qty: c.default_qty,
            is_optional: c.is_optional,
            has_size_option: c.has_size_option,
            size_options: c.has_size_option ? c.size_options : null,
          })),
      });

      toast.success("Tarif biaya berhasil disimpan.");
      setShowRateModal(false);
      setRateForm((f) => ({ ...f, amount: "", tingkat: "", notes: "" }));
      setRateComponents([]);
      loadData();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyimpan tarif.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleCreateType(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);

    try {
      await api.post("/api/admin/fee-types", {
        code: typeForm.code,
        name: typeForm.name,
        recurrence: typeForm.recurrence,
        allow_installment: typeForm.allow_installment,
        requires_selection: typeForm.requires_selection,
        requires_roster_membership: typeForm.requires_roster_membership,
      });

      toast.success("Jenis biaya baru berhasil ditambahkan.");
      setShowTypeModal(false);
      setTypeForm({
        code: "", name: "", recurrence: "monthly",
        allow_installment: false, requires_selection: false, requires_roster_membership: false,
      });
      loadData();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menambahkan jenis biaya.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleCreateYear(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      await api.post("/api/admin/academic-years", {
        year: newYearName,
        starts_on: newYearStarts,
        ends_on: newYearEnds,
        is_active: false,
      });
      toast.success(`Tahun ajaran ${newYearName} berhasil ditambahkan.`);
      loadData();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menambahkan tahun ajaran.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleActivateYear(yearUlid: string, yearLabel: string) {
    try {
      await api.post(`/api/admin/academic-years/${yearUlid}/activate`);
      toast.success(`Tahun ajaran ${yearLabel} telah diaktifkan.`);
      loadData();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengaktifkan tahun ajaran.");
    }
  }

  async function handleImportTariff(e: React.FormEvent) {
    e.preventDefault();
    if (!importFile) {
      toast.error("Silakan pilih file CSV tarif.");
      return;
    }

    setImporting(true);
    setImportResult(null);

    const form = new FormData();
    form.set("file", importFile);

    try {
      const res = await api.post<{
        message: string;
        imported_count: number;
        updated_count: number;
        errors: string[];
      }>("/api/admin/import/fee-rates", form);

      toast.success(res.message);
      setImportResult({
        message: res.message,
        imported: res.imported_count,
        updated: res.updated_count,
        errors: res.errors || [],
      });
      loadData();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengimpor tarif.");
    } finally {
      setImporting(false);
    }
  }

  const filteredRates = rates?.filter((r) => {
    if (filterUnit && r.unit.code !== filterUnit) return false;
    if (filterType && r.fee_type.code !== filterType) return false;
    if (filterYear && r.academic_year !== filterYear) return false;
    return true;
  });

  if (!isCentral) {
    return (
      <Card className="p-6 text-sm text-muted-foreground">
        Tarif hanya dikelola admin pusat — perubahan nominal tarif berlaku untuk penagihan seluruh unit sekolah.
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header section */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Pengaturan Biaya & SPP Sekolah</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Atur jenis tagihan (SPP, Gedung, Seragam), nominal tarif per unit/tingkat kelas, dan master tahun ajaran.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Button
            onClick={() => setShowYearModal(true)}
            variant="outline"
            size="sm"
            className="gap-1.5 font-semibold text-xs h-9"
          >
            <Calendar className="size-4 text-primary" />
            <span>Kelola Tahun Ajaran</span>
          </Button>

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
            <span>Import Tarif SPP (CSV)</span>
          </Button>

          {activeTab === "rates" ? (
            <Button onClick={() => setShowRateModal(true)} size="sm" className="gap-1.5 font-bold text-xs h-9 shadow-xs">
              <Plus className="size-4" />
              <span>Tambah Tarif Baru</span>
            </Button>
          ) : (
            <Button onClick={() => setShowTypeModal(true)} size="sm" className="gap-1.5 font-bold text-xs h-9 shadow-xs">
              <Plus className="size-4" />
              <span>Tambah Jenis Biaya</span>
            </Button>
          )}
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Card className="p-5 border-border/80 shadow-xs">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Total Tarif Terpasang</span>
            <Building2 className="size-5 text-primary" />
          </div>
          <p className="mt-2 text-2xl font-bold">{rates?.length ?? <Skeleton className="h-8 w-16" />}</p>
          <p className="mt-1 text-xs text-muted-foreground">Kombinasi unit, kelas & tahun ajaran</p>
        </Card>

        <Card className="p-5 border-border/80 shadow-xs">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Master Jenis Biaya</span>
            <Layers className="size-5 text-indigo-600" />
          </div>
          <p className="mt-2 text-2xl font-bold">{feeTypes?.length ?? <Skeleton className="h-8 w-16" />}</p>
          <p className="mt-1 text-xs text-muted-foreground">Kategori tagihan (SPP, Gedung, Seragam, dll)</p>
        </Card>

        <Card className="p-5 border-border/80 shadow-xs">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Tahun Ajaran Terdaftar</span>
            <Calendar className="size-5 text-emerald-600" />
          </div>
          <p className="mt-2 text-2xl font-bold">{years.length} Tahun</p>
          <p className="mt-1 text-xs text-muted-foreground">
            Aktif: <strong>{years.find((y) => y.is_active)?.year ?? "—"}</strong>
          </p>
        </Card>
      </div>

      {/* Tabs */}
      <div className="flex border-b border-border gap-4">
        <button
          onClick={() => setActiveTab("rates")}
          className={`pb-3 text-sm font-semibold border-b-2 transition-all ${
            activeTab === "rates"
              ? "border-primary text-primary"
              : "border-transparent text-muted-foreground hover:text-foreground"
          }`}
        >
          Daftar Tarif Berlaku ({rates?.length ?? 0})
        </button>
        <button
          onClick={() => setActiveTab("types")}
          className={`pb-3 text-sm font-semibold border-b-2 transition-all ${
            activeTab === "types"
              ? "border-primary text-primary"
              : "border-transparent text-muted-foreground hover:text-foreground"
          }`}
        >
          Master Jenis Biaya ({feeTypes?.length ?? 0})
        </button>
      </div>

      {/* TAB 1: TARIF BERLAKU */}
      {activeTab === "rates" && (
        <div className="space-y-4">
          {/* Filters Bar */}
          <div className="flex flex-wrap items-center gap-3 bg-muted/40 p-3.5 rounded-xl border border-border">
            <div className="flex items-center gap-2 text-xs font-semibold text-muted-foreground">
              <Filter className="size-4" />
              <span>Filter:</span>
            </div>

            <select
              value={filterYear}
              onChange={(e) => setFilterYear(e.target.value)}
              className="rounded-lg border border-input bg-card px-3 py-1.5 text-xs font-semibold text-foreground shadow-2xs"
            >
              <option value="">Semua Tahun Ajaran</option>
              {years.map((y) => (
                <option key={y.ulid} value={y.year}>
                  Tahun {y.year} {y.is_active ? "(Aktif)" : ""}
                </option>
              ))}
            </select>

            <select
              value={filterUnit}
              onChange={(e) => setFilterUnit(e.target.value)}
              className="rounded-lg border border-input bg-card px-3 py-1.5 text-xs font-semibold text-foreground shadow-2xs"
            >
              <option value="">Semua Unit Sekolah</option>
              {units.map((u) => (
                <option key={u.ulid} value={u.code}>{u.label}</option>
              ))}
            </select>

            <select
              value={filterType}
              onChange={(e) => setFilterType(e.target.value)}
              className="rounded-lg border border-input bg-card px-3 py-1.5 text-xs font-semibold text-foreground shadow-2xs"
            >
              <option value="">Semua Jenis Biaya</option>
              {feeTypes?.map((t) => (
                <option key={t.ulid} value={t.code}>{t.name}</option>
              ))}
            </select>
          </div>

          <Card className="overflow-hidden border-border/80 shadow-xs">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="border-b border-border bg-muted/40 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-5 py-3.5">Jenis Tagihan</th>
                    <th className="px-5 py-3.5">Unit Sekolah</th>
                    <th className="px-5 py-3.5">Tingkat Kelas</th>
                    <th className="px-5 py-3.5">Tahun Ajaran</th>
                    <th className="px-5 py-3.5">Jatuh Tempo</th>
                    <th className="px-5 py-3.5 text-right">Nominal Tagihan</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {rates === null && (
                    <tr>
                      <td colSpan={6} className="p-6 text-center text-muted-foreground">
                        Memuat data tarif...
                      </td>
                    </tr>
                  )}
                  {filteredRates?.length === 0 && (
                    <tr>
                      <td colSpan={6} className="p-8 text-center text-muted-foreground">
                        Tidak ada tarif yang sesuai filter.
                      </td>
                    </tr>
                  )}
                  {filteredRates?.map((r) => (
                    <tr key={r.ulid} className="hover:bg-muted/20 transition-colors">
                      <td className="px-5 py-4">
                        <p className="font-bold text-foreground text-sm">{r.fee_type.name}</p>
                        <span className="text-xs font-mono text-muted-foreground">{r.fee_type.code}</span>
                      </td>
                      <td className="px-5 py-4 font-medium text-foreground">{r.unit.label}</td>
                      <td className="px-5 py-4">
                        <Badge variant="default">{r.tingkat ? `Kelas ${r.tingkat}` : "Semua Tingkat"}</Badge>
                      </td>
                      <td className="px-5 py-4 text-muted-foreground font-semibold">{r.academic_year}</td>
                      <td className="px-5 py-4">
                        <span className="text-xs font-medium text-muted-foreground">
                          {r.due_day ? `Tgl ${r.due_day} tiap bulan` : "—"}
                        </span>
                      </td>
                      <td className="px-5 py-4 text-right">
                        <span className="font-bold text-primary text-sm font-mono">{rupiah(r.amount)}</span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </div>
      )}

      {/* TAB 2: MASTER JENIS BIAYA */}
      {activeTab === "types" && (
        <Card className="overflow-hidden border-border/80 shadow-xs">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="border-b border-border bg-muted/40 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-5 py-3.5">Kode</th>
                  <th className="px-5 py-3.5">Nama Tagihan</th>
                  <th className="px-5 py-3.5">Periode Penagihan</th>
                  <th className="px-5 py-3.5">Dapat Dicicil</th>
                  <th className="px-5 py-3.5">Jumlah Tarif Dibuat</th>
                  <th className="px-5 py-3.5">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {feeTypes?.map((t) => (
                  <tr key={t.ulid} className="hover:bg-muted/20 transition-colors">
                    <td className="px-5 py-4 font-mono font-bold text-primary">{t.code}</td>
                    <td className="px-5 py-4 font-bold text-foreground text-sm">{t.name}</td>
                    <td className="px-5 py-4 text-muted-foreground">
                      {RECURRENCE_LABEL[t.recurrence] ?? t.recurrence}
                    </td>
                    <td className="px-5 py-4">
                      <Badge variant={t.allow_installment ? "good" : "default"}>
                        {t.allow_installment ? "Ya (Cicilan / Custom)" : "Penuh Sekaligus"}
                      </Badge>
                    </td>
                    <td className="px-5 py-4 font-semibold">
                      {t.rate_count} tarif unit
                      {t.requires_selection && (
                        <Badge variant="warn" className="ml-2">Butuh pemilihan ukuran</Badge>
                      )}
                      {t.requires_roster_membership && (
                        <Badge variant="warn" className="ml-2">Butuh terdaftar ekskul</Badge>
                      )}
                    </td>
                    <td className="px-5 py-4">
                      <Badge variant={t.is_active ? "good" : "default"}>
                        {t.is_active ? "Aktif" : "Non-aktif"}
                      </Badge>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {/* MODAL: KELOLA TAHUN AJARAN */}
      {showYearModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
          <Card className="w-full max-w-lg p-6 border-border shadow-2xl space-y-5 my-8">
            <div className="flex items-center justify-between border-b border-border pb-3">
              <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
                <Calendar className="size-5 text-primary" />
                <span>Kelola Master Tahun Ajaran</span>
              </h2>
              <button onClick={() => setShowYearModal(false)} className="text-muted-foreground hover:text-foreground">
                <X className="size-5" />
              </button>
            </div>

            {/* List Tahun Ajaran */}
            <div className="space-y-2">
              <Label className="text-xs font-semibold">Daftar Tahun Ajaran Terdaftar:</Label>
              <div className="space-y-2 max-h-48 overflow-y-auto">
                {years.map((y) => (
                  <div
                    key={y.ulid}
                    className={`flex items-center justify-between p-3 rounded-xl border transition-colors ${
                      y.is_active ? "border-primary bg-primary/10" : "border-border bg-card"
                    }`}
                  >
                    <div>
                      <p className="font-bold text-sm text-foreground flex items-center gap-2">
                        <span>Tahun Ajaran {y.year}</span>
                        {y.is_active && <Badge variant="primary" className="text-[10px]">Aktif Saat Ini</Badge>}
                      </p>
                      <p className="text-[11px] text-muted-foreground">
                        Periode: {y.starts_on || "01 Jul"} s/d {y.ends_on || "30 Jun"}
                      </p>
                    </div>

                    {!y.is_active && (
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => handleActivateYear(y.ulid, y.year || "")}
                        className="text-xs font-semibold h-8"
                      >
                        Jadikan Aktif
                      </Button>
                    )}
                  </div>
                ))}
              </div>
            </div>

            {/* Form Tambah Tahun Ajaran Baru */}
            <form onSubmit={handleCreateYear} className="border-t border-border pt-4 space-y-3 text-xs">
              <p className="font-bold text-foreground">Tambah Tahun Ajaran Baru:</p>
              <div>
                <Label className="text-xs">Nama Tahun Ajaran</Label>
                <Input
                  value={newYearName}
                  onChange={(e) => setNewYearName(e.target.value)}
                  placeholder="contoh: 2027/2028"
                  required
                  className="mt-1 font-bold"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <Label className="text-xs">Tanggal Mulai</Label>
                  <Input
                    type="date"
                    value={newYearStarts}
                    onChange={(e) => setNewYearStarts(e.target.value)}
                    required
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label className="text-xs">Tanggal Selesai</Label>
                  <Input
                    type="date"
                    value={newYearEnds}
                    onChange={(e) => setNewYearEnds(e.target.value)}
                    required
                    className="mt-1"
                  />
                </div>
              </div>

              <div className="flex justify-end pt-2">
                <Button type="submit" disabled={submitting} className="font-bold shadow-xs">
                  {submitting ? "Menyimpan…" : "Simpan Tahun Ajaran"}
                </Button>
              </div>
            </form>
          </Card>
        </div>
      )}

      {/* MODAL: TAMBAH TARIF BARU */}
      {showRateModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
          <div className="w-full max-w-lg rounded-2xl bg-card p-6 shadow-2xl border border-border my-8">
            <h2 className="text-lg font-bold text-foreground">Tambah Tarif Biaya Baru</h2>
            <p className="text-xs text-muted-foreground mt-1">
              Tentukan nominal biaya untuk kombinasi jenis tagihan, unit, dan tingkat kelas.
            </p>

            <form onSubmit={handleCreateRate} className="mt-5 space-y-4 text-xs">
              <div>
                <Label htmlFor="rate_fee_type" className="text-xs">Jenis Biaya</Label>
                <select
                  id="rate_fee_type"
                  value={rateForm.fee_type_ulid}
                  onChange={(e) => setRateForm({ ...rateForm, fee_type_ulid: e.target.value })}
                  required
                  className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-xs"
                >
                  {feeTypes?.map((t) => (
                    <option key={t.ulid} value={t.ulid}>{t.name} ({t.code})</option>
                  ))}
                </select>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <Label htmlFor="rate_unit" className="text-xs">Unit Sekolah</Label>
                  <select
                    id="rate_unit"
                    value={rateForm.school_unit_ulid}
                    onChange={(e) => setRateForm({ ...rateForm, school_unit_ulid: e.target.value })}
                    required
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-xs"
                  >
                    {units.map((u) => (
                      <option key={u.ulid} value={u.ulid}>{u.label}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <Label htmlFor="rate_year" className="text-xs">Tahun Ajaran</Label>
                  <select
                    id="rate_year"
                    value={rateForm.academic_year_ulid}
                    onChange={(e) => setRateForm({ ...rateForm, academic_year_ulid: e.target.value })}
                    required
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-xs"
                  >
                    {years.map((y) => (
                      <option key={y.ulid} value={y.ulid}>{y.year} {y.is_active ? "(Aktif)" : ""}</option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <Label htmlFor="rate_tingkat" className="text-xs">Tingkat Kelas (Kosongkan = Semua)</Label>
                  <Input
                    id="rate_tingkat"
                    type="number"
                    min="1"
                    max="12"
                    placeholder="misal: 1 atau kosong"
                    value={rateForm.tingkat}
                    onChange={(e) => setRateForm({ ...rateForm, tingkat: e.target.value })}
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label htmlFor="rate_amount" className="text-xs">Nominal Tagihan (Rp)</Label>
                  <Input
                    id="rate_amount"
                    type="number"
                    min="0"
                    placeholder="misal: 500000"
                    value={rateForm.amount}
                    onChange={(e) => setRateForm({ ...rateForm, amount: e.target.value })}
                    required
                    className="mt-1 font-bold"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <Label htmlFor="rate_due_day" className="text-xs">Tgl Jatuh Tempo Tiap Bulan</Label>
                  <Input
                    id="rate_due_day"
                    type="number"
                    min="1"
                    max="28"
                    placeholder="contoh: 10"
                    value={rateForm.due_day}
                    onChange={(e) => setRateForm({ ...rateForm, due_day: e.target.value })}
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label htmlFor="rate_notes" className="text-xs">Keterangan Tambahan</Label>
                  <Input
                    id="rate_notes"
                    placeholder="Opsional..."
                    value={rateForm.notes}
                    onChange={(e) => setRateForm({ ...rateForm, notes: e.target.value })}
                    className="mt-1"
                  />
                </div>
              </div>

              <div className="border-t border-border pt-3">
                <div className="flex items-center justify-between">
                  <Label className="text-xs font-bold">
                    Komponen (opsional - kosongkan untuk tarif satu harga)
                  </Label>
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="h-7 text-[11px]"
                    onClick={() => setRateComponents((c) => [...c, emptyComponent()])}
                  >
                    + Komponen
                  </Button>
                </div>
                {feeTypes?.find((t) => t.ulid === rateForm.fee_type_ulid)?.requires_selection && rateComponents.length === 0 && (
                  <p className="mt-1.5 rounded-lg bg-warn-soft p-2 text-[11px] text-warn">
                    Jenis biaya ini butuh pemilihan - tanpa komponen di sini, tagihan tidak akan pernah bisa
                    diterbitkan (orang tua tidak punya apa pun untuk dipilih).
                  </p>
                )}
                <div className="mt-2 flex flex-col gap-2">
                  {rateComponents.map((c, i) => (
                    <div key={i} className="rounded-lg border border-border p-2.5">
                      <div className="flex flex-wrap items-end gap-2">
                        <Input
                          placeholder="Nama (mis. Kemeja Putih)"
                          value={c.name}
                          onChange={(e) => setRateComponents((cs) => cs.map((x, xi) => xi === i ? { ...x, name: e.target.value } : x))}
                          className="h-8 w-40 text-xs"
                        />
                        <Input
                          type="number"
                          min="0"
                          placeholder="Harga"
                          value={c.amount}
                          onChange={(e) => setRateComponents((cs) => cs.map((x, xi) => xi === i ? { ...x, amount: e.target.value } : x))}
                          className="h-8 w-28 text-xs"
                        />
                        <Input
                          type="number"
                          min="1"
                          value={c.default_qty}
                          onChange={(e) => setRateComponents((cs) => cs.map((x, xi) => xi === i ? { ...x, default_qty: parseInt(e.target.value) || 1 } : x))}
                          className="h-8 w-16 text-xs"
                        />
                        <label className="flex items-center gap-1 text-[11px]">
                          <input
                            type="checkbox"
                            checked={c.is_optional}
                            onChange={(e) => setRateComponents((cs) => cs.map((x, xi) => xi === i ? { ...x, is_optional: e.target.checked } : x))}
                          />
                          Opsional
                        </label>
                        <label className="flex items-center gap-1 text-[11px]">
                          <input
                            type="checkbox"
                            checked={c.has_size_option}
                            onChange={(e) => setRateComponents((cs) => cs.map((x, xi) => xi === i ? { ...x, has_size_option: e.target.checked } : x))}
                          />
                          Butuh ukuran
                        </label>
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          className="h-8 text-[11px] text-destructive"
                          onClick={() => setRateComponents((cs) => cs.filter((_, xi) => xi !== i))}
                        >
                          Hapus
                        </Button>
                      </div>
                      {c.has_size_option && (
                        <Input
                          placeholder="Daftar ukuran, pisahkan koma (mis. S,M,L,XL)"
                          value={c.size_options ?? ""}
                          onChange={(e) => setRateComponents((cs) => cs.map((x, xi) => xi === i ? { ...x, size_options: e.target.value } : x))}
                          className="mt-2 h-8 text-xs"
                        />
                      )}
                    </div>
                  ))}
                </div>
              </div>

              <div className="flex justify-end gap-2.5 pt-3 border-t border-border">
                <Button type="button" variant="ghost" onClick={() => setShowRateModal(false)}>
                  Batal
                </Button>
                <Button type="submit" disabled={submitting} className="font-bold shadow-xs">
                  {submitting ? "Menyimpan..." : "Simpan Tarif"}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL: TAMBAH JENIS BIAYA */}
      {showTypeModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
          <div className="w-full max-w-md rounded-2xl bg-card p-6 shadow-2xl border border-border my-8">
            <h2 className="text-lg font-bold text-foreground">Tambah Jenis Biaya Baru</h2>
            <p className="text-xs text-muted-foreground mt-1">
              Tambahkan kategori tagihan ke katalog sekolah.
            </p>

            <form onSubmit={handleCreateType} className="mt-5 space-y-4 text-xs">
              <div>
                <Label htmlFor="type_code" className="text-xs">Kode Kategori</Label>
                <Input
                  id="type_code"
                  placeholder="misal: uang_gedung, seragam"
                  value={typeForm.code}
                  onChange={(e) => setTypeForm({ ...typeForm, code: e.target.value.toLowerCase().replace(/\s+/g, "_") })}
                  required
                  className="mt-1"
                />
              </div>

              <div>
                <Label htmlFor="type_name" className="text-xs">Nama Jenis Biaya</Label>
                <Input
                  id="type_name"
                  placeholder="misal: Uang Pangkal / Gedung"
                  value={typeForm.name}
                  onChange={(e) => setTypeForm({ ...typeForm, name: e.target.value })}
                  required
                  className="mt-1 font-bold"
                />
              </div>

              <div>
                <Label htmlFor="type_recurrence" className="text-xs">Periode Penagihan</Label>
                <select
                  id="type_recurrence"
                  value={typeForm.recurrence}
                  onChange={(e) => setTypeForm({ ...typeForm, recurrence: e.target.value as "monthly" | "per_term" | "once" })}
                  className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-xs"
                >
                  <option value="monthly">Bulanan (seperti SPP)</option>
                  <option value="per_term">Per Semester</option>
                  <option value="once">Sekali Bayar (seperti Uang Pangkal/Gedung)</option>
                </select>
              </div>

              <div className="flex items-center gap-2 pt-1">
                <input
                  type="checkbox"
                  id="type_allow_installment"
                  checked={typeForm.allow_installment}
                  onChange={(e) => setTypeForm({ ...typeForm, allow_installment: e.target.checked })}
                  className="rounded border-input text-primary size-4"
                />
                <Label htmlFor="type_allow_installment" className="text-xs font-semibold cursor-pointer">
                  Izinkan Pembayaran Sebagian / Cicilan
                </Label>
              </div>

              <div className="flex items-center gap-2 pt-1">
                <input
                  type="checkbox"
                  id="type_requires_selection"
                  checked={typeForm.requires_selection}
                  onChange={(e) => setTypeForm({ ...typeForm, requires_selection: e.target.checked })}
                  className="rounded border-input text-primary size-4"
                />
                <Label htmlFor="type_requires_selection" className="text-xs font-semibold cursor-pointer">
                  Butuh Pemilihan Item/Ukuran (seperti Seragam)
                </Label>
              </div>
              {typeForm.requires_selection && (
                <p className="rounded-lg bg-muted/40 p-2.5 text-[11px] text-muted-foreground">
                  Tagihan untuk jenis biaya ini tidak akan terbit sampai orang tua memilih ukuran/item lewat
                  portal wali. Tambahkan komponennya saat membuat tarif.
                </p>
              )}

              <div className="flex items-center gap-2 pt-1">
                <input
                  type="checkbox"
                  id="type_requires_roster_membership"
                  checked={typeForm.requires_roster_membership}
                  onChange={(e) => setTypeForm({ ...typeForm, requires_roster_membership: e.target.checked })}
                  className="rounded border-input text-primary size-4"
                />
                <Label htmlFor="type_requires_roster_membership" className="text-xs font-semibold cursor-pointer">
                  Butuh Terdaftar di Ekstrakurikuler
                </Label>
              </div>
              {typeForm.requires_roster_membership && (
                <p className="rounded-lg bg-muted/40 p-2.5 text-[11px] text-muted-foreground">
                  Tagihan untuk jenis biaya ini hanya terbit untuk siswa yang terdaftar aktif di minimal satu
                  ekstrakurikuler. Kelola rosternya di menu Ekstrakurikuler.
                </p>
              )}

              <div className="flex justify-end gap-2.5 pt-3 border-t border-border">
                <Button type="button" variant="ghost" onClick={() => setShowTypeModal(false)}>
                  Batal
                </Button>
                <Button type="submit" disabled={submitting} className="font-bold shadow-xs">
                  {submitting ? "Menyimpan..." : "Simpan Kategori"}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL: IMPORT TARIF */}
      {showImportModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
          <Card className="w-full max-w-lg p-6 border-border shadow-2xl space-y-4 my-8">
            <div className="flex items-center justify-between border-b border-border pb-3">
              <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
                <UploadCloud className="size-5 text-primary" />
                <span>Import Tarif SPP & Biaya (CSV)</span>
              </h2>
              <button onClick={() => setShowImportModal(false)} className="text-muted-foreground hover:text-foreground">
                <X className="size-5" />
              </button>
            </div>

            <div className="bg-primary/5 border border-primary/20 rounded-xl p-3.5 text-xs text-muted-foreground space-y-2">
              <p className="font-semibold text-foreground">Format Spreadsheet yang Didukung:</p>
              <p>
                File .CSV dengan kolom: <code>fee_type_code</code> (contoh: <code>spp</code>, <code>uang_gedung</code>), <code>unit_code</code> (contoh: <code>sd</code>, <code>smp</code>), <code>tingkat</code> (1-12 atau kosong untuk semua tingkat), <code>academic_year</code> (contoh: <code>2027/2028</code>), <code>amount</code> (nominal angka), <code>due_day</code> (tgl jatuh tempo).
              </p>
              <a
                href="/api/admin/import/fee-rates/template"
                download
                className="inline-flex items-center gap-1.5 font-bold text-primary hover:underline pt-1"
              >
                <Download className="size-3.5" />
                <span>Unduh Format Template CSV Tarif SPP</span>
              </a>
            </div>

            <form onSubmit={handleImportTariff} className="space-y-4 text-xs">
              <div>
                <Label className="text-xs font-semibold">Pilih File CSV Tarif:</Label>
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
                  {importing ? "Mengimpor Tarif…" : "Mulai Import Tarif"}
                </Button>
              </div>
            </form>
          </Card>
        </div>
      )}
    </div>
  );
}

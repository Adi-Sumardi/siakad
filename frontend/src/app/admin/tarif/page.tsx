"use client";

import { useCallback, useEffect, useState } from "react";
import { toast } from "sonner";
import { Building2, CheckCircle2, Filter, Layers, Plus, Search, Trash2 } from "lucide-react";
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
  is_active: boolean;
  rate_count: number;
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
};

type Option = { ulid: string; code?: string; label?: string; year?: string; is_active?: boolean };

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

  // Modals
  const [showRateModal, setShowRateModal] = useState(false);
  const [showTypeModal, setShowTypeModal] = useState(false);
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
  });

  const loadData = useCallback(async () => {
    try {
      const [ftRes, rRes, uRes, yRes] = await Promise.all([
        api.get<{ fee_types: FeeType[] }>("/api/admin/fee-types"),
        api.get<{ rates: Rate[] }>("/api/admin/fee-rates"),
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
  }, [rateForm.fee_type_ulid]);

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
        amount: parseFloat(rateForm.amount),
        due_day: rateForm.due_day ? parseInt(rateForm.due_day) : null,
        late_fee_amount: parseFloat(rateForm.late_fee_amount || "0"),
        notes: rateForm.notes || null,
      });

      toast.success("Tarif biaya berhasil disimpan.");
      setShowRateModal(false);
      setRateForm((f) => ({ ...f, amount: "", tingkat: "", notes: "" }));
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
      });

      toast.success("Jenis biaya baru berhasil ditambahkan.");
      setShowTypeModal(false);
      setTypeForm({ code: "", name: "", recurrence: "monthly", allow_installment: false });
      loadData();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menambahkan jenis biaya.");
    } finally {
      setSubmitting(false);
    }
  }

  const filteredRates = rates?.filter((r) => {
    if (filterUnit && r.unit.code !== filterUnit) return false;
    if (filterType && r.fee_type.code !== filterType) return false;
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
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Kelola Tarif SPP & Biaya Sekolah</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Atur katalog jenis biaya (SPP, Gedung, Seragam) serta nominal tarif per unit sekolah, tingkat kelas, dan tahun ajaran.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          {activeTab === "rates" ? (
            <Button onClick={() => setShowRateModal(true)} className="gap-2 shadow-xs">
              <Plus className="size-4" />
              <span>Tambah Tarif Baru</span>
            </Button>
          ) : (
            <Button onClick={() => setShowTypeModal(true)} className="gap-2 shadow-xs">
              <Plus className="size-4" />
              <span>Tambah Jenis Biaya</span>
            </Button>
          )}
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Card className="p-5 border-border/80">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Total Tarif Aktif</span>
            <Building2 className="size-5 text-primary" />
          </div>
          <p className="mt-2 text-2xl font-bold">{rates?.length ?? <Skeleton className="h-8 w-16" />}</p>
          <p className="mt-1 text-xs text-muted-foreground">Daftar tarif terpasang di sistem</p>
        </Card>

        <Card className="p-5 border-border/80">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Jenis Biaya</span>
            <Layers className="size-5 text-indigo-600" />
          </div>
          <p className="mt-2 text-2xl font-bold">{feeTypes?.length ?? <Skeleton className="h-8 w-16" />}</p>
          <p className="mt-1 text-xs text-muted-foreground">Kategori tagihan (SPP, Kegiatan, dll)</p>
        </Card>

        <Card className="p-5 border-border/80">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Unit Sekolah</span>
            <CheckCircle2 className="size-5 text-emerald-600" />
          </div>
          <p className="mt-2 text-2xl font-bold">{units.length}</p>
          <p className="mt-1 text-xs text-muted-foreground">TK, SD, SMP, SMA YAPI</p>
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
          <div className="flex flex-wrap items-center gap-3 bg-muted/40 p-3 rounded-xl border border-border">
            <div className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
              <Filter className="size-4" />
              <span>Filter:</span>
            </div>
            <select
              value={filterUnit}
              onChange={(e) => setFilterUnit(e.target.value)}
              className="rounded-lg border border-input bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-2xs"
            >
              <option value="">Semua Unit Sekolah</option>
              {units.map((u) => (
                <option key={u.ulid} value={u.code}>{u.label}</option>
              ))}
            </select>
            <select
              value={filterType}
              onChange={(e) => setFilterType(e.target.value)}
              className="rounded-lg border border-input bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-2xs"
            >
              <option value="">Semua Jenis Biaya</option>
              {feeTypes?.map((t) => (
                <option key={t.ulid} value={t.code}>{t.name}</option>
              ))}
            </select>
          </div>

          <Card className="overflow-hidden border-border/80">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-border bg-muted/40 text-xs font-bold uppercase tracking-wider text-muted-foreground">
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
                        <p className="font-bold text-foreground">{r.fee_type.name}</p>
                        <span className="text-xs font-mono text-muted-foreground">{r.fee_type.code}</span>
                      </td>
                      <td className="px-5 py-4 font-medium text-foreground">{r.unit.label}</td>
                      <td className="px-5 py-4">
                        <Badge variant="default">{r.tingkat ? `Kelas ${r.tingkat}` : "Semua Tingkat"}</Badge>
                      </td>
                      <td className="px-5 py-4 text-muted-foreground">{r.academic_year}</td>
                      <td className="px-5 py-4">
                        <span className="text-xs font-medium text-muted-foreground">
                          {r.due_day ? `Tgl ${r.due_day} tiap bulan` : "—"}
                        </span>
                      </td>
                      <td className="px-5 py-4 text-right">
                        <span className="font-bold text-primary text-base">{rupiah(r.amount)}</span>
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
        <Card className="overflow-hidden border-border/80">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border bg-muted/40 text-xs font-bold uppercase tracking-wider text-muted-foreground">
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
                    <td className="px-5 py-4 font-bold text-foreground">{t.name}</td>
                    <td className="px-5 py-4 text-muted-foreground">
                      {RECURRENCE_LABEL[t.recurrence] ?? t.recurrence}
                    </td>
                    <td className="px-5 py-4">
                      <Badge variant={t.allow_installment ? "good" : "default"}>
                        {t.allow_installment ? "Ya (Cicilan)" : "Penuh Sekaligus"}
                      </Badge>
                    </td>
                    <td className="px-5 py-4 font-semibold">{t.rate_count} tarif unit</td>
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

      {/* MODAL: TAMBAH TARIF BARU */}
      {showRateModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
          <div className="w-full max-w-lg rounded-2xl bg-card p-6 shadow-2xl border border-border">
            <h2 className="text-lg font-bold text-foreground">Tambah Tarif Biaya Baru</h2>
            <p className="text-xs text-muted-foreground mt-1">
              Tentukan nominal biaya untuk kombinasi jenis tagihan, unit, dan tingkat kelas.
            </p>

            <form onSubmit={handleCreateRate} className="mt-5 space-y-4">
              <div>
                <Label htmlFor="rate_fee_type" className="text-xs">Jenis Biaya</Label>
                <select
                  id="rate_fee_type"
                  value={rateForm.fee_type_ulid}
                  onChange={(e) => setRateForm({ ...rateForm, fee_type_ulid: e.target.value })}
                  required
                  className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary"
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
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary"
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
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary"
                  >
                    {years.map((y) => (
                      <option key={y.ulid} value={y.ulid}>{y.year}</option>
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

              <div className="flex justify-end gap-2.5 pt-3">
                <Button type="button" variant="outline" onClick={() => setShowRateModal(false)}>
                  Batal
                </Button>
                <Button type="submit" disabled={submitting}>
                  {submitting ? "Menyimpan..." : "Simpan Tarif"}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL: TAMBAH JENIS BIAYA */}
      {showTypeModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
          <div className="w-full max-w-md rounded-2xl bg-card p-6 shadow-2xl border border-border">
            <h2 className="text-lg font-bold text-foreground">Tambah Jenis Biaya Baru</h2>
            <p className="text-xs text-muted-foreground mt-1">
              Tambahkan kategori tagihan ke katalog sekolah.
            </p>

            <form onSubmit={handleCreateType} className="mt-5 space-y-4">
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
                  className="mt-1"
                />
              </div>

              <div>
                <Label htmlFor="type_recurrence" className="text-xs">Periode Penagihan</Label>
                <select
                  id="type_recurrence"
                  value={typeForm.recurrence}
                  onChange={(e) => setTypeForm({ ...typeForm, recurrence: e.target.value as "monthly" | "per_term" | "once" })}
                  className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus:outline-hidden focus:ring-2 focus:ring-primary"
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
                  className="rounded border-input text-primary"
                />
                <Label htmlFor="type_allow_installment" className="text-xs font-medium cursor-pointer">
                  Izinkan Pembayaran Sebagian / Cicilan
                </Label>
              </div>

              <div className="flex justify-end gap-2.5 pt-3">
                <Button type="button" variant="outline" onClick={() => setShowTypeModal(false)}>
                  Batal
                </Button>
                <Button type="submit" disabled={submitting}>
                  {submitting ? "Menyimpan..." : "Simpan Kategori"}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

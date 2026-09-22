"use client";

import { Suspense, useCallback, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { CreditCard, Download, FilePlus2, Filter, Plus, RefreshCw, Search, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError, API_BASE } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";
import { dueLabel, rupiah, tanggal } from "@/lib/format";
import { isOpen, OPEN_STATUSES, type Bill, type Payment } from "@/lib/types/billing";
import { Pagination } from "@/components/ui/pagination";

type Paginated<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; total: number; per_page?: number };
};

type Option = { ulid: string; code: string; label: string };

type FeeTypeOption = {
  ulid: string;
  code: string;
  name: string;
  is_active: boolean;
  has_va_prefix: boolean;
};

type StudentHit = { ulid: string; nama_lengkap: string; nis: string | null; unit: { label: string } | null };

/** Module-scope so the component render stays pure - fresh defaults per open. */
function emptyManualForm() {
  return {
    student_search: "",
    student_ulid: "",
    fee_type_ulid: "",
    description: "",
    amount: "",
    due_date: new Date(Date.now() + 14 * 86_400_000).toISOString().split("T")[0],
  };
}

function statusBadge(bill: Bill) {
  if (bill.status === "paid") return <Badge variant="good">Lunas</Badge>;
  if (bill.status === "waived") return <Badge>Dibebaskan</Badge>;
  if (bill.status === "cancelled") return <Badge>Dibatalkan</Badge>;
  if (bill.status === "overdue") return <Badge variant="bad">{dueLabel(bill.days_to_due)}</Badge>;
  if (bill.status === "partial") return <Badge variant="warn">Kurang bayar</Badge>;
  return <Badge>{dueLabel(bill.days_to_due)}</Badge>;
}

type Action = { bill: Bill; kind: "bebaskan" | "batalkan" };

function AdminBillsContent() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";
  const searchParams = useSearchParams();

  const [bills, setBills] = useState<Paginated<Bill> | null>(null);
  const [status, setStatus] = useState("open");
  const [feeTypeCode, setFeeTypeCode] = useState("");
  const [month, setMonth] = useState("");
  const [q, setQ] = useState("");
  const [unitCode, setUnitCode] = useState("");
  const [page, setPage] = useState(1);
  // The dashboard's money alerts land here with ?year= of the period their
  // count came from (T23) - the dropdown starts from it so numbers line up.
  const [academicYear, setAcademicYear] = useState(searchParams.get("year") ?? "");
  const [units, setUnits] = useState<Option[]>([]);
  const [years, setYears] = useState<{ ulid: string; year: string; is_active: boolean }[]>([]);
  const [action, setAction] = useState<Action | null>(null);

  // Form states
  const [submitting, setSubmitting] = useState(false);
  const [reason, setReason] = useState("");

  // Manual bill modal (one-off bills for unexpected cases)
  const [showManualModal, setShowManualModal] = useState(false);
  const [feeTypes, setFeeTypes] = useState<FeeTypeOption[]>([]);
  const [manual, setManual] = useState(emptyManualForm);
  const [studentHits, setStudentHits] = useState<StudentHit[]>([]);
  const [searchingStudents, setSearchingStudents] = useState(false);
  const [creatingManual, setCreatingManual] = useState(false);

  // Cambridge model (school decision 2026-09-22): a unit admin's manual bills
  // are cambridge-shaped only - nominal prefilled from their unit's rate,
  // buku folded in as itemised lines, one bill one VA.
  const [manualLines, setManualLines] = useState<{ name: string; qty: string; unit_price: string }[]>([]);
  const [existingCambridge, setExistingCambridge] = useState(false);

  // "Buat VA" - an admin mints the Virtual Account for one bill on the
  // family's behalf; the number is pushed to the wali's WhatsApp.
  const [vaBill, setVaBill] = useState<Bill | null>(null);
  const [vaBank, setVaBank] = useState<"muamalat" | "bsi">("muamalat");
  const [creatingVa, setCreatingVa] = useState(false);
  const [vaResult, setVaResult] = useState<{ payment: Payment; whatsapp: { sent: boolean; reason: string | null } } | null>(null);

  // Debounced student search for the manual-bill picker - same endpoint and
  // debounce shape as the diskon page's assignment picker.
  useEffect(() => {
    if (!showManualModal || manual.student_search.length < 2) {
      const clear = setTimeout(() => setStudentHits([]), 0);
      return () => clearTimeout(clear);
    }
    const timer = setTimeout(async () => {
      setSearchingStudents(true);
      try {
        const res = await api.get<{ students: { data: StudentHit[] } }>(
          `/api/admin/students?search=${encodeURIComponent(manual.student_search)}&per_page=8`,
        );
        setStudentHits(res.students.data ?? []);
      } catch {
        setStudentHits([]);
      } finally {
        setSearchingStudents(false);
      }
    }, 300);
    return () => clearTimeout(timer);
  }, [showManualModal, manual.student_search]);

  const selectedFeeType = feeTypes.find((t) => t.ulid === manual.fee_type_ulid) ?? null;
  const cambridgeType = feeTypes.find((t) => t.code === "cambridge") ?? null;
  const manualIsCambridge = selectedFeeType?.code === "cambridge";
  const linesTotal = manualLines.reduce(
    (s, l) => s + (parseInt(l.qty || "0", 10) || 0) * (parseFloat(l.unit_price || "0") || 0),
    0,
  );

  // A unit admin's manual bills are cambridge-shaped only (the API refuses
  // anything else) - the form opens pre-locked to it, nominal prefilled from
  // their own unit's cambridge rate for the active year. Server-side the
  // rates list is already unit-scoped for admin_unit.
  useEffect(() => {
    if (!showManualModal || isCentral || !manualIsCambridge || manual.amount || manualLines.length > 0) return;

    let cancelled = false;
    api
      .get<{ rates: { academic_year: string; tingkat: number | null; amount: number }[] }>(
        "/api/admin/fee-rates?type=cambridge",
      )
      .then((d) => {
        if (cancelled) return;
        const activeYear = years.find((y) => y.is_active)?.year;
        const rate =
          d.rates.find((r) => r.academic_year === activeYear && r.tingkat === null)
          ?? d.rates.find((r) => r.academic_year === activeYear)
          ?? d.rates[0];
        if (rate && !manual.amount) {
          setManual((m) => ({ ...m, amount: String(rate.amount) }));
        }
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [showManualModal, manualIsCambridge, isCentral, years]);

  // Soft duplicate guard (manual → run direction): both lanes issue
  // cambridge, so warn before a second bill lands on the same student. Soft
  // on purpose - a follow-up bill (extra buku, a second program year-half)
  // is legitimate. Early return instead of a sync reset - the chip render is
  // gated on manualIsCambridge so a stale value can never show outside the
  // cambridge context, and state is only ever written from async callbacks.
  useEffect(() => {
    if (!manual.student_ulid || !manualIsCambridge) return;

    let cancelled = false;
    const activeYear = years.find((y) => y.is_active)?.year;
    const params = new URLSearchParams({ student: manual.student_ulid, type: "cambridge", per_page: "1" });
    if (activeYear) params.set("year", activeYear);

    api
      .get<{ bills: { data: unknown[] } }>(`/api/admin/bills?${params.toString()}`)
      .then((d) => {
        if (!cancelled) setExistingCambridge((d.bills.data ?? []).length > 0);
      })
      .catch(() => {
        if (!cancelled) setExistingCambridge(false);
      });
    return () => {
      cancelled = true;
    };
  }, [manual.student_ulid, manualIsCambridge, years]);

  async function submitManualBill(e: React.FormEvent) {
    e.preventDefault();
    if (!manual.student_ulid) {
      toast.error("Pilih siswa terlebih dahulu.");
      return;
    }
    setCreatingManual(true);
    try {
      // With itemised rows the total IS the rows' sum (the input is locked to
      // it); without them the typed amount stands.
      const lines = manualLines
        .filter((l) => l.name.trim() && parseInt(l.qty, 10) > 0 && parseFloat(l.unit_price) > 0)
        .map((l) => ({ name: l.name.trim(), qty: parseInt(l.qty, 10), unit_price: parseFloat(l.unit_price) }));

      const res = await api.post<{ bill: Bill }>("/api/admin/bills/manual", {
        student_ulid: manual.student_ulid,
        fee_type_ulid: manual.fee_type_ulid,
        description: manual.description,
        amount: lines.length > 0 ? linesTotal : parseFloat(manual.amount),
        due_date: manual.due_date,
        ...(lines.length > 0 ? { lines } : {}),
      });
      toast.success(`Tagihan manual ${res.bill.bill_number} diterbitkan.`);
      setShowManualModal(false);
      setManual(emptyManualForm());
      setManualLines([]);
      setExistingCambridge(false);
      setPage(1);
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menerbitkan tagihan manual.");
    } finally {
      setCreatingManual(false);
    }
  }

  // .then() chains (not async/await) so setState only ever runs in an async
  // callback - the effect below calls this synchronously, and awaiting first
  // still trips react-hooks/set-state-in-effect's analysis.
  const load = useCallback(() => {
    const params = new URLSearchParams();
    if (status) params.set("status", status);
    if (q) params.set("q", q);
    if (unitCode) params.set("unit", unitCode);
    if (academicYear) params.set("year", academicYear);
    if (feeTypeCode) params.set("type", feeTypeCode);
    if (month) params.set("month", month);
    params.set("page", String(page));
    params.set("per_page", "20");

    api
      .get<{ bills: Paginated<Bill> }>(`/api/admin/bills?${params}`)
      .then((d) => setBills(d.bills))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat tagihan."));
  }, [status, q, unitCode, academicYear, feeTypeCode, month, page]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    api
      .get<{ school_units: Option[] }>("/api/admin/school-units")
      .then((d) => setUnits(d.school_units))
      .catch(() => {});

    api
      .get<{ academic_years: { ulid: string; year: string; is_active: boolean }[] }>("/api/admin/academic-years")
      .then((d) => setYears(d.academic_years))
      .catch(() => {});

    // The jenis filter needs the catalogue even before the manual-bill modal
    // is ever opened (that modal has its own lazy load with the same guard).
    api
      .get<{ fee_types: FeeTypeOption[] }>("/api/admin/fee-types")
      .then((d) => setFeeTypes(d.fee_types.filter((t) => t.is_active)))
      .catch(() => {});
  }, []);

  // Download endpoints live on the API origin, so a plain <a href="/api/...">
  // would hit Next.js itself and 404 in development. Fetch with the Sanctum
  // session cookie and hand the browser a blob instead - same pattern as the
  // bill PDF downloads.
  async function downloadApiFile(path: string, filename: string) {
    try {
      const res = await fetch(`${API_BASE}${path}`, { credentials: "include" });

      if (!res.ok) {
        throw new Error("Gagal mengunduh file. Pastikan sesi Anda masih aktif.");
      }

      const blob = await res.blob();
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Gagal mengunduh file.");
    }
  }

  function openAction(bill: Bill, kind: Action["kind"]) {
    setAction({ bill, kind });
    setReason("");
  }

  async function handleActionSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!action) return;

    if (!reason.trim()) {
      toast.error("Alasan wajib diisi.");
      return;
    }

    setSubmitting(true);
    try {
      if (action.kind === "bebaskan") {
        await api.post(`/api/admin/bills/${action.bill.ulid}/waive`, { reason });
        toast.success("Tagihan berhasil dibebaskan.");
      } else {
        await api.post(`/api/admin/bills/${action.bill.ulid}/cancel`, { reason });
        toast.success("Tagihan berhasil dibatalkan.");
      }

      setAction(null);
      // A closed bill leaves any view that excludes its next status. If it
      // was the last row of a page, step back instead of refetching a
      // now-empty page; rows that stay in view plain reload.
      const nextStatus = action.kind === "bebaskan" ? "waived" : "cancelled";
      const staysInView =
        status === "" || status === nextStatus || (status === "open" && OPEN_STATUSES.includes(nextStatus));
      if (bills && bills.data.length === 1 && page > 1 && !staysInView) {
        setPage(page - 1);
      } else {
        load();
      }
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memproses aksi.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Tagihan & Transaksi Siswa</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            {isCentral
              ? "Daftar tagihan SPP dan administrasi sekolah di seluruh unit."
              : "Daftar tagihan SPP dan administrasi sekolah di unit Anda."}
          </p>
        </div>

        <div className="flex items-center gap-2 self-start sm:self-auto">
          <Button
            variant="outline"
            size="sm"
            className="gap-2"
            onClick={async () => {
              setManualLines([]);
              setExistingCambridge(false);
              let current = feeTypes;
              if (feeTypes.length === 0) {
                try {
                  const d = await api.get<{ fee_types: FeeTypeOption[] }>("/api/admin/fee-types");
                  current = d.fee_types.filter((t) => t.is_active);
                  setFeeTypes(current);
                } catch {
                  toast.error("Gagal memuat daftar jenis biaya.");
                }
              }
              // The one type a unit admin may issue by hand - pre-locked so a
              // submit can never name something the API would refuse.
              if (!isCentral) {
                const cambridge = current.find((t) => t.code === "cambridge");
                setManual((m) => ({ ...m, fee_type_ulid: m.fee_type_ulid || (cambridge?.ulid ?? "") }));
              }
              setShowManualModal(true);
            }}
          >
            <FilePlus2 className="size-4" />
            <span>Buat Tagihan Manual</span>
          </Button>
          <Button variant="outline" size="sm" onClick={load} className="gap-2">
            <RefreshCw className="size-4" />
            <span>Segarkan Data</span>
          </Button>
        </div>
      </div>

      {/* Filter Toolbar */}
      <div className="flex flex-wrap items-center gap-3 bg-muted/40 p-3.5 rounded-2xl border border-border">
        <div className="relative min-w-[240px] flex-1">
          <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={q}
            onChange={(e) => {
              setQ(e.target.value);
              setPage(1);
            }}
            placeholder="Cari nama siswa atau no tagihan…"
            className="pl-9 bg-card text-xs shadow-2xs"
          />
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <div className="flex items-center gap-1 text-xs text-muted-foreground">
            <Filter className="size-3.5" />
            <span>Filter:</span>
          </div>

          <select
            value={academicYear}
            onChange={(e) => {
              setAcademicYear(e.target.value);
              setPage(1);
            }}
            className="rounded-lg border border-input bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-2xs"
          >
            <option value="">Semua Tahun Ajaran</option>
            {years.map((y) => (
              <option key={y.ulid} value={y.year}>
                Tahun {y.year} {y.is_active ? "(Aktif)" : ""}
              </option>
            ))}
          </select>

          {/* Unit filter is central-admin only: a per-unit admin's scope
              already narrows the list to their own unit, so the dropdown
              would list campuses they can never see. */}
          {isCentral && (
            <select
              value={unitCode}
              onChange={(e) => {
                setUnitCode(e.target.value);
                setPage(1);
              }}
              className="rounded-lg border border-input bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-2xs"
            >
              <option value="">Semua Unit Sekolah</option>
              {units.map((u) => (
                <option key={u.ulid} value={u.code}>{u.label}</option>
              ))}
            </select>
          )}

          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            className="rounded-lg border border-input bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-2xs"
          >
            <option value="open">Belum Lunas</option>
            <option value="overdue">Jatuh Tempo (Menunggak)</option>
            <option value="partial">Kurang Bayar (Cicilan)</option>
            <option value="paid">Lunas</option>
            <option value="waived">Dibebaskan</option>
            <option value="cancelled">Dibatalkan</option>
            <option value="">Semua Status</option>
          </select>

          <select
            value={feeTypeCode}
            onChange={(e) => {
              setFeeTypeCode(e.target.value);
              setPage(1);
            }}
            className="rounded-lg border border-input bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-2xs"
          >
            <option value="">Semua Jenis Biaya</option>
            {feeTypes.map((t) => (
              <option key={t.ulid} value={t.code}>{t.name}</option>
            ))}
          </select>

          <select
            value={month}
            onChange={(e) => {
              setMonth(e.target.value);
              setPage(1);
            }}
            className="rounded-lg border border-input bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-2xs"
          >
            <option value="">Semua Bulan</option>
            {["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"].map(
              (nama, i) => (
                <option key={i + 1} value={i + 1}>{nama}</option>
              ),
            )}
          </select>
        </div>
      </div>

      {/* Bill List */}
      {bills === null && (
        <div className="space-y-3">
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-20 w-full" />
        </div>
      )}

      <div className="grid grid-cols-1 gap-3">
        {bills?.data.map((bill) => (
          <Card key={bill.ulid} className="p-4 sm:p-5 border-border/80 hover:border-primary/40 transition-colors">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <p className="font-bold text-foreground text-base">{bill.description}</p>
                  <Badge variant="default" className="font-mono text-[11px]">{bill.bill_number}</Badge>
                  {statusBadge(bill)}
                </div>

                <p className="text-sm font-medium text-muted-foreground mt-1">
                  Siswa: <strong className="text-foreground">{bill.student?.nama_lengkap}</strong> · Jatuh tempo {tanggal(bill.due_date)}
                </p>

                {bill.discount_amount > 0 && (
                  <p className="text-xs text-emerald-600 font-semibold mt-1">
                    Diskon/Beasiswa: {rupiah(bill.discount_amount)}
                  </p>
                )}
              </div>

              <div className="flex sm:flex-col sm:items-end justify-between items-center gap-1 border-t sm:border-t-0 pt-2 sm:pt-0 border-border/60">
                <span className="text-xs text-muted-foreground">Sisa Tagihan</span>
                <p className="tabular font-bold text-foreground text-lg sm:text-right">{rupiah(bill.remaining_amount)}</p>
                {bill.paid_amount > 0 && (
                  <p className="tabular text-xs text-muted-foreground sm:text-right">
                    Terbayar: {rupiah(bill.paid_amount)} dari {rupiah(bill.total_amount)}
                  </p>
                )}
              </div>
            </div>

            {isOpen(bill) && (
              <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-border/60 pt-3">
                <div className="flex items-center gap-2">
                  {(isCentral || bill.fee_type?.code === "cambridge") && (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => {
                        setVaBill(bill);
                        setVaBank("muamalat");
                        setVaResult(null);
                      }}
                      className="h-7 gap-1.5 border-primary/40 text-xs font-semibold text-primary hover:bg-primary/10"
                    >
                      <CreditCard className="size-3.5" />
                      <span>Buat VA</span>
                    </Button>
                  )}
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => openAction(bill, "bebaskan")}
                    className="text-xs text-muted-foreground hover:text-foreground"
                  >
                    Bebaskan
                  </Button>
                  {bill.paid_amount === 0 && (
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => openAction(bill, "batalkan")}
                      className="text-xs text-destructive hover:bg-destructive/10"
                    >
                      Batalkan
                    </Button>
                  )}
                </div>

                <button
                  type="button"
                  onClick={() =>
                    downloadApiFile(
                      `/api/admin/bills/${bill.ulid}/pdf`,
                      `Tagihan-${bill.bill_number.replace(/\//g, "-")}.pdf`,
                    )
                  }
                  className="inline-flex items-center gap-1 text-xs text-primary font-medium hover:underline"
                >
                  <Download className="size-3.5" />
                  <span>Invoice PDF</span>
                </button>
              </div>
            )}
          </Card>
        ))}

        {bills?.data.length === 0 && (
          <Card className="p-8 text-center text-sm text-muted-foreground">
            Tidak ada tagihan yang sesuai kriteria pencarian.
          </Card>
        )}
      </div>

      {bills && bills.data.length > 0 && <Pagination meta={bills.meta} onPage={setPage} label="tagihan" />}

      {/* MODAL: BUAT TAGIHAN MANUAL */}
      {showManualModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
          <Card className="w-full max-w-lg p-6 border-border shadow-2xl space-y-4 my-8">
            <div>
              <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
                <FilePlus2 className="size-5 text-primary" />
                <span>Buat Tagihan Manual</span>
              </h2>
              <p className="mt-1 text-xs text-muted-foreground">
                Untuk kasus di luar penerbitan rutin (seragam pengganti, bulan tertinggal siswa baru, denda khusus).
                Tagihan masuk ke portal wali dan mengikuti alur bayar yang sama seperti tagihan rutin.
              </p>
            </div>

            <form onSubmit={submitManualBill} className="space-y-3.5 text-xs">
              <div>
                <Label className="text-xs">Siswa</Label>
                <Input
                  value={manual.student_search}
                  onChange={(e) =>
                    setManual({ ...manual, student_search: e.target.value, student_ulid: "" })
                  }
                  placeholder="Ketik minimal 2 huruf nama siswa / NIS..."
                  required
                  className="mt-1"
                  autoFocus
                />
                {searchingStudents && <p className="mt-1 text-[11px] text-muted-foreground">Mencari...</p>}
                {studentHits.length > 0 && !manual.student_ulid && (
                  <div className="mt-1 max-h-36 overflow-y-auto rounded-md border border-border bg-card shadow-lg divide-y divide-border">
                    {studentHits.map((s) => (
                      <button
                        type="button"
                        key={s.ulid}
                        onClick={() => {
                          setManual({
                            ...manual,
                            student_ulid: s.ulid,
                            student_search: `${s.nama_lengkap} (${s.unit?.label ?? "-"})`,
                          });
                          setStudentHits([]);
                        }}
                        className="flex w-full items-center justify-between px-3 py-2 text-left text-xs hover:bg-muted/40"
                      >
                        <span className="font-semibold">{s.nama_lengkap}</span>
                        <span className="text-muted-foreground">{s.unit?.label ?? "-"}</span>
                      </button>
                    ))}
                  </div>
                )}
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <Label className="text-xs">Jenis Biaya</Label>
                  <select
                    value={manual.fee_type_ulid}
                    onChange={(e) => setManual({ ...manual, fee_type_ulid: e.target.value })}
                    disabled={!isCentral}
                    required
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-2xs disabled:opacity-70"
                  >
                    <option value="">{isCentral ? "Pilih jenis..." : cambridgeType ? "" : "Memuat..."}</option>
                    {!isCentral
                      ? cambridgeType && <option value={cambridgeType.ulid}>{cambridgeType.name}</option>
                      : feeTypes.map((t) => (
                          <option key={t.ulid} value={t.ulid}>
                            {t.name}
                          </option>
                        ))}
                  </select>
                  {!isCentral && (
                    <p className="mt-1 text-[11px] text-muted-foreground">
                      Admin unit hanya bisa menerbitkan tagihan manual jenis Cambridge (biaya buku digabung di sini).
                    </p>
                  )}
                </div>
                <div>
                  <Label className="text-xs">Jatuh Tempo</Label>
                  <Input
                    type="date"
                    value={manual.due_date}
                    onChange={(e) => setManual({ ...manual, due_date: e.target.value })}
                    required
                    className="mt-1"
                  />
                </div>
              </div>

              {selectedFeeType && !selectedFeeType.has_va_prefix && (
                <p className="rounded-lg border border-warn/30 bg-warn-soft px-3 py-2 text-[11px] font-medium text-warn">
                  Jenis “{selectedFeeType.name}” belum punya nomor Virtual Account di bank — pembayaran hanya lewat
                  Virtual Account, jadi tagihan jenis ini belum bisa dibayar lewat sistem sampai prefix VA-nya
                  terdaftar di e-SPP.
                </p>
              )}

              {manualIsCambridge && existingCambridge && (
                <p className="rounded-lg border border-warn/30 bg-warn-soft px-3 py-2 text-[11px] font-medium text-warn">
                  Siswa ini sudah punya tagihan Cambridge tahun ajaran aktif. Tetap terbitkan tagihan baru?
                  (Sah bila memang ada penambahan program/buku.)
                </p>
              )}

              {manualIsCambridge && (
                <div className="border-t border-border pt-3">
                  <div className="flex items-center justify-between">
                    <Label className="text-xs font-bold">Rincian (opsional — mis. Cambridge + Buku)</Label>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      className="h-7 text-[11px]"
                      onClick={() => setManualLines((ls) => [...ls, { name: "", qty: "1", unit_price: "" }])}
                    >
                      <Plus className="size-3" /> Baris
                    </Button>
                  </div>
                  {manualLines.length > 0 && (
                    <div className="mt-2 space-y-2">
                      {manualLines.map((l, i) => (
                        <div key={i} className="flex items-end gap-2">
                          <Input
                            placeholder="Nama (mis. Buku)"
                            value={l.name}
                            onChange={(e) => setManualLines((ls) => ls.map((x, xi) => (xi === i ? { ...x, name: e.target.value } : x)))}
                            className="h-8 flex-1 text-xs"
                          />
                          <Input
                            type="number"
                            min="1"
                            placeholder="Qty"
                            value={l.qty}
                            onChange={(e) => setManualLines((ls) => ls.map((x, xi) => (xi === i ? { ...x, qty: e.target.value } : x)))}
                            className="h-8 w-16 text-xs"
                          />
                          <Input
                            type="number"
                            min="0"
                            placeholder="Harga satuan"
                            value={l.unit_price}
                            onChange={(e) => setManualLines((ls) => ls.map((x, xi) => (xi === i ? { ...x, unit_price: e.target.value } : x)))}
                            className="h-8 w-32 text-xs"
                          />
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="h-8 px-2 text-destructive hover:bg-destructive/10"
                            onClick={() => setManualLines((ls) => ls.filter((_, xi) => xi !== i))}
                          >
                            <Trash2 className="size-3.5" />
                          </Button>
                        </div>
                      ))}
                      <p className="text-[11px] font-semibold text-muted-foreground">
                        Total rincian: <span className="text-foreground">{rupiah(linesTotal)}</span> — nominal
                        tagihan mengikuti total ini.
                      </p>
                    </div>
                  )}
                </div>
              )}

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <Label className="text-xs">Nominal (Rp)</Label>
                  <Input
                    type="number"
                    min={1000}
                    step={500}
                    value={manualLines.length > 0 ? (linesTotal || "") : manual.amount}
                    onChange={(e) => setManual({ ...manual, amount: e.target.value })}
                    disabled={manualLines.length > 0}
                    required
                    placeholder="mis. 450000"
                    className="mt-1 font-bold disabled:opacity-70"
                  />
                  {!isCentral && manualIsCambridge && manualLines.length === 0 && (
                    <p className="mt-1 text-[11px] text-muted-foreground">
                      Nominal terisi dari tarif Cambridge unit — sesuaikan bila perlu.
                    </p>
                  )}
                </div>
                <div>
                  <Label className="text-xs">Deskripsi</Label>
                  <Input
                    value={manual.description}
                    onChange={(e) => setManual({ ...manual, description: e.target.value })}
                    required
                    maxLength={200}
                    placeholder={
                      selectedFeeType ? `${selectedFeeType.name} - keterangan` : "mis. Seragam pengganti"
                    }
                    className="mt-1"
                  />
                </div>
              </div>

              <div className="flex justify-end gap-2 border-t border-border pt-4">
                <Button type="button" variant="ghost" onClick={() => setShowManualModal(false)} disabled={creatingManual}>
                  Batal
                </Button>
                <Button type="submit" disabled={creatingManual || !manual.student_ulid} className="font-bold shadow-xs">
                  {creatingManual ? "Menerbitkan…" : "Terbitkan Tagihan"}
                </Button>
              </div>
            </form>
          </Card>
        </div>
      )}

      {/* MODAL: BUAT VA (admin mints the Virtual Account for one bill) */}
      {vaBill && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
          <Card className="w-full max-w-md p-6 border-border shadow-2xl space-y-4 my-8">
            <div>
              <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
                <CreditCard className="size-5 text-primary" />
                <span>Buat Virtual Account</span>
              </h2>
              <p className="mt-1 text-xs text-muted-foreground">
                Nomor VA dibuat atas nama wali penagih siswa dan dikirim ke WhatsApp-nya. VA berlaku 3 hari; VA lama
                yang masih terbuka otomatis digantikan.
              </p>
            </div>

            <div className="p-3 bg-muted/40 rounded-xl text-xs space-y-1">
              <p><strong>Tagihan:</strong> {vaBill.description}</p>
              <p><strong>Siswa:</strong> {vaBill.student?.nama_lengkap}</p>
              <p><strong>Sisa Tagihan:</strong> {rupiah(vaBill.remaining_amount)}</p>
            </div>

            {vaResult ? (
              <div className="space-y-3 text-xs">
                <div className="rounded-xl border border-good/40 bg-good/5 p-4 space-y-2">
                  <p className="font-semibold text-good">Virtual Account berhasil dibuat</p>
                  <p className="text-muted-foreground">{vaResult.payment.virtual_account?.bank_name}</p>
                  <p className="font-mono text-lg font-bold tracking-wider text-foreground">
                    {vaResult.payment.virtual_account?.va_number}
                  </p>
                  <p className="text-muted-foreground">
                    Jumlah: <strong className="text-foreground">{rupiah(vaResult.payment.amount)}</strong> · Bayar
                    sebelum{" "}
                    {vaResult.payment.virtual_account?.due_date
                      ? tanggal(vaResult.payment.virtual_account.due_date)
                      : "-"}
                  </p>
                </div>
                <p className={`text-[11px] ${vaResult.whatsapp.sent ? "text-muted-foreground" : "text-warn"}`}>
                  {vaResult.whatsapp.sent
                    ? "Nomor VA sudah dikirim ke wali via WhatsApp."
                    : `Nomor VA belum terkirim ke wali: ${vaResult.whatsapp.reason ?? "alasan tidak diketahui"} — akan dicoba ulang otomatis; wali tetap bisa melihatnya di aplikasi.`}
                </p>
                <Button onClick={() => setVaBill(null)} className="w-full font-bold">
                  Selesai
                </Button>
              </div>
            ) : (
              <form
                onSubmit={async (e) => {
                  e.preventDefault();
                  setCreatingVa(true);
                  try {
                    const res = await api.post<{
                      payment: Payment;
                      whatsapp: { sent: boolean; reason: string | null };
                    }>(`/api/admin/bills/${vaBill.ulid}/va`, { bank: vaBank });
                    setVaResult(res);
                    load();
                  } catch (err) {
                    toast.error(err instanceof ApiError ? err.message : "Gagal membuat Virtual Account.");
                  } finally {
                    setCreatingVa(false);
                  }
                }}
                className="space-y-3.5 text-xs"
              >
                <div>
                  <Label className="text-xs">Bank</Label>
                  <select
                    value={vaBank}
                    onChange={(e) => setVaBank(e.target.value as "muamalat" | "bsi")}
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-2xs"
                  >
                    <option value="muamalat">Bank Muamalat</option>
                    <option value="bsi">Bank Syariah Indonesia (BSI)</option>
                  </select>
                </div>

                <div className="flex justify-end gap-2 border-t border-border pt-4">
                  <Button type="button" variant="ghost" onClick={() => setVaBill(null)} disabled={creatingVa}>
                    Batal
                  </Button>
                  <Button type="submit" disabled={creatingVa} className="font-bold shadow-xs">
                    {creatingVa ? "Membuat VA…" : "Buat Virtual Account"}
                  </Button>
                </div>
              </form>
            )}
          </Card>
        </div>
      )}

      {/* ACTION MODAL */}
      {action && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
          <div className="w-full max-w-md rounded-2xl bg-card p-6 shadow-2xl border border-border">
            <h2 className="text-lg font-bold text-foreground">
              {action.kind === "bebaskan" && "Bebaskan Tagihan (Waiver)"}
              {action.kind === "batalkan" && "Batalkan Tagihan"}
            </h2>

            <div className="mt-3 p-3 bg-muted/40 rounded-xl text-xs space-y-1">
              <p><strong>Tagihan:</strong> {action.bill.description}</p>
              <p><strong>Siswa:</strong> {action.bill.student?.nama_lengkap}</p>
              <p><strong>Sisa Tagihan:</strong> {rupiah(action.bill.remaining_amount)}</p>
            </div>

            <form onSubmit={handleActionSubmit} className="mt-4 space-y-4">
              {(action.kind === "bebaskan" || action.kind === "batalkan") && (
                <div>
                  <Label htmlFor="reason" className="text-xs">Alasan (Wajib diisi)</Label>
                  <Input
                    id="reason"
                    placeholder="Alasan pembatalan atau pembebasan tagihan..."
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    required
                    className="mt-1"
                  />
                </div>
              )}

              <div className="flex justify-end gap-2.5 pt-2">
                <Button type="button" variant="outline" onClick={() => setAction(null)}>
                  Batal
                </Button>
                <Button
                  type="submit"
                  disabled={submitting}
                  variant={action.kind === "batalkan" ? "destructive" : "default"}
                >
                  {submitting ? "Memproses..." : "Konfirmasi"}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

export default function AdminBillsPage() {
  return (
    <Suspense fallback={null}>
      <AdminBillsContent />
    </Suspense>
  );
}

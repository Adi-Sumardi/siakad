"use client";

import { useEffect, useState } from "react";
import { Printer, RefreshCw } from "lucide-react";
import {
  AgingBars,
  AlertList,
  CompareBars,
  DataNote,
  EnrollmentTable,
  MonthBars,
  Section,
  SectionLabel,
  StatTile,
  persen,
  rupiahRingkas,
  type CompareRow,
} from "@/components/eksekutif/parts";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";
import { tanggal } from "@/lib/format";
import type { EducationRow, ExecSummary } from "@/lib/types/eksekutif";
import { cn } from "@/lib/utils";

/**
 * Dashboard yayasan. One page, read top to bottom:
 * perlu perhatian → angka utama → perbandingan antar unit → tren.
 * The unit buttons narrow everything to one unit; "Cetak" prints the page
 * as it stands for a board meeting.
 */
export default function EksekutifPage() {
  const { user } = useAuth();
  const isAdmin = user?.role === "admin";
  const [unit, setUnit] = useState<string | null>(null);
  // Central admin only: which yayasan view to preview.
  const [previewAs, setPreviewAs] = useState<"bph" | "direktur_pendidikan">("bph");
  const [data, setData] = useState<ExecSummary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams();
    if (unit) params.set("unit", unit);
    if (isAdmin) params.set("as", previewAs);
    const query = params.size ? `?${params.toString()}` : "";
    api
      .get<ExecSummary>(`/api/eksekutif/ringkasan${query}`)
      .then((res) => {
        if (cancelled) return;
        setData(res);
        setError(null);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Data belum bisa dimuat. Coba lagi sebentar.");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [unit, reloadKey, isAdmin, previewAs]);

  function choose(code: string | null) {
    setLoading(true);
    setUnit(code);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  if (!data) {
    return error ? (
      <ErrorBox message={error} onRetry={() => { setLoading(true); setReloadKey((k) => k + 1); }} />
    ) : (
      <div className="space-y-4">
        <Skeleton className="h-10 w-72" />
        <Skeleton className="h-28 w-full" />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-32" />)}
        </div>
      </div>
    );
  }

  const isBph = data.viewer.role === "bph";
  const scopeLabel = data.selected_unit?.label ?? "Semua unit (7 sekolah)";

  return (
    <div className={cn("space-y-8 transition-opacity", loading && "opacity-60")}>
      {/* Judul */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <p className="hidden text-sm font-semibold print:block">YAPI Al-Azhar Rawamangun · {data.viewer.role_label}</p>
          <h1 className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
            {isBph ? "Ringkasan Keuangan & Keberlanjutan" : "Ringkasan Pendidikan"}
          </h1>
          <p className="mt-1 text-base text-muted-foreground">
            <span className="font-semibold text-foreground">{scopeLabel}</span>
            {data.period.term_label && <> · Semester {data.period.term_label}</>}
          </p>
          <p className="mt-0.5 text-sm text-muted-foreground">Data per {waktu(data.period.generated_at)} · diperbarui setiap 2 menit</p>
        </div>
        <Button variant="outline" size="lg" className="no-print shrink-0 text-base" onClick={() => window.print()}>
          <Printer className="size-5" aria-hidden /> Cetak
        </Button>
      </div>

      {isAdmin && (
        <div className="no-print rounded-2xl border border-primary/30 bg-accent p-4">
          <p className="mb-2 text-sm font-semibold text-accent-foreground">Pratinjau Admin Pusat. Lihat sebagai:</p>
          <div className="flex flex-wrap gap-2">
            <UnitButton active={previewAs === "bph"} onClick={() => { setLoading(true); setPreviewAs("bph"); }}>Badan Pengurus Harian</UnitButton>
            <UnitButton active={previewAs === "direktur_pendidikan"} onClick={() => { setLoading(true); setPreviewAs("direktur_pendidikan"); }}>Direktur Pendidikan</UnitButton>
          </div>
        </div>
      )}

      {/* Pilih unit */}
      <div className="no-print">
        <p className="mb-2 text-sm font-semibold text-muted-foreground">Tampilkan:</p>
        <div className="flex flex-wrap gap-2">
          <UnitButton active={unit === null} onClick={() => choose(null)}>Semua unit</UnitButton>
          {data.units.map((u) => (
            <UnitButton key={u.code} active={unit === u.code} onClick={() => choose(u.code)} title={u.label}>
              {u.short}
            </UnitButton>
          ))}
        </div>
      </div>

      {error && <ErrorBox message={error} onRetry={() => { setLoading(true); setReloadKey((k) => k + 1); }} />}

      <div>
        <SectionLabel>Perlu perhatian</SectionLabel>
        <AlertList alerts={data.alerts} more={data.alerts_more} onOpenUnit={unit ? undefined : (code) => choose(code)} />
      </div>

      {isBph ? <BphView data={data} /> : <DirekturView data={data} />}

      <p className="border-t border-border pt-4 text-sm leading-relaxed text-muted-foreground">
        Semua angka diambil langsung dari SIAKAD, sama dengan yang dilihat Tata Usaha di tiap unit. Halaman ini hanya
        menampilkan jumlah per unit, tanpa data siswa perorangan. Batas &quot;wajar&quot; (kehadiran {persen(data.thresholds.attendance_warn)},
        SPP terbayar {persen(data.thresholds.collection_warn)}, KKM {data.thresholds.kkm ?? "—"}) masih perlu disahkan sekolah.
      </p>
    </div>
  );
}

// ---------------------------------------------------------------------------

function DirekturView({ data }: { data: ExecSummary }) {
  const t = data.pendidikan.tiles;
  const rows = data.pendidikan.per_unit;
  const sel = data.selected_unit?.code ?? null;
  const th = data.thresholds;

  const attendanceRows: CompareRow[] = rows.map((r) => ({
    code: r.code,
    label: r.short,
    value: r.attendance_rate,
    display: persen(r.attendance_rate),
    emptyText: r.students_active === 0 ? "belum ada siswa" : r.attendance_rate === null ? "belum ada presensi" : undefined,
    note: r.attendance_rate !== null && !r.data_complete.attendance ? "presensi belum lengkap" : undefined,
    flag:
      r.attendance_rate === null || !r.data_complete.attendance
        ? undefined
        : r.attendance_rate < th.attendance_crit
          ? { text: "rendah", tone: "bad" }
          : r.attendance_rate < th.attendance_warn
            ? { text: "perlu dicek", tone: "warn" }
            : undefined,
  }));

  const gradeRows: CompareRow[] = rows.map((r) => ({
    code: r.code,
    label: r.short,
    value: r.grades_average,
    display: r.grades_average === null ? "—" : r.grades_average.toLocaleString("id-ID"),
    emptyText:
      r.students_active === 0
        ? "belum ada siswa"
        : !r.data_complete.grades
          ? r.grades_graded_pct
            ? `baru ${persen(r.grades_graded_pct)} terisi`
            : "belum ada nilai"
          : undefined,
    flag: th.kkm !== null && r.grades_average !== null && r.grades_average < th.kkm ? { text: "di bawah KKM", tone: "bad" } : undefined,
  }));

  const violationMax = Math.max(20, ...rows.map((r) => r.violation_pct ?? 0));
  const violationRows: CompareRow[] = rows.map((r) => ({
    code: r.code,
    label: r.short,
    value: r.violation_pct ?? null,
    display: persen(r.violation_pct),
    emptyText: r.students_active === 0 ? "belum ada siswa" : undefined,
    flag: (r.violation_pct ?? 0) >= 10 ? { text: "perlu dicek", tone: "warn" } : undefined,
  }));

  const sppRows: CompareRow[] = rows.map((r) => collectionRow(r.code, r.short, r.collection_rate ?? null, th));

  return (
    <>
      <div>
        <SectionLabel>Angka utama</SectionLabel>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
          <StatTile label="Siswa aktif" value={t.students_active.toLocaleString("id-ID")} note={`${t.teachers} guru aktif`} />
          <StatTile
            label="Kehadiran semester ini"
            value={persen(t.attendance_rate)}
            note={t.attendance_rate === null ? "Belum ada presensi harian." : "Rata-rata hari hadir dari presensi harian."}
            tone={attendanceTone(t.attendance_rate, th)}
          />
          <StatTile
            label="Nilai akhir rata-rata"
            value={t.grades_average === null ? "—" : t.grades_average.toLocaleString("id-ID")}
            note={
              t.grades_average === null
                ? "Belum ada nilai akhir lengkap semester ini."
                : `Dari ${persen(t.grades_graded_pct)} siswa yang nilainya sudah lengkap. KKM ${th.kkm ?? "—"}.`
            }
          />
          <StatTile
            label="Siswa dengan pelanggaran"
            value={t.violation_students.toLocaleString("id-ID")}
            note={t.violation_pct === null ? undefined : `${persen(t.violation_pct)} dari siswa aktif, semester ini.`}
          />
          <StatTile
            label="SPP terbayar"
            value={persen(t.collection_rate)}
            note={t.collection_rate === null ? "Belum ada tagihan tahun ajaran ini." : `Tagihan ${data.period.billing_label ?? "tahun ini"} yang sudah dibayar.`}
            tone={collectionTone(t.collection_rate, th)}
          />
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Section title="Kehadiran per unit" sentence={lowest(attendanceRows, "Kehadiran terendah")}>
          <CompareBars rows={attendanceRows} max={100} selected={sel} threshold={th.attendance_warn} thresholdLabel={`Garis putus-putus = batas wajar ${persen(th.attendance_warn)}`} />
        </Section>
        <Section title="Nilai akhir rata-rata per unit" sentence={lowest(gradeRows, "Nilai terendah")}>
          <CompareBars rows={gradeRows} max={100} selected={sel} threshold={th.kkm ?? undefined} thresholdLabel={th.kkm ? `Garis putus-putus = KKM ${th.kkm}` : undefined} />
        </Section>
        <Section title="Siswa dengan catatan pelanggaran" sentence="Persentase siswa aktif yang punya poin pelanggaran semester ini.">
          <CompareBars rows={violationRows} max={violationMax} selected={sel} />
        </Section>
        <Section title="SPP terbayar per unit" sentence="Satu-satunya angka keuangan di halaman ini. Rinciannya ada pada BPH.">
          <CompareBars rows={sppRows} max={100} selected={sel} threshold={th.collection_warn} thresholdLabel={`Garis putus-putus = batas wajar ${persen(th.collection_warn)}`} />
        </Section>
      </div>

      <Section title="Kehadiran 6 bulan terakhir" sentence={`${data.selected_unit ? data.selected_unit.label : "Seluruh unit digabung"}. Bulan terakhir masih berjalan.`}>
        <div className="max-w-3xl">
          <MonthBars points={data.pendidikan.attendance_trend} field="rate" format={(v) => persen(v)} max={100} />
        </div>
      </Section>

      <Section title="Jumlah siswa per tahun ajaran" sentence="Siswa yang terdaftar di kelas pada tiap tahun ajaran.">
        <EnrollmentTable {...data.enrollment_trend} />
      </Section>

      <Section title="Kelengkapan data dari unit" sentence="Angka di atas hanya bisa dipercaya kalau guru dan TU rutin mengisi presensi dan nilai.">
        <CompletenessTable rows={rows.filter((r) => !sel || r.code === sel)} />
      </Section>
    </>
  );
}

function CompletenessTable({ rows }: { rows: EducationRow[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[36rem] text-base">
        <thead>
          <tr className="border-b border-border text-left text-sm text-muted-foreground">
            <th className="py-2 pr-3 font-semibold">Unit</th>
            <th className="px-3 py-2 font-semibold">Presensi harian terakhir diisi</th>
            <th className="py-2 pl-3 font-semibold">Nilai akhir semester ini</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.code} className="border-b border-border/60 align-top">
              <td className="py-3 pr-3 font-medium text-foreground">{r.label}</td>
              <td className="px-3 py-3">
                {r.students_active === 0 ? (
                  <span className="text-sm text-muted-foreground">Belum ada siswa</span>
                ) : (
                  <>
                    <p className="text-foreground">{r.attendance_last_date ? tanggal(r.attendance_last_date) : "Belum pernah"}</p>
                    <DataNote ok={r.data_complete.attendance} okText="Lengkap" badText={r.attendance_missed_days ? `${r.attendance_missed_days} hari sekolah terlewat` : "Belum diisi"} />
                  </>
                )}
              </td>
              <td className="py-3 pl-3">
                {r.students_active === 0 ? (
                  <span className="text-sm text-muted-foreground">Belum ada siswa</span>
                ) : (
                  <>
                    <p className="text-foreground">{persen(r.grades_graded_pct ?? 0)} siswa sudah punya nilai akhir</p>
                    <DataNote ok={r.data_complete.grades} okText="Cukup untuk dibandingkan" badText="Belum cukup untuk dibandingkan" />
                  </>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// ---------------------------------------------------------------------------

function BphView({ data }: { data: ExecSummary }) {
  const k = data.keuangan!;
  const t = k.tiles;
  const th = data.thresholds;
  const sel = data.selected_unit?.code ?? null;
  const sppRows = k.per_unit.map((r) => collectionRow(r.code, r.short, r.collection_rate, th));

  return (
    <>
      <div>
        <SectionLabel>Angka utama</SectionLabel>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
          <StatTile label="Uang SPP masuk" value={rupiahRingkas(t.paid)} note={`Dari ${rupiahRingkas(t.billed)} yang ditagih, ${data.period.billing_label ?? "tahun ini"}.`} />
          <StatTile
            label="SPP terbayar"
            value={persen(t.collection_rate)}
            note={t.collection_rate === null ? "Belum ada tagihan tahun ajaran ini." : "Bagian tagihan tahun ini yang sudah dibayar."}
            tone={collectionTone(t.collection_rate, th)}
          />
          <StatTile
            label="Tunggakan"
            value={rupiahRingkas(t.arrears_total)}
            note={
              t.arrears_total > 0
                ? `Lewat jatuh tempo, semua tahun. ${rupiahRingkas(t.arrears_over_90)} di antaranya lebih dari 90 hari.`
                : "Tidak ada tagihan yang lewat jatuh tempo."
            }
            tone={(t.arrears_over_90_pct ?? 0) >= 15 ? "warn" : undefined}
          />
          <StatTile
            label="Diskon & beasiswa"
            value={rupiahRingkas(t.discount)}
            note={t.discount_pct === null ? "Belum ada tagihan tahun ini." : `${persen(t.discount_pct)} dari tagihan sebelum potongan.`}
          />
          <StatTile label="Siswa aktif" value={data.pendidikan.tiles.students_active.toLocaleString("id-ID")} note={`${data.pendidikan.tiles.teachers} guru aktif`} />
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Section title="SPP terbayar per unit" sentence={lowest(sppRows, "Terendah")}>
          <CompareBars rows={sppRows} max={100} selected={sel} threshold={th.collection_warn} thresholdLabel={`Garis putus-putus = batas wajar ${persen(th.collection_warn)}`} />
        </Section>
        <Section title="Umur tunggakan per unit" sentence="Makin gelap warnanya, makin lama belum dibayar. Tunggakan lebih dari 90 hari berisiko tidak tertagih.">
          <AgingBars rows={k.per_unit.map((r) => ({ code: r.code, label: r.short, arrears: r.arrears, total: r.arrears_total }))} selected={sel} />
        </Section>
      </div>

      <Section
        title="Uang masuk 6 bulan terakhir"
        sentence={`Pembayaran yang sudah diterima per bulan${data.selected_unit ? `, ${data.selected_unit.label}` : ", seluruh unit"}. Bulan terakhir masih berjalan.`}
      >
        <div className="max-w-3xl">
          <MonthBars points={k.cash_trend} field="amount" format={(v) => rupiahRingkas(v).replace("Rp ", "")} />
        </div>
      </Section>

      <Section title="Jumlah siswa per tahun ajaran" sentence="Turunnya jumlah siswa berarti turunnya pendapatan tahun berikutnya.">
        <EnrollmentTable {...data.enrollment_trend} />
      </Section>

      <Section title="Ringkasan pendidikan" sentence="Gambaran singkat dari halaman Direktur Pendidikan.">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[32rem] text-base">
            <thead>
              <tr className="border-b border-border text-left text-sm text-muted-foreground">
                <th className="py-2 pr-3 font-semibold">Unit</th>
                <th className="px-3 py-2 text-right font-semibold">Kehadiran</th>
                <th className="px-3 py-2 text-right font-semibold">Nilai rata-rata</th>
                <th className="py-2 pl-3 text-right font-semibold">Siswa perlu perhatian</th>
              </tr>
            </thead>
            <tbody>
              {data.pendidikan.per_unit
                .filter((r) => !sel || r.code === sel)
                .map((r) => (
                  <tr key={r.code} className="border-b border-border/60">
                    <td className="py-2.5 pr-3 font-medium text-foreground">{r.label}</td>
                    <td className="px-3 py-2.5 text-right tabular-nums">
                      {persen(r.attendance_rate)}
                      {r.attendance_rate !== null && !r.data_complete.attendance && <span className="block text-xs font-semibold text-warn">belum lengkap</span>}
                    </td>
                    <td className="px-3 py-2.5 text-right tabular-nums">
                      {r.data_complete.grades && r.grades_average !== null ? r.grades_average.toLocaleString("id-ID") : <span className="text-sm text-muted-foreground">belum lengkap</span>}
                    </td>
                    <td className="py-2.5 pl-3 text-right tabular-nums">{r.students_active === 0 ? "—" : r.needing_attention}</td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      </Section>
    </>
  );
}

// ---------------------------------------------------------------------------

function collectionRow(code: string, label: string, rate: number | null, th: ExecSummary["thresholds"]): CompareRow {
  return {
    code,
    label,
    value: rate,
    display: persen(rate),
    emptyText: rate === null ? "belum ada tagihan" : undefined,
    flag: rate === null ? undefined : rate < th.collection_crit ? { text: "rendah", tone: "bad" } : rate < th.collection_warn ? { text: "perlu dicek", tone: "warn" } : undefined,
  };
}

function attendanceTone(rate: number | null, th: ExecSummary["thresholds"]) {
  if (rate === null) return undefined;
  return rate < th.attendance_crit ? "bad" : rate < th.attendance_warn ? "warn" : undefined;
}

function collectionTone(rate: number | null, th: ExecSummary["thresholds"]) {
  if (rate === null) return undefined;
  return rate < th.collection_crit ? "bad" : rate < th.collection_warn ? "warn" : undefined;
}

/** One plain sentence naming the weakest unit, so the reader knows where to look. */
function lowest(rows: CompareRow[], prefix: string): string {
  const usable = rows.filter((r) => r.value !== null && !r.emptyText);
  if (usable.length === 0) return "Belum ada data untuk dibandingkan.";
  if (usable.length === 1) return `${usable[0].label}: ${usable[0].display}.`;
  const min = usable.reduce((a, b) => ((b.value as number) < (a.value as number) ? b : a));
  return `${prefix}: ${min.label}, ${min.display}.`;
}

function waktu(iso: string): string {
  return new Date(iso).toLocaleString("id-ID", { day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit", timeZone: "Asia/Jakarta" });
}

function UnitButton({ active, onClick, title, children }: { active: boolean; onClick: () => void; title?: string; children: React.ReactNode }) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      title={title}
      className={cn(
        "h-11 rounded-full border px-4 text-base font-semibold transition-colors",
        active ? "border-primary bg-primary text-primary-foreground" : "border-border bg-card text-foreground hover:bg-accent",
      )}
    >
      {children}
    </button>
  );
}

function ErrorBox({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="flex flex-col items-start gap-3 rounded-2xl border border-bad/30 bg-bad-soft p-5">
      <p className="text-base font-semibold text-bad">{message}</p>
      <Button variant="outline" onClick={onRetry}>
        <RefreshCw className="size-4" aria-hidden /> Coba lagi
      </Button>
    </div>
  );
}

"use client";

import { use, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowLeft, Check, ChevronDown, ChevronUp, Sparkles, UserCheck, Users } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { tanggal, todayJakarta } from "@/lib/format";
import type { PointRecord } from "@/lib/types/kesiswaan";

type StudentRow = { ulid: string; nama_lengkap: string; nis: string | null; point_balance: number | null };
type Rule = { ulid: string; code: string; name: string; type: "violation" | "merit"; category: string; points: number; requires_evidence: boolean };
type TodaySchedule = { ulid: string; subject: string; teacher: string | null; start_time: string; end_time: string };

function TodaySchedulePanel({ classroomUlid }: { classroomUlid: string }) {
  const router = useRouter();
  const [schedules, setSchedules] = useState<TodaySchedule[] | null>(null);
  const [opening, setOpening] = useState<string | null>(null);

  useEffect(() => {
    api
      .get<{ schedules: TodaySchedule[] }>(`/api/guru/classrooms/${classroomUlid}/schedules/today`)
      .then((d) => setSchedules(d.schedules))
      .catch(() => setSchedules([]));
  }, [classroomUlid]);

  async function openAttendance(scheduleUlid: string) {
    setOpening(scheduleUlid);
    try {
      const { session } = await api.post<{ session: { ulid: string } }>(`/api/guru/schedules/${scheduleUlid}/attendance-sessions`);
      router.push(`/guru/presensi/${session.ulid}`);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal membuka presensi.");
      setOpening(null);
    }
  }

  if (schedules === null) return <Skeleton className="h-14 w-full rounded-xl" />;
  if (schedules.length === 0) return null;

  return (
    <Card className="p-4">
      <h2 className="mb-2 flex items-center gap-1.5 text-xs font-bold text-foreground">
        <UserCheck className="size-4" />
        Jadwal Hari Ini
      </h2>
      <div className="flex flex-col gap-2">
        {schedules.map((s) => (
          <div key={s.ulid} className="flex items-center justify-between gap-3 rounded-lg bg-muted/30 p-2.5">
            <div>
              <p className="text-sm font-semibold">{s.subject}</p>
              <p className="text-xs text-muted-foreground">
                {s.start_time.slice(0, 5)}–{s.end_time.slice(0, 5)}{s.teacher ? ` · ${s.teacher}` : ""}
              </p>
            </div>
            <Button size="sm" onClick={() => openAttendance(s.ulid)} disabled={opening === s.ulid} className="text-xs">
              {opening === s.ulid ? "Membuka…" : "Buka Presensi"}
            </Button>
          </div>
        ))}
      </div>
    </Card>
  );
}

function RuleSelect({ rules, value, onChange }: { rules: Rule[]; value: string; onChange: (v: string) => void }) {
  return (
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      required
      className="h-9 rounded-lg border border-input bg-card px-3 text-xs shadow-2xs font-medium"
    >
      <option value="">Pilih aturan poin…</option>
      {rules.map((r) => (
        <option key={r.ulid} value={r.ulid}>
          {r.name} ({r.type === "violation" ? "−" : "+"}{Math.abs(r.points)} poin)
        </option>
      ))}
    </select>
  );
}

/** Single-student form - the only path that can attach evidence. */
function SingleRecordForm({
  student,
  rules,
  onDone,
  onCancel,
}: {
  student: StudentRow;
  rules: Rule[];
  onDone: () => void;
  onCancel: () => void;
}) {
  const [ruleUlid, setRuleUlid] = useState("");
  const [occurredOn, setOccurredOn] = useState(todayJakarta());
  const [description, setDescription] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const rule = rules.find((r) => r.ulid === ruleUlid);

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    const form = new FormData(e.currentTarget);
    form.set("student_ulid", student.ulid);
    form.set("point_rule_ulid", ruleUlid);
    form.set("occurred_on", occurredOn);
    form.set("description", description);

    try {
      await api.post("/api/guru/points", form);
      toast.success("Poin berhasil dicatat.");
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal mencatat poin.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={submit} className="mt-3 flex flex-col gap-2.5 border-t border-border/70 pt-3">
      <div className="flex flex-wrap gap-2">
        <RuleSelect rules={rules} value={ruleUlid} onChange={setRuleUlid} />
        <Input
          value={occurredOn}
          onChange={(e) => setOccurredOn(e.target.value)}
          type="date"
          max={todayJakarta()}
          className="w-36 h-9 text-xs"
        />
      </div>
      <Input
        value={description}
        onChange={(e) => setDescription(e.target.value)}
        required
        placeholder="Keterangan kejadian / pelanggaran / apresiasi..."
        className="text-xs h-9"
      />
      {rule?.requires_evidence && (
        <div className="flex flex-col gap-1">
          <Label className="text-xs">Lampiran Bukti (Wajib untuk aturan ini):</Label>
          <input name="evidence" type="file" accept=".jpg,.jpeg,.png,.pdf" required className="text-xs" />
        </div>
      )}
      {error && <p className="rounded-lg bg-destructive/10 p-2 text-xs text-destructive">{error}</p>}
      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={submitting || !ruleUlid} className="text-xs font-semibold">
          {submitting ? "Menyimpan…" : "Simpan Catatan"}
        </Button>
        <Button type="button" size="sm" variant="ghost" onClick={onCancel} disabled={submitting} className="text-xs">
          Batal
        </Button>
      </div>
    </form>
  );
}

function LedgerPanel({ student }: { student: StudentRow }) {
  const [records, setRecords] = useState<PointRecord[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [revoking, setRevoking] = useState<string | null>(null);
  const [reason, setReason] = useState("");

  function load() {
    api
      .get<{ records: PointRecord[] }>(`/api/guru/students/${student.ulid}/points`)
      .then((d) => setRecords(d.records))
      .catch((err) => setError(err instanceof ApiError ? err.message : "Gagal memuat riwayat poin."));
  }

  useEffect(() => {
    load();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  async function revoke(ulid: string) {
    if (!reason.trim()) return;
    try {
      await api.patch(`/api/guru/points/${ulid}/revoke`, { reason });
      toast.success("Catatan dibatalkan.");
      setRevoking(null);
      setReason("");
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal membatalkan.");
    }
  }

  if (error) return <p className="mt-3 text-xs text-destructive">{error}</p>;
  if (records === null) return <Skeleton className="mt-3 h-16 w-full" />;
  if (records.length === 0) return <p className="mt-3 text-xs text-muted-foreground">Belum ada riwayat catatan semester ini.</p>;

  return (
    <div className="mt-3 flex flex-col gap-2 border-t border-border/70 pt-3">
      {records.map((r) => (
        <div
          key={r.ulid}
          className={`flex items-start justify-between gap-3 text-xs p-2 rounded-lg bg-muted/30 ${r.status === "revoked" ? "opacity-50" : ""}`}
        >
          <div>
            <p className="font-semibold text-foreground">{r.description} {r.status === "revoked" && <span className="text-[10px] text-muted-foreground">(dibatalkan)</span>}</p>
            <p className="text-[11px] text-muted-foreground mt-0.5">{tanggal(r.occurred_on)} · {r.recorded_by}</p>
            {revoking === r.ulid && (
              <div className="mt-1.5 flex gap-1.5">
                <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Alasan pembatalan..." className="h-7 w-48 text-[11px]" />
                <Button size="sm" variant="destructive" onClick={() => revoke(r.ulid)} className="h-7 text-[11px]">OK</Button>
              </div>
            )}
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <span className={`tabular font-bold ${r.points > 0 ? "text-good" : "text-bad"}`}>
              {r.points > 0 ? `+${r.points}` : r.points}
            </span>
            {r.status === "recorded" && revoking !== r.ulid && (
              <button onClick={() => setRevoking(r.ulid)} className="text-[11px] text-muted-foreground hover:text-destructive underline">
                Batalkan
              </button>
            )}
          </div>
        </div>
      ))}
    </div>
  );
}

export default function GuruClassroomPage({ params }: { params: Promise<{ ulid: string }> }) {
  const { ulid } = use(params);

  const [className, setClassName] = useState("");
  const [students, setStudents] = useState<StudentRow[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [rules, setRules] = useState<Rule[]>([]);
  const [openForm, setOpenForm] = useState<string | null>(null);
  const [openLedger, setOpenLedger] = useState<string | null>(null);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [bulkRule, setBulkRule] = useState("");
  const [bulkDate, setBulkDate] = useState(todayJakarta());
  const [bulkDescription, setBulkDescription] = useState("");
  const [bulkSubmitting, setBulkSubmitting] = useState(false);

  function load() {
    api
      .get<{ classroom: { name: string }; students: StudentRow[] }>(`/api/guru/classrooms/${ulid}/students`)
      .then((d) => {
        setClassName(d.classroom.name);
        setStudents(d.students);
      })
      .catch((err) => setLoadError(err instanceof ApiError ? err.message : "Gagal memuat kelas."));
  }

  useEffect(() => {
    load();
    api.get<{ rules: Rule[] }>("/api/guru/point-rules").then((d) => setRules(d.rules));
  }, [ulid]); // eslint-disable-line react-hooks/exhaustive-deps

  function toggleSelect(studentUlid: string) {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(studentUlid)) next.delete(studentUlid);
      else next.add(studentUlid);
      return next;
    });
  }

  function selectAll() {
    if (!students) return;
    if (selected.size === students.length) {
      setSelected(new Set());
    } else {
      setSelected(new Set(students.map((s) => s.ulid)));
    }
  }

  const bulkRuleData = rules.find((r) => r.ulid === bulkRule);

  async function submitBulk() {
    setBulkSubmitting(true);
    try {
      const { recorded } = await api.post<{ recorded: number }>("/api/guru/points/bulk", {
        student_ulids: [...selected],
        point_rule_ulid: bulkRule,
        occurred_on: bulkDate,
        description: bulkDescription,
      });
      toast.success(`Poin berhasil dicatat untuk ${recorded} siswa.`);
      setSelected(new Set());
      setBulkRule("");
      setBulkDescription("");
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mencatat poin massal.");
    } finally {
      setBulkSubmitting(false);
    }
  }

  if (loadError) {
    return (
      <div className="space-y-4">
        <Link href="/guru" className="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" />
          <span>Kembali ke Kelas Saya</span>
        </Link>
        <Card className="p-6 text-sm text-destructive">{loadError}</Card>
      </div>
    );
  }

  return (
    <div className="space-y-6 pb-28">
      <div>
        <Link href="/guru" className="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" />
          <span>Kembali ke Kelas Saya</span>
        </Link>
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mt-2">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-foreground">
              Daftar Siswa {className ? `Kelas ${className}` : "..."}
            </h1>
            <p className="text-xs text-muted-foreground mt-0.5">
              Centang beberapa siswa untuk mencatat poin massal, atau catat satu per satu.
            </p>
          </div>

          {students && students.length > 0 && (
            <Button variant="outline" size="sm" onClick={selectAll} className="text-xs self-start sm:self-auto">
              {selected.size === students.length ? "Batalkan Pilih Semua" : "Pilih Semua Siswa"}
            </Button>
          )}
        </div>
      </div>

      <TodaySchedulePanel classroomUlid={ulid} />

      {students === null && (
        <div className="space-y-3">
          <Skeleton className="h-16 w-full rounded-xl" />
          <Skeleton className="h-16 w-full rounded-xl" />
        </div>
      )}

      <div className="grid grid-cols-1 gap-3">
        {students?.map((student) => {
          const checked = selected.has(student.ulid);

          return (
            <Card
              key={student.ulid}
              className={`p-4 sm:p-5 border-border/80 transition-colors ${checked ? "border-primary bg-primary/5 shadow-xs" : ""}`}
            >
              <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                  <button
                    type="button"
                    role="checkbox"
                    aria-checked={checked}
                    onClick={() => toggleSelect(student.ulid)}
                    className={`grid size-5.5 shrink-0 place-items-center rounded-lg border transition-all ${
                      checked ? "border-primary bg-primary text-primary-foreground shadow-2xs" : "border-input bg-card"
                    }`}
                  >
                    {checked && <Check className="size-3.5" strokeWidth={3} />}
                  </button>
                  <div>
                    <p className="font-bold text-foreground text-sm">{student.nama_lengkap}</p>
                    <p className="text-xs text-muted-foreground">NIS: {student.nis ?? "Belum terbit"}</p>
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  {student.point_balance !== null && (
                    <Badge variant={student.point_balance < 0 ? "warn" : "default"} className="font-mono">
                      {student.point_balance > 0 ? `+${student.point_balance}` : student.point_balance} poin
                    </Badge>
                  )}
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                      setOpenForm(openForm === student.ulid ? null : student.ulid);
                      setOpenLedger(null);
                    }}
                    className="text-xs h-8"
                  >
                    Catat Poin
                  </Button>
                  <button
                    onClick={() => {
                      setOpenLedger(openLedger === student.ulid ? null : student.ulid);
                      setOpenForm(null);
                    }}
                    className="rounded-lg p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                    aria-label="Lihat riwayat"
                  >
                    {openLedger === student.ulid ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
                  </button>
                </div>
              </div>

              {openForm === student.ulid && (
                <SingleRecordForm
                  student={student}
                  rules={rules}
                  onCancel={() => setOpenForm(null)}
                  onDone={() => {
                    setOpenForm(null);
                    load();
                  }}
                />
              )}
              {openLedger === student.ulid && <LedgerPanel student={student} />}
            </Card>
          );
        })}
      </div>

      {/* Floating Bulk Action Bar */}
      {selected.size > 0 && (
        <div className="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-card/95 backdrop-blur-md shadow-2xl">
          <div className="mx-auto flex max-w-7xl 2xl:max-w-full flex-wrap items-center justify-between gap-3 px-4 sm:px-6 lg:px-8 py-3.5">
            <p className="text-xs font-bold text-foreground">
              {selected.size} Siswa Terpilih
            </p>

            <div className="flex flex-wrap items-center gap-2">
              <RuleSelect rules={rules.filter((r) => !r.requires_evidence)} value={bulkRule} onChange={setBulkRule} />
              <Input
                value={bulkDate}
                onChange={(e) => setBulkDate(e.target.value)}
                type="date"
                max={todayJakarta()}
                className="w-32 h-9 text-xs"
              />
              <Input
                value={bulkDescription}
                onChange={(e) => setBulkDescription(e.target.value)}
                placeholder="Keterangan..."
                className="w-40 h-9 text-xs"
              />
              <Button
                size="sm"
                onClick={submitBulk}
                disabled={bulkSubmitting || !bulkRule || !bulkDescription}
                className="h-9 text-xs font-semibold"
              >
                {bulkSubmitting ? "Menyimpan…" : "Catat Massal"}
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())} className="h-9 text-xs">
                Batal
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

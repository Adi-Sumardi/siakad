"use client";

import { use, useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, Check, ChevronDown, ChevronUp } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { tanggal } from "@/lib/format";
import type { PointRecord } from "@/lib/types/kesiswaan";

type StudentRow = { ulid: string; nama_lengkap: string; nis: string | null; point_balance: number | null };
type Rule = { ulid: string; code: string; name: string; type: "violation" | "merit"; category: string; points: number; requires_evidence: boolean };

function RuleSelect({ rules, value, onChange }: { rules: Rule[]; value: string; onChange: (v: string) => void }) {
  return (
    <select value={value} onChange={(e) => onChange(e.target.value)} required className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
      <option value="">Pilih aturan…</option>
      {rules.map((r) => (
        <option key={r.ulid} value={r.ulid}>
          {r.name} ({r.type === "violation" ? "−" : "+"}{Math.abs(r.points)})
        </option>
      ))}
    </select>
  );
}

/** Single-student form - the only path that can attach evidence. */
function SingleRecordForm({ student, rules, onDone, onCancel }: {
  student: StudentRow; rules: Rule[]; onDone: () => void; onCancel: () => void;
}) {
  const [ruleUlid, setRuleUlid] = useState("");
  const [occurredOn, setOccurredOn] = useState(new Date().toISOString().slice(0, 10));
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
      toast.success("Poin dicatat.");
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal mencatat poin.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={submit} className="mt-3 flex flex-col gap-2 border-t border-border pt-3">
      <div className="flex flex-wrap gap-2">
        <RuleSelect rules={rules} value={ruleUlid} onChange={setRuleUlid} />
        <Input value={occurredOn} onChange={(e) => setOccurredOn(e.target.value)} type="date" max={new Date().toISOString().slice(0, 10)} className="w-40" />
      </div>
      <Input value={description} onChange={(e) => setDescription(e.target.value)} required placeholder="Keterangan" />
      {rule?.requires_evidence && (
        <div className="flex flex-col gap-1">
          <Label className="text-xs">Bukti (wajib untuk aturan ini)</Label>
          <input name="evidence" type="file" accept=".jpg,.jpeg,.png,.pdf" required className="text-sm" />
        </div>
      )}
      {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={submitting || !ruleUlid}>{submitting ? "Menyimpan…" : "Simpan"}</Button>
        <Button type="button" size="sm" variant="ghost" onClick={onCancel} disabled={submitting}>Batal</Button>
      </div>
    </form>
  );
}

function LedgerPanel({ student }: { student: StudentRow }) {
  const [records, setRecords] = useState<PointRecord[] | null>(null);
  const [revoking, setRevoking] = useState<string | null>(null);
  const [reason, setReason] = useState("");

  function load() {
    api.get<{ records: PointRecord[] }>(`/api/guru/students/${student.ulid}/points`).then((d) => setRecords(d.records));
  }

  useEffect(() => { load(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

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

  if (records === null) return <Skeleton className="mt-3 h-16 w-full" />;
  if (records.length === 0) return <p className="mt-3 text-sm text-muted-foreground">Belum ada catatan semester ini.</p>;

  return (
    <div className="mt-3 flex flex-col gap-2 border-t border-border pt-3">
      {records.map((r) => (
        <div key={r.ulid} className={`flex items-start justify-between gap-3 text-sm ${r.status === "revoked" ? "opacity-50" : ""}`}>
          <div>
            <p>{r.description} {r.status === "revoked" && <span className="text-xs">(dibatalkan)</span>}</p>
            <p className="text-xs text-muted-foreground">{tanggal(r.occurred_on)} · {r.recorded_by}</p>
            {revoking === r.ulid && (
              <div className="mt-1 flex gap-1.5">
                <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Alasan" className="h-8 w-48 text-xs" />
                <Button size="sm" variant="destructive" onClick={() => revoke(r.ulid)}>OK</Button>
              </div>
            )}
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <span className={`tabular font-semibold ${r.points > 0 ? "text-good" : "text-bad"}`}>
              {r.points > 0 ? `+${r.points}` : r.points}
            </span>
            {r.status === "recorded" && revoking !== r.ulid && (
              <button onClick={() => setRevoking(r.ulid)} className="text-xs text-muted-foreground hover:text-bad">Batalkan</button>
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
  const [rules, setRules] = useState<Rule[]>([]);
  const [openForm, setOpenForm] = useState<string | null>(null);
  const [openLedger, setOpenLedger] = useState<string | null>(null);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [bulkRule, setBulkRule] = useState("");
  const [bulkDate, setBulkDate] = useState(new Date().toISOString().slice(0, 10));
  const [bulkDescription, setBulkDescription] = useState("");
  const [bulkSubmitting, setBulkSubmitting] = useState(false);

  function load() {
    api.get<{ classroom: { name: string }; students: StudentRow[] }>(`/api/guru/classrooms/${ulid}/students`)
      .then((d) => { setClassName(d.classroom.name); setStudents(d.students); });
  }

  useEffect(() => {
    load();
    api.get<{ rules: Rule[] }>("/api/guru/point-rules").then((d) => setRules(d.rules));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ulid]);

  function toggleSelect(studentUlid: string) {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(studentUlid)) next.delete(studentUlid);
      else next.add(studentUlid);
      return next;
    });
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
      toast.success(`Poin dicatat untuk ${recorded} siswa.`);
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

  return (
    <div className="flex flex-col gap-5 pb-24">
      <Link href="/guru" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" />
        Kelas saya
      </Link>

      <div>
        <h1 className="text-xl font-bold tracking-tight">{className || "Memuat…"}</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Centang beberapa siswa untuk mencatat satu aturan sekaligus, atau catat satu per satu.
        </p>
      </div>

      {students === null && <Skeleton className="h-64 w-full" />}

      <div className="flex flex-col gap-2">
        {students?.map((student) => {
          const checked = selected.has(student.ulid);

          return (
            <Card key={student.ulid} className={`p-4 ${checked ? "border-primary bg-accent/40" : ""}`}>
              <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                  <button
                    type="button"
                    role="checkbox"
                    aria-checked={checked}
                    onClick={() => toggleSelect(student.ulid)}
                    className={`grid size-5 shrink-0 place-items-center rounded border ${checked ? "border-primary bg-primary text-primary-foreground" : "border-input bg-card"}`}
                  >
                    {checked && <Check className="size-3.5" strokeWidth={3} />}
                  </button>
                  <div>
                    <p className="font-medium">{student.nama_lengkap}</p>
                    <p className="text-xs text-muted-foreground">{student.nis ?? "NIS belum terbit"}</p>
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  {student.point_balance !== null && (
                    <Badge variant={student.point_balance < 0 ? "warn" : "default"}>
                      {student.point_balance > 0 ? `+${student.point_balance}` : student.point_balance}
                    </Badge>
                  )}
                  <Button size="sm" variant="outline" onClick={() => { setOpenForm(openForm === student.ulid ? null : student.ulid); setOpenLedger(null); }}>
                    Catat
                  </Button>
                  <button
                    onClick={() => { setOpenLedger(openLedger === student.ulid ? null : student.ulid); setOpenForm(null); }}
                    className="text-muted-foreground hover:text-foreground"
                    aria-label="Lihat riwayat"
                  >
                    {openLedger === student.ulid ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
                  </button>
                </div>
              </div>

              {openForm === student.ulid && (
                <SingleRecordForm student={student} rules={rules} onCancel={() => setOpenForm(null)} onDone={() => { setOpenForm(null); load(); }} />
              )}
              {openLedger === student.ulid && <LedgerPanel student={student} />}
            </Card>
          );
        })}
      </div>

      {selected.size > 0 && (
        <div className="fixed inset-x-0 bottom-0 border-t border-border bg-card/95 backdrop-blur">
          <div className="mx-auto flex max-w-3xl flex-wrap items-end gap-2 px-4 py-3">
            <p className="mr-auto text-sm font-medium">{selected.size} siswa dipilih</p>
            <RuleSelect rules={rules.filter((r) => !r.requires_evidence)} value={bulkRule} onChange={setBulkRule} />
            <Input value={bulkDate} onChange={(e) => setBulkDate(e.target.value)} type="date" max={new Date().toISOString().slice(0, 10)} className="w-36" />
            <Input value={bulkDescription} onChange={(e) => setBulkDescription(e.target.value)} placeholder="Keterangan" className="w-48" />
            <Button size="sm" onClick={submitBulk} disabled={bulkSubmitting || !bulkRule || !bulkDescription}>
              {bulkSubmitting ? "Menyimpan…" : "Catat untuk semua"}
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>Batal</Button>
          </div>
          {bulkRuleData?.requires_evidence && (
            <p className="mx-auto max-w-3xl px-4 pb-2 text-xs text-bad">
              Aturan ini mewajibkan bukti dan tidak tersedia untuk pencatatan massal.
            </p>
          )}
        </div>
      )}
    </div>
  );
}

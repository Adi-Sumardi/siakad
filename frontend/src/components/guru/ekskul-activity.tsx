"use client";

import { useCallback, useEffect, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api, ApiError } from "@/lib/api";
import { tanggal, todayJakarta } from "@/lib/format";

type Status = "hadir" | "sakit" | "izin" | "alpa";
type Member = { ulid: string; student: { nama_lengkap: string; nis: string | null } };
type Meeting = { ulid: string; date: string; notes: string | null; records: Record<string, Status> };
type Tally = Record<Status, number>;

const STATUSES: { value: Status; label: string }[] = [
  { value: "hadir", label: "H" },
  { value: "sakit", label: "S" },
  { value: "izin", label: "I" },
  { value: "alpa", label: "A" },
];

/**
 * Practice-day attendance for one ekskul (audit 6 Okt 2026 #8). Pick a date,
 * mark everyone (default hadir), save; re-saving a date replaces its marks.
 */
export function EkskulPracticePanel({ ekskulUlid }: { ekskulUlid: string }) {
  const [members, setMembers] = useState<Member[]>([]);
  const [meetings, setMeetings] = useState<Meeting[]>([]);
  const [summary, setSummary] = useState<Record<string, Tally>>({});
  const [date, setDate] = useState(todayJakarta());
  const [notes, setNotes] = useState("");
  const [marks, setMarks] = useState<Record<string, Status>>({});
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    Promise.all([
      api.get<{ members: Member[] }>(`/api/guru/extracurriculars/${ekskulUlid}/members`),
      api.get<{ meetings: Meeting[]; term_summary: Record<string, Tally> }>(`/api/guru/extracurriculars/${ekskulUlid}/meetings`),
    ])
      .then(([m, mt]) => {
        setMembers(m.members);
        setMeetings(mt.meetings);
        setSummary(mt.term_summary ?? {});
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat presensi latihan."));
  }, [ekskulUlid]);

  useEffect(load, [load]);

  // Picking a date that was already recorded loads its marks for editing.
  const existing = meetings.find((m) => m.date === date);
  const statusOf = (memberUlid: string): Status => marks[memberUlid] ?? existing?.records[memberUlid] ?? "hadir";

  async function save() {
    if (members.length === 0) return;
    setBusy(true);
    try {
      await api.post(`/api/guru/extracurriculars/${ekskulUlid}/meetings`, {
        date,
        notes: notes || existing?.notes || undefined,
        records: members.map((m) => ({ member_ulid: m.ulid, status: statusOf(m.ulid) })),
      });
      toast.success(`Presensi latihan ${tanggal(date)} tersimpan.`);
      setMarks({});
      setNotes("");
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyimpan presensi latihan.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="border-t border-border p-4">
      <h3 className="text-sm font-semibold">Presensi Latihan</h3>
      <div className="mt-2 flex flex-wrap items-end gap-2">
        <div className="flex flex-col gap-1">
          <Label className="text-xs">Tanggal</Label>
          <Input
            type="date"
            value={date}
            max={todayJakarta()}
            onChange={(e) => {
              setDate(e.target.value);
              setMarks({});
            }}
            className="h-9 w-40"
          />
        </div>
        <div className="flex min-w-48 flex-1 flex-col gap-1">
          <Label className="text-xs">Catatan (opsional)</Label>
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder={existing?.notes ?? "misal: latihan baris-berbaris"} className="h-9" maxLength={500} />
        </div>
        <Button size="sm" onClick={save} disabled={busy || members.length === 0}>
          {existing ? "Perbarui" : "Simpan"}
        </Button>
      </div>

      <div className="mt-3 flex flex-col gap-1">
        {members.map((m) => {
          const t = summary[m.ulid];
          return (
            <div key={m.ulid} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted/30 px-3 py-1.5 text-sm">
              <span>
                {m.student.nama_lengkap}
                {t && (
                  <span className="ml-2 text-xs text-muted-foreground">
                    semester ini: H{t.hadir} S{t.sakit} I{t.izin} A{t.alpa}
                  </span>
                )}
              </span>
              <div className="flex gap-1">
                {STATUSES.map((s) => (
                  <Button
                    key={s.value}
                    size="sm"
                    variant={statusOf(m.ulid) === s.value ? "default" : "ghost"}
                    onClick={() => setMarks((prev) => ({ ...prev, [m.ulid]: s.value }))}
                    className="h-7 w-8 px-0"
                    aria-label={`${m.student.nama_lengkap} ${s.value}`}
                  >
                    {s.label}
                  </Button>
                ))}
              </div>
            </div>
          );
        })}
        {members.length === 0 && <p className="text-sm text-muted-foreground">Belum ada anggota.</p>}
      </div>

      {meetings.length > 0 && (
        <p className="mt-2 text-xs text-muted-foreground">
          Tercatat {meetings.length} pertemuan terakhir: {meetings.slice(0, 6).map((m) => tanggal(m.date)).join(", ")}
          {meetings.length > 6 && ", …"}
        </p>
      )}
    </div>
  );
}

type AssessmentRow = { member_ulid: string; nama_lengkap: string; nis: string | null; predikat: string | null; keterangan: string | null };

/** The pembina's per-term predikat - printed on the rapor. */
export function EkskulAssessmentPanel({ ekskulUlid }: { ekskulUlid: string }) {
  const [rows, setRows] = useState<AssessmentRow[]>([]);
  const [options, setOptions] = useState<{ value: string; label: string }[]>([]);
  const [term, setTerm] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    api
      .get<{ members: AssessmentRow[]; predikat_options: { value: string; label: string }[]; term: string | null }>(
        `/api/guru/extracurriculars/${ekskulUlid}/assessments`,
      )
      .then((d) => {
        setRows(d.members);
        setOptions(d.predikat_options);
        setTerm(d.term);
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat penilaian."));
  }, [ekskulUlid]);

  useEffect(load, [load]);

  function patch(memberUlid: string, field: "predikat" | "keterangan", value: string) {
    setRows((prev) => prev.map((r) => (r.member_ulid === memberUlid ? { ...r, [field]: value } : r)));
  }

  async function save() {
    const items = rows.filter((r) => r.predikat).map((r) => ({ member_ulid: r.member_ulid, predikat: r.predikat, keterangan: r.keterangan || null }));
    if (items.length === 0) {
      toast.error("Isi predikat minimal satu siswa.");
      return;
    }
    setBusy(true);
    try {
      await api.put(`/api/guru/extracurriculars/${ekskulUlid}/assessments`, { items });
      toast.success(`Penilaian ${items.length} siswa tersimpan.`);
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyimpan penilaian.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="border-t border-border p-4">
      <div className="flex items-center justify-between gap-2">
        <h3 className="text-sm font-semibold">Penilaian Rapor {term && <span className="font-normal text-muted-foreground">· {term}</span>}</h3>
        <Button size="sm" onClick={save} disabled={busy || rows.length === 0}>
          Simpan penilaian
        </Button>
      </div>
      <div className="mt-2 flex flex-col gap-1">
        {rows.map((r) => (
          <div key={r.member_ulid} className="flex flex-wrap items-center gap-2 rounded-lg bg-muted/30 px-3 py-1.5 text-sm">
            <span className="min-w-40 flex-1">{r.nama_lengkap}</span>
            <select
              value={r.predikat ?? ""}
              onChange={(e) => patch(r.member_ulid, "predikat", e.target.value)}
              className="h-8 rounded-lg border border-input bg-card px-2 text-sm"
              aria-label={`Predikat ${r.nama_lengkap}`}
            >
              <option value="">—</option>
              {options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.value} · {o.label}
                </option>
              ))}
            </select>
            <Input
              value={r.keterangan ?? ""}
              onChange={(e) => patch(r.member_ulid, "keterangan", e.target.value)}
              placeholder="Keterangan (opsional)"
              maxLength={500}
              className="h-8 min-w-48 flex-1 text-xs"
            />
          </div>
        ))}
        {rows.length === 0 && <p className="text-sm text-muted-foreground">Belum ada anggota aktif.</p>}
      </div>
    </div>
  );
}

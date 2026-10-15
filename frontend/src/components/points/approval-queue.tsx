"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Check, CheckCheck, Loader2, Paperclip, X } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { API_BASE, api, ApiError } from "@/lib/api";
import { tanggal } from "@/lib/format";
import { APPROVALS_CHANGED } from "@/lib/use-pending-approvals";

type Approval = {
  stage: string;
  stage_label: string;
  decision: "approved" | "rejected" | "skipped";
  decided_by: string | null;
  note: string | null;
  at: string;
};

export type ApprovalItem = {
  ulid: string;
  student: { ulid: string; nama_lengkap: string } | undefined;
  nis: string | null;
  classroom: string | null;
  type: "violation" | "merit";
  points: number;
  severity: "ringan" | "sedang" | "berat" | null;
  occurred_on: string;
  description: string;
  evidence_path: boolean;
  rule: { code: string; name: string; category: string } | null;
  recorded_by: string | null;
  status: "pending" | "recorded" | "rejected" | "revoked";
  approval_stage: string | null;
  stage_label: string | null;
  approvals: Approval[];
  created_at: string;
};

const SEVERITY_LABEL = { ringan: "Ringan", sedang: "Sedang", berat: "Berat" } as const;
const STATUS_LABEL: Record<ApprovalItem["status"], string> = {
  pending: "Menunggu",
  recorded: "Berlaku",
  rejected: "Ditolak",
  revoked: "Dibatalkan",
};
const DECISION_LABEL: Record<Approval["decision"], string> = {
  approved: "disetujui",
  rejected: "ditolak",
  skipped: "dilewati",
};

/** Trail line: "Wali Kelas disetujui · Bu Rina — catatan". */
function Trail({ approvals }: { approvals: Approval[] }) {
  if (approvals.length === 0) return null;
  return (
    <ul className="mt-2 space-y-0.5 text-xs text-muted-foreground">
      {approvals.map((a, i) => (
        <li key={i}>
          <span className={a.decision === "rejected" ? "font-semibold text-bad" : a.decision === "approved" ? "text-good" : ""}>
            {a.stage_label} {DECISION_LABEL[a.decision]}
          </span>
          {a.decided_by && ` · ${a.decided_by}`}
          {a.note && ` — ${a.note}`}
        </li>
      ))}
    </ul>
  );
}

function ItemCard({
  item,
  selectable,
  selected,
  onToggle,
  onDecided,
  apiBase,
}: {
  item: ApprovalItem;
  selectable: boolean;
  selected: boolean;
  onToggle: () => void;
  onDecided: () => void;
  apiBase: string;
}) {
  const [busy, setBusy] = useState<"approve" | "reject" | null>(null);
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState("");

  async function approve() {
    setBusy("approve");
    try {
      const { record } = await api.post<{ record: { status: string; approval_stage_label: string | null } }>(
        `${apiBase}/point-approvals/${item.ulid}/approve`,
      );
      toast.success(
        record.status === "recorded"
          ? `Disetujui - poin ${item.student?.nama_lengkap} kini berlaku.`
          : `Disetujui - diteruskan ke ${record.approval_stage_label}.`,
      );
      onDecided();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyetujui.");
    } finally {
      setBusy(null);
    }
  }

  async function reject() {
    setBusy("reject");
    try {
      await api.post(`${apiBase}/point-approvals/${item.ulid}/reject`, { reason: reason.trim() });
      toast.success("Catatan ditolak. Guru pencatat akan melihat alasannya.");
      onDecided();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menolak.");
    } finally {
      setBusy(null);
    }
  }

  const violation = item.type === "violation";

  return (
    <Card className={`p-4 transition-colors ${selected ? "border-primary bg-primary/5" : ""}`}>
      <div className="flex items-start gap-3">
        {selectable && (
          <button
            type="button"
            role="checkbox"
            aria-checked={selected}
            aria-label={`Pilih ${item.student?.nama_lengkap}`}
            onClick={onToggle}
            className={`mt-0.5 grid size-5 shrink-0 place-items-center rounded-md border transition-colors ${
              selected ? "border-primary bg-primary text-primary-foreground" : "border-input bg-card"
            }`}
          >
            {selected && <Check className="size-3.5" strokeWidth={3} />}
          </button>
        )}

        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div className="min-w-0">
              <p className="font-semibold text-foreground">{item.student?.nama_lengkap}</p>
              <p className="text-xs text-muted-foreground">
                {item.classroom ? `Kelas ${item.classroom}` : "Belum ada kelas"}
                {item.nis && ` · NIS ${item.nis}`}
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-1.5">
              {item.severity && (
                <Badge variant={item.severity === "berat" ? "bad" : item.severity === "sedang" ? "warn" : "default"}>
                  {SEVERITY_LABEL[item.severity]}
                </Badge>
              )}
              <Badge variant={violation ? "bad" : "good"} className="font-mono">
                {item.points > 0 ? `+${item.points}` : item.points} poin
              </Badge>
            </div>
          </div>

          <p className="mt-2 text-sm font-medium text-foreground">{item.rule?.name ?? "—"}</p>
          <p className="text-sm text-muted-foreground">{item.description}</p>
          <p className="mt-1 text-xs text-muted-foreground">
            {tanggal(item.occurred_on)} · dicatat {item.recorded_by ?? "—"}
            {item.evidence_path && (
              <>
                {" · "}
                <a
                  href={`${API_BASE}/api/files/points/${item.ulid}/evidence`}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center gap-0.5 font-medium text-primary hover:underline"
                >
                  <Paperclip className="size-3" />
                  bukti
                </a>
              </>
            )}
          </p>

          <Trail approvals={item.approvals} />

          {item.status === "pending" && selectable && (
            <div className="mt-3">
              {rejecting ? (
                <div className="space-y-2">
                  <textarea
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    rows={2}
                    autoFocus
                    placeholder="Alasan penolakan (wajib), mis. siswa sedang izin tugas OSIS"
                    className="w-full rounded-lg border border-input bg-card px-3 py-2 text-sm"
                  />
                  <div className="flex gap-2">
                    <Button size="sm" variant="outline" onClick={reject} disabled={busy !== null || reason.trim().length < 5} className="text-bad">
                      {busy === "reject" && <Loader2 className="size-4 animate-spin" />}
                      Tolak catatan
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setRejecting(false)}>
                      Batal
                    </Button>
                  </div>
                </div>
              ) : (
                <div className="flex gap-2">
                  <Button size="sm" onClick={approve} disabled={busy !== null} className="gap-1.5">
                    {busy === "approve" ? <Loader2 className="size-4 animate-spin" /> : <Check className="size-4" />}
                    Setujui
                  </Button>
                  <Button size="sm" variant="outline" onClick={() => setRejecting(true)} disabled={busy !== null} className="gap-1.5">
                    <X className="size-4" />
                    Tolak
                  </Button>
                </div>
              )}
            </div>
          )}

          {!selectable && (
            <p className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
              <Badge variant={item.status === "recorded" ? "good" : item.status === "rejected" ? "bad" : "default"}>
                {STATUS_LABEL[item.status]}
              </Badge>
              {item.status === "pending" && item.stage_label && `sekarang di ${item.stage_label}`}
            </p>
          )}
        </div>
      </div>
    </Card>
  );
}

/**
 * The approval queue: everything waiting for this user's seat on the ladder
 * (wali kelas in the guru portal, waka/kepsek in the admin portal), plus
 * what they decided in the last 30 days.
 */
export function ApprovalQueue({ apiBase, intro }: { apiBase: "/api/guru" | "/api/admin"; intro: string }) {
  const [data, setData] = useState<{ pending: ApprovalItem[]; decided: ApprovalItem[] } | null>(null);
  const [tab, setTab] = useState<"pending" | "decided">("pending");
  const [onlyBerat, setOnlyBerat] = useState(false);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [bulkBusy, setBulkBusy] = useState(false);

  const load = useCallback(() => {
    return api
      .get<{ pending: ApprovalItem[]; decided: ApprovalItem[] }>(`${apiBase}/point-approvals`)
      .then((d) => {
        setData(d);
        setSelected((prev) => new Set([...prev].filter((u) => d.pending.some((p) => p.ulid === u))));
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat antrean persetujuan."));
  }, [apiBase]);

  useEffect(() => {
    void load();
  }, [load]);

  const refresh = useCallback(() => {
    void load();
    window.dispatchEvent(new Event(APPROVALS_CHANGED));
  }, [load]);

  const pending = useMemo(
    () => (data?.pending ?? []).filter((p) => !onlyBerat || p.severity === "berat"),
    [data, onlyBerat],
  );
  const beratCount = (data?.pending ?? []).filter((p) => p.severity === "berat").length;

  function toggle(ulid: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(ulid)) next.delete(ulid);
      else next.add(ulid);
      return next;
    });
  }

  async function approveSelected() {
    setBulkBusy(true);
    try {
      const res = await api.post<{ approved: number; skipped: number }>(`${apiBase}/point-approvals/approve-many`, {
        ulids: [...selected],
      });
      toast.success(`${res.approved} catatan disetujui${res.skipped ? `, ${res.skipped} dilewati (sudah diputuskan)` : ""}.`);
      setSelected(new Set());
      refresh();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyetujui.");
    } finally {
      setBulkBusy(false);
    }
  }

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">Persetujuan Poin</h1>
        <p className="mt-0.5 text-sm text-muted-foreground">{intro}</p>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div role="tablist" className="inline-flex rounded-lg border border-border p-0.5">
          {(["pending", "decided"] as const).map((t) => (
            <button
              key={t}
              type="button"
              role="tab"
              aria-selected={tab === t}
              onClick={() => setTab(t)}
              className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                tab === t ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground"
              }`}
            >
              {t === "pending" ? `Menunggu (${data?.pending.length ?? 0})` : "Keputusan saya (30 hari)"}
            </button>
          ))}
        </div>

        {tab === "pending" && beratCount > 0 && (
          <button
            type="button"
            onClick={() => setOnlyBerat((v) => !v)}
            className={`rounded-full border px-3 py-1 text-xs font-semibold transition-colors ${
              onlyBerat ? "border-bad bg-bad text-white" : "border-bad/40 text-bad hover:bg-bad/5"
            }`}
          >
            Hanya pelanggaran berat ({beratCount})
          </button>
        )}
      </div>

      {data === null && (
        <div className="space-y-3">
          <Skeleton className="h-32 w-full rounded-xl" />
          <Skeleton className="h-32 w-full rounded-xl" />
        </div>
      )}

      {data && tab === "pending" && (
        <>
          {pending.length === 0 ? (
            <Card className="p-8 text-center text-sm text-muted-foreground">Tidak ada catatan yang menunggu keputusan Anda.</Card>
          ) : (
            <div className="space-y-3">
              {pending.map((item) => (
                <ItemCard
                  key={item.ulid}
                  item={item}
                  selectable
                  selected={selected.has(item.ulid)}
                  onToggle={() => toggle(item.ulid)}
                  onDecided={refresh}
                  apiBase={apiBase}
                />
              ))}
            </div>
          )}

          {pending.length > 0 && (
            <div className="sticky bottom-3 z-20 rounded-xl border border-border bg-card/95 p-3 shadow-sm backdrop-blur-sm">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <button
                  type="button"
                  onClick={() =>
                    setSelected(selected.size === pending.length ? new Set() : new Set(pending.map((p) => p.ulid)))
                  }
                  className="text-sm font-medium text-primary hover:underline"
                >
                  {selected.size === pending.length ? "Batalkan pilihan" : `Pilih semua (${pending.length})`}
                </button>
                <Button onClick={approveSelected} disabled={bulkBusy || selected.size === 0} className="gap-1.5 font-bold">
                  {bulkBusy ? <Loader2 className="size-4 animate-spin" /> : <CheckCheck className="size-4" />}
                  Setujui terpilih ({selected.size})
                </Button>
              </div>
            </div>
          )}
        </>
      )}

      {data && tab === "decided" && (
        <div className="space-y-3">
          {data.decided.length === 0 ? (
            <Card className="p-8 text-center text-sm text-muted-foreground">Belum ada keputusan dalam 30 hari terakhir.</Card>
          ) : (
            data.decided.map((item) => (
              <ItemCard
                key={item.ulid}
                item={item}
                selectable={false}
                selected={false}
                onToggle={() => {}}
                onDecided={refresh}
                apiBase={apiBase}
              />
            ))
          )}
        </div>
      )}
    </div>
  );
}

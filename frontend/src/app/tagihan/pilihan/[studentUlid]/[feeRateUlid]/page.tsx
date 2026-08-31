"use client";

import { use, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowLeft, CheckCircle2 } from "lucide-react";
import { toast } from "sonner";
import { WaliShell } from "@/components/layout/wali-shell";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useRequireRole } from "@/lib/auth/use-require-role";
import { rupiah } from "@/lib/format";
import type { FeeComponentOption, FeeSelectionEntry } from "@/lib/types/billing";

type Picks = Record<string, { included: boolean; size_option: string | null }>;

export default function FeeSelectionFormPage({
  params,
}: {
  params: Promise<{ studentUlid: string; feeRateUlid: string }>;
}) {
  const { studentUlid, feeRateUlid } = use(params);
  const { user, loading } = useRequireRole("orangtua");
  const router = useRouter();

  const [entry, setEntry] = useState<FeeSelectionEntry | null>(null);
  const [studentName, setStudentName] = useState("");
  const [picks, setPicks] = useState<Picks>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (user?.role !== "orangtua") return;

    api
      .get<{ fee_selections: FeeSelectionEntry[] }>(`/api/wali/students/${studentUlid}/fee-selections`)
      .then((d) => {
        const found = d.fee_selections.find((e) => e.fee_rate.ulid === feeRateUlid);
        if (!found) {
          toast.error("Pilihan ini tidak ditemukan.");
          router.replace("/tagihan/pilihan");
          return;
        }
        setEntry(found);

        const initial: Picks = {};
        for (const c of found.fee_rate.components) {
          const existing = found.selection?.items.find((i) => i.fee_component_ulid === c.ulid);
          initial[c.ulid] = {
            included: existing?.included ?? !c.is_optional,
            size_option: existing?.size_option ?? (c.size_options[0] ?? null),
          };
        }
        setPicks(initial);
      })
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat pilihan."));

    api
      .get<{ students: { ulid: string; nama_lengkap: string; nama_panggilan: string | null }[] }>("/api/wali/students")
      .then((d) => {
        const s = d.students.find((s) => s.ulid === studentUlid);
        if (s) setStudentName(s.nama_panggilan ?? s.nama_lengkap);
      });
  }, [user, studentUlid, feeRateUlid, router]);

  const total = useMemo(() => {
    if (!entry) return 0;
    return entry.fee_rate.components.reduce((sum, c) => {
      const pick = picks[c.ulid];
      if (!pick?.included) return sum;
      return sum + c.amount * c.default_qty;
    }, 0);
  }, [entry, picks]);

  const locked = !!entry?.selection?.locked_at;

  async function submit() {
    if (!entry) return;
    setSubmitting(true);

    try {
      await api.post(`/api/wali/students/${studentUlid}/fee-selections`, {
        fee_rate_ulid: entry.fee_rate.ulid,
        items: entry.fee_rate.components.map((c) => ({
          component_ulid: c.ulid,
          included: picks[c.ulid]?.included ?? !c.is_optional,
          size_option: c.has_size_option ? picks[c.ulid]?.size_option : null,
        })),
      });
      toast.success("Pilihan berhasil disimpan.");
      router.push("/tagihan/pilihan");
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyimpan pilihan.");
    } finally {
      setSubmitting(false);
    }
  }

  if (loading || !user || entry === null) {
    return (
      <WaliShell>
        <Skeleton className="h-64 w-full" />
      </WaliShell>
    );
  }

  return (
    <WaliShell>
      <div className="space-y-6 pb-28">
        <div>
          <Link href="/tagihan/pilihan" className="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground">
            <ArrowLeft className="size-4" />
            <span>Kembali</span>
          </Link>
          <h1 className="text-2xl font-extrabold tracking-tight text-foreground mt-2">{entry.fee_type.name}</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Untuk ananda <strong className="text-foreground">{studentName}</strong>
            {locked && (
              <>
                {" · "}
                <Badge variant="good">Sudah ditagih, tidak bisa diubah</Badge>
              </>
            )}
          </p>
        </div>

        <div className="grid grid-cols-1 gap-3">
          {entry.fee_rate.components.map((c: FeeComponentOption) => {
            const pick = picks[c.ulid];

            return (
              <Card key={c.ulid} className="p-5">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex items-start gap-3">
                    {c.is_optional ? (
                      <input
                        type="checkbox"
                        checked={pick?.included ?? false}
                        disabled={locked}
                        onChange={(e) => setPicks((p) => ({ ...p, [c.ulid]: { ...p[c.ulid], included: e.target.checked } }))}
                        className="mt-1 size-4 rounded border-input text-primary"
                      />
                    ) : (
                      <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-good" />
                    )}
                    <div>
                      <p className="font-bold text-foreground text-sm">{c.name}</p>
                      <p className="text-xs text-muted-foreground">
                        {c.is_optional ? "Opsional" : "Wajib"} · {rupiah(c.amount)}
                      </p>
                    </div>
                  </div>
                  <span className="tabular font-bold text-primary text-sm">{rupiah(c.amount)}</span>
                </div>

                {c.has_size_option && pick?.included && (
                  <div className="mt-3 border-t border-border/60 pt-3">
                    <p className="text-xs font-semibold text-muted-foreground mb-1.5">Pilih ukuran</p>
                    <div className="flex flex-wrap gap-2">
                      {c.size_options.map((size) => (
                        <button
                          key={size}
                          type="button"
                          disabled={locked}
                          onClick={() => setPicks((p) => ({ ...p, [c.ulid]: { ...p[c.ulid], size_option: size } }))}
                          className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ${
                            pick?.size_option === size
                              ? "border-primary bg-primary text-primary-foreground"
                              : "border-input bg-card text-foreground hover:border-primary/50"
                          }`}
                        >
                          {size}
                        </button>
                      ))}
                    </div>
                  </div>
                )}
              </Card>
            );
          })}
        </div>

        {!locked && (
          <div className="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-card/95 backdrop-blur-md shadow-2xl">
            <div className="mx-auto flex max-w-3xl items-center justify-between px-4 py-3.5">
              <p className="tabular font-black text-foreground text-base">
                Total: <span className="text-primary">{rupiah(total)}</span>
              </p>
              <Button onClick={submit} disabled={submitting} size="lg" className="font-bold">
                {submitting ? "Menyimpan…" : "Simpan Pilihan"}
              </Button>
            </div>
          </div>
        )}
      </div>
    </WaliShell>
  );
}

"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";

type Rule = {
  ulid: string; school_unit: string | null; code: string; name: string;
  type: "violation" | "merit"; category: string; points: number;
  requires_evidence: boolean; is_active: boolean;
};
type Unit = { ulid: string; code: string; label: string };

function NewRuleForm({ units, isCentral, onCreated }: { units: Unit[]; isCentral: boolean; onCreated: () => void }) {
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [type, setType] = useState<"violation" | "merit">("violation");
  const [category, setCategory] = useState("");
  const [points, setPoints] = useState("10");
  const [requiresEvidence, setRequiresEvidence] = useState(false);
  const [unitCode, setUnitCode] = useState(""); // "" = school-wide, central only
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      await api.post("/api/admin/point-rules", {
        code, name, type, category, points: Number(points),
        requires_evidence: requiresEvidence,
        school_unit_code: isCentral && unitCode ? unitCode : undefined,
      });
      toast.success("Aturan ditambahkan.");
      setCode(""); setName(""); setCategory(""); setPoints("10"); setRequiresEvidence(false);
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal menyimpan aturan.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
      <div className="flex flex-col gap-1.5">
        <Label>Kode</Label>
        <Input value={code} onChange={(e) => setCode(e.target.value)} required className="w-28" placeholder="TL-02" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Nama</Label>
        <Input value={name} onChange={(e) => setName(e.target.value)} required className="w-56" placeholder="Tidak mengerjakan PR" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Kategori</Label>
        <Input value={category} onChange={(e) => setCategory(e.target.value)} required className="w-36" placeholder="Kedisiplinan" />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Jenis</Label>
        <select value={type} onChange={(e) => setType(e.target.value as "violation" | "merit")} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
          <option value="violation">Pelanggaran</option>
          <option value="merit">Penghargaan</option>
        </select>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Poin</Label>
        <Input value={points} onChange={(e) => setPoints(e.target.value)} type="number" min={1} required className="w-24" />
      </div>
      {isCentral && (
        <div className="flex flex-col gap-1.5">
          <Label>Cakupan</Label>
          <select value={unitCode} onChange={(e) => setUnitCode(e.target.value)} className="h-10 rounded-lg border border-input bg-card px-3 text-sm">
            <option value="">Seluruh sekolah</option>
            {units.map((u) => <option key={u.code} value={u.code}>{u.label}</option>)}
          </select>
        </div>
      )}
      <label className="flex items-center gap-2 pb-2.5 text-sm">
        <input type="checkbox" checked={requiresEvidence} onChange={(e) => setRequiresEvidence(e.target.checked)} />
        Wajib bukti
      </label>
      <Button type="submit" disabled={submitting}>{submitting ? "Menyimpan…" : "Tambah"}</Button>
      {error && <p className="w-full rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
    </form>
  );
}

export default function PointRulesPage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [rules, setRules] = useState<Rule[] | null>(null);
  const [units, setUnits] = useState<Unit[]>([]);

  function load() {
    api
      .get<{ rules: Rule[] }>("/api/admin/point-rules")
      .then((d) => setRules(d.rules))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat aturan poin."));
  }

  useEffect(() => {
    load();
    if (isCentral) {
      api
        .get<{ school_units: Unit[] }>("/api/admin/school-units")
        .then((d) => setUnits(d.school_units))
        .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar unit."));
    }
  }, [isCentral]);

  async function toggleActive(rule: Rule) {
    try {
      await api.patch(`/api/admin/point-rules/${rule.ulid}`, { is_active: !rule.is_active });
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengubah status aturan.");
    }
  }

  async function remove(rule: Rule) {
    try {
      await api.delete(`/api/admin/point-rules/${rule.ulid}`);
      toast.success("Aturan dihapus.");
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus.");
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <Link href="/admin/poin" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" />
        Poin siswa
      </Link>

      <div>
        <h1 className="text-xl font-bold tracking-tight">Aturan poin</h1>
        <p className="mt-1 text-sm text-muted-foreground">Katalog yang dipakai guru saat mencatat poin.</p>
      </div>

      <Card className="p-5">
        {rules === null ? <Skeleton className="h-10 w-full" /> : <NewRuleForm units={units} isCentral={isCentral} onCreated={load} />}
      </Card>

      <div className="flex flex-col gap-2">
        {rules === null && <Skeleton className="h-40 w-full" />}
        {rules?.map((rule) => (
          <Card key={rule.ulid} className={`flex flex-wrap items-center justify-between gap-3 p-4 ${!rule.is_active ? "opacity-50" : ""}`}>
            <div>
              <p className="font-medium">{rule.name} <span className="text-xs text-muted-foreground">{rule.code}</span></p>
              <p className="text-sm text-muted-foreground">
                {rule.category} · {rule.school_unit ?? "Seluruh sekolah"}
                {rule.requires_evidence && " · wajib bukti"}
              </p>
            </div>
            <div className="flex items-center gap-2">
              <Badge variant={rule.type === "violation" ? "bad" : "good"}>
                {rule.type === "violation" ? "−" : "+"}{rule.points}
              </Badge>
              <Button size="sm" variant="ghost" onClick={() => toggleActive(rule)}>
                {rule.is_active ? "Nonaktifkan" : "Aktifkan"}
              </Button>
              <Button size="sm" variant="ghost" onClick={() => remove(rule)}>
                <Trash2 className="size-4" />
              </Button>
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}

"use client";

import { useCallback, useEffect, useState } from "react";
import { Search, Star, Trash2, Wallet } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { api, ApiError } from "@/lib/api";

type GuardianRow = {
  ulid: string;
  nama: string;
  hubungan: string;
  no_hp: string | null;
  has_account: boolean;
  account_active: boolean;
  relationship: "ayah" | "ibu" | "wali" | null;
  is_primary: boolean;
  is_billing_contact: boolean;
};

const REL = ["ayah", "ibu", "wali"] as const;

/**
 * Guardian <-> student links for one student (audit 6 Okt 2026 #9): search
 * and link an existing contact (e.g. a parent account just made on the Users
 * page), add a new contact, switch the primary / billing contact, unlink.
 */
export function GuardianLinks({ studentUlid, onChanged }: { studentUlid: string; onChanged?: () => void }) {
  const [rows, setRows] = useState<GuardianRow[] | null>(null);
  const [q, setQ] = useState("");
  const [results, setResults] = useState<GuardianRow[]>([]);
  const [relationship, setRelationship] = useState<(typeof REL)[number]>("ibu");
  const [newName, setNewName] = useState("");
  const [newPhone, setNewPhone] = useState("");
  const [busy, setBusy] = useState(false);

  const base = `/api/admin/students/${studentUlid}/guardians`;

  const load = useCallback(() => {
    api
      .get<{ guardians: GuardianRow[] }>(base)
      .then((d) => setRows(d.guardians))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat wali."));
  }, [base]);

  useEffect(load, [load]);

  function changed() {
    load();
    onChanged?.();
  }

  async function search() {
    if (q.trim().length < 3) {
      toast.error("Ketik minimal 3 huruf, atau nomor HP / email lengkap.");
      return;
    }
    try {
      const d = await api.get<{ guardians: GuardianRow[] }>(`/api/admin/guardians/search?q=${encodeURIComponent(q.trim())}`);
      setResults(d.guardians);
      if (d.guardians.length === 0) toast.info("Tidak ditemukan — tambahkan sebagai kontak baru di bawah.");
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mencari.");
    }
  }

  async function link(body: Record<string, unknown>) {
    setBusy(true);
    try {
      await api.post(base, { relationship, ...body });
      toast.success("Wali ditautkan.");
      setResults([]);
      setQ("");
      setNewName("");
      setNewPhone("");
      changed();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menautkan wali.");
    } finally {
      setBusy(false);
    }
  }

  async function patch(row: GuardianRow, body: Record<string, unknown>) {
    try {
      await api.patch(`${base}/${row.ulid}`, body);
      changed();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memperbarui.");
    }
  }

  async function unlink(row: GuardianRow) {
    if (!window.confirm(`Lepas tautan ${row.nama} dari siswa ini?`)) return;
    try {
      await api.delete(`${base}/${row.ulid}`);
      toast.success("Tautan dilepas.");
      changed();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal melepas tautan.");
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1.5">
        {rows?.length === 0 && <p className="text-sm text-muted-foreground">Belum ada wali tertaut.</p>}
        {rows?.map((row) => (
          <div key={row.ulid} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border p-2.5 text-sm">
            <div>
              <p className="font-medium">
                {row.nama}
                <span className="ml-2 text-xs font-normal text-muted-foreground">
                  {row.no_hp ?? "tanpa HP"} · {row.has_account ? (row.account_active ? "akun aktif" : "akun belum aktivasi") : "tanpa akun portal"}
                </span>
              </p>
              <div className="mt-1 flex flex-wrap gap-1">
                {row.is_primary && <Badge className="bg-primary/10 text-primary">Utama</Badge>}
                {row.is_billing_contact && <Badge className="bg-good/10 text-good">Kontak tagihan</Badge>}
              </div>
            </div>
            <div className="flex items-center gap-1">
              <select
                value={row.relationship ?? "wali"}
                onChange={(e) => patch(row, { relationship: e.target.value })}
                className="h-8 rounded-lg border border-input bg-card px-1.5 text-xs"
                aria-label="Hubungan"
              >
                {REL.map((r) => (
                  <option key={r} value={r}>
                    {r}
                  </option>
                ))}
              </select>
              {!row.is_primary && (
                <Button size="sm" variant="ghost" title="Jadikan wali utama" onClick={() => patch(row, { is_primary: true })}>
                  <Star className="size-3.5" />
                </Button>
              )}
              {!row.is_billing_contact && (
                <Button size="sm" variant="ghost" title="Jadikan kontak tagihan" onClick={() => patch(row, { is_billing_contact: true })}>
                  <Wallet className="size-3.5" />
                </Button>
              )}
              <Button size="sm" variant="ghost" title="Lepas tautan" onClick={() => unlink(row)}>
                <Trash2 className="size-3.5 text-destructive" />
              </Button>
            </div>
          </div>
        ))}
      </div>

      <div className="flex flex-col gap-2 rounded-lg border border-border p-3">
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          Tautkan sebagai
          <select
            value={relationship}
            onChange={(e) => setRelationship(e.target.value as (typeof REL)[number])}
            className="h-8 rounded-lg border border-input bg-card px-1.5 text-xs text-foreground"
          >
            {REL.map((r) => (
              <option key={r} value={r}>
                {r}
              </option>
            ))}
          </select>
        </div>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            search();
          }}
          className="flex gap-2"
        >
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari nama, nomor HP, atau email wali" className="h-9" />
          <Button type="submit" size="sm" variant="outline" className="gap-1">
            <Search className="size-3.5" /> Cari
          </Button>
        </form>
        {results.map((r) => (
          <div key={r.ulid} className="flex items-center justify-between gap-2 rounded-lg bg-muted/30 px-3 py-1.5 text-sm">
            <span>
              {r.nama}
              <span className="ml-2 text-xs text-muted-foreground">
                {r.no_hp ?? "tanpa HP"} · {r.has_account ? "punya akun" : "tanpa akun"}
              </span>
            </span>
            <Button size="sm" disabled={busy} onClick={() => link({ guardian_ulid: r.ulid })}>
              Tautkan
            </Button>
          </div>
        ))}
        <div className="flex flex-wrap gap-2 border-t border-border/60 pt-2">
          <Input value={newName} onChange={(e) => setNewName(e.target.value)} placeholder="Atau kontak baru: nama" className="h-9 min-w-40 flex-1" />
          <Input value={newPhone} onChange={(e) => setNewPhone(e.target.value)} placeholder="No. HP (opsional)" className="h-9 w-44" inputMode="tel" />
          <Button size="sm" variant="outline" disabled={busy || !newName.trim()} onClick={() => link({ nama: newName.trim(), no_hp: newPhone.trim() || undefined })}>
            Tambah
          </Button>
        </div>
        <p className="text-xs text-muted-foreground">
          Akun portal wali dibuat di halaman Pengguna (peran Orang Tua), lalu tautkan di sini lewat pencarian.
        </p>
      </div>
    </div>
  );
}

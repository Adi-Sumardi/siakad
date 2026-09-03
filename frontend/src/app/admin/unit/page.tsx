"use client";

import { useEffect, useState } from "react";
import { Building2, Check, Edit2, Plus, Power, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";

type UnitItem = {
  ulid: string;
  code: string;
  label: string;
  jenjang_group: string;
  is_active: boolean;
  sort_order: number;
  student_count: number;
};

const JENJANG_OPTIONS = [
  { value: "ra", label: "RA" },
  { value: "pg", label: "Playgroup" },
  { value: "tk", label: "TK" },
  { value: "sd", label: "SD" },
  { value: "smp", label: "SMP" },
  { value: "sma", label: "SMA" },
];

function jenjangLabel(value: string) {
  return JENJANG_OPTIONS.find((j) => j.value === value)?.label.toUpperCase() ?? value.toUpperCase();
}

/**
 * The unit master, editable here instead of the raw SQL statement that used
 * to be the only way to add, rename, or retire a campus - which is exactly
 * how school_units ended up with two seedings of the same eight campuses
 * (see database/migrations/2026_09_02_000001_dedupe_school_units.php).
 */
export default function AdminSchoolUnitsPage() {
  const [units, setUnits] = useState<UnitItem[] | null>(null);
  const [loading, setLoading] = useState(true);

  const [showCreateModal, setShowCreateModal] = useState(false);
  const [editingUnit, setEditingUnit] = useState<UnitItem | null>(null);
  const [deletingUnit, setDeletingUnit] = useState<UnitItem | null>(null);
  const [deleteConfirmInput, setDeleteConfirmInput] = useState("");

  const [formCode, setFormCode] = useState("");
  const [formLabel, setFormLabel] = useState("");
  const [formJenjang, setFormJenjang] = useState("sd");
  const [formSortOrder, setFormSortOrder] = useState("0");
  const [formIsActive, setFormIsActive] = useState(true);
  const [submitting, setSubmitting] = useState(false);

  function loadUnits() {
    setLoading(true);
    api
      .get<{ school_units: UnitItem[] }>("/api/admin/school-units/manage")
      .then((d) => setUnits(d.school_units))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat data unit."))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    loadUnits();
  }, []);

  function openCreate() {
    setFormCode("");
    setFormLabel("");
    setFormJenjang("sd");
    setFormSortOrder(String((units?.length ?? 0)));
    setFormIsActive(true);
    setShowCreateModal(true);
  }

  function openEdit(u: UnitItem) {
    setEditingUnit(u);
    setFormCode(u.code);
    setFormLabel(u.label);
    setFormJenjang(u.jenjang_group);
    setFormSortOrder(String(u.sort_order));
    setFormIsActive(u.is_active);
  }

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      await api.post("/api/admin/school-units", {
        code: formCode,
        label: formLabel,
        jenjang_group: formJenjang,
        sort_order: Number(formSortOrder) || 0,
        is_active: formIsActive,
      });
      toast.success("Unit sekolah berhasil ditambahkan.");
      setShowCreateModal(false);
      loadUnits();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menambahkan unit.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleUpdate(e: React.FormEvent) {
    e.preventDefault();
    if (!editingUnit) return;
    setSubmitting(true);
    try {
      await api.patch(`/api/admin/school-units/${editingUnit.ulid}`, {
        code: formCode,
        label: formLabel,
        jenjang_group: formJenjang,
        sort_order: Number(formSortOrder) || 0,
        is_active: formIsActive,
      });
      toast.success("Unit sekolah berhasil diperbarui.");
      setEditingUnit(null);
      loadUnits();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memperbarui unit.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete() {
    if (!deletingUnit) return;
    setSubmitting(true);
    try {
      await api.delete(`/api/admin/school-units/${deletingUnit.ulid}`);
      toast.success("Unit sekolah berhasil dihapus.");
      setDeletingUnit(null);
      loadUnits();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus unit.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleToggleActive(u: UnitItem) {
    try {
      await api.patch(`/api/admin/school-units/${u.ulid}`, {
        code: u.code, label: u.label, jenjang_group: u.jenjang_group, sort_order: u.sort_order,
        is_active: !u.is_active,
      });
      toast.success(u.is_active ? "Unit dinonaktifkan." : "Unit diaktifkan kembali.");
      loadUnits();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengubah status unit.");
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Manajemen Unit Sekolah</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Tambah, ubah, atau nonaktifkan unit/campus - tidak lagi lewat perintah SQL manual.
          </p>
        </div>

        <Button onClick={openCreate} className="gap-2 font-bold shadow-xs">
          <Plus className="size-4" />
          <span>Tambah Unit</span>
        </Button>
      </div>

      <Card className="border-border/80 shadow-xs overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-muted/40 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
              <tr>
                <th className="px-5 py-3.5">Unit</th>
                <th className="px-5 py-3.5">Kode</th>
                <th className="px-5 py-3.5">Jenjang</th>
                <th className="px-5 py-3.5">Siswa</th>
                <th className="px-5 py-3.5">Status</th>
                <th className="px-5 py-3.5 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border/60">
              {loading && (
                <tr>
                  <td colSpan={6} className="p-5">
                    <Skeleton className="h-20 w-full rounded-xl" />
                  </td>
                </tr>
              )}

              {!loading && units?.length === 0 && (
                <tr>
                  <td colSpan={6} className="p-8 text-center text-muted-foreground">
                    Belum ada unit sekolah.
                  </td>
                </tr>
              )}

              {!loading &&
                units?.map((u) => (
                  <tr key={u.ulid} className="hover:bg-accent/30 transition-colors">
                    <td className="px-5 py-4">
                      <div className="flex items-center gap-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                          <Building2 className="size-4" />
                        </span>
                        <p className="font-bold text-foreground text-sm">{u.label}</p>
                      </div>
                    </td>
                    <td className="px-5 py-4 font-mono text-muted-foreground">{u.code}</td>
                    <td className="px-5 py-4">
                      <Badge variant="default" className="font-bold">{jenjangLabel(u.jenjang_group)}</Badge>
                    </td>
                    <td className="px-5 py-4 text-muted-foreground">{u.student_count} siswa</td>
                    <td className="px-5 py-4">
                      {u.is_active ? (
                        <Badge variant="good" className="gap-1 font-semibold">
                          <Check className="size-3" /> Aktif
                        </Badge>
                      ) : (
                        <Badge variant="bad" className="gap-1 font-semibold">
                          <X className="size-3" /> Nonaktif
                        </Badge>
                      )}
                    </td>
                    <td className="px-5 py-4 text-right">
                      <div className="flex items-center justify-end gap-1.5">
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => handleToggleActive(u)}
                          title={u.is_active ? "Nonaktifkan unit" : "Aktifkan unit"}
                          className={`h-8 px-2.5 text-xs font-semibold gap-1 ${u.is_active ? "" : "text-good border-good/40"}`}
                        >
                          <Power className="size-3.5" />
                          <span>{u.is_active ? "Nonaktifkan" : "Aktifkan"}</span>
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => openEdit(u)}
                          className="h-8 px-2.5 text-xs font-semibold gap-1"
                        >
                          <Edit2 className="size-3.5" />
                          <span>Edit</span>
                        </Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => { setDeletingUnit(u); setDeleteConfirmInput(""); }}
                          className="h-8 px-2 text-destructive hover:bg-destructive/10 hover:text-destructive"
                        >
                          <Trash2 className="size-3.5" />
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      </Card>

      {/* MODAL: TAMBAH UNIT */}
      {showCreateModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
          <Card className="w-full max-w-lg p-6 border-border shadow-2xl space-y-4 my-8">
            <div className="flex items-center justify-between border-b border-border pb-3">
              <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
                <Plus className="size-5 text-primary" />
                <span>Tambah Unit Sekolah</span>
              </h2>
              <button onClick={() => setShowCreateModal(false)} className="text-muted-foreground hover:text-foreground">
                <X className="size-5" />
              </button>
            </div>

            <form onSubmit={handleCreate} className="space-y-3.5 text-xs">
              <div>
                <Label className="text-xs">Nama Unit</Label>
                <Input value={formLabel} onChange={(e) => setFormLabel(e.target.value)} required placeholder="misal: SD Islam Al Azhar 13" className="mt-1" />
              </div>
              <div>
                <Label className="text-xs">Kode Unit (unik, tanpa spasi)</Label>
                <Input value={formCode} onChange={(e) => setFormCode(e.target.value)} required placeholder="misal: sd-13" className="mt-1" />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <Label className="text-xs">Jenjang</Label>
                  <select
                    value={formJenjang}
                    onChange={(e) => setFormJenjang(e.target.value)}
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-2xs"
                  >
                    {JENJANG_OPTIONS.map((j) => (
                      <option key={j.value} value={j.value}>{j.label}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <Label className="text-xs">Urutan Tampil</Label>
                  <Input type="number" min={0} value={formSortOrder} onChange={(e) => setFormSortOrder(e.target.value)} className="mt-1" />
                </div>
              </div>
              <div className="flex items-center gap-2 pt-1">
                <input
                  type="checkbox"
                  id="is_active_create"
                  checked={formIsActive}
                  onChange={(e) => setFormIsActive(e.target.checked)}
                  className="size-4 rounded border-input"
                />
                <Label htmlFor="is_active_create" className="text-xs font-semibold cursor-pointer">Unit Aktif</Label>
              </div>
              <div className="flex justify-end gap-2 border-t border-border pt-4">
                <Button type="button" variant="ghost" onClick={() => setShowCreateModal(false)} disabled={submitting}>Batal</Button>
                <Button type="submit" disabled={submitting} className="font-bold shadow-xs">
                  {submitting ? "Menyimpan…" : "Simpan Unit"}
                </Button>
              </div>
            </form>
          </Card>
        </div>
      )}

      {/* MODAL: EDIT UNIT */}
      {editingUnit && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
          <Card className="w-full max-w-lg p-6 border-border shadow-2xl space-y-4 my-8">
            <div className="flex items-center justify-between border-b border-border pb-3">
              <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
                <Edit2 className="size-5 text-primary" />
                <span>Edit Unit Sekolah</span>
              </h2>
              <button onClick={() => setEditingUnit(null)} className="text-muted-foreground hover:text-foreground">
                <X className="size-5" />
              </button>
            </div>

            <form onSubmit={handleUpdate} className="space-y-3.5 text-xs">
              <div>
                <Label className="text-xs">Nama Unit</Label>
                <Input value={formLabel} onChange={(e) => setFormLabel(e.target.value)} required className="mt-1" />
              </div>
              <div>
                <Label className="text-xs">Kode Unit</Label>
                <Input value={formCode} onChange={(e) => setFormCode(e.target.value)} required className="mt-1" />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <Label className="text-xs">Jenjang</Label>
                  <select
                    value={formJenjang}
                    onChange={(e) => setFormJenjang(e.target.value)}
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-2xs"
                  >
                    {JENJANG_OPTIONS.map((j) => (
                      <option key={j.value} value={j.value}>{j.label}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <Label className="text-xs">Urutan Tampil</Label>
                  <Input type="number" min={0} value={formSortOrder} onChange={(e) => setFormSortOrder(e.target.value)} className="mt-1" />
                </div>
              </div>
              <div className="flex items-center gap-2 pt-1">
                <input
                  type="checkbox"
                  id="is_active_edit"
                  checked={formIsActive}
                  onChange={(e) => setFormIsActive(e.target.checked)}
                  className="size-4 rounded border-input"
                />
                <Label htmlFor="is_active_edit" className="text-xs font-semibold cursor-pointer">Unit Aktif</Label>
              </div>
              <div className="flex justify-end gap-2 border-t border-border pt-4">
                <Button type="button" variant="ghost" onClick={() => setEditingUnit(null)} disabled={submitting}>Batal</Button>
                <Button type="submit" disabled={submitting} className="font-bold shadow-xs">
                  {submitting ? "Menyimpan…" : "Simpan Perubahan"}
                </Button>
              </div>
            </form>
          </Card>
        </div>
      )}

      {/* MODAL: KONFIRMASI HAPUS */}
      {deletingUnit && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4">
          <Card className="w-full max-w-md p-6 border-border shadow-2xl space-y-4">
            <div className="flex items-center gap-3 text-destructive">
              <div className="size-10 rounded-full bg-destructive/10 grid place-items-center">
                <Trash2 className="size-5" />
              </div>
              <div>
                <h3 className="font-bold text-base text-foreground">Hapus Unit Sekolah</h3>
                <p className="text-xs text-muted-foreground">Tindakan ini tidak dapat dibatalkan.</p>
              </div>
            </div>

            <p className="text-xs text-muted-foreground">
              Apakah Anda yakin ingin menghapus unit <strong className="text-foreground">{deletingUnit.label}</strong>?
              {deletingUnit.student_count > 0 && (
                <span className="block mt-1.5 text-destructive font-semibold">
                  Unit ini masih punya {deletingUnit.student_count} siswa terdaftar - penghapusan akan ditolak sampai siswanya dipindahkan.
                </span>
              )}
            </p>

            <div>
              <Label className="text-xs">
                Ketik <strong className="font-mono text-foreground">{deletingUnit.code}</strong> untuk konfirmasi
              </Label>
              <Input
                value={deleteConfirmInput}
                onChange={(e) => setDeleteConfirmInput(e.target.value)}
                placeholder={deletingUnit.code}
                className="mt-1"
                autoFocus
              />
            </div>

            <div className="flex justify-end gap-2 border-t border-border pt-4">
              <Button variant="ghost" size="sm" onClick={() => setDeletingUnit(null)} disabled={submitting}>Batal</Button>
              <Button
                variant="destructive"
                size="sm"
                onClick={handleDelete}
                disabled={submitting || deleteConfirmInput.trim() !== deletingUnit.code}
                className="font-bold"
              >
                {submitting ? "Menghapus…" : "Ya, Hapus Unit"}
              </Button>
            </div>
          </Card>
        </div>
      )}
    </div>
  );
}

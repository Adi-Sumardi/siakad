"use client";

import { useEffect, useRef, useState } from "react";
import {
  Check,
  Download,
  Edit2,
  FileUp,
  Mail,
  Phone,
  Plus,
  RefreshCw,
  Search,
  Shield,
  ShieldAlert,
  ShieldCheck,
  Trash2,
  Upload,
  UserCheck,
  UserPlus,
  Users,
  UserX,
  X,
} from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError, API_BASE } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";
import { tanggalWaktu } from "@/lib/format";

type UserItem = {
  ulid: string;
  name: string;
  email: string | null;
  phone: string | null;
  role: "admin" | "admin_unit" | "guru" | "orangtua";
  role_label: string;
  school_unit: { ulid: string; code: string; label: string } | null;
  is_active: boolean;
  activated_at: string | null;
  last_login_at: string | null;
  created_at: string;
};

type SchoolUnit = { ulid: string; code: string; label: string };

export default function UserManagementPage() {
  const { user } = useAuth();
  // A per-unit admin can only onboard guru accounts for their own unit -
  // the backend forces both, this only keeps the form honest about it.
  const isUnitAdmin = user?.role === "admin_unit";

  const [users, setUsers] = useState<UserItem[] | null>(null);
  const [units, setUnits] = useState<SchoolUnit[]>([]);
  const [loading, setLoading] = useState(true);

  // Guru CSV import
  const [importFile, setImportFile] = useState<File | null>(null);
  const [importing, setImporting] = useState(false);
  const [importResult, setImportResult] = useState<{ message: string; imported: number; updated: number; errors: string[] } | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  // Filters
  const [search, setSearch] = useState("");
  const [roleFilter, setRoleFilter] = useState("");
  const [unitFilter, setUnitFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");

  // Modals
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [editingUser, setEditingUser] = useState<UserItem | null>(null);
  const [deletingUser, setDeletingUser] = useState<UserItem | null>(null);

  // Form states
  const [formName, setFormName] = useState("");
  const [formEmail, setFormEmail] = useState("");
  const [formPhone, setFormPhone] = useState("");
  const [formRole, setFormRole] = useState<"admin" | "admin_unit" | "guru" | "orangtua">("admin_unit");
  const [formUnitUlid, setFormUnitUlid] = useState("");
  const [formIsActive, setFormIsActive] = useState(true);
  const [submitting, setSubmitting] = useState(false);

  function loadUsers() {
    setLoading(true);
    const params = new URLSearchParams();
    if (search) params.set("search", search);
    if (roleFilter) params.set("role", roleFilter);
    if (unitFilter) params.set("unit", unitFilter);
    if (statusFilter !== "") params.set("is_active", statusFilter);

    api
      .get<{ users: { data: UserItem[] } }>(`/api/admin/users?${params.toString()}`)
      .then((d) => setUsers(d.users.data))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat pengguna."))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    loadUsers();
    api
      .get<{ school_units: SchoolUnit[] }>("/api/admin/school-units")
      .then((d) => setUnits(d.school_units))
      .catch(() => {});
  }, [roleFilter, unitFilter, statusFilter]); // eslint-disable-line react-hooks/exhaustive-deps

  function handleSearchSubmit(e: React.FormEvent) {
    e.preventDefault();
    loadUsers();
  }

  function openCreate() {
    setFormName("");
    setFormEmail("");
    setFormPhone("");
    setFormRole(isUnitAdmin ? "guru" : "admin_unit");
    setFormUnitUlid(isUnitAdmin ? (user?.school_unit?.ulid ?? "") : (units[0]?.ulid ?? ""));
    setFormIsActive(true);
    setShowCreateModal(true);
  }

  async function downloadApiFile(path: string, filename: string) {
    try {
      const res = await fetch(`${API_BASE}${path}`, { credentials: "include" });
      if (!res.ok) throw new Error("Gagal mengunduh file.");

      const blob = await res.blob();
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);
    } catch {
      toast.error("Gagal mengunduh template. Pastikan sesi Anda masih aktif.");
    }
  }

  async function handleImportUsers(e: React.FormEvent) {
    e.preventDefault();
    if (!importFile) {
      toast.error("Silakan pilih file CSV terlebih dahulu.");
      return;
    }

    setImporting(true);
    setImportResult(null);

    const form = new FormData();
    form.set("file", importFile);

    try {
      const res = await api.post<{
        message: string;
        imported_count: number;
        updated_count: number;
        errors: string[];
      }>("/api/admin/import/users", form);

      toast.success(res.message);
      setImportResult({
        message: res.message,
        imported: res.imported_count,
        updated: res.updated_count,
        errors: res.errors || [],
      });
      loadUsers();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengimpor akun pengguna.");
    } finally {
      setImporting(false);
    }
  }

  function openEdit(u: UserItem) {
    setEditingUser(u);
    setFormName(u.name);
    setFormEmail(u.email ?? "");
    setFormPhone(u.phone ?? "");
    setFormRole(u.role);
    setFormUnitUlid(u.school_unit?.ulid ?? "");
    setFormIsActive(u.is_active);
  }

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      await api.post("/api/admin/users", {
        name: formName,
        email: formEmail || null,
        phone: formPhone || null,
        role: formRole,
        school_unit_ulid: (formRole === "admin_unit" || formRole === "guru")
          ? (isUnitAdmin ? (user?.school_unit?.ulid ?? null) : formUnitUlid)
          : null,
        is_active: formIsActive,
      });
      toast.success("Pengguna baru berhasil ditambahkan.");
      setShowCreateModal(false);
      loadUsers();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menambahkan pengguna.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleUpdate(e: React.FormEvent) {
    e.preventDefault();
    if (!editingUser) return;
    setSubmitting(true);
    try {
      await api.patch(`/api/admin/users/${editingUser.ulid}`, {
        name: formName,
        email: formEmail || null,
        phone: formPhone || null,
        role: formRole,
        school_unit_ulid: (formRole === "admin_unit" || formRole === "guru") ? formUnitUlid : null,
        is_active: formIsActive,
      });
      toast.success("Data pengguna berhasil diperbarui.");
      setEditingUser(null);
      loadUsers();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal memperbarui pengguna.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete() {
    if (!deletingUser) return;
    setSubmitting(true);
    try {
      await api.delete(`/api/admin/users/${deletingUser.ulid}`);
      toast.success("Pengguna berhasil dihapus.");
      setDeletingUser(null);
      loadUsers();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus pengguna.");
    } finally {
      setSubmitting(false);
    }
  }

  function getRoleBadge(role: UserItem["role"]) {
    switch (role) {
      case "admin":
        return <Badge variant="bad" className="gap-1 font-bold"><ShieldAlert className="size-3" /> Admin Pusat</Badge>;
      case "admin_unit":
        return <Badge variant="primary" className="gap-1 font-bold"><ShieldCheck className="size-3" /> TU / Unit</Badge>;
      case "guru":
        return <Badge variant="good" className="gap-1 font-bold"><Shield className="size-3" /> Guru</Badge>;
      case "orangtua":
        return <Badge variant="default" className="gap-1 font-bold"><Users className="size-3" /> Wali Murid</Badge>;
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Manajemen Pengguna</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Kelola hak akses akun Administrator, Tata Usaha Unit, Guru, dan Wali Murid.
          </p>
        </div>

        <Button onClick={openCreate} className="gap-2 font-bold shadow-xs">
          <UserPlus className="size-4" />
          <span>{isUnitAdmin ? "Tambah Guru" : "Tambah Pengguna"}</span>
        </Button>
      </div>

      {/* Impor Akun CSV */}
      <Card className="p-5 border-border/80 shadow-xs">
        <h2 className="text-sm font-semibold">
          {isUnitAdmin ? "Impor Akun Guru & Wali Murid (CSV)" : "Impor Akun Pengguna (CSV)"}
        </h2>
        <p className="mt-0.5 text-xs text-muted-foreground">
          {isUnitAdmin ? (
            <>
              Masukkan banyak akun sekaligus — kolom: nama_lengkap, email, no_hp,
              role (guru / orangtua; kosong = guru). Semua akun otomatis terhubung ke
              unit Anda. Akun wali murid langsung siap dihubungkan ke siswa saat impor
              siswa (dicocokkan lewat No HP / email). Login memakai OTP ke email atau No HP.
            </>
          ) : (
            <>
              Masukkan banyak akun sekaligus (sesuai form Tambah Pengguna) — kolom:
              nama_lengkap, email, no_hp, role (admin / admin_unit / guru / orangtua),
              unit_code (wajib untuk admin_unit &amp; guru — isi persis nama unit pada
              dropdown Tambah Pengguna, kosongkan untuk lainnya), is_aktif (kosong = aktif).
              Login memakai OTP ke email atau No HP.
            </>
          )}
        </p>
        <form onSubmit={handleImportUsers} className="mt-3 flex flex-col gap-2.5">
          {/* The native "Choose File" control ignores the theme; a styled block */}
          {/* button opens the same dialog via a hidden input. */}
          <input
            ref={fileInputRef}
            type="file"
            accept=".csv,text/csv,text/plain"
            onChange={(e) => setImportFile(e.target.files?.[0] ?? null)}
            className="hidden"
          />
          <Button
            type="button"
            onClick={() => fileInputRef.current?.click()}
            className="w-full gap-2 font-bold shadow-xs"
          >
            <FileUp className="size-4" />
            {importFile ? importFile.name : "Pilih File CSV"}
          </Button>
          <div className="flex flex-wrap items-center gap-2">
            <Button
              type="button"
              size="sm"
              variant="outline"
              className="gap-1.5"
              onClick={() =>
                downloadApiFile(
                  "/api/admin/import/users/template",
                  isUnitAdmin ? "template_import_guru_wali_siakad.csv" : "template_import_pengguna_siakad.csv",
                )
              }
            >
              <Download className="size-3.5" />
              Unduh Template
            </Button>
            <Button type="submit" size="sm" disabled={!importFile || importing} className="gap-1.5">
              <Upload className="size-3.5" />
              {importing ? "Mengimpor…" : isUnitAdmin ? "Impor Akun" : "Impor Pengguna"}
            </Button>
          </div>
        </form>
        {importResult && (
          <div className="mt-3 rounded-lg bg-muted/30 p-3 text-xs">
            <p className="font-semibold">{importResult.message}</p>
            {importResult.errors.length > 0 && (
              <ul className="mt-1.5 list-disc pl-4 text-destructive">
                {importResult.errors.map((err, i) => <li key={i}>{err}</li>)}
              </ul>
            )}
          </div>
        )}
      </Card>

      {/* Filter & Search Bar */}
      <Card className="p-4 border-border/80 shadow-xs">
        <form onSubmit={handleSearchSubmit} className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <div className="lg:col-span-2">
            <Label className="text-xs">Cari Pengguna</Label>
            <div className="relative mt-1">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Cari nama atau email..."
                className="pl-9 text-xs h-9"
              />
            </div>
          </div>

          {/* A per-unit admin's list holds exactly two account kinds - their */}
          {/* own unit's guru and wali murid - so their role filter offers only */}
          {/* those; the unit filter stays central-only (one unit, no choice). */}
          <div>
            <Label className="text-xs">Role / Peran</Label>
            <select
              value={roleFilter}
              onChange={(e) => setRoleFilter(e.target.value)}
              className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-medium shadow-2xs"
            >
              <option value="">Semua Role</option>
              {isUnitAdmin ? (
                <>
                  <option value="guru">Guru</option>
                  <option value="orangtua">Wali Murid</option>
                </>
              ) : (
                <>
                  <option value="admin">Administrator Pusat</option>
                  <option value="admin_unit">Tata Usaha / Admin Unit</option>
                  <option value="guru">Guru</option>
                  <option value="orangtua">Wali Murid</option>
                </>
              )}
            </select>
          </div>

          {!isUnitAdmin && (
            <div>
              <Label className="text-xs">Unit Sekolah</Label>
              <select
                value={unitFilter}
                onChange={(e) => setUnitFilter(e.target.value)}
                className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-medium shadow-2xs"
              >
                <option value="">Semua Unit</option>
                {units.map((u) => (
                  <option key={u.ulid} value={u.code}>
                    {u.label}
                  </option>
                ))}
              </select>
            </div>
          )}

          <div className="flex items-end gap-2">
            <div className="flex-1">
              <Label className="text-xs">Status Akun</Label>
              <select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
                className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-medium shadow-2xs"
              >
                <option value="">Semua Status</option>
                <option value="1">Aktif</option>
                <option value="0">Nonaktif</option>
              </select>
            </div>
            <Button type="submit" size="sm" variant="outline" className="h-9 px-3 text-xs">
              <RefreshCw className="size-3.5" />
            </Button>
          </div>
        </form>
      </Card>

      {/* Users Table */}
      <Card className="border-border/80 shadow-xs overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-muted/40 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
              <tr>
                <th className="px-5 py-3.5">Nama & Kontak</th>
                <th className="px-5 py-3.5">Role / Akses</th>
                <th className="px-5 py-3.5">Unit Penugasan</th>
                <th className="px-5 py-3.5">Status Akun</th>
                <th className="px-5 py-3.5">Terakhir Masuk</th>
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

              {!loading && users?.length === 0 && (
                <tr>
                  <td colSpan={6} className="p-8 text-center text-muted-foreground">
                    Tidak ada data pengguna yang sesuai dengan filter pencarian.
                  </td>
                </tr>
              )}

              {!loading &&
                users?.map((u) => (
                  <tr key={u.ulid} className="hover:bg-accent/30 transition-colors">
                    <td className="px-5 py-4">
                      <div className="flex items-center gap-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary font-bold text-xs">
                          {u.name.charAt(0)}
                        </span>
                        <div>
                          <p className="font-bold text-foreground text-sm">{u.name}</p>
                          <div className="flex items-center gap-2 mt-0.5 text-muted-foreground">
                            {u.email && (
                              <span className="flex items-center gap-1">
                                <Mail className="size-3" />
                                {u.email}
                              </span>
                            )}
                            {u.phone && (
                              <span className="flex items-center gap-1">
                                <Phone className="size-3" />
                                {u.phone}
                              </span>
                            )}
                          </div>
                        </div>
                      </div>
                    </td>
                    <td className="px-5 py-4">{getRoleBadge(u.role)}</td>
                    <td className="px-5 py-4">
                      <span className="font-medium text-foreground">
                        {u.school_unit?.label ?? (u.role === "admin" ? "Seluruh Yayasan" : "—")}
                      </span>
                    </td>
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
                    <td className="px-5 py-4 text-muted-foreground">
                      {u.last_login_at ? tanggalWaktu(u.last_login_at) : "Belum pernah masuk"}
                    </td>
                    <td className="px-5 py-4 text-right">
                      {/* Editing/deleting any account stays central-admin only - */}
                      {/* hidden rather than left to 403 on click. */}
                      {!isUnitAdmin && (
                        <div className="flex items-center justify-end gap-1.5">
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
                            onClick={() => setDeletingUser(u)}
                            className="h-8 px-2 text-destructive hover:bg-destructive/10 hover:text-destructive"
                          >
                            <Trash2 className="size-3.5" />
                          </Button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      </Card>

      {/* Modal Tambah User */}
      {showCreateModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
          <Card className="w-full max-w-lg p-6 border-border shadow-2xl space-y-4 my-8">
            <div className="flex items-center justify-between border-b border-border pb-3">
              <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
                <UserPlus className="size-5 text-primary" />
                <span>Tambah Pengguna Baru</span>
              </h2>
              <button onClick={() => setShowCreateModal(false)} className="text-muted-foreground hover:text-foreground">
                <X className="size-5" />
              </button>
            </div>

            <form onSubmit={handleCreate} className="space-y-3.5 text-xs">
              <div>
                <Label className="text-xs">Nama Lengkap</Label>
                <Input
                  value={formName}
                  onChange={(e) => setFormName(e.target.value)}
                  required
                  placeholder="misal: Ustadz Abdullah"
                  className="mt-1"
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <Label className="text-xs">Email</Label>
                  <Input
                    type="email"
                    value={formEmail}
                    onChange={(e) => setFormEmail(e.target.value)}
                    placeholder="nama@yapinet.id"
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label className="text-xs">No HP / WhatsApp (OTP)</Label>
                  <Input
                    value={formPhone}
                    onChange={(e) => setFormPhone(e.target.value)}
                    placeholder="081234567890"
                    className="mt-1"
                  />
                </div>
              </div>

              <div>
                <Label className="text-xs">Peran / Role Pengguna</Label>
                {isUnitAdmin ? (
                  <div className="mt-1 space-y-1.5">
                    <select
                      value={formRole}
                      onChange={(e) => setFormRole(e.target.value as any)}
                      className="w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-2xs"
                    >
                      <option value="guru">Guru</option>
                      <option value="orangtua">Wali Murid</option>
                    </select>
                    <p className="text-[11px] text-muted-foreground">
                      Akun otomatis terpasang di unit {user?.school_unit?.label ?? "Anda"}. Guru mengajar
                      sesuai jadwal pelajaran; akun wali murid langsung bisa dihubungkan ke siswa saat impor siswa.
                    </p>
                  </div>
                ) : (
                  <select
                    value={formRole}
                    onChange={(e) => setFormRole(e.target.value as any)}
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-2xs"
                  >
                    <option value="admin">Administrator Pusat (Akses Penuh Semua Unit)</option>
                    <option value="admin_unit">Tata Usaha / Admin Unit (Akses 1 Unit)</option>
                    <option value="guru">Guru (mengajar sesuai jadwal; catat poin &amp; prestasi)</option>
                    <option value="orangtua">Wali Murid</option>
                  </select>
                )}
              </div>

              {(formRole === "admin_unit" || formRole === "guru") && !isUnitAdmin && (
                <div>
                  <Label className="text-xs">Unit Sekolah Penugasan</Label>
                  <select
                    value={formUnitUlid}
                    onChange={(e) => setFormUnitUlid(e.target.value)}
                    required
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-2xs"
                  >
                    {units.map((u) => (
                      <option key={u.ulid} value={u.ulid}>
                        {u.label}
                      </option>
                    ))}
                  </select>
                </div>
              )}

              <div className="flex items-center gap-2 pt-1">
                <input
                  type="checkbox"
                  id="is_active_create"
                  checked={formIsActive}
                  onChange={(e) => setFormIsActive(e.target.checked)}
                  className="size-4 rounded border-input"
                />
                <Label htmlFor="is_active_create" className="text-xs font-semibold cursor-pointer">
                  Akun Aktif (Bisa masuk dengan OTP)
                </Label>
              </div>

              <div className="flex justify-end gap-2 border-t border-border pt-4">
                <Button type="button" variant="ghost" onClick={() => setShowCreateModal(false)} disabled={submitting}>
                  Batal
                </Button>
                <Button type="submit" disabled={submitting} className="font-bold shadow-xs">
                  {submitting ? "Menyimpan…" : "Simpan Pengguna"}
                </Button>
              </div>
            </form>
          </Card>
        </div>
      )}

      {/* Modal Edit User */}
      {editingUser && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto">
          <Card className="w-full max-w-lg p-6 border-border shadow-2xl space-y-4 my-8">
            <div className="flex items-center justify-between border-b border-border pb-3">
              <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
                <Edit2 className="size-5 text-primary" />
                <span>Edit Pengguna</span>
              </h2>
              <button onClick={() => setEditingUser(null)} className="text-muted-foreground hover:text-foreground">
                <X className="size-5" />
              </button>
            </div>

            <form onSubmit={handleUpdate} className="space-y-3.5 text-xs">
              <div>
                <Label className="text-xs">Nama Lengkap</Label>
                <Input
                  value={formName}
                  onChange={(e) => setFormName(e.target.value)}
                  required
                  className="mt-1"
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <Label className="text-xs">Email</Label>
                  <Input
                    type="email"
                    value={formEmail}
                    onChange={(e) => setFormEmail(e.target.value)}
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label className="text-xs">No HP / WhatsApp (OTP)</Label>
                  <Input
                    value={formPhone}
                    onChange={(e) => setFormPhone(e.target.value)}
                    className="mt-1"
                  />
                </div>
              </div>

              <div>
                <Label className="text-xs">Peran / Role Pengguna</Label>
                <select
                  value={formRole}
                  onChange={(e) => setFormRole(e.target.value as any)}
                  className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-2xs"
                >
                  <option value="admin">Administrator Pusat</option>
                  <option value="admin_unit">Tata Usaha / Admin Unit</option>
                  <option value="guru">Guru</option>
                  <option value="orangtua">Wali Murid</option>
                </select>
              </div>

              {(formRole === "admin_unit" || formRole === "guru") && (
                <div>
                  <Label className="text-xs">Unit Sekolah Penugasan</Label>
                  <select
                    value={formUnitUlid}
                    onChange={(e) => setFormUnitUlid(e.target.value)}
                    required
                    className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-xs font-semibold shadow-2xs"
                  >
                    {units.map((u) => (
                      <option key={u.ulid} value={u.ulid}>
                        {u.label}
                      </option>
                    ))}
                  </select>
                </div>
              )}

              <div className="flex items-center gap-2 pt-1">
                <input
                  type="checkbox"
                  id="is_active_edit"
                  checked={formIsActive}
                  onChange={(e) => setFormIsActive(e.target.checked)}
                  className="size-4 rounded border-input"
                />
                <Label htmlFor="is_active_edit" className="text-xs font-semibold cursor-pointer">
                  Akun Aktif
                </Label>
              </div>

              <div className="flex justify-end gap-2 border-t border-border pt-4">
                <Button type="button" variant="ghost" onClick={() => setEditingUser(null)} disabled={submitting}>
                  Batal
                </Button>
                <Button type="submit" disabled={submitting} className="font-bold shadow-xs">
                  {submitting ? "Menyimpan…" : "Simpan Perubahan"}
                </Button>
              </div>
            </form>
          </Card>
        </div>
      )}

      {/* Modal Konfirmasi Hapus */}
      {deletingUser && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4">
          <Card className="w-full max-w-md p-6 border-border shadow-2xl space-y-4">
            <div className="flex items-center gap-3 text-destructive">
              <div className="size-10 rounded-full bg-destructive/10 grid place-items-center">
                <Trash2 className="size-5" />
              </div>
              <div>
                <h3 className="font-bold text-base text-foreground">Hapus Pengguna</h3>
                <p className="text-xs text-muted-foreground">Tindakan ini tidak dapat dibatalkan.</p>
              </div>
            </div>

            <p className="text-xs text-muted-foreground">
              Apakah Anda yakin ingin menghapus akun pengguna <strong className="text-foreground">{deletingUser.name}</strong> ({deletingUser.email || deletingUser.phone})?
            </p>

            <div className="flex justify-end gap-2 border-t border-border pt-4">
              <Button variant="ghost" size="sm" onClick={() => setDeletingUser(null)} disabled={submitting}>
                Batal
              </Button>
              <Button variant="destructive" size="sm" onClick={handleDelete} disabled={submitting} className="font-bold">
                {submitting ? "Menghapus…" : "Ya, Hapus Pengguna"}
              </Button>
            </div>
          </Card>
        </div>
      )}
    </div>
  );
}

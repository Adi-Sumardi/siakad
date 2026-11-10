"use client";

import { useCallback, useEffect, useState } from "react";
import { ScanFace } from "lucide-react";
import { toast } from "sonner";
import { AbsenLinkCard } from "@/components/absen-link-card";
import { FacePhotoButton } from "@/components/face-photo";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Pagination, type PageMeta } from "@/components/ui/pagination";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";

/**
 * Wajah Absen Siswa (docs/09): the unit's absen link to share, and the
 * registered faces. Waka kesiswaan, kepala sekolah and the admins can open a
 * face, and delete a wrong one so the student registers again on their next
 * face check-in. Wali kelas do the same for their own class from Presensi
 * Harian.
 */

type FaceRow = {
  ulid: string;
  nama_lengkap: string;
  nis: string;
  classroom: string | null;
  face_enrolled_at: string | null;
};

type FaceList = { data: FaceRow[]; meta: PageMeta & { enrolled: number } };

export default function StudentFacesPage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [units, setUnits] = useState<{ ulid: string; label: string }[]>([]);
  const [unitUlid, setUnitUlid] = useState("");
  const [q, setQ] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [list, setList] = useState<FaceList | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  useEffect(() => {
    if (!isCentral) return;
    api
      .get<{ school_units: { ulid: string; label: string }[] }>("/api/admin/school-units")
      .then((res) => {
        setUnits(res.school_units);
        setUnitUlid((current) => current || res.school_units[0]?.ulid || "");
      })
      .catch(() => toast.error("Gagal memuat daftar unit."));
  }, [isCentral]);

  const unitQuery = isCentral && unitUlid ? `unit=${unitUlid}` : "";
  const ready = !isCentral || !!unitUlid;

  const load = useCallback(() => {
    if (!ready) return;
    const params = new URLSearchParams(unitQuery);
    if (q.trim()) params.set("q", q.trim());
    if (status) params.set("status", status);
    params.set("page", String(page));

    api
      .get<FaceList>(`/api/admin/student-faces?${params}`)
      .then(setList)
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat data wajah."));
  }, [ready, unitQuery, q, status, page]);

  useEffect(() => {
    const t = setTimeout(load, 250);
    return () => clearTimeout(t);
  }, [load]);

  async function reset(row: FaceRow) {
    if (!window.confirm(`Hapus wajah terdaftar ${row.nama_lengkap}? Absen wajah berikutnya akan mendaftarkan wajah baru.`)) {
      return;
    }
    setBusy(row.ulid);
    try {
      await api.delete(`/api/admin/students/${row.ulid}/face`);
      toast.success("Wajah dihapus. Siswa mendaftar ulang saat absen berikutnya.");
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menghapus wajah.");
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-center gap-2">
        <ScanFace className="size-5 text-primary" />
        <div>
          <h1 className="text-xl font-bold tracking-tight">Wajah Absen Siswa</h1>
          <p className="text-sm text-muted-foreground">
            Link absen untuk dibagikan, dan wajah siswa yang sudah terdaftar dari absen pertamanya.
          </p>
        </div>
      </div>

      {isCentral && (
        <Card className="flex flex-wrap items-center gap-3 p-4">
          <Label className="text-xs">Unit</Label>
          <select
            value={unitUlid}
            onChange={(e) => {
              setUnitUlid(e.target.value);
              setPage(1);
            }}
            className="h-9 rounded-lg border border-input bg-card px-2 text-sm"
          >
            {units.map((u) => (
              <option key={u.ulid} value={u.ulid}>
                {u.label}
              </option>
            ))}
          </select>
        </Card>
      )}

      {ready && (
        <AbsenLinkCard
          key={unitQuery}
          endpoint={`/api/admin/daily-attendance/public-link${unitQuery ? `?${unitQuery}` : ""}`}
          printHref={`/cetak/qr-absen${unitQuery ? `?${unitQuery}` : ""}`}
        />
      )}

      <Card className="p-5">
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-48 flex-1">
            <Label className="text-xs">Cari nama / NIS</Label>
            <Input
              value={q}
              onChange={(e) => {
                setQ(e.target.value);
                setPage(1);
              }}
              placeholder="Ketik nama atau NIS"
              className="mt-1.5 h-9"
            />
          </div>
          <div>
            <Label className="text-xs">Status</Label>
            <select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
              }}
              className="mt-1.5 h-9 rounded-lg border border-input bg-card px-2 text-sm"
            >
              <option value="">Semua</option>
              <option value="terdaftar">Wajah terdaftar</option>
              <option value="belum">Belum terdaftar</option>
            </select>
          </div>
        </div>

        {list && (
          <p className="mt-3 text-xs text-muted-foreground">
            {list.meta.enrolled} siswa aktif sudah punya wajah terdaftar.
          </p>
        )}

        {list === null ? (
          <Skeleton className="mt-4 h-48 w-full" />
        ) : (
          <div className="mt-3 overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left text-xs text-muted-foreground">
                  <th className="py-1.5 pr-3 font-medium">Nama</th>
                  <th className="py-1.5 pr-3 font-medium">NIS</th>
                  <th className="py-1.5 pr-3 font-medium">Kelas</th>
                  <th className="py-1.5 pr-3 font-medium">Wajah</th>
                  <th className="py-1.5 font-medium" />
                </tr>
              </thead>
              <tbody>
                {list.data.map((row) => (
                  <tr key={row.ulid} className="border-b border-border/60">
                    <td className="py-1.5 pr-3">{row.nama_lengkap}</td>
                    <td className="py-1.5 pr-3 text-muted-foreground">{row.nis}</td>
                    <td className="py-1.5 pr-3 text-muted-foreground">{row.classroom ?? "—"}</td>
                    <td className="py-1.5 pr-3">
                      {row.face_enrolled_at ? (
                        <FacePhotoButton
                          path={`/api/files/student-faces/${row.ulid}`}
                          label={`Terdaftar ${new Date(row.face_enrolled_at).toLocaleDateString("id-ID")}`}
                          title={`${row.nama_lengkap} · wajah terdaftar`}
                        />
                      ) : (
                        <span className="text-xs text-muted-foreground">Belum</span>
                      )}
                    </td>
                    <td className="py-1.5 text-right">
                      {row.face_enrolled_at && (
                        <button
                          type="button"
                          className="text-xs text-bad hover:underline"
                          onClick={() => reset(row)}
                          disabled={busy === row.ulid}
                        >
                          {busy === row.ulid ? "Menghapus…" : "Hapus wajah"}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
                {list.data.length === 0 && (
                  <tr>
                    <td colSpan={5} className="py-3 text-center text-xs text-muted-foreground">
                      Tidak ada siswa yang cocok.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
            <Pagination meta={list.meta} onPage={setPage} label="siswa" className="mt-3" />
          </div>
        )}
      </Card>
    </div>
  );
}

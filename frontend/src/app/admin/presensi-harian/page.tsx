"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { QRCodeSVG } from "qrcode.react";
import { CalendarCheck2, Copy, Link2, RefreshCw, ShieldAlert } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-context";

type Settings = {
  unit: { ulid: string; label: string };
  enabled: boolean;
  days: number[];
  masuk: { opens_at: string; closes_at: string; late_after: string | null };
  pulang: { enabled: boolean; opens_at: string | null; closes_at: string | null };
  intake_mode: "wali_kelas" | "gerbang";
  geo: { required: boolean; gate_lat: number | null; gate_lng: number | null; radius_m: number | null };
  qr_required: boolean;
  notifications: { masuk: boolean; pulang: boolean; absent: boolean };
  public_slug: string | null;
  public_path: string | null;
};

type RosterRow = {
  ulid: string;
  nama_lengkap: string;
  nis: string;
  classroom: string | null;
  attendance_status: string | null;
  is_late: boolean;
  source: string | null;
  marked_at: string | null;
};

type TodaySession = {
  ulid: string;
  type: "masuk" | "pulang";
  status: "open" | "closed";
  opens_at: string;
  closes_at: string;
  late_after: string | null;
  tally: Record<string, number>;
  roster: RosterRow[];
  suspected: string[];
};

const DAY_LABEL = ["Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu", "Minggu"];
const DAY_ISO = [1, 2, 3, 4, 5, 6, 7];

/** "HH:MM:SS" -> "HH:MM" for <input type="time">. */
function hm(value: string | null | undefined): string {
  return value ? value.slice(0, 5) : "";
}

export default function AdminDailyAttendancePage() {
  const { user } = useAuth();
  const isCentral = user?.role === "admin";

  const [units, setUnits] = useState<{ ulid: string; label: string }[]>([]);
  const [unitUlid, setUnitUlid] = useState<string>("");
  const [settings, setSettings] = useState<Settings | null>(null);
  const [sessions, setSessions] = useState<TodaySession[] | null>(null);
  const [saving, setSaving] = useState(false);

  const query = isCentral && unitUlid ? `?unit=${unitUlid}` : "";

  const load = useCallback(() => {
    if (isCentral && !unitUlid) return;
    api
      .get<Settings>(`/api/admin/daily-attendance/settings${query}`)
      .then(setSettings)
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat pengaturan."));
    api
      .get<{ sessions: TodaySession[] }>(`/api/admin/daily-attendance/today${query}`)
      .then((res) => setSessions(res.sessions))
      .catch(() => setSessions([]));
  }, [isCentral, unitUlid, query]);

  useEffect(() => {
    if (!isCentral) return;
    api
      .get<{ school_units: { ulid: string; label: string }[] }>("/api/admin/school-units")
      .then((res) => {
        setUnits(res.school_units);
        setUnitUlid((prev) => prev || res.school_units[0]?.ulid || "");
      })
      .catch(() => toast.error("Gagal memuat daftar unit."));
  }, [isCentral]);

  useEffect(load, [load]);

  function patchSettings(partial: Record<string, unknown>) {
    setSettings((prev) => (prev ? ({ ...prev, ...partial } as Settings) : prev));
  }

  async function save() {
    if (!settings) return;
    setSaving(true);
    try {
      const body = {
        unit: unitUlid || undefined,
        enabled: settings.enabled,
        days: settings.days,
        masuk_opens_at: hm(settings.masuk.opens_at),
        masuk_closes_at: hm(settings.masuk.closes_at),
        masuk_late_after: settings.masuk.late_after ? hm(settings.masuk.late_after) : null,
        pulang_enabled: settings.pulang.enabled,
        pulang_opens_at: settings.pulang.opens_at ? hm(settings.pulang.opens_at) : null,
        pulang_closes_at: settings.pulang.closes_at ? hm(settings.pulang.closes_at) : null,
        intake_mode: settings.intake_mode,
        geo_required: settings.geo.required,
        gate_lat: settings.geo.gate_lat,
        gate_lng: settings.geo.gate_lng,
        geo_radius_m: settings.geo.radius_m,
        qr_required: settings.qr_required,
        notify_masuk: settings.notifications.masuk,
        notify_pulang: settings.notifications.pulang,
        notify_absent: settings.notifications.absent,
      };
      const updated = await api.patch<Settings>(`/api/admin/daily-attendance/settings${query}`, body);
      setSettings(updated);
      toast.success("Pengaturan presensi harian tersimpan.");
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyimpan pengaturan.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-center gap-2">
        <CalendarCheck2 className="size-5 text-primary" />
        <div>
          <h1 className="text-xl font-bold tracking-tight">Presensi Harian</h1>
          <p className="text-sm text-muted-foreground">
            Pengaturan jadwal & jam absen unit, plus papan kehadiran hari ini.
          </p>
        </div>
      </div>

      {isCentral && (
        <Card className="flex flex-wrap items-center gap-3 p-4">
          <Label className="text-xs">Unit</Label>
          <select
            value={unitUlid}
            onChange={(e) => setUnitUlid(e.target.value)}
            className="h-9 rounded-lg border border-input bg-card px-2 text-sm"
          >
            {units.map((u) => (
              <option key={u.ulid} value={u.ulid}>{u.label}</option>
            ))}
          </select>
        </Card>
      )}

      {settings === null ? (
        <Skeleton className="h-96 w-full" />
      ) : (
        <>
          <Card className="p-5">
            <div className="flex items-center justify-between">
              <h2 className="text-sm font-semibold">Pengaturan {settings.unit.label}</h2>
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={settings.enabled}
                  onChange={(e) => patchSettings({ enabled: e.target.checked })}
                  className="size-4 accent-primary"
                />
                Aktifkan presensi harian
              </label>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
              <div>
                <Label className="text-xs">Hari aktif</Label>
                <div className="mt-1.5 flex flex-wrap gap-1.5">
                  {DAY_ISO.map((iso, i) => {
                    const on = settings.days.includes(iso);
                    return (
                      <button
                        key={iso}
                        type="button"
                        onClick={() =>
                          patchSettings({
                            days: on ? settings.days.filter((d) => d !== iso) : [...settings.days, iso].sort(),
                          })
                        }
                        className={`rounded-full border px-3 py-1 text-xs ${
                          on ? "border-primary bg-primary text-primary-foreground" : "border-border text-muted-foreground"
                        }`}
                      >
                        {DAY_LABEL[i]}
                      </button>
                    );
                  })}
                </div>

                <div className="mt-4">
                  <Label className="text-xs">Mode penandaan</Label>
                  <select
                    value={settings.intake_mode}
                    onChange={(e) => patchSettings({ intake_mode: e.target.value })}
                    className="mt-1.5 h-9 w-full rounded-lg border border-input bg-card px-2 text-sm"
                  >
                    <option value="wali_kelas">Wali kelas menandai di kelas (PG/RA/TK/SD)</option>
                    <option value="gerbang">Gerbang — QR + radius (SMP/SMA)</option>
                  </select>
                </div>

                {settings.intake_mode === "gerbang" && (
                  <div className="mt-4 flex flex-col gap-3 rounded-lg border border-border p-3">
                    <div className="flex flex-wrap gap-4">
                      <label className="flex items-center gap-2 text-sm">
                        <input
                          type="checkbox"
                          checked={settings.qr_required}
                          onChange={(e) => patchSettings({ qr_required: e.target.checked })}
                          className="size-4 accent-primary"
                        />
                        QR gerbang wajib
                      </label>
                      <label className="flex items-center gap-2 text-sm">
                        <input
                          type="checkbox"
                          checked={settings.geo.required}
                          onChange={(e) => patchSettings({ geo: { ...settings.geo, required: e.target.checked } })}
                          className="size-4 accent-primary"
                        />
                        Radius GPS wajib
                      </label>
                    </div>

                    {settings.geo.required && (
                      <div className="grid grid-cols-3 gap-2">
                        <div>
                          <Label className="text-xs">Titik gerbang (lat)</Label>
                          <Input
                            type="number"
                            step="0.0000001"
                            value={settings.geo.gate_lat ?? ""}
                            onChange={(e) =>
                              patchSettings({ geo: { ...settings.geo, gate_lat: e.target.value ? Number(e.target.value) : null } })
                            }
                            className="mt-1.5 h-9"
                          />
                        </div>
                        <div>
                          <Label className="text-xs">Titik gerbang (lng)</Label>
                          <Input
                            type="number"
                            step="0.0000001"
                            value={settings.geo.gate_lng ?? ""}
                            onChange={(e) =>
                              patchSettings({ geo: { ...settings.geo, gate_lng: e.target.value ? Number(e.target.value) : null } })
                            }
                            className="mt-1.5 h-9"
                          />
                        </div>
                        <div>
                          <Label className="text-xs">Radius (m)</Label>
                          <Input
                            type="number"
                            value={settings.geo.radius_m ?? ""}
                            onChange={(e) =>
                              patchSettings({ geo: { ...settings.geo, radius_m: e.target.value ? Number(e.target.value) : null } })
                            }
                            className="mt-1.5 h-9"
                          />
                        </div>
                      </div>
                    )}
                    <p className="text-xs text-muted-foreground">
                      Radius minimal 30 m — GPS ponsel meleset ±10–50 m; radius terlalu kecil menolak siswa jujur.
                    </p>
                  </div>
                )}
              </div>

              <div className="flex flex-col gap-3">
                <div className="grid grid-cols-3 gap-2">
                  <div>
                    <Label className="text-xs">Masuk dibuka</Label>
                    <Input
                      type="time"
                      value={hm(settings.masuk.opens_at)}
                      onChange={(e) => patchSettings({ masuk: { ...settings.masuk, opens_at: e.target.value } })}
                      className="mt-1.5 h-9"
                    />
                  </div>
                  <div>
                    <Label className="text-xs">Masuk ditutup</Label>
                    <Input
                      type="time"
                      value={hm(settings.masuk.closes_at)}
                      onChange={(e) => patchSettings({ masuk: { ...settings.masuk, closes_at: e.target.value } })}
                      className="mt-1.5 h-9"
                    />
                  </div>
                  <div>
                    <Label className="text-xs">Batas terlambat</Label>
                    <Input
                      type="time"
                      value={hm(settings.masuk.late_after)}
                      onChange={(e) => patchSettings({ masuk: { ...settings.masuk, late_after: e.target.value } })}
                      className="mt-1.5 h-9"
                    />
                  </div>
                </div>

                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={settings.pulang.enabled}
                    onChange={(e) => patchSettings({ pulang: { ...settings.pulang, enabled: e.target.checked } })}
                    className="size-4 accent-primary"
                  />
                  Ada absen pulang sekolah
                </label>

                <p className="text-xs text-muted-foreground">
                  Perubahan jam langsung berlaku untuk sesi hari ini: sesi yang masih terbuka ikut digeser, dan sesi
                  yang sudah ditutup dibuka kembali bila jendela barunya masih di depan — alpa otomatis akibat tutup
                  yang lama ikut dibatalkan.
                </p>

                {settings.pulang.enabled && (
                  <div className="grid grid-cols-2 gap-2">
                    <div>
                      <Label className="text-xs">Pulang dibuka</Label>
                      <Input
                        type="time"
                        value={hm(settings.pulang.opens_at)}
                        onChange={(e) => patchSettings({ pulang: { ...settings.pulang, opens_at: e.target.value } })}
                        className="mt-1.5 h-9"
                      />
                    </div>
                    <div>
                      <Label className="text-xs">Pulang ditutup</Label>
                      <Input
                        type="time"
                        value={hm(settings.pulang.closes_at)}
                        onChange={(e) => patchSettings({ pulang: { ...settings.pulang, closes_at: e.target.value } })}
                        className="mt-1.5 h-9"
                      />
                    </div>
                  </div>
                )}

                <div>
                  <Label className="text-xs">Notifikasi WhatsApp ke wali murid</Label>
                  <div className="mt-1.5 flex flex-col gap-1.5 text-sm">
                    {(
                      [
                        ["masuk", "Saat anak tercatat masuk"],
                        ["pulang", "Saat anak tercatat pulang"],
                        ["absent", "Anak tidak tercatat hadir saat absen masuk ditutup"],
                      ] as const
                    ).map(([key, label]) => (
                      <label key={key} className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          checked={settings.notifications[key]}
                          onChange={(e) =>
                            patchSettings({ notifications: { ...settings.notifications, [key]: e.target.checked } })
                          }
                          className="size-4 accent-primary"
                        />
                        {label}
                      </label>
                    ))}
                  </div>
                </div>
              </div>
            </div>

            <div className="mt-4 flex justify-end">
              <Button onClick={save} disabled={saving}>
                {saving ? "Menyimpan…" : "Simpan Pengaturan"}
              </Button>
            </div>
          </Card>

          {settings.intake_mode === "gerbang" && settings.enabled && (
            <PublicLinkCard slug={settings.public_slug} query={query} onChanged={load} />
          )}

          <Card className="p-5">
            <h2 className="text-sm font-semibold">Papan hari ini</h2>
            {sessions === null && <Skeleton className="mt-3 h-40 w-full" />}
            {sessions?.length === 0 && (
              <p className="mt-2 text-sm text-muted-foreground">
                Belum ada sesi hari ini — belum aktif, bukan hari presensi, atau belum ada semester aktif.
              </p>
            )}
            {sessions?.map((session) => (
              <div key={session.ulid} className="mt-4">
                <div className="flex flex-wrap items-center gap-2">
                  <h3 className="text-sm font-semibold">
                    {session.type === "masuk" ? "Absen Masuk" : "Absen Pulang"}
                    <span className="ml-2 font-normal text-muted-foreground">
                      {session.opens_at}–{session.closes_at} WIB
                    </span>
                  </h3>
                  <Badge variant={session.status === "open" ? "good" : "default"}>
                    {session.status === "open" ? "Terbuka" : "Ditutup"}
                  </Badge>
                  <span className="text-xs text-muted-foreground">
                    Hadir {session.tally.hadir} · Terlambat {session.tally.terlambat} · Sakit {session.tally.sakit} ·
                    Izin {session.tally.izin} · Alpa {session.tally.alpa} · Belum {session.tally.belum}
                  </span>
                </div>

                {settings.intake_mode === "gerbang" && session.type === "masuk" && session.status === "open" && (
                  <div className="mt-3 grid gap-4 lg:grid-cols-2">
                    <GateQrPanel sessionUlid={session.ulid} query={query} />
                    <ManualMark session={session} query={query} onDone={load} />
                  </div>
                )}

                {session.suspected.length > 0 && (
                  <div className="mt-3 flex items-start gap-2 rounded-lg bg-warn/10 p-3 text-sm text-warn">
                    <ShieldAlert className="mt-0.5 size-4 shrink-0" />
                    <p>
                      Beberapa siswa absen dari jaringan yang sama — periksa apakah satu ponsel digunakan bergantian
                      (mode penyamaran bisa menghapus jejak perangkat):{" "}
                      <strong>
                        {session.roster
                          .filter((r) => session.suspected.includes(r.ulid))
                          .map((r) => `${r.nama_lengkap} (${r.nis})`)
                          .join(", ")}
                      </strong>
                    </p>
                  </div>
                )}

                <div className="mt-2 overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-border text-left text-xs text-muted-foreground">
                        <th className="py-1.5 pr-3 font-medium">Nama</th>
                        <th className="py-1.5 pr-3 font-medium">NIS</th>
                        <th className="py-1.5 pr-3 font-medium">Kelas</th>
                        <th className="py-1.5 pr-3 font-medium">Status</th>
                        <th className="py-1.5 font-medium">Dicatat</th>
                      </tr>
                    </thead>
                    <tbody>
                      {session.roster.map((row) => (
                        <tr key={row.ulid} className="border-b border-border/60">
                          <td className="py-1.5 pr-3">
                            {row.nama_lengkap}
                            {session.suspected.includes(row.ulid) && (
                              <span title="Beberapa NIS absen dari jaringan yang sama"> ⚠️</span>
                            )}
                          </td>
                          <td className="py-1.5 pr-3 text-muted-foreground">{row.nis}</td>
                          <td className="py-1.5 pr-3 text-muted-foreground">{row.classroom ?? "—"}</td>
                          <td className="py-1.5 pr-3">
                            {row.attendance_status ? (
                              <Badge variant="default">
                                {row.attendance_status === "hadir" && row.is_late
                                  ? "Terlambat"
                                  : row.attendance_status === "hadir"
                                    ? "Hadir"
                                    : row.attendance_status === "sakit"
                                      ? "Sakit"
                                      : row.attendance_status === "izin"
                                        ? "Izin"
                                        : "Alpa"}
                              </Badge>
                            ) : (
                              <span className="text-muted-foreground">belum</span>
                            )}
                          </td>
                          <td className="py-1.5 text-xs text-muted-foreground">
                            {row.source === "self"
                              ? `check-in sendiri${row.marked_at ? ` ${row.marked_at.slice(11, 16)}` : ""}`
                              : row.source === "wali_kelas"
                                ? "wali kelas"
                                : row.source === "tu"
                                  ? "otomatis/TU"
                                  : "—"}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            ))}
          </Card>
        </>
      )}
    </div>
  );
}

/**
 * The unit's public check-in link (§5D): paste it into the WA class groups'
 * description. Issue once; the reset button kills a leaked link on the spot.
 * One link per unit - students are separated by NIS, never by URL.
 */
function PublicLinkCard({ slug, query, onChanged }: { slug: string | null; query: string; onChanged: () => void }) {
  const [busy, setBusy] = useState(false);

  // This card only renders once the client fetch has populated settings, so
  // window is always there by now - no state, no effect, no hydration dance.
  const url = slug && typeof window !== "undefined" ? `${window.location.origin}/absen/${slug}` : null;

  async function issue(reset: boolean) {
    setBusy(true);
    try {
      await api.post(`/api/admin/daily-attendance/public-link${reset ? "/reset" : ""}${query}`);
      if (reset) toast.success("Link lama tidak berlaku lagi — sebarkan link yang baru.");
      onChanged();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal mengatur link publik.");
    } finally {
      setBusy(false);
    }
  }

  async function copy() {
    if (!url) return;
    try {
      await navigator.clipboard.writeText(url);
      toast.success("Link tersalin — tempel ke deskripsi grup WA kelas.");
    } catch {
      toast.error("Gagal menyalin — salin manual dari teks link.");
    }
  }

  return (
    <Card className="p-5">
      <div className="flex items-center gap-2">
        <Link2 className="size-4 text-primary" />
        <h2 className="text-sm font-semibold">Link Absen Publik</h2>
      </div>

      {url === null ? (
        <div className="mt-3">
          <p className="text-sm text-muted-foreground">
            Belum ada link. Buat link lalu tempelkan ke deskripsi grup WhatsApp tiap kelas — siswa membukanya tanpa
            perlu login.
          </p>
          <Button className="mt-3" onClick={() => issue(false)} disabled={busy}>
            Buat Link Absen
          </Button>
        </div>
      ) : (
        <div className="mt-3 grid gap-4 lg:grid-cols-2">
          <div className="flex flex-col gap-3">
            <div>
              <Label className="text-xs">Link</Label>
              <div className="mt-1.5 flex gap-2">
                <Input readOnly value={url} className="h-9 text-xs" />
                <Button variant="outline" size="sm" onClick={copy}>
                  <Copy className="size-3.5" /> Salin
                </Button>
              </div>
            </div>
            <p className="text-xs text-muted-foreground">
              QR di samping juga berisi link — tampilkan/scan untuk membagikan tanpa mengetik.
            </p>
            <Button
              variant="outline"
              size="sm"
              className="w-fit"
              onClick={() => issue(true)}
              disabled={busy}
            >
              <RefreshCw className="size-3.5" /> Reset Link (bila bocor)
            </Button>
          </div>
          <div className="flex items-center justify-center">
            {url && <QRCodeSVG value={url} size={148} className="rounded-lg border border-border bg-white p-2" />}
          </div>
        </div>
      )}
    </Card>
  );
}

/**
 * The "layar QR" a TU holds up at the gate (§5D) - no TV needed, this page on
 * any phone/laptop IS the screen. Polls the rotating code and refreshes
 * itself right when the server says the current one goes stale.
 */
function GateQrPanel({ sessionUlid, query }: { sessionUlid: string; query: string }) {
  const [qr, setQr] = useState<{ code: string; rotates_in: number } | null>(null);
  const [closed, setClosed] = useState(false);

  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let cancelled = false;

    const tick = () => {
      api
        .get<{ code: string; rotates_in: number }>(`/api/admin/daily-attendance/sessions/${sessionUlid}/gate-qr${query}`)
        .then((d) => {
          if (cancelled) return;
          setQr(d);
          timer = setTimeout(tick, Math.max(3, d.rotates_in) * 1000);
        })
        .catch((err) => {
          if (cancelled) return;
          if (err instanceof ApiError && err.status === 410) {
            setClosed(true);
            return;
          }
          timer = setTimeout(tick, 15000);
        });
    };

    tick();

    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [sessionUlid, query]);

  if (closed) {
    return (
      <div className="flex items-center justify-center rounded-lg border border-border p-6 text-sm text-muted-foreground">
        Sesi sudah ditutup — QR tidak lagi diterbitkan.
      </div>
    );
  }

  return (
    <div className="flex flex-col items-center gap-2 rounded-lg border border-border p-4">
      <p className="text-xs font-medium text-muted-foreground">Mode Layar QR — letakkan di meja gerbang</p>
      {qr ? (
        <>
          <QRCodeSVG value={qr.code} size={208} className="rounded-lg bg-white p-2" />
          <p className="font-mono text-lg font-bold tracking-[0.25em]">{qr.code}</p>
          <p className="text-xs text-muted-foreground">
            Berganti otomatis tiap ±{qr.rotates_in} detik — biarkan halaman ini terbuka.
          </p>
        </>
      ) : (
        <Skeleton className="h-52 w-52" />
      )}
    </div>
  );
}

/**
 * The quick lane for a student whose scan keeps failing: TU types the NIS,
 * the row lands as a hadir mark with source 'tu' - a human witnessed it.
 */
function ManualMark({ session, query, onDone }: { session: TodaySession; query: string; onDone: () => void }) {
  const [nis, setNis] = useState("");
  const [busy, setBusy] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  async function markHadir() {
    const row = session.roster.find((r) => r.nis === nis.trim());
    if (!row) {
      toast.error("NIS tidak ada di roster unit ini.");
      return;
    }

    setBusy(true);
    try {
      await api.post(`/api/admin/daily-attendance/sessions/${session.ulid}/records${query}`, {
        student_ulid: row.ulid,
        status: "hadir",
      });
      toast.success(`${row.nama_lengkap} ditandai hadir.`);
      setNis("");
      onDone();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menandai siswa.");
    } finally {
      setBusy(false);
      inputRef.current?.focus();
    }
  }

  return (
    <div className="flex flex-col justify-center gap-3 rounded-lg border border-border p-4">
      <p className="text-xs font-medium text-muted-foreground">Input Manual (scan gagal / tanpa HP)</p>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (nis.trim()) markHadir();
        }}
        className="flex gap-2"
      >
        <Input
          ref={inputRef}
          value={nis}
          onChange={(e) => setNis(e.target.value)}
          placeholder="Ketik NIS siswa"
          inputMode="numeric"
          className="h-9"
        />
        <Button type="submit" size="sm" disabled={busy || !nis.trim()}>
          {busy ? "…" : "Tandai Hadir"}
        </Button>
      </form>
      <p className="text-xs text-muted-foreground">
        Menandai ulang siswa yang sudah ada otomatis mengoreksi catatan sebelumnya.
      </p>
    </div>
  );
}

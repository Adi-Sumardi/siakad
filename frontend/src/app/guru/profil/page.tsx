"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { BookOpen, CalendarDays, ChevronRight, Loader2, Mail, Pencil, School, Smartphone, Star, Trophy, User } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { tanggalWaktu } from "@/lib/format";

type Profile = {
  user: {
    name: string;
    email: string | null;
    phone: string | null;
    unit: string | null;
    last_login_at: string | null;
    activated_at: string | null;
  };
  staff_profile: { nip: string | null; jabatan: string | null };
  academic_year: string | null;
  homeroom: { ulid: string; name: string; student_count: number }[];
  teaching: { classroom: { ulid: string; name: string }; subject: { ulid: string; name: string }; periods_per_week: number }[];
  weekly_schedule: { day_of_week: number; start_time: string; end_time: string; classroom: { ulid: string; name: string }; subject: string }[];
  extracurriculars: { ulid: string; name: string; member_count: number }[];
};

const DAYS = ["", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu", "Minggu"];

function InfoRow({ icon: Icon, label, value }: { icon: typeof Mail; label: string; value: string | null }) {
  return (
    <div className="flex items-start gap-3">
      <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
      <div className="min-w-0">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p className="break-words text-sm font-medium text-foreground">{value || "—"}</p>
      </div>
    </div>
  );
}

export default function GuruProfilePage() {
  const [profile, setProfile] = useState<Profile | null>(null);
  const [editing, setEditing] = useState(false);
  const [nip, setNip] = useState("");
  const [jabatan, setJabatan] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api
      .get<Profile>("/api/guru/profile")
      .then(setProfile)
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat profil."));
  }, []);

  function startEdit() {
    if (!profile) return;
    setNip(profile.staff_profile.nip ?? "");
    setJabatan(profile.staff_profile.jabatan ?? "");
    setEditing(true);
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      const next = await api.patch<Profile>("/api/guru/profile", { nip: nip.trim() || null, jabatan: jabatan.trim() || null });
      setProfile(next);
      setEditing(false);
      toast.success("Profil diperbarui.");
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menyimpan profil.");
    } finally {
      setSaving(false);
    }
  }

  if (!profile) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-48 w-full rounded-2xl" />
        <Skeleton className="h-64 w-full rounded-2xl" />
      </div>
    );
  }

  const days = [...new Set(profile.weekly_schedule.map((s) => s.day_of_week))];
  const totalPeriods = profile.weekly_schedule.length;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight text-foreground">
          <User className="size-6 text-primary" />
          Profil Saya
        </h1>
        <p className="mt-0.5 text-sm text-muted-foreground">
          Data akun Anda dan tugas mengajar tahun ajaran {profile.academic_year ?? "aktif"}.
        </p>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        {/* Identity */}
        <Card className="space-y-5 p-5 lg:col-span-1">
          <div className="flex items-center gap-3">
            <span className="grid size-14 shrink-0 place-items-center rounded-full bg-primary/10 text-xl font-bold text-primary">
              {profile.user.name.charAt(0)}
            </span>
            <div className="min-w-0">
              <p className="font-bold text-foreground">{profile.user.name}</p>
              <p className="text-xs text-muted-foreground">{profile.staff_profile.jabatan || "Guru"}</p>
              <p className="text-xs text-muted-foreground">{profile.user.unit}</p>
            </div>
          </div>

          {editing ? (
            <form onSubmit={save} className="space-y-3">
              <div className="grid gap-1.5">
                <Label htmlFor="nip">NIP / Nomor Induk Pegawai</Label>
                <Input id="nip" inputMode="numeric" value={nip} onChange={(e) => setNip(e.target.value)} maxLength={30} />
              </div>
              <div className="grid gap-1.5">
                <Label htmlFor="jabatan">Jabatan</Label>
                <Input
                  id="jabatan"
                  value={jabatan}
                  onChange={(e) => setJabatan(e.target.value)}
                  placeholder="mis. Guru Matematika"
                  maxLength={100}
                />
              </div>
              <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={saving}>
                  {saving && <Loader2 className="size-4 animate-spin" />}
                  Simpan
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setEditing(false)}>
                  Batal
                </Button>
              </div>
            </form>
          ) : (
            <div className="space-y-3">
              <InfoRow icon={User} label="NIP" value={profile.staff_profile.nip} />
              <InfoRow icon={Mail} label="Email (untuk masuk)" value={profile.user.email} />
              <InfoRow icon={Smartphone} label="No. WhatsApp (untuk masuk)" value={profile.user.phone} />
              <InfoRow
                icon={CalendarDays}
                label="Terakhir masuk"
                value={profile.user.last_login_at ? tanggalWaktu(profile.user.last_login_at) : null}
              />
              <Button size="sm" variant="outline" className="w-full gap-1.5" onClick={startEdit}>
                <Pencil className="size-3.5" />
                Ubah NIP & Jabatan
              </Button>
              <p className="text-xs text-muted-foreground">
                Nama, email, dan nomor WhatsApp dipakai untuk masuk ke SIAKAD. Untuk mengubahnya, hubungi admin unit.
              </p>
            </div>
          )}
        </Card>

        {/* Responsibilities */}
        <div className="space-y-4 lg:col-span-2">
          <Card className="p-5">
            <h2 className="flex items-center gap-2 text-sm font-bold text-foreground">
              <Star className="size-4 text-primary" />
              Wali Kelas
            </h2>
            {profile.homeroom.length === 0 ? (
              <p className="mt-2 text-sm text-muted-foreground">Anda tidak menjadi wali kelas tahun ajaran ini.</p>
            ) : (
              <div className="mt-3 flex flex-wrap gap-2">
                {profile.homeroom.map((c) => (
                  <Link
                    key={c.ulid}
                    href={`/guru/kelas/${c.ulid}`}
                    className="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm hover:border-primary"
                  >
                    <span className="font-semibold">Kelas {c.name}</span>
                    <span className="text-xs text-muted-foreground">{c.student_count} siswa</span>
                    <ChevronRight className="size-4 text-muted-foreground" />
                  </Link>
                ))}
              </div>
            )}
          </Card>

          <Card className="p-5">
            <h2 className="flex items-center gap-2 text-sm font-bold text-foreground">
              <BookOpen className="size-4 text-primary" />
              Mata Pelajaran yang Diajar
            </h2>
            {profile.teaching.length === 0 ? (
              <p className="mt-2 text-sm text-muted-foreground">
                Belum ada jadwal mengajar. Minta admin unit memasukkan Anda ke jadwal pelajaran.
              </p>
            ) : (
              <div className="mt-3 grid gap-2 sm:grid-cols-2">
                {profile.teaching.map((t) => (
                  <Link
                    key={`${t.classroom.ulid}-${t.subject.ulid}`}
                    href={`/guru/nilai/${t.classroom.ulid}/${t.subject.ulid}`}
                    className="flex items-center justify-between rounded-lg border border-border px-3 py-2 text-sm hover:border-primary"
                  >
                    <span>
                      <span className="font-semibold">{t.subject.name}</span>
                      <span className="text-muted-foreground"> · Kelas {t.classroom.name}</span>
                    </span>
                    <span className="text-xs text-muted-foreground">{t.periods_per_week}× / minggu</span>
                  </Link>
                ))}
              </div>
            )}
          </Card>

          {profile.extracurriculars.length > 0 && (
            <Card className="p-5">
              <h2 className="flex items-center gap-2 text-sm font-bold text-foreground">
                <Trophy className="size-4 text-primary" />
                Pembina Ekskul
              </h2>
              <div className="mt-3 flex flex-wrap gap-2">
                {profile.extracurriculars.map((e) => (
                  <Badge key={e.ulid} variant="default" className="text-xs">
                    {e.name} · {e.member_count} anggota
                  </Badge>
                ))}
              </div>
            </Card>
          )}
        </div>
      </div>

      {/* Weekly timetable */}
      <Card className="p-5">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="flex items-center gap-2 text-sm font-bold text-foreground">
            <School className="size-4 text-primary" />
            Jadwal Mengajar Mingguan
          </h2>
          {totalPeriods > 0 && <p className="text-xs text-muted-foreground">{totalPeriods} jam pelajaran per minggu</p>}
        </div>
        {days.length === 0 ? (
          <p className="mt-2 text-sm text-muted-foreground">Belum ada jadwal.</p>
        ) : (
          <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {days.map((d) => (
              <div key={d} className="rounded-xl border border-border p-3">
                <p className="mb-2 text-xs font-bold uppercase tracking-wide text-muted-foreground">{DAYS[d]}</p>
                <ul className="space-y-1.5">
                  {profile.weekly_schedule
                    .filter((s) => s.day_of_week === d)
                    .map((s, i) => (
                      <li key={i} className="flex items-baseline gap-2 text-sm">
                        <span className="w-24 shrink-0 tabular-nums text-muted-foreground">
                          {s.start_time}–{s.end_time}
                        </span>
                        <span className="min-w-0">
                          <span className="font-medium">{s.subject}</span>
                          <span className="text-muted-foreground"> · {s.classroom.name}</span>
                        </span>
                      </li>
                    ))}
                </ul>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}

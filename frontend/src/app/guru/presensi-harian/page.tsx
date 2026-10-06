"use client";

import { useCallback, useEffect, useState } from "react";
import { CalendarCheck2 } from "lucide-react";
import { toast } from "sonner";
import { CorrectionCell } from "@/components/attendance-correction";
import { LeaveReviewPanel } from "@/components/leave-review-panel";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { todayJakarta } from "@/lib/format";

type RosterEntry = {
  ulid: string;
  nama_lengkap: string;
  nis: string;
  classroom: string | null;
  attendance_status: "hadir" | "sakit" | "izin" | "alpa" | null;
  is_late: boolean;
  source: "self" | "wali_kelas" | "tu" | null;
  leave: "sakit" | "izin" | null;
};

type DailySession = {
  ulid: string;
  type: "masuk" | "pulang";
  status: "open" | "closed";
  is_open: boolean;
  opens_at: string;
  closes_at: string;
  late_after: string | null;
  roster: RosterEntry[];
};

type TodayResponse = {
  date: string;
  is_today: boolean;
  enabled: boolean;
  intake_mode: "wali_kelas" | "gerbang";
  homeroom_classrooms: { ulid: string; name: string }[];
  sessions: DailySession[];
};

const MARK_OPTIONS = [
  { value: "hadir", label: "Hadir" },
  { value: "terlambat", label: "Terlambat" },
  { value: "sakit", label: "Sakit" },
  { value: "izin", label: "Izin" },
  { value: "alpa", label: "Alpa" },
] as const;

const STATUS_BADGE: Record<string, string> = {
  hadir: "bg-good/10 text-good",
  sakit: "bg-warn/10 text-warn",
  izin: "bg-info/10 text-info",
  alpa: "bg-bad/10 text-bad",
};

const SOURCE_LABEL: Record<string, string> = {
  self: "check-in sendiri",
  wali_kelas: "dicatat wali kelas",
  tu: "otomatis/TU",
};

export default function GuruDailyAttendancePage() {
  const [today, setToday] = useState<TodayResponse | null>(null);
  const [marking, setMarking] = useState<string | null>(null);
  // Today by default; a past day is correction mode (audit 6 Okt 2026 #1).
  const [boardDate, setBoardDate] = useState(todayJakarta());
  const isToday = boardDate === todayJakarta();

  const load = useCallback(() => {
    api
      .get<TodayResponse>(`/api/guru/daily-attendance/today?date=${boardDate}`)
      .then(setToday)
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat presensi harian."));
  }, [boardDate]);

  useEffect(load, [load]);

  async function mark(session: DailySession, studentUlid: string, status: string) {
    setMarking(studentUlid + status);
    try {
      await api.post(`/api/guru/daily-attendance/sessions/${session.ulid}/records`, {
        student_ulid: studentUlid,
        status,
      });
      // Optimistic enough for a marking board: refresh the whole payload so
      // the tallies and the row both reflect the ledger, supersede included.
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menandai presensi.");
    } finally {
      setMarking(null);
    }
  }

  async function markAllHadir(session: DailySession) {
    const unmarked = session.roster.filter((s) => !s.attendance_status);
    if (unmarked.length === 0) return;

    setMarking("all");
    try {
      for (const student of unmarked) {
        await api.post(`/api/guru/daily-attendance/sessions/${session.ulid}/records`, {
          student_ulid: student.ulid,
          status: "hadir",
        });
      }
      toast.success(`${unmarked.length} siswa ditandai hadir.`);
      load();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Gagal menandai presensi.");
    } finally {
      setMarking(null);
    }
  }

  if (today && !today.enabled) {
    return (
      <div className="flex flex-col gap-4">
        <Header />
        <Card className="p-6 text-sm text-muted-foreground">
          Presensi harian unit Anda belum diaktifkan. Hubungi admin unit untuk
          mengaktifkannya di halaman <span className="font-medium text-foreground">Presensi Harian</span> milik unit.
        </Card>
      </div>
    );
  }

  if (today && today.homeroom_classrooms.length === 0) {
    return (
      <div className="flex flex-col gap-4">
        <Header />
        <Card className="p-6 text-sm text-muted-foreground">
          Anda belum tercatat sebagai wali kelas di kelas mana pun tahun ajaran
          ini, jadi tidak ada roster yang bisa ditandai. Presensi harian kelas
          ditandai oleh wali kelasnya masing-masing.
        </Card>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <Header />

      <LeaveReviewPanel endpoint="/api/guru/leave-requests" onChanged={load} />

      <Card className="flex flex-wrap items-center gap-2 p-3">
        <span className="text-sm font-medium">{isToday ? "Hari ini" : "Koreksi tanggal"}</span>
        <Input
          type="date"
          value={boardDate}
          max={todayJakarta()}
          onChange={(e) => e.target.value && setBoardDate(e.target.value)}
          className="h-8 w-40"
        />
        {!isToday && (
          <Button size="sm" variant="outline" onClick={() => setBoardDate(todayJakarta())}>
            Kembali ke hari ini
          </Button>
        )}
        {!isToday && (
          <p className="w-full text-xs text-muted-foreground">
            Mode koreksi: pilih status baru dan tulis alasannya. Catatan lama tetap tersimpan sebagai jejak.
          </p>
        )}
      </Card>

      {today === null && <Skeleton className="h-64 w-full" />}

      {today?.sessions.length === 0 && (
        <Card className="p-6 text-sm text-muted-foreground">
          {isToday
            ? "Hari ini bukan hari presensi untuk unit Anda (sesuai pengaturan hari aktif), atau sedang tidak ada semester aktif."
            : "Tidak ada sesi presensi pada tanggal ini (libur atau bukan hari presensi)."}
        </Card>
      )}

      {today?.sessions.map((session) => {
        const roster = session.roster;
        const tally = {
          hadir: roster.filter((s) => s.attendance_status === "hadir" && !s.is_late).length,
          terlambat: roster.filter((s) => s.attendance_status === "hadir" && s.is_late).length,
          sakit: roster.filter((s) => s.attendance_status === "sakit").length,
          izin: roster.filter((s) => s.attendance_status === "izin").length,
          alpa: roster.filter((s) => s.attendance_status === "alpa").length,
          belum: roster.filter((s) => !s.attendance_status).length,
        };

        return (
          <Card key={session.ulid} className="p-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 className="text-sm font-semibold">
                  {session.type === "masuk" ? "Absen Masuk" : "Absen Pulang"}
                  <span className="ml-2 font-normal text-muted-foreground">
                    {session.opens_at}–{session.closes_at} WIB
                    {session.type === "masuk" && session.late_after && ` · terlambat lewat ${session.late_after}`}
                  </span>
                </h2>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  Kelas: {today.homeroom_classrooms.map((c) => c.name).join(", ")} · {roster.length} siswa
                </p>
              </div>
              <div className="flex items-center gap-1.5 text-xs">
                <Badge variant={session.is_open ? "good" : "default"}>
                  {session.is_open ? "Jendela terbuka" : "Ditutup"}
                </Badge>
                {isToday && tally.belum > 0 && (
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={marking === "all"}
                    onClick={() => markAllHadir(session)}
                  >
                    {marking === "all" ? "Menandai…" : `Tandai ${tally.belum} hadir`}
                  </Button>
                )}
              </div>
            </div>

            <div className="mt-3 flex flex-wrap gap-1.5 text-xs">
              <Badge className={STATUS_BADGE.hadir}>Hadir {tally.hadir}</Badge>
              <Badge className="bg-warn/10 text-warn">Terlambat {tally.terlambat}</Badge>
              <Badge className={STATUS_BADGE.sakit}>Sakit {tally.sakit}</Badge>
              <Badge className={STATUS_BADGE.izin}>Izin {tally.izin}</Badge>
              <Badge className={STATUS_BADGE.alpa}>Alpa {tally.alpa}</Badge>
              <Badge variant="default">Belum {tally.belum}</Badge>
            </div>

            <div className="mt-4 flex flex-col gap-1.5">
              {roster.map((student) => (
                <div
                  key={student.ulid}
                  className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border p-2.5"
                >
                  <div>
                    <p className="text-sm font-medium">{student.nama_lengkap}</p>
                    <p className="text-xs text-muted-foreground">
                      NIS {student.nis}
                      {student.attendance_status && (
                        <>
                          {" · "}
                          {student.attendance_status === "hadir" && student.is_late ? "Terlambat" : STATUS_LABEL[student.attendance_status]}
                          {student.source && ` (${SOURCE_LABEL[student.source]})`}
                        </>
                      )}
                      {!student.attendance_status && student.leave && (
                        <span className="text-info"> · {student.leave === "sakit" ? "Sakit" : "Izin"} (pengajuan wali disetujui)</span>
                      )}
                    </p>
                  </div>
                  {!isToday ? (
                    <CorrectionCell
                      session={session}
                      row={student}
                      requireReason
                      endpoint={`/api/guru/daily-attendance/sessions/${session.ulid}/records`}
                      onDone={load}
                    />
                  ) : (
                  <div className="flex flex-wrap gap-1">
                    {MARK_OPTIONS.map((option) => {
                      const active =
                        student.attendance_status === option.value ||
                        (option.value === "terlambat" && student.attendance_status === "hadir" && student.is_late);
                      return (
                        <Button
                          key={option.value}
                          size="sm"
                          variant={active ? "default" : "ghost"}
                          disabled={marking !== null}
                          onClick={() => mark(session, student.ulid, option.value)}
                        >
                          {option.label}
                        </Button>
                      );
                    })}
                  </div>
                  )}
                </div>
              ))}
              {roster.length === 0 && (
                <p className="text-sm text-muted-foreground">Belum ada siswa aktif di kelas Anda.</p>
              )}
            </div>

            <p className="mt-3 text-xs text-muted-foreground">
              Menandai ulang siswa otomatis mengoreksi catatan sebelumnya — rekap
              kehadiran hari itu ikut diperbarui.
            </p>
          </Card>
        );
      })}
    </div>
  );
}

const STATUS_LABEL: Record<string, string> = {
  hadir: "Hadir",
  sakit: "Sakit",
  izin: "Izin",
  alpa: "Alpa",
};

function Header() {
  return (
    <div className="flex items-center gap-2">
      <CalendarCheck2 className="size-5 text-primary" />
      <div>
        <h1 className="text-xl font-bold tracking-tight">Presensi Harian</h1>
        <p className="text-sm text-muted-foreground">
          Tandai kehadiran masuk & pulang siswa kelas Anda — sekali sehari, bukan per mapel.
        </p>
      </div>
    </div>
  );
}

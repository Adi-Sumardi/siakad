"use client";

import { useEffect, useState, useSyncExternalStore } from "react";
import Link from "next/link";
import { Award, CalendarCheck, ChevronRight, Clock, GraduationCap, School, Star } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";

type TodayInfo = { total: number; mine: number; first_start: string | null; last_end: string | null };
type Classroom = {
  ulid: string;
  name: string;
  tingkat: number;
  is_homeroom: boolean;
  homeroom_teacher: string | null;
  student_count: number;
  schedules_today: TodayInfo;
};

/** "07:00:00" -> "07:00"; null-safe so the time range can be composed inline. */
function jam(t: string | null): string {
  return t ? t.slice(0, 5) : "—";
}

const emptySubscribe = () => () => {};

/**
 * Client-only weekday label for the "Jadwal Hari Ini" heading. The server
 * prerenders this shell in its own timezone, and 00:00-07:00 WIB it would
 * otherwise name the wrong day - and the backend picks the day by
 * Asia/Jakarta, so the label has to match that clock, not the server's.
 * useSyncExternalStore's server snapshot returns null (heading renders
 * without the day), the real weekday once hydrated.
 */
function useDayNameToday(): string | null {
  return useSyncExternalStore(
    emptySubscribe,
    () => new Date().toLocaleDateString("id-ID", { weekday: "long", timeZone: "Asia/Jakarta" }),
    () => null,
  );
}

function ClassroomCard({ classroom, today = false }: { classroom: Classroom; today?: boolean }) {
  const c = classroom;

  return (
    <Link href={`/guru/kelas/${c.ulid}`} className="group">
      <Card
        className={`h-full p-5 border-border/80 group-hover:border-primary transition-all duration-200 shadow-xs group-hover:shadow-md flex flex-col justify-between ${
          today ? "border-primary/40 bg-primary/[0.03]" : ""
        }`}
      >
        <div>
          <div className="flex items-start justify-between gap-2">
            <span
              className={`grid size-11 place-items-center rounded-xl transition-colors ${
                today
                  ? "bg-primary text-primary-foreground"
                  : "bg-primary/10 text-primary group-hover:bg-primary group-hover:text-primary-foreground"
              }`}
            >
              {today ? <CalendarCheck className="size-6" /> : <GraduationCap className="size-6" />}
            </span>

            <div className="flex flex-col items-end gap-1">
              {today && c.schedules_today.mine > 0 && (
                <Badge variant="primary" className="text-[10px] font-bold">
                  Anda Mengajar
                </Badge>
              )}
              {c.is_homeroom && (
                <Badge variant="default" className="gap-1 text-[10px] font-bold">
                  <Star className="size-3 fill-current" />
                  <span>Wali Kelas Anda</span>
                </Badge>
              )}
            </div>
          </div>

          <div className="mt-4">
            <h3 className="text-lg font-bold text-foreground group-hover:text-primary transition-colors">
              Kelas {c.name}
            </h3>
            {today ? (
              <p className="text-xs text-muted-foreground mt-0.5 flex items-center gap-1">
                <Clock className="size-3.5" />
                <span>
                  {c.schedules_today.total} sesi · {jam(c.schedules_today.first_start)}–{jam(c.schedules_today.last_end)}
                </span>
                <span>· {c.student_count} siswa</span>
              </p>
            ) : (
              <p className="text-xs text-muted-foreground mt-0.5">
                {c.student_count} siswa terdaftar
                {c.homeroom_teacher && !c.is_homeroom ? ` · Wali: ${c.homeroom_teacher}` : ""}
              </p>
            )}
          </div>
        </div>

        <div className="mt-4 pt-3 border-t border-border/60 flex items-center justify-between text-xs font-semibold text-primary">
          <span>Buka Rombel Siswa</span>
          <ChevronRight className="size-4 group-hover:translate-x-1 transition-transform" />
        </div>
      </Card>
    </Link>
  );
}

export default function GuruClassroomsPage() {
  const [classrooms, setClassrooms] = useState<Classroom[] | null>(null);
  const dayName = useDayNameToday();

  useEffect(() => {
    api
      .get<{ classrooms: Classroom[] }>("/api/guru/classrooms")
      .then((d) => setClassrooms(d.classrooms))
      .catch((err) => toast.error(err instanceof ApiError ? err.message : "Gagal memuat daftar kelas."));
  }, []);

  // Classes with at least one period today, the day's earliest bell first -
  // the order a teacher will actually walk through them.
  const withScheduleToday = (classrooms ?? [])
    .filter((c) => c.schedules_today.total > 0)
    .sort((a, b) => (a.schedules_today.first_start ?? "").localeCompare(b.schedules_today.first_start ?? ""));

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Portal Guru & Wali Kelas</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Kelola kedisiplinan poin siswa, input apresiasi prestasi, dan pantau rekap kelas.
          </p>
        </div>

        <Link href="/guru/prestasi">
          <Button className="gap-2 shadow-xs">
            <Award className="size-4" />
            <span>Catat Prestasi Siswa</span>
          </Button>
        </Link>
      </div>

      {classrooms === null && (
        <div className="space-y-4">
          <Skeleton className="h-6 w-56" />
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Skeleton className="h-32 w-full rounded-2xl" />
            <Skeleton className="h-32 w-full rounded-2xl" />
            <Skeleton className="h-32 w-full rounded-2xl" />
          </div>
        </div>
      )}

      {classrooms?.length === 0 && (
        <Card className="p-8 text-center text-sm text-muted-foreground">
          Belum ada kelas yang terdaftar di unit Anda.
        </Card>
      )}

      {classrooms !== null && classrooms.length > 0 && (
        <>
          {/* Section 1: classes that have a schedule today */}
          <section className="space-y-4">
            <div>
              <h2 className="flex items-center gap-1.5 text-base font-bold text-foreground">
                <CalendarCheck className="size-4.5 text-primary" />
                Jadwal Hari Ini{dayName ? ` — ${dayName}` : ""}
              </h2>
              <p className="text-xs text-muted-foreground mt-0.5">
                Kelas di unit Anda yang memiliki jadwal pelajaran hari ini.
              </p>
            </div>

            {withScheduleToday.length === 0 ? (
              <Card className="p-6 text-center text-sm text-muted-foreground">
                Tidak ada kelas dengan jadwal pelajaran hari ini.
              </Card>
            ) : (
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {withScheduleToday.map((c) => (
                  <ClassroomCard key={c.ulid} classroom={c} today />
                ))}
              </div>
            )}
          </section>

          {/* Section 2: every classroom in the unit */}
          <section className="space-y-4">
            <div>
              <h2 className="flex items-center gap-1.5 text-base font-bold text-foreground">
                <School className="size-4.5 text-primary" />
                Semua Kelas
              </h2>
              <p className="text-xs text-muted-foreground mt-0.5">Keseluruhan kelas aktif di unit Anda.</p>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {classrooms.map((c) => (
                <ClassroomCard key={c.ulid} classroom={c} />
              ))}
            </div>
          </section>
        </>
      )}
    </div>
  );
}

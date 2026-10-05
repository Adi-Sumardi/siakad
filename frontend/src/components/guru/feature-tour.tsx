"use client";

import {
  Award,
  CalendarCheck,
  CalendarCheck2,
  CircleHelp,
  ClipboardList,
  School,
  Sparkles,
  Trophy,
} from "lucide-react";
import { FeatureTour, type TourStep } from "@/components/feature-tour";

/**
 * The guru "Panduan Fitur" tour: what the spotlight walks a teacher through,
 * on top of the shared engine (components/feature-tour.tsx). Module-level so
 * the array identity stays stable across renders.
 */
const GURU_STEPS: TourStep[] = [
  {
    title: "Tur Singkat Portal Guru",
    body: "Kenali fitur utama portal dalam ±1 menit. Bisa dilewati kapan saja - panduan ini selalu bisa dibuka ulang dari Beranda.",
    icon: Sparkles,
  },
  {
    target: "jadwal-hari-ini",
    title: "Jadwal Hari Ini",
    body: "Kelas di unit Anda yang memiliki jadwal hari ini, urut dari bel paling pagi. Ketuk kartunya untuk membuka daftar siswa dan mencatat poin kedisiplinan.",
    icon: CalendarCheck,
    placement: "below",
  },
  {
    target: "semua-kelas",
    title: "Semua Kelas",
    body: "Seluruh kelas aktif di unit Anda. Kartu berbintang adalah kelas yang Anda wali - dari sanalah presensi harian dicatat.",
    icon: School,
    placement: "below",
  },
  {
    target: "nav-presensi-harian",
    title: "Presensi Harian",
    body: "Tandai kehadiran masuk & pulang siswa kelas yang Anda wali - sekali sehari, bukan per mapel.",
    icon: CalendarCheck2,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-nilai",
    title: "Nilai",
    body: "Kelas dan mata pelajaran yang Anda ampu, sesuai jadwal pelajaran. Input nilai akhir siswa langsung dari sini.",
    icon: ClipboardList,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-prestasi",
    title: "Catat Prestasi",
    body: "Ajukan prestasi siswa maupun prestasi pribadi Anda - diverifikasi wali kelas atau admin unit sebelum poin diberikan.",
    icon: Award,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-ekskul",
    title: "Ekskul Saya",
    body: "Kegiatan yang Anda bina: kelola anggota dan pantau siswa yang terdaftar.",
    icon: Trophy,
    placement: "right",
    nav: true,
  },
  {
    target: "tour-replay",
    title: "Selamat Mengajar",
    body: "Panduan ini selalu tersedia lewat tombol Panduan Fitur di Beranda. Terima kasih, wassalamu'alaikum.",
    icon: CircleHelp,
    placement: "below",
  },
];

export function GuruFeatureTour({ open, onFinish }: { open: boolean; onFinish: () => void }) {
  return <FeatureTour open={open} steps={GURU_STEPS} onFinish={onFinish} />;
}

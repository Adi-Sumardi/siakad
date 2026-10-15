"use client";

import {
  Award,
  CalendarCheck,
  CalendarCheck2,
  CheckSquare,
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
    body: "Kenali fitur utama portal dalam ±1 menit. Bisa dilewati kapan saja - panduan ini bisa dibuka lagi dari halaman Kelas saya.",
    icon: Sparkles,
  },
  {
    target: "jadwal-hari-ini",
    title: "Jadwal Hari Ini",
    body: "Kelas yang Anda ajar hari ini, urut dari jam pelajaran paling pagi. Ketuk kartunya untuk membuka presensi mapel, daftar siswa, dan pencatatan poin.",
    icon: CalendarCheck,
    placement: "below",
  },
  {
    target: "semua-kelas",
    title: "Kelas yang Anda Ajar",
    body: "Semua kelas yang Anda ajar tahun ajaran ini. Kartu bertanda bintang \"Wali Kelas Anda\" adalah kelas tempat Anda menjadi wali kelas.",
    icon: School,
    placement: "below",
  },
  {
    target: "nav-presensi-harian",
    title: "Presensi Harian",
    body: "Khusus wali kelas: catat kehadiran masuk & pulang siswa di kelas perwalian Anda, cukup sekali sehari (bukan per mata pelajaran).",
    icon: CalendarCheck2,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-nilai",
    title: "Nilai",
    body: "Mata pelajaran yang Anda ajar di tiap kelas. Buat kolom tugas sebanyak yang diperlukan (Tugas 1, Tugas 2, ...), lalu isi nilai tugas, UTS, dan UAS dalam satu lembar.",
    icon: ClipboardList,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-prestasi",
    title: "Catat Prestasi",
    body: "Catat prestasi siswa atau prestasi Anda sendiri. Prestasi siswa diperiksa wali kelasnya, prestasi guru diperiksa admin unit, sebelum poin diberikan.",
    icon: Award,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-persetujuan-poin",
    title: "Persetujuan Poin",
    body: "Poin yang Anda catat belum langsung berlaku: pelanggaran disetujui wali kelas lalu waka kesiswaan (berat juga kepala sekolah), poin positif cukup wali kelas. Jika Anda wali kelas, catatan guru lain untuk siswa Anda menunggu di sini.",
    icon: CheckSquare,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-ekskul",
    title: "Ekskul Saya",
    body: "Khusus pembina ekskul: kelola anggota, presensi latihan, dan nilai ekskul untuk rapor.",
    icon: Trophy,
    placement: "right",
    nav: true,
  },
  {
    target: "tour-replay",
    title: "Selamat Mengajar",
    body: "Panduan ini bisa dibuka lagi kapan saja lewat tombol Panduan Fitur di halaman Kelas saya. Terima kasih, wassalamu'alaikum.",
    icon: CircleHelp,
    placement: "below",
  },
];

export function GuruFeatureTour({ open, onFinish }: { open: boolean; onFinish: () => void }) {
  return <FeatureTour open={open} steps={GURU_STEPS} onFinish={onFinish} />;
}

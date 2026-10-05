"use client";

import {
  Bell,
  BookOpen,
  CircleHelp,
  GraduationCap,
  LayoutGrid,
  SlidersHorizontal,
  Sparkles,
  Users,
  Wallet,
} from "lucide-react";
import { FeatureTour, type TourStep } from "@/components/feature-tour";

/**
 * The admin unit "Panduan Fitur" tour: what the spotlight walks a unit admin
 * through, on top of the shared engine (components/feature-tour.tsx).
 * Module-level so the array identity stays stable across renders. Central
 * admin (role "admin") does not run a tour today; if it ever does, its extra
 * nav items (Manajemen Unit, Log Aktivitas, Monitoring) simply join the
 * "Sistem" grouping's description - the steps below highlight whole sidebar
 * GROUPS ("nav-group-<slug>", heading + items together), not single menu
 * entries, because that is how the portal is organised: a unit admin thinks
 * in "keuangan / akademik / kesiswaan", not in 21 flat links.
 */
const ADMIN_UNIT_STEPS: TourStep[] = [
  {
    title: "Tur Singkat Portal Admin Unit",
    body: "Kenali fitur utama portal dalam ±1 menit. Bisa dilewati kapan saja - panduan ini selalu bisa dibuka ulang dari Beranda.",
    icon: Sparkles,
  },
  {
    target: "kpi-grid",
    title: "Ringkasan Unit",
    body: "Siswa aktif, kas masuk, piutang, dan kehadiran hari ini dalam satu layar. Cakupan angka keuangan bisa diganti lewat TA berjalan / semua periode.",
    icon: LayoutGrid,
    placement: "below",
  },
  {
    target: "tindak-lanjut",
    title: "Perlu Tindak Lanjut",
    body: "Hal yang perlu ditindak hari ini: prestasi menunggu verifikasi, nilai di bawah KKM, hingga tagihan lewat jatuh tempo. Ketuk baris untuk membuka halamannya.",
    icon: Bell,
    placement: "below",
  },
  {
    target: "nav-group-siswa-kelas",
    title: "Grup Siswa & Kelas",
    body: "Database siswa unit Anda beserta kelas dan tagihannya: Data Siswa & SPP, Data Kelas, sampai Kenaikan Kelas tiap akhir tahun ajaran.",
    icon: GraduationCap,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-group-akademik",
    title: "Grup Akademik",
    body: "Urusan akademik harian: Jadwal Pelajaran, Presensi Harian per kelas, serta Nilai & Rapor siswa.",
    icon: BookOpen,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-group-kesiswaan",
    title: "Grup Kesiswaan",
    body: "Kehidupan siswa di luar pelajaran: Ekstrakurikuler, Poin & Tata Tertib, Prestasi Siswa, dan Pengumuman.",
    icon: Users,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-group-keuangan",
    title: "Grup Keuangan",
    body: "Semua urusan SPP dan biaya ada di sini: Tagihan & Transaksi, Terbitkan SPP Massal, Laporan Keuangan, Pengaturan Biaya & SPP, hingga Diskon & Beasiswa.",
    icon: Wallet,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-group-sistem",
    title: "Grup Sistem",
    body: "Perkakas akun dan sistem: buat akun guru unit Anda (satu per satu atau impor CSV) lewat Manajemen Pengguna. Mengubah dan menghapus akun tetap urusan admin pusat.",
    icon: SlidersHorizontal,
    placement: "right",
    nav: true,
  },
  {
    target: "tour-replay",
    title: "Selamat Bekerja",
    body: "Panduan ini selalu tersedia lewat tombol Panduan Fitur di Beranda. Terima kasih, wassalamu'alaikum.",
    icon: CircleHelp,
    placement: "below",
  },
];

export function AdminUnitFeatureTour({ open, onFinish }: { open: boolean; onFinish: () => void }) {
  return <FeatureTour open={open} steps={ADMIN_UNIT_STEPS} onFinish={onFinish} />;
}

"use client";

import {
  Bell,
  CircleHelp,
  GraduationCap,
  LayoutGrid,
  Receipt,
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
 * nav items (Manajemen Unit, Log Aktivitas, Monitoring) can join as optional
 * steps - they are filtered out of the DOM for a unit admin, and optional
 * steps slip past exactly then.
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
    target: "nav-siswa",
    title: "Data Siswa & SPP",
    body: "Database siswa unit Anda beserta kelas dan tagihannya. Grup Siswa & Kelas juga menampung kenaikan kelas tiap akhir tahun ajaran.",
    icon: GraduationCap,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-tagihan",
    title: "Tagihan & Transaksi",
    body: "Semua tagihan SPP dan pembayaran di unit Anda, lengkap dengan status dan riwayat transaksinya.",
    icon: Receipt,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-generate",
    title: "Terbitkan SPP Massal",
    body: "Terbitkan tagihan SPP untuk banyak siswa sekaligus per kelas dan jenis biaya - tombol Terbitkan SPP di Beranda juga mengarah ke sini.",
    icon: Wallet,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-users",
    title: "Manajemen Pengguna",
    body: "Buat akun guru di unit Anda, satu per satu atau lewat impor CSV. Mengubah dan menghapus akun tetap urusan admin pusat.",
    icon: Users,
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

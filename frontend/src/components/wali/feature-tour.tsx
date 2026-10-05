"use client";

import {
  Award,
  Bell,
  CircleHelp,
  CreditCard,
  GraduationCap,
  LayoutGrid,
  Megaphone,
  Receipt,
  Sparkles,
  User,
} from "lucide-react";
import { FeatureTour, type TourStep } from "@/components/feature-tour";

/**
 * The wali "Panduan Fitur" tour: what the spotlight walks a parent through,
 * on top of the shared engine (components/feature-tour.tsx). Module-level so
 * the array identity stays stable across renders.
 */
const WALI_STEPS: TourStep[] = [
  {
    title: "Tur Singkat Portal Wali",
    body: "Kenali fitur utama portal dalam ±1 menit. Bisa dilewati kapan saja - panduan ini selalu bisa dibuka ulang dari Beranda.",
    icon: Sparkles,
  },
  {
    target: "stat-grid",
    title: "Ringkasan Beranda",
    body: "Total tunggakan SPP, kabar pengumuman, dan poin kedisiplinan ananda dalam satu layar. Ketuk kartu untuk membuka halamannya.",
    icon: LayoutGrid,
    placement: "below",
  },
  {
    target: "anak-cards",
    title: "Kartu Ananda",
    body: "Ketuk nama ananda untuk membuka buku rekap lengkap: poin kedisiplinan, presensi, nilai rapor, ekstrakurikuler, hingga prestasi.",
    icon: GraduationCap,
    placement: "below",
  },
  {
    target: "bill-alert",
    title: "Lonceng Notifikasi",
    body: "Tagihan yang dibuka atau mendekati jatuh tempo, serta pembayaran yang sudah diterima sekolah, muncul di sini - diperbarui otomatis tiap 1 menit.",
    icon: Bell,
    placement: "below",
    optional: true,
  },
  {
    target: "nav-tagihan",
    title: "Tagihan SPP",
    body: "Daftar tagihan semua ananda. Pilih beberapa sekaligus dan bayar lewat Virtual Account Muamalat/BSI, atau cicilan dengan nominal bebas.",
    icon: Receipt,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-pembayaran",
    title: "Riwayat Bayar",
    body: "Semua transaksi beserta statusnya: salin nomor VA, unduh invoice dan kuitansi PDF, lengkap dengan petunjuk bayar per bank.",
    icon: CreditCard,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-prestasi",
    title: "Prestasi Siswa",
    body: "Rekap prestasi ananda, plus ajukan prestasi baru - diverifikasi staf sekolah sebelum masuk rekap.",
    icon: Award,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-informasi",
    title: "Pengumuman",
    body: "Kabar dan agenda dari sekolah, unit, jenjang, hingga kelas ananda.",
    icon: Megaphone,
    placement: "right",
    nav: true,
  },
  {
    target: "nav-profil",
    title: "Profil Akun",
    body: "Identitas akun, kontak untuk kode masuk (OTP), daftar ananda yang terhubung, dan keluar.",
    icon: User,
    placement: "right",
    nav: true,
  },
  {
    target: "tour-replay",
    title: "Selamat Menjelajah",
    body: "Panduan ini selalu tersedia lewat tombol Panduan Fitur di Beranda. Terima kasih, wassalamu'alaikum.",
    icon: CircleHelp,
    placement: "below",
  },
];

export function WaliFeatureTour({ open, onFinish }: { open: boolean; onFinish: () => void }) {
  return <FeatureTour open={open} steps={WALI_STEPS} onFinish={onFinish} />;
}

"use client";

import { ApprovalQueue } from "@/components/points/approval-queue";
import { useAuth } from "@/lib/auth/auth-context";

const INTRO: Record<string, string> = {
  waka_kesiswaan:
    "Catatan pelanggaran yang sudah disetujui wali kelas. Setujui untuk memberlakukannya - pelanggaran berat masih diteruskan ke kepala sekolah.",
  kepala_sekolah: "Pelanggaran berat yang sudah disetujui wali kelas dan waka kesiswaan. Keputusan Anda adalah tahap terakhir.",
};

export default function AdminPointApprovalsPage() {
  const { user } = useAuth();

  return (
    <ApprovalQueue
      apiBase="/api/admin"
      intro={
        INTRO[user?.role ?? ""] ??
        "Selama unit belum punya akun waka kesiswaan atau kepala sekolah yang aktif, admin unit mengambil keputusan tahap tersebut di sini."
      }
    />
  );
}

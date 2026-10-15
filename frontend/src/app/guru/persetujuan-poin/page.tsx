"use client";

import { ApprovalQueue } from "@/components/points/approval-queue";

export default function GuruPointApprovalsPage() {
  return (
    <ApprovalQueue
      apiBase="/api/guru"
      intro="Poin yang dicatat guru lain untuk siswa kelas perwalian Anda. Setujui agar diteruskan ke waka kesiswaan (pelanggaran) atau langsung berlaku (poin positif), atau tolak dengan alasan."
    />
  );
}

export type PointRecord = {
  ulid: string;
  type: "violation" | "merit";
  points: number;
  occurred_on: string;
  description: string;
  evidence_path: boolean;
  rule: { code: string; name: string; category: string } | null;
  recorded_by: string | null;
  status: "recorded" | "revoked";
  revoked_at: string | null;
  revoke_reason: string | null;
  created_at: string;
};

export type PointThresholdInfo = {
  label: string;
  color: string | null;
  action?: string | null;
};

export type PointSummary = {
  balance: number;
  term: string | null;
  threshold: PointThresholdInfo | null;
  records: PointRecord[];
};

export type AchievementStatus = "pending" | "verified" | "rejected";

export type Achievement = {
  ulid: string;
  nama_prestasi: string;
  kategori: string;
  tingkat: string;
  juara: string | null;
  nama_event: string | null;
  penyelenggara: string | null;
  tanggal_event: string | null;
  tempat_event: string | null;
  has_sertifikat: boolean;
  has_foto: boolean;
  source: "pmb" | "sekolah";
  status: AchievementStatus;
  point_awarded: number | null;
  verified_at: string | null;
  rejection_reason: string | null;
  created_at: string;
};

export type Announcement = {
  ulid: string;
  title: string;
  body: string;
  scope: "school" | "unit" | "classroom";
  school_unit: string | null;
  classroom: string | null;
  file_name: string | null;
  has_file: boolean;
  is_pinned: boolean;
  published_at: string | null;
};

export const KATEGORI_OPTIONS = ["Akademik", "Non-Akademik", "Olahraga", "Seni", "Lainnya"] as const;

export const TINGKAT_OPTIONS = [
  "Kelas", "Sekolah", "Kecamatan", "Kabupaten/Kota", "Provinsi", "Nasional", "Internasional",
] as const;

export const JUARA_OPTIONS = ["1", "2", "3", "Harapan 1", "Harapan 2", "Harapan 3", "Peserta"] as const;

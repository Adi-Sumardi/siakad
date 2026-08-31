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

export type StudentSummary = {
  ulid: string;
  nama_lengkap: string;
  nama_panggilan: string | null;
};

export type PointSummary = {
  student: StudentSummary;
  balance: number;
  term: string | null;
  threshold: PointThresholdInfo | null;
  records: PointRecord[];
};

export type AttendanceStatus = "hadir" | "sakit" | "izin" | "alpa";

export const ATTENDANCE_STATUS_LABEL: Record<AttendanceStatus, string> = {
  hadir: "Hadir",
  sakit: "Sakit",
  izin: "Izin",
  alpa: "Alpa",
};

export type AttendanceRecord = {
  ulid: string;
  attendance_status: AttendanceStatus;
  occurred_on: string;
  description: string | null;
  source: "self" | "guru";
  recorded_by: string | null;
  record_status: "recorded" | "revoked";
  revoked_at: string | null;
  revoke_reason: string | null;
  created_at: string;
};

export type AttendanceSummary = {
  hadir: number;
  sakit: number;
  izin: number;
  alpa: number;
};

export type AttendanceOverview = {
  student: StudentSummary;
  term: string | null;
  summary: AttendanceSummary;
  records: AttendanceRecord[];
};

export type Subject = {
  ulid: string;
  school_unit: string | null;
  code: string;
  name: string;
};

export type ClassSchedule = {
  ulid: string;
  subject: { ulid: string; name: string };
  teacher: { ulid: string; name: string } | null;
  day_of_week: number;
  start_time: string;
  end_time: string;
};

export const DAY_OF_WEEK_LABEL: Record<number, string> = {
  1: "Senin",
  2: "Selasa",
  3: "Rabu",
  4: "Kamis",
  5: "Jumat",
  6: "Sabtu",
};

/** One lesson period today, as a teacher's classroom page offers to open attendance for. */
export type TodaySchedule = {
  ulid: string;
  subject: string;
  teacher: string | null;
  start_time: string;
  end_time: string;
};

export type AttendanceSessionInfo = {
  ulid: string;
  token: string;
  expires_at: string;
};

/** One row in a session's live roster - null status/source means the student hasn't checked in yet. */
export type AttendanceRosterEntry = {
  ulid: string;
  nama_lengkap: string;
  nis: string | null;
  record_ulid: string | null;
  attendance_status: AttendanceStatus | null;
  source: "self" | "guru" | null;
  checked_in_at: string | null;
};

export type GradeCategory = "tugas" | "uts" | "uas";

export const GRADE_CATEGORY_LABEL: Record<GradeCategory, string> = {
  tugas: "Tugas",
  uts: "UTS",
  uas: "UAS",
};

export type TeachingAssignment = {
  classroom: { ulid: string; name: string };
  subject: { ulid: string; name: string };
};

export type GradeRosterEntry = {
  ulid: string;
  nama_lengkap: string;
  nis: string | null;
  tugas: number | null;
  uts: number | null;
  uas: number | null;
};

export type SubjectGradeSummary = {
  subject: { ulid: string; name: string };
  tugas: number | null;
  uts: number | null;
  uas: number | null;
  final: number | null;
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

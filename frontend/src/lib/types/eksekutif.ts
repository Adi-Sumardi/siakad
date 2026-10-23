/** Payload of GET /api/eksekutif/ringkasan (Direktur Pendidikan & BPH). */

export type ExecAlert = {
  tone: "kritis" | "waspada";
  title: string;
  body: string;
  unit_code: string;
};

export type MonthPoint = { key: string; label: string; rate?: number | null; amount?: number };

export type EducationRow = {
  code: string;
  label: string;
  short: string;
  students_active: number;
  attendance_rate: number | null;
  grades_average: number | null;
  needing_attention: number;
  data_complete: { attendance: boolean; grades: boolean };
  // Direktur only:
  teachers?: number;
  students_per_teacher?: number | null;
  attendance_last_date?: string | null;
  attendance_missed_days?: number | null;
  grades_graded_pct?: number | null;
  grades_below_kkm?: number;
  grades_below_kkm_pct?: number | null;
  violation_students?: number;
  violation_pct?: number | null;
  achievements?: number;
  collection_rate?: number | null;
};

export type FinanceRow = {
  code: string;
  label: string;
  short: string;
  billed: number;
  paid: number;
  collection_rate: number | null;
  arrears: [number, number, number, number];
  arrears_total: number;
  discount: number;
};

export type ExecSummary = {
  period: { academic_year: string | null; term_label: string | null; billing_label: string | null; generated_at: string };
  viewer: { role: "direktur_pendidikan" | "bph"; role_label: string; is_preview: boolean };
  units: Array<{ code: string; label: string; short: string }>;
  selected_unit: { code: string; label: string } | null;
  alerts: ExecAlert[];
  alerts_more: number;
  thresholds: {
    attendance_warn: number;
    attendance_crit: number;
    collection_warn: number;
    collection_crit: number;
    kkm: number | null;
  };
  pendidikan: {
    tiles: {
      students_active: number;
      teachers: number;
      attendance_rate: number | null;
      grades_average: number | null;
      grades_graded_pct: number | null;
      violation_students: number;
      violation_pct: number | null;
      achievements: number;
      needing_attention: number;
      collection_rate: number | null;
    };
    per_unit: EducationRow[];
    attendance_trend: MonthPoint[];
  };
  enrollment_trend: {
    years: string[];
    units: Array<{ code: string; label: string; counts: number[] }>;
    totals: number[];
  };
  keuangan?: {
    tiles: {
      billed: number;
      paid: number;
      collection_rate: number | null;
      arrears_total: number;
      arrears_over_90: number;
      arrears_over_90_pct: number | null;
      discount: number;
      discount_pct: number | null;
    };
    per_unit: FinanceRow[];
    cash_trend: MonthPoint[];
  };
};

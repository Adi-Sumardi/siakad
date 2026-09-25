/**
 * The single source of the school's fixed jenjang ladder (feature batch
 * Poin 1): every jenjang picker renders THIS order - never alphabetical,
 * never whatever the API happened to return.
 *
 * Sixteen entries: RA, the two PG programs (SB/KB), the two TK years, then
 * every numbered SD/SMP/SMA year. The early-childhood split is matched
 * from the classroom NAME by conscious decision: those programs all sit on
 * tingkat 0 in the schema and only the naming convention distinguishes
 * them - a class with no known prefix stays outside the granular filters
 * (coarse group filters still catch it). Name the classes "SB …", "KB …",
 * "TK-A …", "TK-B …".
 *
 * The backend mirror is app/Support/Jenjang.php - keys and order must stay
 * in step.
 */

export type JenjangGroupKey = "ra" | "pg" | "tk" | "sd" | "smp" | "sma";

export type JenjangEntry = {
  key: string;
  label: string;
  group: JenjangGroupKey;
  tingkat: number | null;
  /** Lowercased, separator-stripped name prefixes ("tka" matches "TK-A 1"). */
  nameTokens: string[] | null;
};

export const JENJANG: readonly JenjangEntry[] = [
  { key: "ra", label: "RA", group: "ra", tingkat: null, nameTokens: null },
  { key: "pg-sb", label: "PG-SB (Sanggar Bermain)", group: "pg", tingkat: null, nameTokens: ["sb"] },
  { key: "pg-kb", label: "PG-KB (Kelompok Bermain)", group: "pg", tingkat: null, nameTokens: ["kb"] },
  { key: "tk-a", label: "TK-A", group: "tk", tingkat: null, nameTokens: ["tka"] },
  { key: "tk-b", label: "TK-B", group: "tk", tingkat: null, nameTokens: ["tkb"] },
  { key: "sd-1", label: "SD-1", group: "sd", tingkat: 1, nameTokens: null },
  { key: "sd-2", label: "SD-2", group: "sd", tingkat: 2, nameTokens: null },
  { key: "sd-3", label: "SD-3", group: "sd", tingkat: 3, nameTokens: null },
  { key: "sd-4", label: "SD-4", group: "sd", tingkat: 4, nameTokens: null },
  { key: "sd-5", label: "SD-5", group: "sd", tingkat: 5, nameTokens: null },
  { key: "sd-6", label: "SD-6", group: "sd", tingkat: 6, nameTokens: null },
  { key: "smp-7", label: "SMP-7", group: "smp", tingkat: 7, nameTokens: null },
  { key: "smp-8", label: "SMP-8", group: "smp", tingkat: 8, nameTokens: null },
  { key: "smp-9", label: "SMP-9", group: "smp", tingkat: 9, nameTokens: null },
  { key: "sma-10", label: "SMA-10", group: "sma", tingkat: 10, nameTokens: null },
  { key: "sma-11", label: "SMA-11", group: "sma", tingkat: 11, nameTokens: null },
  { key: "sma-12", label: "SMA-12", group: "sma", tingkat: 12, nameTokens: null },
];

export const JENJANG_GROUPS: readonly { key: JenjangGroupKey; label: string }[] = [
  { key: "ra", label: "RA" },
  { key: "pg", label: "PG" },
  { key: "tk", label: "TK" },
  { key: "sd", label: "SD" },
  { key: "smp", label: "SMP" },
  { key: "sma", label: "SMA" },
];

export function jenjangEntry(key: string): JenjangEntry | null {
  return JENJANG.find((j) => j.key === key) ?? null;
}

/** A group key maps to itself; a granular key resolves to its group. */
export function groupOf(key: string): JenjangGroupKey | null {
  if (JENJANG_GROUPS.some((g) => g.key === key)) return key as JenjangGroupKey;
  return jenjangEntry(key)?.group ?? null;
}

function normalizeName(name: string): string {
  return name.trim().toLowerCase().replace(/[\s\-_.]+/g, "");
}

/** Anything classroom-shaped the ladder filters on. */
export type ClassroomLike = {
  name: string;
  tingkat: number | null;
  school_unit?: { jenjang_group?: string | null } | null;
};

/** Does this classroom sit on the given ladder key? (null key = all.) */
export function matchesClassroom(classroom: ClassroomLike, key: string | null): boolean {
  if (!key) return true;

  const entry = jenjangEntry(key);
  const group = entry?.group ?? (JENJANG_GROUPS.some((g) => g.key === key) ? (key as JenjangGroupKey) : null);
  if (!group) return true;

  if ((classroom.school_unit?.jenjang_group ?? null) !== group) return false;
  if (!entry) return true;
  if (entry.tingkat !== null && classroom.tingkat !== entry.tingkat) return false;
  if (entry.nameTokens) {
    const normalized = normalizeName(classroom.name);
    return entry.nameTokens.some((token) => normalized.startsWith(token));
  }
  return true;
}

/** The ladder key a classroom sits on (null when unrecognizable). */
export function keyForClassroom(classroom: ClassroomLike): string | null {
  const group = classroom.school_unit?.jenjang_group;
  if (!group) return null;

  if (group === "sd" || group === "smp" || group === "sma") {
    const key = `${group}-${classroom.tingkat}`;
    return jenjangEntry(key) ? key : null;
  }

  if (group === "ra") return "ra";

  const hit = JENJANG.find(
    (j) => j.group === group && j.nameTokens && matchesClassroom(classroom, j.key),
  );
  return hit?.key ?? null;
}

/** Sort index of a classroom on the ladder (unrecognizable sinks last). */
export function jenjangSortIndex(classroom: ClassroomLike): number {
  const key = keyForClassroom(classroom);
  const idx = key ? JENJANG.findIndex((j) => j.key === key) : -1;
  return idx === -1 ? JENJANG.length : idx;
}

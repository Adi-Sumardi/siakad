/**
 * The unit↔jenjang map the cascading filters read (bug batch Poin 1-3) -
 * the frontend half of one shared source. Which ladder keys actually run
 * in each unit is DERIVED from the classrooms that exist (never a static
 * mapping table), so a new class structure updates the dropdowns by
 * itself. Backend mirror: Jenjang::unitJenjangMap() +
 * GET /api/admin/unit-jenjang.
 *
 * Tabs that already hold a classrooms list (Kenaikan Kelas, Jadwal)
 * derive locally via deriveUnitJenjang(); tabs that do not (Data Siswa)
 * fetch the endpoint once. Either way the SAME shape feeds the SAME two
 * direction helpers below - no per-tab query duplication.
 */

import { JENJANG, keyForClassroom } from "@/lib/jenjang";

/** unit code -> ladder keys running in that unit */
export type UnitJenjangMap = Record<string, string[]>;

/** Anything the classrooms reference endpoint returns a row of. */
export type ClassroomRowLike = {
  name: string;
  tingkat: number | null;
  school_unit?: { code: string; label?: string; jenjang_group?: string | null } | null;
};

/** Derive the map from a classrooms list the page already holds. */
export function deriveUnitJenjang(classrooms: ClassroomRowLike[]): UnitJenjangMap {
  const seen: Record<string, Set<string>> = {};

  for (const classroom of classrooms) {
    const key = keyForClassroom(classroom);
    const code = classroom.school_unit?.code;
    if (!key || !code) continue;
    (seen[code] ??= new Set()).add(key);
  }

  const order = new Map(JENJANG.map((j, i) => [j.key, i]));
  return Object.fromEntries(
    Object.entries(seen).map(([code, keys]) => [
      code,
      [...keys].sort((a, b) => (order.get(a) ?? JENJANG.length) - (order.get(b) ?? JENJANG.length)),
    ]),
  );
}

/**
 * Ladder keys available for the Jenjang dropdown once a unit is picked.
 * Null = no unit picked (or the unit has no recognizable classes) - show
 * the whole ladder, the historical unfiltered behaviour.
 */
export function jenjangKeysForUnit(map: UnitJenjangMap, unitCode: string | null): string[] | null {
  if (!unitCode) return null;
  return map[unitCode] ?? [];
}

/** Units that actually run the picked jenjang; all units when nothing is picked. */
export function unitsWithJenjang<T extends { code: string }>(units: T[], map: UnitJenjangMap, jenjang: string | null): T[] {
  if (!jenjang) return units;
  return units.filter((u) => (map[u.code] ?? []).includes(jenjang));
}

<?php

namespace App\Support;

use App\Models\Classroom;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single source of the school's fixed jenjang ladder (feature batch
 * 25-9/26-09, Poin 1): the order every jenjang picker in the app renders,
 * never alphabetical and never whatever the database happened to return.
 *
 * Sixteen entries, coarse-to-fine: RA, the two PG programs (SB/KB), the two
 * TK years, then every numbered SD/SMP/SMA year. The early-childhood split
 * (PG-SB vs PG-KB vs TK-A vs TK-B) is matched FROM THE CLASSROOM NAME by
 * conscious decision (user, 25-9): those programs all sit on tingkat 0 in
 * the schema and nothing but the naming convention distinguishes them - a
 * class whose name carries no known prefix simply stays outside the
 * granular filters (coarse group filters still catch it).
 *
 * The frontend mirror of this ladder is frontend/src/lib/jenjang.ts - the
 * two must stay in step (keys and order).
 */
class Jenjang
{
    /**
     * @return list<array{key: string, label: string, group: string, tingkat: ?int, name_tokens: list<string>}>
     */
    public static function ladder(): array
    {
        return [
            ['key' => 'ra', 'label' => 'RA', 'group' => 'ra', 'tingkat' => null, 'name_tokens' => []],
            ['key' => 'pg-sb', 'label' => 'PG-SB (Sanggar Bermain)', 'group' => 'pg', 'tingkat' => null, 'name_tokens' => ['sb']],
            ['key' => 'pg-kb', 'label' => 'PG-KB (Kelompok Bermain)', 'group' => 'pg', 'tingkat' => null, 'name_tokens' => ['kb']],
            ['key' => 'tk-a', 'label' => 'TK-A', 'group' => 'tk', 'tingkat' => null, 'name_tokens' => ['tka']],
            ['key' => 'tk-b', 'label' => 'TK-B', 'group' => 'tk', 'tingkat' => null, 'name_tokens' => ['tkb']],
            ['key' => 'sd-1', 'label' => 'SD-1', 'group' => 'sd', 'tingkat' => 1, 'name_tokens' => []],
            ['key' => 'sd-2', 'label' => 'SD-2', 'group' => 'sd', 'tingkat' => 2, 'name_tokens' => []],
            ['key' => 'sd-3', 'label' => 'SD-3', 'group' => 'sd', 'tingkat' => 3, 'name_tokens' => []],
            ['key' => 'sd-4', 'label' => 'SD-4', 'group' => 'sd', 'tingkat' => 4, 'name_tokens' => []],
            ['key' => 'sd-5', 'label' => 'SD-5', 'group' => 'sd', 'tingkat' => 5, 'name_tokens' => []],
            ['key' => 'sd-6', 'label' => 'SD-6', 'group' => 'sd', 'tingkat' => 6, 'name_tokens' => []],
            ['key' => 'smp-7', 'label' => 'SMP-7', 'group' => 'smp', 'tingkat' => 7, 'name_tokens' => []],
            ['key' => 'smp-8', 'label' => 'SMP-8', 'group' => 'smp', 'tingkat' => 8, 'name_tokens' => []],
            ['key' => 'smp-9', 'label' => 'SMP-9', 'group' => 'smp', 'tingkat' => 9, 'name_tokens' => []],
            ['key' => 'sma-10', 'label' => 'SMA-10', 'group' => 'sma', 'tingkat' => 10, 'name_tokens' => []],
            ['key' => 'sma-11', 'label' => 'SMA-11', 'group' => 'sma', 'tingkat' => 11, 'name_tokens' => []],
            ['key' => 'sma-12', 'label' => 'SMA-12', 'group' => 'sma', 'tingkat' => 12, 'name_tokens' => []],
        ];
    }

    /** @var list<string> the six coarse groups, in ladder order */
    public const GROUPS = ['ra', 'pg', 'tk', 'sd', 'smp', 'sma'];

    /** @return array{key: string, label: string, group: string, tingkat: ?int, name_tokens: list<string>}|null */
    public static function entry(string $key): ?array
    {
        foreach (self::ladder() as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        return null;
    }

    /** The coarse group behind a key - a group key maps to itself. */
    public static function groupOf(string $key): ?string
    {
        if (in_array($key, self::GROUPS, true)) {
            return $key;
        }

        return self::entry($key)['group'] ?? null;
    }

    public static function isValid(string $key): bool
    {
        return self::entry($key) !== null || in_array($key, self::GROUPS, true);
    }

    /**
     * Narrows a Classroom query to one ladder key. Coarse group keys (the
     * historical tk|sd|smp|sma values included) keep the old behaviour;
     * granular keys add the tingkat and, for early childhood, the
     * name-prefix match. LOWER(name) LIKE keeps it portable between the
     * SQLite test driver and case-sensitive Postgres production - and the
     * prefix is matched against separators stripped, so "TK-A 1", "TK A1"
     * and "SB-2" all behave the same.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Classroom>  $query
     */
    public static function applyToClassroomQuery(Builder $query, string $key): Builder
    {
        $group = self::groupOf($key);
        $entry = self::entry($key);

        if ($group === null) {
            return $query;
        }

        $query->whereHas('schoolUnit', fn ($u) => $u->where('jenjang_group', $group));

        if ($entry === null) {
            return $query;
        }

        if ($entry['tingkat'] !== null) {
            $query->where('tingkat', $entry['tingkat']);
        }

        if ($entry['name_tokens'] !== []) {
            [$sql, $bindings] = self::nameMatchSql($key);
            $query->whereRaw("({$sql})", $bindings);
        }

        return $query;
    }

    /**
     * The early-childhood name match, shared by query and in-memory use:
     * lowercased, separators collapsed away, then starts-with one of the
     * entry's tokens ("TK-A 1" -> "tka1" starts with "tka").
     */
    public static function nameMatches(Classroom $classroom, string $key): bool
    {
        $entry = self::entry($key);

        if ($entry === null || $entry['name_tokens'] === []) {
            return true;
        }

        $normalized = self::normalizeName((string) $classroom->name);

        foreach ($entry['name_tokens'] as $token) {
            if (str_starts_with($normalized, $token)) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeName(string $name): string
    {
        return preg_replace('/[\s\-_.]+/', '', mb_strtolower(trim($name))) ?? '';
    }

    /**
     * The ladder key a classroom sits on, for the announcement targeting
     * feed (Poin 8) and any server-side sorting. Null when the classroom
     * carries no recognizable early-childhood prefix (outside the granular
     * ladder; coarse group filters still catch it).
     */
    public static function keyForClassroom(Classroom $classroom): ?string
    {
        $group = (string) ($classroom->schoolUnit->jenjang_group ?? '');
        $tingkat = $classroom->tingkat;

        if (in_array($group, ['sd', 'smp', 'sma'], true) && $tingkat !== null) {
            $key = "{$group}-{$tingkat}";

            return self::entry($key) !== null ? $key : null;
        }

        foreach (self::ladder() as $entry) {
            if ($entry['group'] === $group && $entry['name_tokens'] !== [] && self::nameMatches($classroom, $entry['key'])) {
                return $entry['key'];
            }
        }

        if ($group === 'ra') {
            return 'ra';
        }

        return null;
    }

    /**
     * The raw LIKE clauses for the granular name match inside a SQL query
     * (OR-combined by the caller) - prefixes are matched against the
     * separator-stripped lowercase name, emulated in SQL by folding every
     * separator run into nothing: LOWER(REPLACE(...)) chains keep it driver
     * portable without function-index needs (tiny tables).
     *
     * @return list<string> $token-interpolated LOWER(...) expressions' bound values are returned by reference via [sql, bindings] instead - see nameMatchSql().
     */
    public static function nameMatchSql(string $key): ?array
    {
        $entry = self::entry($key);

        if ($entry === null || $entry['name_tokens'] === []) {
            return null;
        }

        $folded = "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(classrooms.name, ' ', ''), '-', ''), '_', ''), '.', ''))";

        $sql = [];
        $bindings = [];

        foreach ($entry['name_tokens'] as $token) {
            $sql[] = "{$folded} LIKE ?";
            $bindings[] = $token.'%';
        }

        return [implode(' OR ', $sql), $bindings];
    }
}

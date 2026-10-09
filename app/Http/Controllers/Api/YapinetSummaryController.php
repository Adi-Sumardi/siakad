<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Admin\DashboardSummaryController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DashboardSummaryRequest;
use App\Models\Achievement;
use App\Models\User;
use App\Services\Academic\WatchlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Ringkasan SIAKAD untuk portal Yapinet — kontrak v1.1
 * (yapinet/rules/detail-pages.md). Dipanggil server Yapinet (middleware
 * `yapinet.auth`), bukan browser.
 *
 * Angka diambil dari DashboardSummaryController yang sama dengan dashboard
 * admin pusat, supaya Yapinet dan SIAKAD tidak pernah berbeda angka. Nama
 * siswa TIDAK dikirim — hanya agregat per unit.
 */
class YapinetSummaryController extends Controller
{
    /** Kehadiran hari ini di bawah ini → Kritis. */
    private const LOW_ATTENDANCE = 85.0;

    /** Penagihan (terbayar / ditagih) di bawah ini → Kritis. */
    private const LOW_COLLECTION = 60.0;

    public function summary(DashboardSummaryController $dashboard, WatchlistService $watchlist): JsonResponse
    {
        $data = $this->dashboard($dashboard, $watchlist);
        $kpi = $data['kpi'];
        $units = collect($data['units']);
        $billingLabel = $data['period']['billing_label'] ?? null;

        $metrics = [];
        $attention = [];
        $sections = [];

        // Cakupan "semua unit" dari total pusat.
        $all = [
            'students' => $kpi['students_active'],
            'today' => $kpi['attendance_today'],
            'today_rate' => $kpi['attendance_today']['rate'] ?? null,
            'collection' => $kpi['billing']['collection_rate'] ?? 0,
            'billed' => $kpi['billing']['total_billed'] ?? 0,
            'paid' => $kpi['billing']['total_paid'] ?? 0,
            'outstanding' => $kpi['billing']['total_outstanding'] ?? 0,
            'overdue' => $kpi['billing']['overdue_bills'] ?? 0,
        ];
        foreach ($this->metrics($all, $billingLabel, $units->count()) as $metric) {
            $metrics[] = $metric + ['when' => ['unit' => 'all']];
        }

        foreach ($units as $unit) {
            $scope = [
                'students' => $unit['students_active'],
                'today' => null,
                'today_rate' => $unit['attendance_today_rate'],
                'collection' => $unit['collection_rate'],
                'billed' => $unit['billed'],
                'paid' => $unit['paid'],
                'outstanding' => $unit['outstanding'],
                'overdue' => $unit['overdue_bills'],
            ];
            foreach ($this->metrics($scope, $billingLabel, null) as $metric) {
                $metrics[] = $metric + ['when' => ['unit' => $unit['unit_code']]];
            }
        }

        // Peringatan dashboard SIAKAD → kartu "Perlu perhatian", total & per unit.
        foreach ($data['alerts'] as $alert) {
            if ((int) $alert['count'] === 0) {
                continue;
            }
            $attention[] = $this->attentionItem($alert, (int) $alert['count']) + ['when' => ['unit' => 'all']];
            foreach ($alert['units'] ?? [] as $perUnit) {
                if ((int) $perUnit['count'] > 0) {
                    $attention[] = $this->attentionItem($alert, (int) $perUnit['count']) + ['when' => ['unit' => $perUnit['code']]];
                }
            }
        }

        $sections[] = $this->comparisonTable($units) + ['when' => ['unit' => 'all']];
        $sections[] = $this->achievementList(null) + ['when' => ['unit' => 'all']];
        foreach ($units as $unit) {
            $sections[] = $this->achievementList($unit['unit_id']) + ['when' => ['unit' => $unit['unit_code']]];
        }

        $status = match (true) {
            ($all['today_rate'] !== null && $all['today_rate'] < self::LOW_ATTENDANCE)
                || ($all['billed'] > 0 && $all['collection'] < self::LOW_COLLECTION) => 'critical',
            collect($data['alerts'])->contains(fn ($a) => (int) $a['count'] > 0) => 'warning',
            default => 'ok',
        };

        return response()->json([
            'contract_version' => 2,
            'status' => $status,
            'headline' => "{$all['students']} siswa aktif · penagihan ".$this->pct($all['collection'])
                .($all['today_rate'] !== null ? ' · hadir hari ini '.$this->pct($all['today_rate']) : ''),
            'updated_at' => now()->toIso8601String(),
            'filters' => [[
                'key' => 'unit',
                'label' => 'Unit',
                'default' => 'all',
                'options' => collect([['value' => 'all', 'label' => 'Semua sekolah']])
                    ->merge($units->map(fn ($u) => ['value' => $u['unit_code'], 'label' => $u['unit_label']]))
                    ->values(),
            ]],
            'attention' => $attention,
            'metrics' => $metrics,
            'sections' => $sections,
            'detail_path' => '/admin',
        ]);
    }

    /** Jalankan dashboard admin pusat dengan konteks super admin sistem (tanpa login). */
    private function dashboard(DashboardSummaryController $dashboard, WatchlistService $watchlist): array
    {
        $system = (new User)->forceFill(['id' => 0, 'role' => 'admin', 'name' => 'Yapinet']);
        $request = DashboardSummaryRequest::create('/api/admin/dashboard/summary', 'GET');
        $request->setUserResolver(fn () => $system);

        return $dashboard->summary($request, $watchlist)->getData(true);
    }

    private function metrics(array $d, ?string $billingLabel, ?int $unitCount): array
    {
        $collection = (float) $d['collection'] / 100;
        $today = $d['today'];

        return [
            [
                'label' => 'Siswa aktif',
                'value' => (int) $d['students'],
                'format' => 'number',
                'hint' => $unitCount !== null ? "{$unitCount} unit sekolah" : 'siswa berstatus aktif',
            ],
            array_filter([
                'label' => 'Kehadiran hari ini',
                'value' => $d['today_rate'] !== null ? (float) $d['today_rate'] / 100 : '—',
                'format' => $d['today_rate'] !== null ? 'percent' : 'text',
                'progress' => $d['today_rate'] !== null ? (float) $d['today_rate'] / 100 : null,
                'hint' => $today
                    ? "Sakit {$today['sakit']} · Izin {$today['izin']} · Alpa {$today['alpa']}"
                    : ($d['today_rate'] === null ? 'belum ada presensi hari ini' : 'presensi harian'),
                'trend' => $d['today_rate'] !== null && $d['today_rate'] < self::LOW_ATTENDANCE
                    ? ['text' => 'di bawah '.$this->pct(self::LOW_ATTENDANCE), 'tone' => 'critical'] : null,
            ], fn ($v) => $v !== null),
            [
                'label' => 'Penagihan',
                'value' => $collection,
                'format' => 'percent',
                'progress' => min(1, $collection),
                'hint' => 'Rp'.$this->rp($d['paid']).' dari Rp'.$this->rp($d['billed']).($billingLabel ? " · {$billingLabel}" : ''),
            ],
            [
                'label' => 'Sisa piutang',
                'value' => (float) $d['outstanding'],
                'format' => 'currency',
                'hint' => "{$d['overdue']} tagihan lewat jatuh tempo",
            ],
        ];
    }

    private function attentionItem(array $alert, int $count): array
    {
        return array_filter([
            'tone' => $alert['severity'] === 'bad' ? 'critical' : 'warning',
            'title' => $count.' '.Str::lcfirst($alert['label']),
            'description' => $alert['detail'] ?? null,
            'link' => $alert['href'] ?? null,
            'link_label' => 'Lihat di SIAKAD',
        ], fn ($v) => $v !== null);
    }

    private function comparisonTable($units): array
    {
        return [
            'type' => 'table',
            'title' => 'Perbandingan antar sekolah',
            'columns' => [
                ['key' => 'unit', 'label' => 'Unit'],
                ['key' => 'siswa', 'label' => 'Siswa', 'format' => 'number'],
                ['key' => 'hadir', 'label' => 'Hadir hari ini', 'format' => 'progress'],
                ['key' => 'tagih', 'label' => 'Penagihan', 'format' => 'progress'],
                ['key' => 'piutang', 'label' => 'Sisa piutang', 'format' => 'currency'],
                ['key' => 'perhatian', 'label' => 'Siswa perlu perhatian', 'format' => 'number'],
                ['key' => 'prestasi', 'label' => 'Prestasi', 'format' => 'number'],
            ],
            'rows' => $units->map(function ($u) {
                $emphasis = array_filter([
                    'hadir' => $u['attendance_today_rate'] !== null && $u['attendance_today_rate'] < self::LOW_ATTENDANCE ? 'critical' : null,
                    'tagih' => $u['billed'] > 0 && $u['collection_rate'] < self::LOW_COLLECTION ? 'critical'
                        : ($u['billed'] > 0 && $u['collection_rate'] < 80 ? 'warning' : null),
                ]);

                return array_filter([
                    'unit' => $u['unit_label'],
                    'siswa' => $u['students_active'],
                    'hadir' => $u['attendance_today_rate'] !== null ? (float) $u['attendance_today_rate'] / 100 : null,
                    'tagih' => (float) $u['collection_rate'] / 100,
                    'piutang' => (float) $u['outstanding'],
                    'perhatian' => $u['students_needing_attention'],
                    'prestasi' => $u['achievements'],
                    '_emphasis' => $emphasis ?: null,
                ], fn ($v) => $v !== null);
            })->values(),
        ];
    }

    /** Prestasi terverifikasi terbaru — nama kegiatan & tingkat, tanpa nama siswa. */
    private function achievementList(?int $unitId): array
    {
        $items = Achievement::query()
            ->where('status', 'verified')
            ->when($unitId, fn ($q) => $q->where('school_unit_id', $unitId))
            ->with('schoolUnit:id,label')
            ->orderByDesc('tanggal_event')
            ->limit(6)
            ->get();

        return [
            'type' => 'list',
            'title' => 'Prestasi terbaru',
            'items' => $items->map(fn (Achievement $a) => array_filter([
                'title' => trim(($a->juara && $a->juara !== 'Peserta' ? "Juara {$a->juara} " : '').$a->nama_prestasi),
                'subtitle' => trim(($a->schoolUnit?->label ?? '').' · '.($a->tanggal_event?->translatedFormat('j M Y') ?? ''), ' ·'),
                'badge' => $a->tingkat ? ['text' => $a->tingkat, 'tone' => 'info'] : null,
                'link' => '/admin/prestasi',
            ]))->values(),
        ];
    }

    private function pct(float|int $value): string
    {
        return number_format((float) $value, 1, ',', '.').'%';
    }

    private function rp(float|int $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }
}

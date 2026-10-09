<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Achievement;
use App\Models\Bill;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YapinetSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.yapinet.api_key' => 'kunci-yapinet']);
    }

    private function student(AcademicYear $year, SchoolUnit $unit, string $nis, float $billed, float $paid): Student
    {
        $student = Student::create([
            'nama_lengkap' => "Siswa Rahasia {$nis}", 'jenis_kelamin' => 'L',
            'school_unit_id' => $unit->id, 'entry_year_id' => $year->id, 'nis' => $nis, 'status' => 'active',
        ]);
        $spp = FeeType::create(['code' => "spp-{$nis}", 'name' => "SPP {$nis}", 'recurrence' => 'monthly']);
        Bill::create([
            'bill_number' => "SPP/2026/09/{$nis}", 'dedup_key' => "spp:2026:09:{$student->id}",
            'description' => 'SPP September', 'student_id' => $student->id, 'academic_year_id' => $year->id,
            'fee_type_id' => $spp->id, 'subtotal' => $billed, 'total_amount' => $billed,
            'paid_amount' => $paid, 'remaining_amount' => $billed - $paid,
            'status' => $paid >= $billed ? 'paid' : 'unpaid',
            'due_date' => now()->subDays(3)->toDateString(), 'issued_at' => now()->subMonth(),
        ]);

        return $student;
    }

    public function test_menolak_tanpa_api_key(): void
    {
        $this->getJson('/api/integrations/yapinet/summary')->assertUnauthorized();
        $this->withToken('salah')->getJson('/api/integrations/yapinet/summary')->assertUnauthorized();
    }

    public function test_ringkasan_v11_per_unit_tanpa_nama_siswa(): void
    {
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);
        $sd = SchoolUnit::create(['code' => 'sd', 'label' => 'SD', 'jenjang_group' => 'sd', 'is_active' => true]);
        $smp = SchoolUnit::create(['code' => 'smp', 'label' => 'SMP', 'jenjang_group' => 'smp', 'is_active' => true]);

        $this->student($year, $sd, '1001', 500_000, 500_000);
        $this->student($year, $sd, '1002', 500_000, 500_000);
        $this->student($year, $smp, '2001', 600_000, 0); // lewat jatuh tempo, belum bayar

        Achievement::create([
            'achiever_type' => 'siswa', 'school_unit_id' => $sd->id, 'nama_prestasi' => 'Olimpiade Matematika',
            'kategori' => 'Akademik', 'tingkat' => 'Kabupaten/Kota', 'juara' => '1', 'nama_event' => 'OSN',
            'tanggal_event' => now()->subDays(2)->toDateString(), 'status' => 'verified',
        ]);

        $response = $this->withToken('kunci-yapinet')->getJson('/api/integrations/yapinet/summary')
            ->assertOk()
            ->assertJsonPath('contract_version', 2)
            ->assertJsonPath('filters.0.key', 'unit');
        $json = $response->json();

        $this->assertStringNotContainsString('Siswa Rahasia', $response->getContent());

        $this->assertSame(['all', 'sd', 'smp'], collect($json['filters'][0]['options'])->pluck('value')->all());

        $all = collect($json['metrics'])->where('when.unit', 'all')->keyBy('label');
        $this->assertSame(3, $all['Siswa aktif']['value']);
        $this->assertEqualsWithDelta(1_000_000 / 1_600_000, $all['Penagihan']['value'], 0.01);
        $this->assertEquals(600_000, $all['Sisa piutang']['value']);

        $smpMetrics = collect($json['metrics'])->where('when.unit', 'smp')->keyBy('label');
        $this->assertEquals(0, $smpMetrics['Penagihan']['value']);

        $overdue = collect($json['attention'])->where('when.unit', 'smp')->pluck('title')->implode(' | ');
        $this->assertStringContainsString('1 tagihan lewat jatuh tempo', $overdue);

        $table = collect($json['sections'])->firstWhere('title', 'Perbandingan antar sekolah');
        $this->assertSame(['tagih' => 'critical'], collect($table['rows'])->firstWhere('unit', 'SMP')['_emphasis']);

        $prestasi = collect($json['sections'])->where('when.unit', 'sd')->firstWhere('title', 'Prestasi terbaru');
        $this->assertSame('Juara 1 Olimpiade Matematika', $prestasi['items'][0]['title']);

        file_put_contents(sys_get_temp_dir().'/yapinet-summary-siakad.json', json_encode($json));
    }
}

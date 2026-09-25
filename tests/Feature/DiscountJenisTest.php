<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Jenis Beasiswa" (feature batch Poin 13, user's choice b): one column
 * distinguishing what a scheme IS. Nullable-in, 'lainnya' default - older
 * callers that never send the field stay valid.
 */
class DiscountJenisTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));

        $this->admin = User::create([
            'name' => 'Admin Pusat',
            'email' => 'admin'.uniqid().'@yapinet.id',
            'role' => 'admin',
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    public function test_a_scheme_stores_and_reports_its_jenis(): void
    {
        $created = $this->actingAs($this->admin)->postJson('/api/admin/discount-schemes', [
            'code' => 'beasiswa_anak_guru',
            'name' => 'Beasiswa Anak Guru',
            'type' => 'percent',
            'jenis' => 'diskon_karyawan',
            'value' => 50,
        ]);
        $created->assertCreated();

        $ulid = $created->json('scheme.ulid');

        $list = $this->actingAs($this->admin)->getJson('/api/admin/discount-schemes')->assertOk();
        $this->assertSame('diskon_karyawan', collect($list->json('schemes'))->firstWhere('ulid', $ulid)['jenis']);

        // Update changes it.
        $this->actingAs($this->admin)->patchJson("/api/admin/discount-schemes/{$ulid}", [
            'jenis' => 'keringanan',
        ])->assertOk();

        $this->assertSame(
            'keringanan',
            collect($this->actingAs($this->admin)->getJson('/api/admin/discount-schemes')->json('schemes'))->firstWhere('ulid', $ulid)['jenis'],
        );
    }

    public function test_a_scheme_without_jenis_lands_on_lainnya_and_unknown_values_are_refused(): void
    {
        $created = $this->actingAs($this->admin)->postJson('/api/admin/discount-schemes', [
            'code' => 'potongan_lama',
            'name' => 'Potongan Lama',
            'type' => 'nominal',
            'value' => 250000,
        ]);
        $created->assertCreated();

        $this->assertSame(
            'lainnya',
            collect($this->actingAs($this->admin)->getJson('/api/admin/discount-schemes')->json('schemes'))
                ->firstWhere('code', 'potongan_lama')['jenis'],
            'kolom default menampung pemanggil lama',
        );

        $this->actingAs($this->admin)->postJson('/api/admin/discount-schemes', [
            'code' => 'nilai_asal',
            'name' => 'Nilai Asal',
            'type' => 'percent',
            'jenis' => 'beasiswa_total',
            'value' => 10,
        ])->assertStatus(422)->assertInvalid('jenis');
    }
}

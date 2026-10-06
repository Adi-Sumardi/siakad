<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Audit 6 Okt 2026 #7: student documents finally have a way in and out. */
class StudentDocumentTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->sd = SchoolUnit::create(['code' => 'SD-1', 'label' => 'SD Satu', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-1', 'label' => 'SMP Satu', 'jenjang_group' => 'smp']);
    }

    private function user(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id', 'role' => $role,
            'school_unit_id' => $unit?->id, 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function student(SchoolUnit $unit): Student
    {
        return Student::create([
            'nama_lengkap' => 'Anak '.$unit->code, 'jenis_kelamin' => 'P', 'nis' => uniqid(),
            'school_unit_id' => $unit->id, 'status' => 'active',
        ]);
    }

    public function test_guardian_upload_waits_for_tu_verification(): void
    {
        $student = $this->student($this->sd);
        $wali = $this->user('orangtua');
        $guardian = Guardian::create(['user_id' => $wali->id, 'nama' => 'Ibu', 'hubungan' => 'ibu']);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ibu', 'is_primary' => true]);

        $this->actingAs($wali)->post("/api/wali/students/{$student->ulid}/documents", [
            'document_type' => 'akta',
            'file' => UploadedFile::fake()->create('akta.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('document.verified', false);

        $doc = StudentDocument::firstOrFail();
        Storage::disk('local')->assertExists($doc->file_path);

        $tu = $this->user('admin_unit', $this->sd);
        $this->actingAs($tu)->postJson("/api/admin/student-documents/{$doc->ulid}/verify")
            ->assertOk()->assertJsonPath('document.verified', true);

        // Verified: the guardian can no longer remove it.
        $this->actingAs($wali)->deleteJson("/api/wali/student-documents/{$doc->ulid}")->assertStatus(422);
        $this->actingAs($wali)->get("/api/files/student-documents/{$doc->ulid}")->assertOk();
    }

    public function test_another_units_staff_and_strangers_get_404(): void
    {
        $student = $this->student($this->sd);
        $tu = $this->user('admin_unit', $this->sd);

        $this->actingAs($tu)->post("/api/admin/students/{$student->ulid}/documents", [
            'document_type' => 'kk',
            'file' => UploadedFile::fake()->image('kk.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('document.verified', true);

        $doc = StudentDocument::firstOrFail();

        $this->actingAs($this->user('admin_unit', $this->smp))->getJson("/api/admin/students/{$student->ulid}/documents")->assertNotFound();
        $this->actingAs($this->user('admin_unit', $this->smp))->get("/api/files/student-documents/{$doc->ulid}")->assertNotFound();
        $this->actingAs($this->user('orangtua'))->get("/api/files/student-documents/{$doc->ulid}")->assertNotFound();
    }
}

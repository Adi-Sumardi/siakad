<?php

namespace App\Services\Handoff;

use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\IntegrationEvent;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Security\FieldEncrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Turns a PMB handoff event into a student, their guardians, and a login.
 *
 * Runs from a queued job, never from the webhook request: creating records and
 * sending an invitation takes long enough that PMB's HTTP client would time out
 * and redeliver, and a redelivery mid-write is exactly what we do not want.
 */
class PmbHandoffProcessor
{
    public function __construct(
        private AccountInvitationSender $invitations,
        private FieldEncrypter $encrypter,
    ) {}

    public function process(IntegrationEvent $event): void
    {
        // The inbox already rejects duplicate event_ids, but a job can be
        // retried after its own success (worker killed between the write and
        // the ack), so the guard is repeated here.
        if ($event->isProcessed()) {
            return;
        }

        try {
            match ($event->event_type) {
                'student.enrolled' => $this->handleEnrolled($event),
                'student.updated' => $this->handleUpdated($event),
                'student.cancelled' => $this->handleCancelled($event),
                default => throw new RuntimeException("Unknown event type: {$event->event_type}"),
            };
        } catch (\Throwable $e) {
            $event->markFailed($e->getMessage());

            throw $e;
        }
    }

    /**
     * The main path: uang pangkal is settled, so the student becomes ours.
     */
    private function handleEnrolled(IntegrationEvent $event): void
    {
        $payload = $event->payload;
        $studentData = $payload['student'];

        $unit = SchoolUnit::findByCode($studentData['unit_code'] ?? null);

        if (! $unit) {
            // Deliberately fatal rather than "create the unit from the payload":
            // a typo in PMB would otherwise spawn a phantom unit that no admin
            // is scoped to, and every student in it would be invisible.
            throw new RuntimeException("Unit tidak dikenal: {$studentData['unit_code']}. Tambahkan unit ini lebih dulu.");
        }

        [$student, $invitees] = DB::transaction(function () use ($studentData, $payload, $unit) {
            $student = $this->upsertStudent($studentData, $unit);
            $invitees = $this->syncGuardians($student, $payload['guardians'] ?? [], $unit);

            return [$student, $invitees];
        });

        $event->markProcessed($student);

        // Outside the transaction: a gateway that is slow or down must not roll
        // back a student who is, by then, genuinely enrolled.
        foreach ($invitees as $user) {
            $this->safelyInvite($user, $student, $studentData);
        }
    }

    private function upsertStudent(array $data, SchoolUnit $unit): Student
    {
        $student = Student::where('pmb_student_ulid', $data['pmb_ulid'])->first() ?? new Student;

        $student->fill([
            'pmb_student_ulid' => $data['pmb_ulid'],
            'no_pendaftaran' => $data['no_pendaftaran'] ?? null,
            'nama_lengkap' => $data['nama_lengkap'],
            'nama_panggilan' => $data['nama_panggilan'] ?? null,
            'jenis_kelamin' => $data['jenis_kelamin'],
            'tempat_lahir' => $data['tempat_lahir'] ?? null,
            'tanggal_lahir' => $data['tanggal_lahir'] ?? null,
            'agama' => $data['agama'] ?? null,
            'kewarganegaraan' => $data['kewarganegaraan'] ?? null,
            'alamat_lengkap' => $data['alamat_lengkap'] ?? null,
            'rt' => $data['rt'] ?? null,
            'rw' => $data['rw'] ?? null,
            'kelurahan' => $data['kelurahan'] ?? null,
            'kecamatan' => $data['kecamatan'] ?? null,
            'kota_kabupaten' => $data['kota_kabupaten'] ?? null,
            'provinsi' => $data['provinsi'] ?? null,
            'kode_pos' => $data['kode_pos'] ?? null,
            'school_unit_id' => $unit->id,
            'entry_year_id' => $this->resolveAcademicYear($data['academic_year'] ?? null)?->id,
        ]);

        // Encrypted fields go through the model's mutator so the blind index
        // columns stay in step; assigning them in fill() above would work too,
        // but keeping them here makes the sensitive set obvious.
        foreach (['nisn', 'nik'] as $field) {
            if (! empty($data[$field])) {
                $student->{$field} = $data[$field];
            }
        }

        // A student PMB hands over is enrolled by definition - PMB only sends
        // this event once the uang pangkal bill is settled.
        $student->status = 'active';
        $student->status_notes = 'Diterima dari PMB - uang pangkal lunas';
        $student->status_changed_at = now();

        $student->save();

        return $student;
    }

    /**
     * Creates or reuses each guardian, links them to the student, and returns
     * the accounts that still need an activation invitation.
     *
     * Reuse is the point: a parent enrolling a second child must land on the
     * same login, seeing both children, rather than collecting one account per
     * child. That is why guardians are matched on email/phone before insert.
     *
     * @return list<User>
     */
    private function syncGuardians(Student $student, array $guardians, SchoolUnit $unit): array
    {
        $invitees = [];
        $billingAssigned = false;

        foreach ($guardians as $data) {
            $guardian = $this->findGuardian($data) ?? new Guardian;

            $guardian->fill([
                'nama' => $data['nama'],
                'hubungan' => $data['hubungan'],
                'email' => $data['email'] ?? $guardian->email,
                'pekerjaan' => $data['pekerjaan'] ?? $guardian->pekerjaan,
                'alamat' => $data['alamat'] ?? $guardian->alamat,
            ]);

            if (! empty($data['no_hp'])) {
                $guardian->no_hp = $data['no_hp'];
            }

            $isPrimary = (bool) ($data['is_primary'] ?? false);

            // The account goes to the primary guardian who has a way to receive
            // it. A guardian with neither email nor phone gets a record but no
            // login - there is nowhere to send the invitation.
            if ($isPrimary && ! $guardian->user_id && ($guardian->email || ! empty($data['no_hp']))) {
                $user = $this->resolveUser($data, $unit);
                $guardian->user_id = $user->id;

                if (! $user->hasActivated()) {
                    $invitees[] = $user;
                }
            }

            $guardian->save();

            $student->guardians()->syncWithoutDetaching([
                $guardian->id => [
                    'relationship' => $data['hubungan'],
                    'is_primary' => $isPrimary,
                    // Exactly one billing contact per student: the first primary
                    // guardian wins, the rest are contacts only.
                    'is_billing_contact' => $isPrimary && ! $billingAssigned,
                ],
            ]);

            if ($isPrimary && ! $billingAssigned) {
                $billingAssigned = true;
            }
        }

        return $invitees;
    }

    private function findGuardian(array $data): ?Guardian
    {
        if (! empty($data['email'])) {
            if ($found = Guardian::where('email', $data['email'])->first()) {
                return $found;
            }
        }

        if (! empty($data['no_hp'])) {
            return Guardian::where('no_hp_hash', $this->encrypter->blindIndex($data['no_hp']))->first();
        }

        return null;
    }

    /** Reuses the sibling's login when the same parent appears again. */
    private function resolveUser(array $data, SchoolUnit $unit): User
    {
        $existing = null;

        if (! empty($data['email'])) {
            $existing = User::where('email', $data['email'])->first();
        }

        if (! $existing && ! empty($data['no_hp'])) {
            $existing = User::where('phone_hash', $this->encrypter->blindIndex($data['no_hp']))->first();
        }

        if ($existing) {
            return $existing;
        }

        $user = new User([
            'name' => $data['nama'],
            'email' => $data['email'] ?? null,
            'role' => 'orangtua',
            // Guardians are not unit staff; the column stays null so no unit
            // scope ever mistakes a parent for an admin of that unit.
            'school_unit_id' => null,
            'is_active' => true,
        ]);

        if (! empty($data['no_hp'])) {
            $user->phone = $data['no_hp'];
        }

        $user->save();

        return $user;
    }

    private function safelyInvite(User $user, Student $student, array $studentData): void
    {
        try {
            $this->invitations->send($user, [
                'student_name' => $student->nama_lengkap,
                'nama_panggilan' => $student->nama_panggilan ?: $student->nama_lengkap,
                'unit_label' => $student->schoolUnit?->label ?? '-',
                'academic_year' => $studentData['academic_year'] ?? '-',
            ]);
        } catch (\Throwable $e) {
            // The student is enrolled either way. A failed invitation is a
            // resend from the admin screen, not a reason to fail the event and
            // have PMB redeliver the whole handoff.
            Log::error('Gagal mengirim undangan akun setelah handoff', [
                'user_id' => $user->id,
                'student_id' => $student->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * PMB sends "2026/2027" as text. If that year is not on file yet the first
     * handoff of an intake creates it, dormant: an intake cannot wait for an
     * admin to open a settings screen, but it also must not silently become the
     * active year and move every existing student's context.
     */
    private function resolveAcademicYear(?string $year): ?AcademicYear
    {
        if (! $year) {
            return null;
        }

        $existing = AcademicYear::where('year', $year)->first();

        if ($existing) {
            return $existing;
        }

        $startYear = (int) mb_substr($year, 0, 4);

        return AcademicYear::create([
            'year' => $year,
            'starts_on' => "{$startYear}-07-01",
            'ends_on' => ($startYear + 1).'-06-30',
            'is_active' => false,
        ]);
    }

    /**
     * PMB keeps ownership of identity data only until the school year starts;
     * after that, corrections happen here. See docs/02-INTEGRASI-PMB.md.
     */
    private function handleUpdated(IntegrationEvent $event): void
    {
        $data = $event->payload['student'];
        $student = Student::where('pmb_student_ulid', $data['pmb_ulid'])->first();

        if (! $student) {
            throw new RuntimeException("Siswa dengan pmb_ulid {$data['pmb_ulid']} belum pernah diserahkan.");
        }

        $year = $student->entryYear;

        if ($year && $year->starts_on->isPast()) {
            throw new RuntimeException('Tahun ajaran sudah berjalan - data siswa dikelola aplikasi sekolah, bukan PMB.');
        }

        $unit = SchoolUnit::findByCode($data['unit_code'] ?? null) ?? $student->schoolUnit;

        $this->upsertStudent($data, $unit);

        $event->markProcessed($student->fresh());
    }

    /**
     * Registration cancelled or uang pangkal refunded. The student stays on
     * file with a status that explains why - deleting them would erase the
     * audit trail of an account that was already created and emailed.
     */
    private function handleCancelled(IntegrationEvent $event): void
    {
        $data = $event->payload['student'];
        $student = Student::where('pmb_student_ulid', $data['pmb_ulid'])->first();

        if (! $student) {
            $event->markProcessed();

            return;
        }

        DB::transaction(function () use ($student, $event) {
            $student->forceFill([
                'status' => 'dropped_out',
                'status_notes' => $event->payload['reason'] ?? 'Dibatalkan dari PMB',
                'status_changed_at' => now(),
            ])->save();

            // Deactivate a guardian's login only when they have no other child
            // still enrolled here - a sibling's parent must keep their access.
            foreach ($student->guardians as $guardian) {
                $stillEnrolled = $guardian->students()
                    ->where('students.id', '!=', $student->id)
                    ->where('students.status', 'active')
                    ->exists();

                if (! $stillEnrolled && $guardian->user) {
                    $guardian->user->forceFill(['is_active' => false])->save();
                }
            }
        });

        $event->markProcessed($student);
    }
}

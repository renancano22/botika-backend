<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MultiplePrescriptionsTest extends TestCase
{
    use RefreshDatabase;

    private array $ids;
    private array $headers;
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jpa0AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $reg = $this->postJson('/api/register', ['name' => 'Ana Dela Peña', 'barangay' => 'Gate', 'contact_no' => '09171234567',
            'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821'])->assertCreated();
        $this->headers = ['Authorization' => 'Bearer ' . $reg->json('token')];
        $this->ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $m = Medicine::create(['medicine_name' => "Medicine {$i}", 'category' => 'Medicine', 'unit' => 'tablet', 'requires_prescription' => $i <= 3]);
            Inventory::create(['medicine_id' => $m->medicine_id, 'quantity' => 20, 'expiration_date' => now()->addYear()->toDateString()]);
            $this->ids[] = $m->medicine_id;
        }
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function file(int $number, array $ids): array
    {
        return ['name' => "Doctor-{$number}.pdf", 'data' => 'data:application/pdf;base64,' . base64_encode("%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Title (Prescription {$number}) >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF"), 'medicine_ids' => $ids];
    }

    private function files(): array
    {
        return [$this->file(1, array_slice($this->ids, 0, 2)), $this->file(2, [$this->ids[2]])];
    }

    private function submit(?array $files = null)
    {
        return $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => array_map(fn ($id) => ['medicine_id' => $id, 'quantity' => 2], $this->ids),
            'prescriptions' => $files ?? $this->files()], $this->headers);
    }

    public function test_five_medicines_and_three_prescription_medicines_can_use_separate_doctors(): void
    {
        $res = $this->submit()->assertCreated()->assertJsonCount(2, 'prescriptions')->assertJsonCount(5, 'items')
            ->assertJsonPath('prescriptions.0.medicine_ids', array_slice($this->ids, 0, 2));
        $this->assertDatabaseCount('prescriptions', 2);
        foreach ($res->json('prescriptions') as $file) $this->assertArrayNotHasKey('file_data', $file);
        $id = $res->json('request_id');
        $this->getJson("/api/requests/{$id}", $this->headers)->assertOk()->assertJsonCount(2, 'prescriptions');
        $file = $res->json('prescriptions.1.prescription_id');
        $this->getJson("/api/requests/{$id}/prescriptions/{$file}", $this->headers)->assertOk()->assertJsonPath('data', $this->files()[1]['data']);
    }

    public function test_one_shared_prescription_can_cover_all_three_medicines(): void
    {
        $this->submit([$this->file(1, array_slice($this->ids, 0, 3))])->assertCreated()->assertJsonCount(1, 'prescriptions');
    }

    public function test_missing_invalid_and_duplicate_assignments_or_files_are_refused(): void
    {
        $cases = [[], [$this->file(1, [$this->ids[0]])], [$this->file(1, [])], [$this->file(1, [$this->ids[3]])],
            [$this->file(1, [999999])], [$this->file(1, [$this->ids[0], $this->ids[0], $this->ids[1], $this->ids[2]])],
            [$this->file(1, array_slice($this->ids, 0, 2)), $this->file(1, [$this->ids[2]])],
            array_map(fn ($i) => $this->file($i, array_slice($this->ids, 0, 3)), range(1, 6))];
        foreach ($cases as $files) $this->submit($files)->assertUnprocessable();
        $this->assertDatabaseCount('requests', 0);
        $this->assertDatabaseCount('prescriptions', 0);
    }

    public function test_every_file_must_be_checked_before_approval(): void
    {
        $res = $this->submit()->assertCreated(); $id = $res->json('request_id');
        $ids = array_column($res->json('prescriptions'), 'prescription_id');
        $staff = ['Authorization' => 'Bearer ' . User::factory()->create()->createToken('t')->plainTextToken];
        $this->postJson("/api/requests/{$id}/approve", ['prescription_verified' => true], $staff)->assertUnprocessable()->assertJsonValidationErrors('verified_prescription_ids');
        $this->postJson("/api/requests/{$id}/approve", ['prescription_verified' => true, 'verified_prescription_ids' => [$ids[0]]], $staff)->assertUnprocessable();
        $this->assertDatabaseHas('requests', ['request_id' => $id, 'status' => 'pending']);
        $this->postJson("/api/requests/{$id}/approve", ['prescription_verified' => true, 'verified_prescription_ids' => $ids], $staff)->assertOk();
        $this->postJson("/api/requests/{$id}/prescription", ['prescriptions' => $this->files()], $this->headers)->assertUnprocessable();
    }

    public function test_approval_and_pickup_reminder_include_all_original_prescriptions_only_when_needed(): void
    {
        config(['sms.semaphore.api_key' => '']);
        $staff = ['Authorization' => 'Bearer ' . User::factory()->create()->createToken('t')->plainTextToken];
        $requests = [];
        $reminder = 'Bring all original prescriptions when claiming your medicines.';
        foreach ([true, false] as $needsPrescription) {
            $items = $needsPrescription ? array_slice($this->ids, 0, 3) : [$this->ids[3]];
            $response = $this->postJson('/api/requests', [
                'request_type' => 'medicine',
                'items' => array_map(fn ($id) => ['medicine_id' => $id, 'quantity' => 2], $items),
                ...($needsPrescription ? ['prescriptions' => $this->files()] : []),
            ], $this->headers)->assertCreated();
            $id = $response->json('request_id');
            $this->postJson("/api/requests/{$id}/approve", $needsPrescription ? [
                'prescription_verified' => true,
                'verified_prescription_ids' => array_column($response->json('prescriptions'), 'prescription_id'),
            ] : [], $staff)->assertOk();
            $message = DB::table('notifications')->where('request_id', $id)->where('type', 'approved')->value('message');
            $this->assertSame($needsPrescription, str_contains($message, $reminder));
            $requests[$id] = $needsPrescription;
        }
        $this->travel(max(0, config('botika.unclaimed_days') - 1))->days();
        $this->assertSame(2, app(\App\Services\InventoryService::class)->checkPickupDeadlines()['reminded']);
        foreach ($requests as $id => $needsPrescription) {
            $message = DB::table('notifications')->where('request_id', $id)->where('type', 'reminder')->value('message');
            $this->assertSame($needsPrescription, str_contains($message, $reminder));
        }
        $this->assertSame(0, app(\App\Services\InventoryService::class)->checkPickupDeadlines()['reminded']);
        $this->assertSame(2, DB::table('notifications')->where('type', 'reminder')->count());
    }

    public function test_replacement_keeps_explicit_existing_files_and_failure_keeps_every_original(): void
    {
        $res = $this->submit()->assertCreated(); $id = $res->json('request_id'); $files = $res->json('prescriptions');
        $invalid = [['existing_id' => $files[0]['prescription_id'], 'medicine_ids' => array_slice($this->ids, 0, 2)],
            ['name' => 'bad.pdf', 'data' => 'bad', 'medicine_ids' => [$this->ids[2]]]];
        $this->postJson("/api/requests/{$id}/prescription", ['prescriptions' => $invalid], $this->headers)->assertUnprocessable();
        foreach ($files as $file) $this->assertDatabaseHas('prescriptions', ['prescription_id' => $file['prescription_id']]);
        $valid = [$invalid[0], $this->file(3, [$this->ids[2]])];
        $this->postJson("/api/requests/{$id}/prescription", ['prescriptions' => $valid], $this->headers)->assertOk()->assertJsonCount(2, 'prescriptions')
            ->assertJsonPath('prescriptions.0.file_name', $files[0]['file_name'])->assertJsonPath('prescriptions.1.file_name', 'Doctor-3.pdf');
        $this->assertDatabaseCount('prescriptions', 2);
    }

    public function test_other_residents_cannot_read_or_reuse_files_and_old_clients_cannot_erase_multiple_files(): void
    {
        $res = $this->submit()->assertCreated(); $id = $res->json('request_id'); $file = $res->json('prescriptions.0.prescription_id');
        $other = $this->postJson('/api/register', ['name' => 'Maria Cruz', 'barangay' => 'Gate', 'contact_no' => '09998887777',
            'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821'])->assertCreated();
        $headers = ['Authorization' => 'Bearer ' . $other->json('token')];
        $this->getJson("/api/requests/{$id}/prescriptions/{$file}", $headers)->assertNotFound();
        $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $this->ids[0], 'quantity' => 1]],
            'prescriptions' => [['existing_id' => $file, 'medicine_ids' => [$this->ids[0]]]]], $headers)->assertUnprocessable();
        $this->postJson("/api/requests/{$id}/prescription", ['prescription' => ['name' => 'one.png', 'data' => 'data:image/png;base64,' . self::PNG]], $this->headers)
            ->assertUnprocessable();
        $this->assertDatabaseCount('prescriptions', 2);
    }

    public function test_file_id_must_belong_to_the_requested_request_even_for_staff(): void
    {
        $res = $this->submit()->assertCreated(); $id = $res->json('request_id');
        $other = MedicineRequest::create(['resident_id' => $res->json('resident_id'), 'request_type' => 'medicine', 'request_date' => now(), 'status' => 'pending']);
        $foreign = $other->prescriptions()->create(['file_name' => 'other.png', 'mime_type' => 'image/png', 'file_data' => self::PNG, 'size' => strlen(base64_decode(self::PNG)), 'medicine_ids' => [$this->ids[0]]]);
        $staff = ['Authorization' => 'Bearer ' . User::factory()->create()->createToken('t')->plainTextToken];
        $this->getJson("/api/requests/{$id}/prescriptions/{$foreign->prescription_id}", $staff)->assertNotFound();
        $this->postJson("/api/requests/{$id}/prescription", ['prescriptions' => [['existing_id' => $foreign->prescription_id, 'medicine_ids' => array_slice($this->ids, 0, 3)]]], $this->headers)->assertUnprocessable();
    }

    public function test_migration_preserves_legacy_single_prescription_and_backfills_coverage(): void
    {
        $migration = require database_path('migrations/2026_11_16_000001_support_multiple_prescriptions.php');
        $migration->down();
        $residentId = User::where('name', 'Ana Dela Peña')->firstOrFail()->resident->resident_id;
        $request = MedicineRequest::create(['resident_id' => $residentId, 'request_type' => 'medicine', 'request_date' => now(), 'status' => 'pending']);
        $request->items()->createMany(array_map(fn ($id) => ['medicine_id' => $id, 'quantity' => 1], $this->ids));
        DB::table('prescriptions')->insert(['request_id' => $request->request_id, 'file_name' => 'legacy.png', 'mime_type' => 'image/png', 'file_data' => self::PNG, 'size' => strlen(base64_decode(self::PNG)), 'uploaded_at' => now()]);
        $migration->up();
        $file = Prescription::where('request_id', $request->request_id)->firstOrFail();
        $this->assertSame(self::PNG, $file->file_data);
        $this->assertSame(array_slice($this->ids, 0, 3), $file->medicine_ids);
        $this->assertSame([], $request->fresh()->missingPrescriptionMedicineIds());
    }

    public function test_aggregate_file_size_is_limited_without_saving_partial_files(): void
    {
        $files = [];
        foreach (array_slice($this->ids, 0, 3) as $i => $id) {
            $file = $this->file($i, [$id]);
            $bytes = base64_decode(explode(',', $file['data'], 2)[1]);
            $file['data'] = 'data:application/pdf;base64,' . base64_encode(str_pad($bytes, 3 * 1024 * 1024, ' '));
            $files[] = $file;
        }
        $this->submit($files)->assertUnprocessable()->assertJsonPath('errors.prescriptions.0', 'The combined prescription files must be 8 MB or smaller.');
        $this->assertDatabaseCount('requests', 0);
        $this->assertDatabaseCount('prescriptions', 0);
    }

    public function test_rollback_refuses_to_discard_multiple_prescriptions(): void
    {
        $this->submit()->assertCreated();
        $migration = require database_path('migrations/2026_11_16_000001_support_multiple_prescriptions.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse to discard multiple files.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No files were deleted', $e->getMessage());
        }
        $this->assertDatabaseCount('prescriptions', 2);
    }

    public function test_approval_refuses_file_ids_from_before_a_replacement_even_for_a_single_file(): void
    {
        $res = $this->submit([$this->file(1, array_slice($this->ids, 0, 3))])->assertCreated();
        $id = $res->json('request_id'); $oldId = $res->json('prescriptions.0.prescription_id');
        $this->postJson("/api/requests/{$id}/prescription", ['prescriptions' => [$this->file(2, array_slice($this->ids, 0, 3))]], $this->headers)->assertOk();
        $staff = ['Authorization' => 'Bearer ' . User::factory()->create()->createToken('t')->plainTextToken];
        $this->postJson("/api/requests/{$id}/approve", ['prescription_verified' => true, 'verified_prescription_ids' => [$oldId]], $staff)
            ->assertUnprocessable()->assertJsonValidationErrors('verified_prescription_ids');
        $this->assertDatabaseHas('requests', ['request_id' => $id, 'status' => 'pending']);
    }
}

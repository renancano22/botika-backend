<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The medicine lists can show only the medicines that need a prescription, or only those that do not. */
class MedicinePrescriptionFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filter_by_prescription(): void
    {
        Medicine::create(['medicine_name' => 'Amoxicillin 500mg Capsule', 'generic_name' => 'Amoxicillin', 'category' => 'Antibacterial', 'unit' => 'capsule', 'reorder_level' => 5, 'requires_prescription' => true]);
        Medicine::create(['medicine_name' => 'Paracetamol 500mg Tablet', 'generic_name' => 'Paracetamol', 'category' => 'Analgesic/Antipyretic', 'unit' => 'tablet', 'reorder_level' => 5]);
        $staff = ['Authorization' => 'Bearer ' . User::factory()->create()->createToken('t')->plainTextToken];

        $this->getJson('/api/medicines?per_page=5&prescription=required', $staff)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.generic_name', 'Amoxicillin');
        $this->getJson('/api/medicines?per_page=5&prescription=not_required', $staff)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.generic_name', 'Paracetamol');
        $this->getJson('/api/medicines?per_page=5', $staff)->assertJsonPath('meta.total', 2);
        $this->getJson('/api/medicines?prescription=required', $staff)->assertJsonCount(1); // residents' list (no paging)
    }
}

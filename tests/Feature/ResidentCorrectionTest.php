<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The administrator cleans up resident records: deletes a counter record by mistake, corrects name and barangay. */
class ResidentCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public function test_delete_only_counter_records_without_history(): void
    {
        $admin = ['Authorization' => 'Bearer ' . User::factory()->admin()->create()->createToken('t')->plainTextToken];
        $staff = ['Authorization' => 'Bearer ' . User::factory()->create()->createToken('t')->plainTextToken];
        $dupe = Resident::create(['name' => 'Rovylyn Gutierrez', 'address' => 'San Isidro, Bulan, Sorsogon', 'qr_code' => 'BBC-000008']);
        $served = Resident::create(['name' => 'Rovylyn Gutierrez', 'address' => 'Calomagon, Bulan, Sorsogon', 'qr_code' => 'BBC-000009']);
        $med = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        Inventory::create(['medicine_id' => $med->medicine_id, 'quantity' => 50, 'expiration_date' => now()->addYear()->toDateString()]);
        $this->postJson('/api/dispensing/walk-in', ['qr_code' => 'BBC-000009', 'items' => [['medicine_id' => $med->medicine_id, 'quantity' => 2]]], $staff)->assertCreated();
        $online = $this->postJson('/api/register', ['name' => 'Ana Cruz', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821',
            'barangay' => 'Gate', 'contact_no' => '09171234567'])->assertCreated()->json('user.resident.resident_id');

        // User Accounts shows which counter records have history.
        $rows = collect($this->getJson('/api/users?per_page=10&status=no_account', $admin)->json('data'))->keyBy(fn ($r) => $r['resident']['qr_code']);
        $this->assertSame([false, true], [$rows['BBC-000008']['has_history'], $rows['BBC-000009']['has_history']]);

        $this->deleteJson("/api/residents/{$dupe->resident_id}", [], $staff)->assertForbidden();     // administrator only
        $this->deleteJson("/api/residents/{$served->resident_id}", [], $admin)->assertStatus(422);   // has dispensing history
        $this->deleteJson("/api/residents/{$online}", [], $admin)->assertStatus(422);                // has an online account
        $this->deleteJson("/api/residents/{$dupe->resident_id}", [], $admin)->assertOk();
        $this->assertSame(['BBC-000009', Resident::find($online)->qr_code], Resident::orderBy('resident_id')->pluck('qr_code')->all());
    }

    public function test_admin_corrects_name_and_barangay_without_making_a_duplicate(): void
    {
        $admin = ['Authorization' => 'Bearer ' . User::factory()->admin()->create()->createToken('t')->plainTextToken];
        $counter = Resident::create(['name' => 'Gino Reyes', 'address' => 'Purok 2, Gate, Bulan, Sorsogon', 'qr_code' => 'BBC-000020']);
        Resident::create(['name' => 'Gino Reyes', 'address' => 'Zone I Poblacion, Bulan, Sorsogon', 'qr_code' => 'BBC-000021']);

        // Counter record: wrong barangay corrected (house no. kept); not into an existing name in that barangay.
        $this->putJson("/api/residents/{$counter->resident_id}", ['name' => 'Gino Reyes', 'barangay' => 'Zone I Poblacion'], $admin)->assertStatus(422)
            ->assertJsonPath('errors.name.0', Resident::NAME_TAKEN);
        $this->putJson("/api/residents/{$counter->resident_id}", ['name' => 'Gino S. Reyes', 'barangay' => 'Zone I Poblacion'], $admin)->assertOk()
            ->assertJsonPath('address', 'Purok 2, Zone I Poblacion, Bulan, Sorsogon')->assertJsonPath('qr_code', 'BBC-000020');

        // Resident account: User Accounts → Edit corrects the barangay too (name kept in sync on the account).
        $userId = $this->postJson('/api/register', ['name' => 'Ana Cruz', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821',
            'barangay' => 'Gate', 'contact_no' => '09171234567'])->assertCreated()->json('user.user_id');
        $this->putJson("/api/users/{$userId}", ['name' => 'Ana M. Cruz', 'email' => '', 'role' => 'resident', 'barangay' => 'San Isidro'], $admin)->assertOk()
            ->assertJsonPath('name', 'Ana M. Cruz')->assertJsonPath('resident.address', 'San Isidro, Bulan, Sorsogon')->assertJsonPath('resident.name', 'Ana M. Cruz');
    }
}

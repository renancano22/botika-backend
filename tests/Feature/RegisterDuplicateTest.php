<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A registered resident cannot get a second account (and Resident ID) by registering again with another number. */
class RegisterDuplicateTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function register(array $over = [])
    {
        return $this->postJson('/api/register', [...['name' => 'Carlo Dalisay', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821',
            'barangay' => 'Zone I Poblacion', 'contact_no' => '09171234567'], ...$over]);
    }

    public function test_same_name_in_same_barangay_cannot_register_again(): void
    {
        $this->register()->assertCreated();

        // Same person, another number (any capital letters / extra spaces / periods): refused.
        foreach (['Carlo Dalisay', 'carlo  dalisay', 'CARLO DALISAY.'] as $name) {
            $this->register(['name' => $name, 'contact_no' => '09998887777', 'email' => 'carlo2@gmail.com'])
                ->assertStatus(422)->assertJsonPath('errors.name.0', AuthController::ALREADY_REGISTERED);
        }
        $this->assertSame(1, Resident::count());
        $this->assertSame(1, User::where('role', 'resident')->count());

        // Also a resident registered by the staff at the Botika ng Bayan (no account yet).
        Resident::create(['name' => 'Lola Remedios Bañez', 'address' => 'Gate, Bulan, Sorsogon', 'qr_code' => 'BBC-000099']);
        $this->register(['name' => 'Lola Remedios Bañez', 'barangay' => 'Gate', 'contact_no' => '09175550000'])->assertStatus(422);

        // A different person: middle name / suffix, or the same name in another barangay.
        $this->register(['name' => 'Carlo Dalisay Jr.', 'contact_no' => '09998887777'])->assertCreated();
        $this->register(['name' => 'Carlo Dalisay', 'barangay' => 'Gate', 'contact_no' => '09998886666'])->assertCreated();
    }

    public function test_residents_cannot_change_their_registered_name_or_barangay(): void
    {
        $ana = $this->register(['name' => 'Ana Cruz', 'contact_no' => '09175550001']); // Zone I Poblacion
        $this->register(['name' => 'Carlo Dalisay', 'contact_no' => '09175550002']);
        $h = ['Authorization' => 'Bearer ' . $ana->json('token')];
        $profile = ['name' => 'Ana Cruz', 'email' => null, 'contact_no' => '09175550001', 'barangay' => 'Zone I Poblacion', 'address_line' => ''];

        // Name and barangay are fixed after registration (they cannot be changed into another resident's).
        $this->putJson('/api/profile', [...$profile, 'name' => 'Carlo Dalisay'], $h)->assertStatus(422)
            ->assertJsonPath('errors.name.0', ProfileController::NAME_LOCKED);
        $this->putJson('/api/profile', [...$profile, 'barangay' => 'Gate'], $h)->assertStatus(422)
            ->assertJsonPath('errors.barangay.0', ProfileController::BARANGAY_LOCKED);
        $this->assertSame(['Ana Cruz', 'Zone I Poblacion, Bulan, Sorsogon'], [Resident::where('qr_code', $ana->json('user.resident.qr_code'))->value('name'),
            Resident::where('qr_code', $ana->json('user.resident.qr_code'))->value('address')]);

        // The other details can still be changed (spaces/capital letters in the name are not a change).
        $this->putJson('/api/profile', [...$profile, 'name' => 'ana  cruz', 'contact_no' => '09175559999', 'address_line' => 'Purok 3'], $h)->assertOk();
        $saved = Resident::where('contact_no', '09175559999')->firstOrFail();
        $this->assertSame(['Ana Cruz', 'Purok 3, Zone I Poblacion, Bulan, Sorsogon'], [$saved->name, $saved->address]);

        // The administrator can correct a name, but not into another resident's name in the same barangay.
        $admin = ['Authorization' => 'Bearer ' . User::factory()->admin()->create()->createToken('t')->plainTextToken];
        $anaUser = User::where('name', 'Ana Cruz')->firstOrFail();
        $this->putJson("/api/users/{$anaUser->user_id}", ['name' => 'Carlo Dalisay', 'email' => '', 'role' => 'resident'], $admin)->assertStatus(422);
        $this->putJson("/api/users/{$anaUser->user_id}", ['name' => 'Ana M. Cruz', 'email' => '', 'role' => 'resident'], $admin)->assertOk();
        $this->assertSame('Ana M. Cruz', $anaUser->resident->fresh()->name);
    }
}

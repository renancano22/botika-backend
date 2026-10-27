<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
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
}

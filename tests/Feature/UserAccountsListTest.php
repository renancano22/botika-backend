<?php

namespace Tests\Feature;

use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** User Accounts also lists residents registered at the counter (no online account), so no registered resident is hidden. */
class UserAccountsListTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public function test_residents_without_an_account_are_listed(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin One']);
        $h = ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];
        $this->postJson('/api/register', ['name' => 'Rovylyn Gutierrez', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821',
            'barangay' => 'San Ramon', 'contact_no' => '09092054334'])->assertCreated();
        $staffH = ['Authorization' => 'Bearer ' . User::factory()->create(['name' => 'Staff One'])->createToken('t')->plainTextToken];
        $this->postJson('/api/residents', ['name' => 'Rovylyn Gutierrez', 'barangay' => 'Calomagon'], $staffH)->assertCreated(); // at the counter
        $counterId = Resident::whereNull('user_id')->value('qr_code');

        // All roles: 3 accounts + 1 resident registered at the counter, sorted by name.
        $rows = $this->getJson('/api/users?per_page=10', $h)->assertOk()->assertJsonPath('meta.total', 4)->json('data');
        $this->assertSame(['Admin One', 'Rovylyn Gutierrez', 'Rovylyn Gutierrez', 'Staff One'], array_column($rows, 'name'));
        $counter = collect($rows)->firstWhere('has_account', false);
        $this->assertSame([null, 'resident', $counterId, 'Calomagon, Bulan, Sorsogon'],
            [$counter['user_id'], $counter['role'], $counter['resident']['qr_code'], $counter['resident']['address']]);

        // Residents / search / "No online account"; the other filters list accounts only.
        $this->getJson('/api/users?per_page=10&role=resident', $h)->assertJsonPath('meta.total', 2);
        $this->getJson('/api/users?per_page=10&search=' . $counterId, $h)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.has_account', false);
        $this->getJson('/api/users?per_page=10&status=no_account', $h)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/users?per_page=10&status=active', $h)->assertJsonPath('meta.total', 3);
        $this->getJson('/api/users?per_page=10&role=staff', $h)->assertJsonPath('meta.total', 1);
    }
}

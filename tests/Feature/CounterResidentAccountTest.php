<?php

namespace Tests\Feature;

use App\Models\Dispensing;
use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A resident registered at the counter (no cellphone then) gets an online account later: the same resident record,
 * Resident ID and history. The temporary password goes only to the resident by SMS, never to the administrator.
 */
class CounterResidentAccountTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public function test_admin_creates_an_online_account_for_a_counter_resident(): void
    {
        config(['sms.semaphore.api_key' => 'test-key']);
        Http::fake(['*' => Http::response([['message_id' => 1]], 200)]);
        Mail::fake();
        $admin = ['Authorization' => 'Bearer ' . User::factory()->admin()->create()->createToken('t')->plainTextToken];
        $staff = ['Authorization' => 'Bearer ' . User::factory()->create()->createToken('t')->plainTextToken];

        // Registered at the counter without a cellphone, and given medicine as a walk-in.
        $qr = $this->postJson('/api/residents', ['name' => 'Lola Remedios Bañez', 'barangay' => 'Gate'], $staff)->assertCreated()->json('resident.qr_code');
        $resident = Resident::where('qr_code', $qr)->firstOrFail();
        $med = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        Inventory::create(['medicine_id' => $med->medicine_id, 'quantity' => 50, 'expiration_date' => now()->addYear()->toDateString()]);
        $this->postJson('/api/dispensing/walk-in', ['qr_code' => $qr, 'items' => [['medicine_id' => $med->medicine_id, 'quantity' => 5]]], $staff)->assertCreated();

        // She cannot register online herself (already registered in Gate); the message tells her what to do.
        $this->postJson('/api/register', ['name' => 'Lola Remedios Bañez', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821',
            'barangay' => 'Gate', 'contact_no' => '09175550000'])->assertStatus(422)->assertJsonPath('errors.name.0', fn ($m) => str_contains($m, 'create your online account'));

        // A mobile number is needed; staff/residents cannot do this, only the administrator.
        $this->postJson("/api/residents/{$resident->resident_id}/account", [], $admin)->assertStatus(422)->assertJsonValidationErrors('contact_no');
        $this->postJson("/api/residents/{$resident->resident_id}/account", ['contact_no' => '09175550000'], $staff)->assertForbidden();

        $res = $this->postJson("/api/residents/{$resident->resident_id}/account", ['contact_no' => '0917 555 0000'], $admin)->assertCreated()
            ->assertJsonPath('sent_to.sms', '09175550000')->assertJsonMissingPath('temporary_password')
            ->assertJsonPath('user.must_change_password', true)->assertJsonPath('user.resident.qr_code', $qr);

        // The same record: Resident ID and dispensing history kept, now linked to the new account.
        $resident->refresh();
        $this->assertNotNull($resident->user_id);
        $this->assertSame([$qr, '09175550000', 'Lola Remedios Bañez'], [$resident->qr_code, $resident->contact_no, $resident->user->name]);
        $this->assertSame(1, Resident::count());
        $this->assertSame(1, Dispensing::whereHas('request', fn ($q) => $q->where('resident_id', $resident->resident_id))->count());

        // The temporary password was sent by SMS only to her, never in the administrator's response; it works once.
        $password = null;
        Http::assertSent(function (HttpRequest $r) use (&$password) {
            if (preg_match('/temporary password: (\S+) /', $r['message'] ?? '', $m)) $password = $m[1];
            return $r['number'] === '09175550000';
        });
        $this->assertNotNull($password);
        $this->assertStringNotContainsString($password, $res->getContent());
        $this->postJson('/api/login', ['login' => '09175550000', 'password' => $password])->assertOk()->assertJsonPath('user.must_change_password', true);

        // Only once: she now has an account.
        $this->postJson("/api/residents/{$resident->resident_id}/account", ['contact_no' => '09175550001'], $admin)->assertStatus(422);
    }

    public function test_nothing_is_created_when_the_password_cannot_be_sent(): void
    {
        config(['sms.semaphore.api_key' => null, 'mail.default' => 'log']);
        $admin = ['Authorization' => 'Bearer ' . User::factory()->admin()->create()->createToken('t')->plainTextToken];
        $resident = Resident::create(['name' => 'Pedro Cruz', 'address' => 'Gate, Bulan, Sorsogon', 'qr_code' => 'BBC-000050']);

        $this->postJson("/api/residents/{$resident->resident_id}/account", ['contact_no' => '09175550000'], $admin)->assertStatus(422);
        $this->assertNull($resident->fresh()->user_id);
        $this->assertNull($resident->fresh()->contact_no);
        $this->assertSame(0, User::where('role', 'resident')->count());
    }
}

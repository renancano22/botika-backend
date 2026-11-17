<?php

namespace Tests\Feature;

use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class RegisterValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['name' => 'Ana Dela Peña', 'barangay' => 'Gate', 'contact_no' => '09171234567',
            'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821'], $changes);
    }

    public function test_required_fields_are_validated_and_nothing_is_created(): void
    {
        $this->postJson('/api/register', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'barangay', 'contact_no', 'password', 'password_confirmation']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('residents', 0);
    }

    public function test_non_string_json_fields_return_validation_errors_instead_of_server_errors(): void
    {
        foreach (['name', 'email', 'contact_no', 'barangay', 'address_line', 'password', 'password_confirmation'] as $field) {
            foreach ([['invalid'], 123, true] as $value) {
                $this->postJson('/api/register', $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_names_mobile_email_address_and_barangay_are_refused(): void
    {
        foreach ([['name', '...'], ['name', 'A.'], ['name', 'Ana123'], ['name', str_repeat('A', 101)],
            ['contact_no', 'abc09171234567'], ['contact_no', '0917123456'], ['contact_no', '09171234567,09181234567'],
            ['email', 'wrong@yahoo.com'], ['email', 'a..b@gmail.com'], ['email', str_repeat('a', 256)],
            ['barangay', 'Not a barangay'], ['address_line', str_repeat('A', 121)], ['address_line', "Purok\n3"]] as [$field, $value]) {
            $this->postJson('/api/register', $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_policy_and_confirmation_are_enforced(): void
    {
        foreach (['Short12', '12345678', 'abcdefgh', 'Password123', 'Peña123456', '09171234567a', 'Botika9876', str_repeat('a', 101)] as $password) {
            $this->postJson('/api/register', $this->payload(['password' => $password, 'password_confirmation' => $password]))
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->postJson('/api/register', $this->payload(['password_confirmation' => 'Different123']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/register', $this->payload(['password_confirmation' => '']))->assertUnprocessable()->assertJsonValidationErrors('password_confirmation');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_single_name_initial_or_suffix_cannot_replace_a_full_name(): void
    {
        foreach (['Ana', 'Santos', 'Ña', 'A. Cruz', 'Ana C.', 'Ana Jr.', 'Ana II', 'Ana Sr. Jr.', 'Ana . Cruz', 'Ana - Cruz'] as $name) {
            $this->postJson('/api/register', $this->payload(['name' => $name]))
                ->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('residents', 0);
    }

    public function test_complete_names_allow_middle_names_compound_surnames_and_suffixes(): void
    {
        foreach (["Ma. O'Neil-Cruz", 'Juan Dela Peña', 'Ana M. Cruz', 'Ana Maria Cruz', 'Ana Cruz', 'Ana Cruz Jr.', 'Ana Cruz III', 'Ña Peña', 'Ai Ng'] as $index => $name) {
            $this->postJson('/api/register', $this->payload(['name' => $name, 'contact_no' => '0917000000' . $index]))
                ->assertCreated()->assertJsonPath('user.name', $name);
            $this->assertDatabaseHas('residents', ['name' => $name]);
        }
    }

    public function test_normalized_values_are_saved_and_formatted_duplicate_numbers_are_blocked(): void
    {
        $this->postJson('/api/register', $this->payload(['name' => "  Ana   Dela Peña  ", 'email' => ' ANA.CRZ@GMAIL.COM ',
            'contact_no' => '+63 917 123 4567', 'address_line' => '  Purok 3 ']))->assertCreated();
        $this->assertDatabaseHas('users', ['name' => 'Ana Dela Peña', 'email' => 'ana.crz@gmail.com']);
        $this->assertDatabaseHas('residents', ['contact_no' => '09171234567', 'address' => 'Purok 3, Gate, Bulan, Sorsogon']);
        foreach (['09171234567', '9171234567', '639171234567', '(0917) 123-4567'] as $contact) {
            $this->postJson('/api/register', $this->payload(['name' => 'Maria Cruz', 'contact_no' => $contact]))
                ->assertUnprocessable()->assertJsonValidationErrors('contact_no');
        }
        $this->postJson('/api/register', $this->payload(['name' => 'Maria Cruz', 'contact_no' => '09998887777', 'email' => 'ANA.CRZ@GMAIL.COM']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(1, User::count());
        $this->assertSame(1, Resident::count());
    }

    public function test_email_and_address_are_optional_and_password_is_hashed(): void
    {
        $this->postJson('/api/register', $this->payload())->assertCreated()->assertJsonMissingPath('user.password_hash');
        $user = User::firstOrFail();
        $this->assertNull($user->email);
        $this->assertNotSame('Kalinga4821', $user->password_hash);
        $this->assertSame('Gate, Bulan, Sorsogon', Resident::firstOrFail()->address);
    }
}

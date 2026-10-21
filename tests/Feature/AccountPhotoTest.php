<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Profile picture of the administrator and pharmacy staff (My Profile), with the same rules as the resident's. */
class AccountPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function token(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_staff_and_admin_change_and_delete_their_profile_picture(): void
    {
        $photo = 'data:image/jpeg;base64,' . base64_encode('small-jpeg-bytes');
        $other = 'data:image/png;base64,' . base64_encode('another-picture');
        $staff = User::factory()->create(['name' => 'Joy Mercado']);
        $admin = User::factory()->admin()->create(['name' => 'Admin Reyes']);
        $here = $this->token($staff);

        // No picture yet: the first letter of the name is shown.
        $this->getJson('/api/me', $here)->assertOk()->assertJsonPath('photo', null);

        // My Profile → Change Profile Picture: saved and shown everywhere the user appears (top bar, menu).
        $this->postJson('/api/account/photo', ['photo' => $photo], $here)->assertOk()->assertJsonPath('user.photo', $photo);
        $this->getJson('/api/me', $here)->assertJsonPath('photo', $photo);
        $this->postJson('/api/login', ['login' => $staff->email, 'password' => 'password'])->assertOk()->assertJsonPath('user.photo', $photo);

        // Only pictures, and not too large (the same rules as the resident's picture).
        $this->postJson('/api/account/photo', ['photo' => 'data:text/html;base64,PGI+'], $here)->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->postJson('/api/account/photo', ['photo' => 'data:image/jpeg;base64,' . str_repeat('A', 400001)], $here)->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->postJson('/api/account/photo', [], $here)->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->getJson('/api/me', $here)->assertJsonPath('photo', $photo); // unchanged

        // Edit Profile → Save Changes: name and picture together; a wrong current password saves nothing.
        $this->putJson('/api/account', ['name' => 'Joy M. Mercado', 'photo' => $other, 'current_password' => 'wrong123', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821'], $here)
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->getJson('/api/me', $here)->assertJsonPath('photo', $photo)->assertJsonPath('name', 'Joy Mercado');
        $this->putJson('/api/account', ['name' => 'Joy M. Mercado', 'photo' => $other], $here)->assertOk()
            ->assertJsonPath('user.photo', $other)->assertJsonPath('user.name', 'Joy M. Mercado');
        $this->putJson('/api/account', ['name' => 'Joy M. Mercado', 'remove_photo' => true], $here)->assertOk()->assertJsonPath('user.photo', null);

        // Delete Profile Picture.
        $this->postJson('/api/account/photo', ['photo' => $photo], $here)->assertOk();
        $this->deleteJson('/api/account/photo', [], $here)->assertOk()->assertJsonPath('user.photo', null);
        $this->assertNull($staff->fresh()->photo);

        // The administrator has the same; pictures are not sent in the User Accounts list.
        $this->postJson('/api/account/photo', ['photo' => $photo], $this->token($admin))->assertOk()->assertJsonPath('user.photo', $photo);
        $this->assertStringNotContainsString('base64', $this->getJson('/api/users?per_page=50', $this->token($admin))->assertOk()->getContent());

        // Residents keep using their own profile page.
        $resident = $this->postJson('/api/register', ['name' => 'Ana Cruz', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821',
            'barangay' => 'Gate', 'contact_no' => '09171234567'])->assertCreated();
        $residentHeaders = ['Authorization' => 'Bearer ' . $resident->json('token')];
        $this->postJson('/api/account/photo', ['photo' => $photo], $residentHeaders)->assertForbidden();
        $this->postJson('/api/profile/photo', ['photo' => $photo], $residentHeaders)->assertOk()->assertJsonPath('user.resident.photo', $photo);
        $this->getJson('/api/me', $residentHeaders)->assertJsonPath('resident.photo', $photo)->assertJsonMissingPath('photo');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    protected array $headers;
    protected User $user;
    protected string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'Test Role',
            'description' => 'Role for password reset tests',
            'is_active' => true,
        ]);

        $this->secret = (new Google2FA())->generateSecretKey();

        $this->user = User::create([
            'first_name' => 'Reset',
            'last_name' => 'User',
            'email' => 'reset-user@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
            'status' => 'APPROVED',
            'google_2fa_active' => true,
            'google_2fa_secret' => $this->secret,
        ]);

        $this->headers = [
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret'),
        ];
    }

    public function test_precheck_returns_two_fa_enabled_true()
    {
        $response = $this->postJson('/api/v1/auth/reset-password/precheck', [
            'email' => $this->user->email,
        ], $this->headers);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'two_fa_enabled' => true,
                ],
            ]);
    }

    public function test_verify_reset_otp_returns_reset_token()
    {
        $otp = (new Google2FA())->getCurrentOtp($this->secret);

        $response = $this->postJson('/api/v1/auth/reset-password/verify-otp', [
            'email' => $this->user->email,
            'otp' => $otp,
        ], $this->headers);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'data' => ['reset_token', 'expires_at'],
            ]);

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $this->user->email,
        ]);
    }

    public function test_user_can_reset_password_with_valid_token()
    {
        $otp = (new Google2FA())->getCurrentOtp($this->secret);
        $verify = $this->postJson('/api/v1/auth/reset-password/verify-otp', [
            'email' => $this->user->email,
            'otp' => $otp,
        ], $this->headers);

        $token = $verify->json('data.reset_token');
        $this->assertNotEmpty($token);

        $newPassword = 'newPassword123';
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $this->user->email,
            'token' => $token,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ], $this->headers);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Password reset successfully',
            ]);

        $this->assertTrue(Hash::check($newPassword, $this->user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $this->user->email,
        ]);
    }

    public function test_verify_reset_otp_fails_with_invalid_code()
    {
        $response = $this->postJson('/api/v1/auth/reset-password/verify-otp', [
            'email' => $this->user->email,
            'otp' => '000000',
        ], $this->headers);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid OTP code',
            ]);
    }
}

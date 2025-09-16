<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a basic role
        $this->role = Role::create([
            'name' => 'Test Role',
            'description' => 'Test role for testing',
            'is_active' => true
        ]);
    }

    public function test_user_can_register()
    {
        $userData = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_id' => $this->role->id
        ];

        $response = $this->postJson('/api/v1/auth/register', $userData, [
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret')
        ]);

        $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'message' => 'User registered successfully'
                ]);

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe'
        ]);
    }

    public function test_user_can_login_with_valid_credentials()
    {
        $user = User::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $this->role->id,
            'status' => 'APPROVED'
        ]);

        $loginData = [
            'email' => 'john@example.com',
            'password' => 'password123'
        ];

        $response = $this->postJson('/api/v1/auth/login', $loginData, [
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret')
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Login successful'
                ])
                ->assertJsonStructure([
                    'data' => [
                        'user',
                        'token',
                        'token_type',
                        'expires_at'
                    ]
                ]);
    }

    public function test_user_cannot_login_with_invalid_credentials()
    {
        $user = User::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $this->role->id,
            'status' => 'APPROVED'
        ]);

        $loginData = [
            'email' => 'john@example.com',
            'password' => 'wrongpassword'
        ];

        $response = $this->postJson('/api/v1/auth/login', $loginData, [
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret')
        ]);

        $response->assertStatus(401)
                ->assertJson([
                    'success' => false,
                    'message' => 'Invalid credentials'
                ]);
    }

    public function test_user_cannot_login_if_not_approved()
    {
        $user = User::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $this->role->id,
            'status' => 'PENDING'
        ]);

        $loginData = [
            'email' => 'john@example.com',
            'password' => 'password123'
        ];

        $response = $this->postJson('/api/v1/auth/login', $loginData, [
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret')
        ]);

        $response->assertStatus(401)
                ->assertJson([
                    'success' => false,
                    'message' => 'Account not approved'
                ]);
    }

    public function test_authenticated_user_can_get_profile()
    {
        $user = User::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $this->role->id,
            'status' => 'APPROVED'
        ]);

        $token = $user->createToken('Test Token')->plainTextToken;

        $response = $this->getJson('/api/v1/auth/profile', [
            'Authorization' => 'Bearer ' . $token,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret')
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Profile retrieved successfully'
                ])
                ->assertJsonStructure([
                    'data' => [
                        'id',
                        'first_name',
                        'last_name',
                        'email',
                        'status'
                    ]
                ]);
    }

    public function test_user_can_logout()
    {
        $user = User::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $this->role->id,
            'status' => 'APPROVED'
        ]);

        $token = $user->createToken('Test Token')->plainTextToken;

        $response = $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => 'Bearer ' . $token,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret')
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Logout successful'
                ]);
    }

    public function test_api_requires_credentials()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password'
        ]);

        $response->assertStatus(401)
                ->assertJson([
                    'success' => false,
                    'message' => 'API credentials are required'
                ]);
    }

    public function test_api_rejects_invalid_credentials()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password'
        ], [
            'X-App-Key' => 'invalid_key',
            'X-App-Secret' => 'invalid_secret'
        ]);

        $response->assertStatus(401)
                ->assertJson([
                    'success' => false,
                    'message' => 'Invalid API credentials'
                ]);
    }
}
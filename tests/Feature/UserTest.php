<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $superAdmin;
    protected $token;
    protected $headers;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Seed roles and privileges
        $this->artisan('db:seed', ['--class' => 'RolesAndPrivilegesSeeder']);
        
        // Create super admin user
        $superAdminRole = Role::where('name', 'Super Admin')->first();
        $this->superAdmin = User::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'superadmin@test.com',
            'password' => bcrypt('password123'),
            'role_id' => $superAdminRole->id,
            'status' => 'APPROVED'
        ]);

        // Create token and headers
        $this->token = $this->superAdmin->createToken('Test Token')->plainTextToken;
        $this->headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ];
    }

    public function test_can_list_users()
    {
        User::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/users', $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Users retrieved successfully'
                ]);
    }

    public function test_can_create_user()
    {
        $role = Role::factory()->create();
        
        $userData = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'password' => 'password123',
            'role_id' => $role->id,
            'status' => 'PENDING'
        ];

        $response = $this->postJson('/api/v1/users', $userData, $this->headers);

        $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'message' => 'User created successfully'
                ]);

        $this->assertDatabaseHas('users', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com'
        ]);
    }

    public function test_can_show_user()
    {
        $user = User::factory()->create();

        $response = $this->getJson("/api/v1/users/{$user->id}", $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'User retrieved successfully'
                ]);
    }

    public function test_can_update_user()
    {
        $user = User::factory()->create();
        
        $updateData = [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'status' => 'APPROVED'
        ];

        $response = $this->putJson("/api/v1/users/{$user->id}", $updateData, $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'User updated successfully'
                ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'status' => 'APPROVED'
        ]);
    }

    public function test_can_delete_user()
    {
        $user = User::factory()->create();

        $response = $this->deleteJson("/api/v1/users/{$user->id}", [], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'User deleted successfully'
                ]);

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_can_filter_users_by_status()
    {
        // Count existing approved users first
        $existingApprovedCount = User::where('status', 'APPROVED')->count();
        
        User::factory()->count(2)->create(['status' => 'APPROVED']);
        User::factory()->count(3)->create(['status' => 'PENDING']);

        $response = $this->getJson('/api/v1/users?status=APPROVED', $this->headers);

        $response->assertStatus(200)
                ->assertJsonCount($existingApprovedCount + 2, 'data'); // existing + 2 created
    }

    public function test_user_creation_requires_unique_email()
    {
        $existingUser = User::factory()->create(['email' => 'unique@example.com']);

        $response = $this->postJson('/api/v1/users', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'unique@example.com', // Duplicate email
            'password' => 'password123'
        ], $this->headers);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation Error'
                ])
                ->assertJsonValidationErrors(['email']);
    }

    public function test_user_creation_requires_valid_data()
    {
        $response = $this->postJson('/api/v1/users', [
            'first_name' => '', // Invalid: empty name
            'email' => 'invalid-email', // Invalid: not a valid email
            'password' => '123', // Invalid: too short
            'role_id' => 999999 // Invalid: non-existent role
        ], $this->headers);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation Error'
                ])
                ->assertJsonValidationErrors(['first_name', 'email', 'password', 'role_id']);
    }

    public function test_user_not_found_returns_404()
    {
        $response = $this->getJson('/api/v1/users/999999', $this->headers);

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'User not found'
                ]);
    }

    protected function tearDown(): void
    {
        // Clean up database after each test
        if (isset($this->superAdmin)) {
            $this->superAdmin->tokens()->delete();
        }
        
        parent::tearDown();
    }
}

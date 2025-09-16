<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class MerchantTest extends TestCase
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

    public function test_can_list_merchants()
    {
        // Create test merchants
        Merchant::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/merchants', $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Merchants retrieved successfully'
                ])
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'address',
                            'status',
                            'type',
                            'created_at',
                            'updated_at'
                        ]
                    ],
                    'meta',
                    'links'
                ]);
    }

    public function test_can_create_merchant()
    {
        $merchantData = [
            'name' => 'Test Merchant',
            'address' => '123 Test Street',
            'tel' => '+1234567890',
            'email' => 'test@merchant.com',
            'type' => 'PointOfSell',
            'status' => 'PENDING',
            'lat' => 40.7128,
            'long' => -74.0060
        ];

        $response = $this->postJson('/api/v1/merchants', $merchantData, $this->headers);

        $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'message' => 'Merchant created successfully'
                ]);

        $this->assertDatabaseHas('merchants', [
            'name' => 'Test Merchant',
            'address' => '123 Test Street',
            'type' => 'PointOfSell'
        ]);
    }

    public function test_can_show_merchant()
    {
        $merchant = Merchant::factory()->create();

        $response = $this->getJson("/api/v1/merchants/{$merchant->id}", $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Merchant retrieved successfully',
                    'data' => [
                        'id' => $merchant->id,
                        'name' => $merchant->name,
                        'address' => $merchant->address
                    ]
                ]);
    }

    public function test_can_update_merchant()
    {
        $merchant = Merchant::factory()->create();
        
        $updateData = [
            'name' => 'Updated Merchant Name',
            'address' => '456 Updated Street',
            'status' => 'APPROVED'
        ];

        $response = $this->putJson("/api/v1/merchants/{$merchant->id}", $updateData, $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Merchant updated successfully'
                ]);

        $this->assertDatabaseHas('merchants', [
            'id' => $merchant->id,
            'name' => 'Updated Merchant Name',
            'address' => '456 Updated Street',
            'status' => 'APPROVED'
        ]);
    }

    public function test_can_delete_merchant()
    {
        $merchant = Merchant::factory()->create();

        $response = $this->deleteJson("/api/v1/merchants/{$merchant->id}", [], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Merchant deleted successfully'
                ]);

        $this->assertSoftDeleted('merchants', ['id' => $merchant->id]);
    }

    public function test_can_attach_users_to_merchant()
    {
        $merchant = Merchant::factory()->create();
        $user = User::factory()->create(['status' => 'APPROVED']);

        $response = $this->postJson("/api/v1/merchants/{$merchant->id}/users", [
            'user_ids' => [$user->id]
        ], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Users attached successfully'
                ]);

        $this->assertDatabaseHas('merchant_users', [
            'merchant_id' => $merchant->id,
            'user_id' => $user->id
        ]);
    }

    public function test_can_detach_users_from_merchant()
    {
        $merchant = Merchant::factory()->create();
        $user = User::factory()->create(['status' => 'APPROVED']);
        
        // First attach the user
        $merchant->users()->attach($user->id);

        $response = $this->deleteJson("/api/v1/merchants/{$merchant->id}/users", [
            'user_ids' => [$user->id]
        ], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Users detached successfully'
                ]);

        $this->assertDatabaseMissing('merchant_users', [
            'merchant_id' => $merchant->id,
            'user_id' => $user->id
        ]);
    }

    public function test_merchant_creation_requires_valid_data()
    {
        $response = $this->postJson('/api/v1/merchants', [
            'name' => '', // Invalid: empty name
            'address' => '', // Invalid: empty address
            'type' => 'InvalidType' // Invalid: not in enum
        ], $this->headers);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation Error'
                ])
                ->assertJsonValidationErrors(['address', 'type']);
    }

    public function test_merchant_not_found_returns_404()
    {
        $response = $this->getJson('/api/v1/merchants/999999', $this->headers);

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Merchant not found'
                ]);
    }

    public function test_unauthorized_access_without_token()
    {
        $headers = [
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret')
        ];

        $response = $this->getJson('/api/v1/merchants', $headers);

        $response->assertStatus(401);
    }

    public function test_forbidden_access_without_privileges()
    {
        // Create user without merchant privileges
        $userRole = Role::where('name', 'User')->first();
        $user = User::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'testuser@test.com',
            'password' => bcrypt('password123'),
            'role_id' => $userRole->id,
            'status' => 'APPROVED'
        ]);

        $token = $user->createToken('Test Token')->plainTextToken;
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret')
        ];

        $response = $this->getJson('/api/v1/merchants', $headers);

        $response->assertStatus(403)
                ->assertJson([
                    'success' => false,
                    'message' => 'Forbidden'
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
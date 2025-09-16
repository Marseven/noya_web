<?php

namespace Tests\Feature;

use App\Models\Privilege;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class PrivilegeTest extends TestCase
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

    public function test_can_list_privileges()
    {
        $response = $this->getJson('/api/v1/privileges', $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Privileges retrieved successfully'
                ]);
    }

    public function test_can_create_privilege()
    {
        $privilegeData = [
            'nom' => 'test.custom.privilege',
            'description' => 'A test privilege for testing',
            'is_active' => true
        ];

        $response = $this->postJson('/api/v1/privileges', $privilegeData, $this->headers);

        $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'message' => 'Privilege created successfully'
                ]);

        $this->assertDatabaseHas('privileges', [
            'nom' => 'test.custom.privilege',
            'description' => 'A test privilege for testing'
        ]);
    }

    public function test_can_show_privilege()
    {
        $privilege = Privilege::factory()->create();

        $response = $this->getJson("/api/v1/privileges/{$privilege->id}", $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Privilege retrieved successfully'
                ]);
    }

    public function test_can_update_privilege()
    {
        $privilege = Privilege::factory()->create();
        
        $updateData = [
            'nom' => 'updated.privilege.name',
            'description' => 'Updated description',
            'is_active' => false
        ];

        $response = $this->putJson("/api/v1/privileges/{$privilege->id}", $updateData, $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Privilege updated successfully'
                ]);

        $this->assertDatabaseHas('privileges', [
            'id' => $privilege->id,
            'nom' => 'updated.privilege.name',
            'is_active' => false
        ]);
    }

    public function test_can_delete_privilege()
    {
        $privilege = Privilege::factory()->create();

        $response = $this->deleteJson("/api/v1/privileges/{$privilege->id}", [], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Privilege deleted successfully'
                ]);

        $this->assertSoftDeleted('privileges', ['id' => $privilege->id]);
    }

    public function test_privilege_creation_requires_unique_nom()
    {
        $existingPrivilege = Privilege::factory()->create(['nom' => 'unique.privilege']);

        $response = $this->postJson('/api/v1/privileges', [
            'nom' => 'unique.privilege', // Duplicate nom
            'description' => 'Test description'
        ], $this->headers);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation Error'
                ])
                ->assertJsonValidationErrors(['nom']);
    }

    public function test_privilege_not_found_returns_404()
    {
        $response = $this->getJson('/api/v1/privileges/999999', $this->headers);

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Privilege not found'
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

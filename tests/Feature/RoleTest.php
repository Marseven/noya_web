<?php

namespace Tests\Feature;

use App\Models\Privilege;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class RoleTest extends TestCase
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

    public function test_can_list_roles()
    {
        $response = $this->getJson('/api/v1/roles', $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Roles retrieved successfully'
                ]);
    }

    public function test_can_create_role()
    {
        $roleData = [
            'name' => 'Test Role',
            'description' => 'A test role for testing',
            'is_active' => true
        ];

        $response = $this->postJson('/api/v1/roles', $roleData, $this->headers);

        $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'message' => 'Role created successfully'
                ]);

        $this->assertDatabaseHas('roles', [
            'name' => 'Test Role',
            'description' => 'A test role for testing'
        ]);
    }

    public function test_can_show_role()
    {
        $role = Role::factory()->create();

        $response = $this->getJson("/api/v1/roles/{$role->id}", $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Role retrieved successfully'
                ]);
    }

    public function test_can_update_role()
    {
        $role = Role::factory()->create();
        
        $updateData = [
            'name' => 'Updated Role Name',
            'description' => 'Updated description',
            'is_active' => false
        ];

        $response = $this->putJson("/api/v1/roles/{$role->id}", $updateData, $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Role updated successfully'
                ]);

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'Updated Role Name',
            'is_active' => false
        ]);
    }

    public function test_can_delete_role()
    {
        $role = Role::factory()->create();

        $response = $this->deleteJson("/api/v1/roles/{$role->id}", [], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Role deleted successfully'
                ]);

        $this->assertSoftDeleted('roles', ['id' => $role->id]);
    }

    public function test_can_attach_privileges_to_role()
    {
        $role = Role::factory()->create();
        $privilege1 = Privilege::factory()->create();
        $privilege2 = Privilege::factory()->create();

        $response = $this->postJson("/api/v1/roles/{$role->id}/privileges", [
            'privilege_ids' => [$privilege1->id, $privilege2->id]
        ], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Privileges attached successfully'
                ]);

        $this->assertDatabaseHas('role_privileges', [
            'role_id' => $role->id,
            'privilege_id' => $privilege1->id
        ]);
        
        $this->assertDatabaseHas('role_privileges', [
            'role_id' => $role->id,
            'privilege_id' => $privilege2->id
        ]);
    }

    public function test_can_detach_privileges_from_role()
    {
        $role = Role::factory()->create();
        $privilege = Privilege::factory()->create();
        
        // First attach the privilege
        $role->privileges()->attach($privilege->id);

        $response = $this->deleteJson("/api/v1/roles/{$role->id}/privileges", [
            'privilege_ids' => [$privilege->id]
        ], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Privileges detached successfully'
                ]);

        $this->assertDatabaseMissing('role_privileges', [
            'role_id' => $role->id,
            'privilege_id' => $privilege->id
        ]);
    }

    public function test_role_creation_requires_unique_name()
    {
        $existingRole = Role::factory()->create(['name' => 'Unique Role']);

        $response = $this->postJson('/api/v1/roles', [
            'name' => 'Unique Role', // Duplicate name
            'description' => 'Test description'
        ], $this->headers);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation Error'
                ])
                ->assertJsonValidationErrors(['name']);
    }

    public function test_role_not_found_returns_404()
    {
        $response = $this->getJson('/api/v1/roles/999999', $this->headers);

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Role not found'
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

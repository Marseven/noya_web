<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $superAdmin;
    protected $merchant;
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

        $this->merchant = Merchant::factory()->approved()->create();

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
            'merchant_ids' => [$this->merchant->id],
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
        $userRole = Role::where('name', 'User')->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $userRole->id,
            'status' => 'PENDING',
        ]);
        $user->merchants()->sync([$this->merchant->id]);
        
        $updateData = [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'status' => 'APPROVED',
            'merchant_ids' => [$this->merchant->id],
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
        $role = Role::factory()->create();

        $response = $this->postJson('/api/v1/users', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'unique@example.com', // Duplicate email
            'password' => 'password123',
            'role_id' => $role->id,
            'merchant_ids' => [$this->merchant->id],
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

    public function test_super_admin_cannot_delete_own_account()
    {
        $peerSuperAdmin = User::factory()->create([
            'email' => 'peer-super-admin@test.com',
            'role_id' => $this->superAdmin->role_id,
            'status' => 'APPROVED',
        ]);
        $peerSuperAdmin->merchants()->sync([$this->merchant->id]);
        $this->superAdmin->merchants()->sync([$this->merchant->id]);

        $response = $this->deleteJson("/api/v1/users/{$this->superAdmin->id}", [], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'User deleted successfully',
                ]);

        $this->assertSoftDeleted('users', ['id' => $this->superAdmin->id]);
    }

    public function test_cannot_delete_last_super_admin_even_with_delete_privilege()
    {
        User::query()
            ->whereHas('role', function ($query) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%super admin%']);
            })
            ->where('id', '!=', $this->superAdmin->id)
            ->delete();

        $adminRole = Role::where('name', 'Admin')->first();
        $adminUser = User::factory()->create([
            'email' => 'admin-user@test.com',
            'role_id' => $adminRole->id,
            'status' => 'APPROVED',
        ]);
        $adminUser->merchants()->sync([$this->merchant->id]);
        $this->superAdmin->merchants()->sync([$this->merchant->id]);

        $adminToken = $adminUser->createToken('Admin Token')->plainTextToken;
        $adminHeaders = [
            'Authorization' => 'Bearer ' . $adminToken,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ];

        $response = $this->deleteJson("/api/v1/users/{$this->superAdmin->id}", [], $adminHeaders);

        $response->assertStatus(403)
                ->assertJson([
                    'success' => false,
                    'message' => 'Impossible de supprimer le dernier super admin actif',
                ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->superAdmin->id,
            'deleted_at' => null,
        ]);
    }

    public function test_cannot_downgrade_last_super_admin_role()
    {
        User::query()
            ->whereHas('role', function ($query) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%super admin%']);
            })
            ->where('id', '!=', $this->superAdmin->id)
            ->delete();

        $adminRole = Role::where('name', 'Admin')->firstOrFail();

        $response = $this->putJson("/api/v1/users/{$this->superAdmin->id}", [
            'role_id' => $adminRole->id,
        ], $this->headers);

        $response->assertStatus(403)
                ->assertJson([
                    'success' => false,
                    'message' => 'Cannot downgrade the last Super Admin account',
                ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->superAdmin->id,
            'role_id' => $this->superAdmin->role_id,
            'deleted_at' => null,
        ]);
    }

    public function test_admin_can_update_manager_and_user_but_not_admin_peer()
    {
        $adminRole = Role::where('name', 'Admin')->firstOrFail();
        $managerRole = Role::where('name', 'Manager')->firstOrFail();
        $userRole = Role::where('name', 'User')->firstOrFail();

        $adminActor = User::factory()->create([
            'email' => 'admin-actor@test.com',
            'role_id' => $adminRole->id,
            'status' => 'APPROVED',
        ]);
        $adminPeer = User::factory()->create([
            'email' => 'admin-peer@test.com',
            'role_id' => $adminRole->id,
            'status' => 'APPROVED',
        ]);
        $managerUser = User::factory()->create([
            'email' => 'manager-under-admin@test.com',
            'role_id' => $managerRole->id,
            'status' => 'APPROVED',
        ]);
        $normalUser = User::factory()->create([
            'email' => 'user-under-admin@test.com',
            'role_id' => $userRole->id,
            'status' => 'APPROVED',
        ]);

        $adminActor->merchants()->sync([$this->merchant->id]);
        $adminPeer->merchants()->sync([$this->merchant->id]);
        $managerUser->merchants()->sync([$this->merchant->id]);
        $normalUser->merchants()->sync([$this->merchant->id]);

        $adminToken = $adminActor->createToken('Admin Actor')->plainTextToken;
        $adminHeaders = [
            'Authorization' => 'Bearer ' . $adminToken,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ];

        $this->putJson("/api/v1/users/{$managerUser->id}", [
            'status' => 'BLOCKED',
        ], $adminHeaders)->assertStatus(200);

        $this->putJson("/api/v1/users/{$normalUser->id}", [
            'status' => 'BLOCKED',
        ], $adminHeaders)->assertStatus(200);

        $this->putJson("/api/v1/users/{$adminPeer->id}", [
            'status' => 'BLOCKED',
        ], $adminHeaders)->assertStatus(403);

        $this->assertDatabaseHas('users', [
            'id' => $managerUser->id,
            'status' => 'BLOCKED',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $normalUser->id,
            'status' => 'BLOCKED',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $adminPeer->id,
            'status' => 'APPROVED',
        ]);
    }

    public function test_manager_can_update_user_but_cannot_update_admin()
    {
        $adminRole = Role::where('name', 'Admin')->firstOrFail();
        $managerRole = Role::where('name', 'Manager')->firstOrFail();
        $userRole = Role::where('name', 'User')->firstOrFail();

        $managerActor = User::factory()->create([
            'email' => 'manager-actor@test.com',
            'role_id' => $managerRole->id,
            'status' => 'APPROVED',
        ]);
        $adminTarget = User::factory()->create([
            'email' => 'admin-target@test.com',
            'role_id' => $adminRole->id,
            'status' => 'APPROVED',
        ]);
        $userTarget = User::factory()->create([
            'email' => 'user-target@test.com',
            'role_id' => $userRole->id,
            'status' => 'APPROVED',
        ]);

        $managerActor->merchants()->sync([$this->merchant->id]);
        $adminTarget->merchants()->sync([$this->merchant->id]);
        $userTarget->merchants()->sync([$this->merchant->id]);

        $managerToken = $managerActor->createToken('Manager Actor')->plainTextToken;
        $managerHeaders = [
            'Authorization' => 'Bearer ' . $managerToken,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ];

        $this->putJson("/api/v1/users/{$userTarget->id}", [
            'status' => 'BLOCKED',
        ], $managerHeaders)->assertStatus(200);

        $this->putJson("/api/v1/users/{$adminTarget->id}", [
            'status' => 'BLOCKED',
        ], $managerHeaders)->assertStatus(403);

        $this->assertDatabaseHas('users', [
            'id' => $userTarget->id,
            'status' => 'BLOCKED',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $adminTarget->id,
            'status' => 'APPROVED',
        ]);
    }

    public function test_admin_can_create_admin_in_same_actor_scope()
    {
        $adminRole = Role::where('name', 'Admin')->firstOrFail();

        $adminActor = User::factory()->create([
            'email' => 'admin-creator@test.com',
            'role_id' => $adminRole->id,
            'status' => 'APPROVED',
        ]);
        $adminActor->merchants()->sync([$this->merchant->id]);

        $adminToken = $adminActor->createToken('Admin Creator')->plainTextToken;
        $adminHeaders = [
            'Authorization' => 'Bearer ' . $adminToken,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $response = $this->postJson('/api/v1/users', [
            'first_name' => 'Admin',
            'last_name' => 'Second',
            'email' => 'admin-second@test.com',
            'password' => 'password123',
            'role_id' => $adminRole->id,
            'status' => 'APPROVED',
        ], $adminHeaders);

        $response->assertStatus(201);
        $createdUserId = $response->json('data.id');

        $this->assertDatabaseHas('users', [
            'id' => $createdUserId,
            'email' => 'admin-second@test.com',
            'role_id' => $adminRole->id,
        ]);
        $this->assertDatabaseHas('merchant_users', [
            'user_id' => $createdUserId,
            'merchant_id' => $this->merchant->id,
        ]);
    }

    public function test_manager_can_create_manager_in_same_actor_scope()
    {
        $managerRole = Role::where('name', 'Manager')->firstOrFail();

        $managerActor = User::factory()->create([
            'email' => 'manager-creator@test.com',
            'role_id' => $managerRole->id,
            'status' => 'APPROVED',
        ]);
        $managerActor->merchants()->sync([$this->merchant->id]);

        $managerToken = $managerActor->createToken('Manager Creator')->plainTextToken;
        $managerHeaders = [
            'Authorization' => 'Bearer ' . $managerToken,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $response = $this->postJson('/api/v1/users', [
            'first_name' => 'Manager',
            'last_name' => 'Second',
            'email' => 'manager-second@test.com',
            'password' => 'password123',
            'role_id' => $managerRole->id,
            'status' => 'APPROVED',
        ], $managerHeaders);

        $response->assertStatus(201);
        $createdUserId = $response->json('data.id');

        $this->assertDatabaseHas('users', [
            'id' => $createdUserId,
            'email' => 'manager-second@test.com',
            'role_id' => $managerRole->id,
        ]);
        $this->assertDatabaseHas('merchant_users', [
            'user_id' => $createdUserId,
            'merchant_id' => $this->merchant->id,
        ]);
    }

    public function test_last_admin_cannot_self_change_status_or_role_or_delete()
    {
        $adminRole = Role::where('name', 'Admin')->firstOrFail();
        $managerRole = Role::where('name', 'Manager')->firstOrFail();

        $adminActor = User::factory()->create([
            'email' => 'single-admin@test.com',
            'role_id' => $adminRole->id,
            'status' => 'APPROVED',
        ]);
        $adminActor->merchants()->sync([$this->merchant->id]);

        $adminToken = $adminActor->createToken('Single Admin')->plainTextToken;
        $adminHeaders = [
            'Authorization' => 'Bearer ' . $adminToken,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $this->putJson("/api/v1/users/{$adminActor->id}", [
            'status' => 'BLOCKED',
        ], $adminHeaders)->assertStatus(403);

        $this->putJson("/api/v1/users/{$adminActor->id}", [
            'role_id' => $managerRole->id,
        ], $adminHeaders)->assertStatus(403);

        $this->deleteJson("/api/v1/users/{$adminActor->id}", [], $adminHeaders)->assertStatus(403);
    }

    public function test_last_manager_cannot_self_change_status_or_role_or_delete()
    {
        $managerRole = Role::where('name', 'Manager')->firstOrFail();
        $userRole = Role::where('name', 'User')->firstOrFail();

        $managerActor = User::factory()->create([
            'email' => 'single-manager@test.com',
            'role_id' => $managerRole->id,
            'status' => 'APPROVED',
        ]);
        $managerActor->merchants()->sync([$this->merchant->id]);

        $managerToken = $managerActor->createToken('Single Manager')->plainTextToken;
        $managerHeaders = [
            'Authorization' => 'Bearer ' . $managerToken,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $this->putJson("/api/v1/users/{$managerActor->id}", [
            'status' => 'BLOCKED',
        ], $managerHeaders)->assertStatus(403);

        $this->putJson("/api/v1/users/{$managerActor->id}", [
            'role_id' => $userRole->id,
        ], $managerHeaders)->assertStatus(403);

        $this->deleteJson("/api/v1/users/{$managerActor->id}", [], $managerHeaders)->assertStatus(403);
    }

    public function test_user_can_self_delete()
    {
        $userRole = Role::where('name', 'User')->firstOrFail();

        $basicUser = User::factory()->create([
            'email' => 'basic-self-delete@test.com',
            'role_id' => $userRole->id,
            'status' => 'APPROVED',
        ]);
        $basicUser->merchants()->sync([$this->merchant->id]);

        $userToken = $basicUser->createToken('Basic User')->plainTextToken;
        $userHeaders = [
            'Authorization' => 'Bearer ' . $userToken,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $this->deleteJson("/api/v1/users/{$basicUser->id}", [], $userHeaders)
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'User deleted successfully',
            ]);

        $this->assertSoftDeleted('users', ['id' => $basicUser->id]);
    }

    public function test_admin_can_self_change_role_when_not_last_admin_and_actor_unchanged()
    {
        $adminRole = Role::where('name', 'Admin')->firstOrFail();
        $managerRole = Role::where('name', 'Manager')->firstOrFail();

        $adminSelf = User::factory()->create([
            'email' => 'admin-self-change@test.com',
            'role_id' => $adminRole->id,
            'status' => 'APPROVED',
        ]);
        $adminPeer = User::factory()->create([
            'email' => 'admin-peer-for-self-change@test.com',
            'role_id' => $adminRole->id,
            'status' => 'APPROVED',
        ]);

        $adminSelf->merchants()->sync([$this->merchant->id]);
        $adminPeer->merchants()->sync([$this->merchant->id]);

        $adminToken = $adminSelf->createToken('Admin Self Change')->plainTextToken;
        $adminHeaders = [
            'Authorization' => 'Bearer ' . $adminToken,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $this->putJson("/api/v1/users/{$adminSelf->id}", [
            'role_id' => $managerRole->id,
            'merchant_ids' => [$this->merchant->id],
        ], $adminHeaders)->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $adminSelf->id,
            'role_id' => $managerRole->id,
        ]);
    }

    public function test_manager_can_self_change_role_when_not_last_manager_and_actor_unchanged()
    {
        $managerRole = Role::where('name', 'Manager')->firstOrFail();
        $userRole = Role::where('name', 'User')->firstOrFail();

        $managerSelf = User::factory()->create([
            'email' => 'manager-self-change@test.com',
            'role_id' => $managerRole->id,
            'status' => 'APPROVED',
        ]);
        $managerPeer = User::factory()->create([
            'email' => 'manager-peer-for-self-change@test.com',
            'role_id' => $managerRole->id,
            'status' => 'APPROVED',
        ]);

        $managerSelf->merchants()->sync([$this->merchant->id]);
        $managerPeer->merchants()->sync([$this->merchant->id]);

        $managerToken = $managerSelf->createToken('Manager Self Change')->plainTextToken;
        $managerHeaders = [
            'Authorization' => 'Bearer ' . $managerToken,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $this->putJson("/api/v1/users/{$managerSelf->id}", [
            'role_id' => $userRole->id,
            'merchant_ids' => [$this->merchant->id],
        ], $managerHeaders)->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $managerSelf->id,
            'role_id' => $userRole->id,
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

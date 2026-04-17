<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActorHierarchyScopeTest extends TestCase
{
    use RefreshDatabase;

    private array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => 'RolesAndPrivilegesSeeder']);

        $adminRole = Role::where('name', 'Admin')->firstOrFail();
        $userRole = Role::where('name', 'User')->firstOrFail();

        $parentMerchant = Merchant::factory()->approved()->create([
            'name' => 'Root Distributor',
            'type' => 'Distributor',
        ]);
        $childMerchant = Merchant::factory()->approved()->create([
            'name' => 'Child Wholesaler',
            'type' => 'Wholesaler',
            'merchant_parent_id' => $parentMerchant->id,
        ]);
        $outsideMerchant = Merchant::factory()->approved()->create([
            'name' => 'Outside Network',
            'type' => 'Distributor',
        ]);

        $admin = User::factory()->create([
            'email' => 'network-admin@test.com',
            'role_id' => $adminRole->id,
            'status' => 'APPROVED',
        ]);
        $admin->merchants()->sync([$parentMerchant->id]);

        $childUser = User::factory()->create([
            'email' => 'child-user@test.com',
            'role_id' => $userRole->id,
            'status' => 'APPROVED',
        ]);
        $childUser->merchants()->sync([$childMerchant->id]);

        $outsideUser = User::factory()->create([
            'email' => 'outside-user@test.com',
            'role_id' => $userRole->id,
            'status' => 'APPROVED',
        ]);
        $outsideUser->merchants()->sync([$outsideMerchant->id]);

        $token = $admin->createToken('Test Token')->plainTextToken;
        $this->headers = [
            'Authorization' => 'Bearer ' . $token,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    public function test_parent_actor_can_see_descendant_actors_but_not_outside_network(): void
    {
        $response = $this->getJson('/api/v1/merchants?per_page=100', $this->headers);
        $response->assertStatus(200);

        $merchantIds = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $parent = Merchant::where('name', 'Root Distributor')->firstOrFail();
        $child = Merchant::where('name', 'Child Wholesaler')->firstOrFail();
        $outside = Merchant::where('name', 'Outside Network')->firstOrFail();

        $this->assertContains($parent->id, $merchantIds);
        $this->assertContains($child->id, $merchantIds);
        $this->assertNotContains($outside->id, $merchantIds);
    }

    public function test_parent_actor_can_see_descendant_users_but_not_users_from_other_networks(): void
    {
        $response = $this->getJson('/api/v1/users?per_page=100', $this->headers);
        $response->assertStatus(200);

        $userIds = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $admin = User::where('email', 'network-admin@test.com')->firstOrFail();
        $childUser = User::where('email', 'child-user@test.com')->firstOrFail();
        $outsideUser = User::where('email', 'outside-user@test.com')->firstOrFail();

        $this->assertContains($admin->id, $userIds);
        $this->assertContains($childUser->id, $userIds);
        $this->assertNotContains($outsideUser->id, $userIds);
    }
}


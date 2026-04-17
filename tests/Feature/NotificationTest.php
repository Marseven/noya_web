<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => 'RolesAndPrivilegesSeeder']);

        $superAdminRole = Role::where('name', 'Super Admin')->first();
        $this->superAdmin = User::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'superadmin@test.com',
            'password' => bcrypt('password123'),
            'role_id' => $superAdminRole->id,
            'status' => 'APPROVED',
        ]);

        $token = $this->superAdmin->createToken('Test Token')->plainTextToken;
        $this->headers = [
            'Authorization' => 'Bearer ' . $token,
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    public function test_notification_endpoints_work()
    {
        Notification::create([
            'user_id' => $this->superAdmin->id,
            'type' => 'order_validated',
            'title' => 'Précommande validée',
            'message' => 'La commande ORD-001 est validée.',
            'related_id' => 1,
            'is_read' => false,
        ]);

        $countResponse = $this->getJson('/api/v1/notifications/unread-count', $this->headers);
        $countResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => ['unread_count' => 1],
            ]);

        $listResponse = $this->getJson('/api/v1/notifications', $this->headers);
        $listResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $notificationId = $listResponse->json('data.0.id');
        $this->assertNotNull($notificationId);

        $markReadResponse = $this->patchJson("/api/v1/notifications/{$notificationId}/read", [], $this->headers);
        $markReadResponse->assertStatus(200);

        $countAfterResponse = $this->getJson('/api/v1/notifications/unread-count', $this->headers);
        $countAfterResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => ['unread_count' => 0],
            ]);
    }
}


<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Cart;
use App\Models\DeliveryHistory;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class OrderTest extends TestCase
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

    public function test_can_list_orders()
    {
        Order::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/orders', $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Orders retrieved successfully'
                ]);
    }

    public function test_can_create_order()
    {
        $merchant = Merchant::factory()->create();
        
        $orderData = [
            'order_number' => 'ORD-TEST-001',
            'amount' => 299.99,
            'merchant_id' => $merchant->id,
            'status' => 'INIT'
        ];

        $response = $this->postJson('/api/v1/orders', $orderData, $this->headers);

        $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'message' => 'Order created successfully'
                ]);

        $this->assertDatabaseHas('orders', [
            'order_number' => 'ORD-TEST-001',
            'amount' => 299.99,
            'merchant_id' => $merchant->id
        ]);
    }

    public function test_can_show_order()
    {
        $order = Order::factory()->create();

        $response = $this->getJson("/api/v1/orders/{$order->id}", $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Order retrieved successfully'
                ]);
    }

    public function test_can_update_order()
    {
        $order = Order::factory()->create();
        
        $updateData = [
            'status' => 'PAID',
            'amount' => 399.99
        ];

        $response = $this->putJson("/api/v1/orders/{$order->id}", $updateData, $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Order updated successfully'
                ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'PAID',
            'amount' => 399.99
        ]);
    }

    public function test_can_delete_order()
    {
        $order = Order::factory()->create();

        $response = $this->deleteJson("/api/v1/orders/{$order->id}", [], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Order deleted successfully'
                ]);

        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_can_calculate_order_amount()
    {
        $merchant = Merchant::factory()->create();
        $order = Order::factory()->create(['merchant_id' => $merchant->id]);
        $article1 = Article::factory()->create(['price' => 29.99]);
        $article2 = Article::factory()->create(['price' => 49.99]);

        // Add cart items
        Cart::create([
            'article_id' => $article1->id,
            'quantity' => 2,
            'order_id' => $order->id
        ]);

        Cart::create([
            'article_id' => $article2->id,
            'quantity' => 1,
            'order_id' => $order->id
        ]);

        $response = $this->postJson("/api/v1/orders/{$order->id}/calculate", [], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Order amount calculated successfully'
                ]);

        $expectedAmount = (29.99 * 2) + (49.99 * 1); // 109.97
        $this->assertEquals($expectedAmount, $response->json('data.calculated_amount'));
    }

    public function test_order_not_found_returns_404()
    {
        $response = $this->getJson('/api/v1/orders/999999', $this->headers);

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Order not found'
                ]);
    }

    public function test_order_status_transition_creates_history_and_notification()
    {
        $merchant = Merchant::factory()->create();
        $order = Order::factory()->create([
            'merchant_id' => $merchant->id,
            'destination_merchant_id' => $merchant->id,
            'source_merchant_id' => $merchant->id,
            'status' => 'INIT',
        ]);

        $response = $this->putJson("/api/v1/orders/{$order->id}", [
            'status' => 'VALIDATED',
        ], $this->headers);

        $response->assertStatus(200);

        $this->assertDatabaseHas('delivery_histories', [
            'order_id' => $order->id,
            'from_status' => 'INIT',
            'to_status' => 'VALIDATED',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->superAdmin->id,
            'type' => 'order_validated',
            'related_id' => $order->id,
        ]);
    }

    public function test_can_get_order_history()
    {
        $merchant = Merchant::factory()->create();
        $order = Order::factory()->create([
            'merchant_id' => $merchant->id,
            'destination_merchant_id' => $merchant->id,
            'source_merchant_id' => $merchant->id,
            'status' => 'INIT',
        ]);

        DeliveryHistory::create([
            'order_id' => $order->id,
            'merchant_id' => $merchant->id,
            'changed_by' => $this->superAdmin->id,
            'from_status' => 'INIT',
            'to_status' => 'VALIDATED',
            'changed_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/orders/{$order->id}/history", $this->headers);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Order history retrieved successfully',
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

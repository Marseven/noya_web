<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class CartTest extends TestCase
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

    public function test_can_list_carts()
    {
        Cart::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/carts', $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Carts retrieved successfully'
                ]);
    }

    public function test_can_create_cart_item()
    {
        $article = Article::factory()->create();
        $order = Order::factory()->create();
        
        $cartData = [
            'article_id' => $article->id,
            'quantity' => 2,
            'order_id' => $order->id
        ];

        $response = $this->postJson('/api/v1/carts', $cartData, $this->headers);

        $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'message' => 'Cart item created successfully'
                ]);

        $this->assertDatabaseHas('carts', [
            'article_id' => $article->id,
            'quantity' => 2,
            'order_id' => $order->id
        ]);
    }

    public function test_can_show_cart_item()
    {
        $cart = Cart::factory()->create();

        $response = $this->getJson("/api/v1/carts/{$cart->id}", $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Cart retrieved successfully'
                ]);
    }

    public function test_can_update_cart_item()
    {
        $cart = Cart::factory()->create(['quantity' => 2]);
        
        $updateData = [
            'quantity' => 5
        ];

        $response = $this->putJson("/api/v1/carts/{$cart->id}", $updateData, $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Cart updated successfully'
                ]);

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'quantity' => 5
        ]);
    }

    public function test_can_delete_cart_item()
    {
        $cart = Cart::factory()->create();

        $response = $this->deleteJson("/api/v1/carts/{$cart->id}", [], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Cart deleted successfully'
                ]);

        $this->assertSoftDeleted('carts', ['id' => $cart->id]);
    }

    public function test_cart_creation_requires_valid_data()
    {
        $response = $this->postJson('/api/v1/carts', [
            'article_id' => 999999, // Invalid: non-existent article
            'quantity' => 0,        // Invalid: zero quantity
            'order_id' => 999999    // Invalid: non-existent order
        ], $this->headers);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation Error'
                ])
                ->assertJsonValidationErrors(['article_id', 'quantity', 'order_id']);
    }

    public function test_cart_not_found_returns_404()
    {
        $response = $this->getJson('/api/v1/carts/999999', $this->headers);

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Cart not found'
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

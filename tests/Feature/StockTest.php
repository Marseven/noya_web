<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Merchant;
use App\Models\Role;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class StockTest extends TestCase
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

    public function test_can_list_stocks()
    {
        // Create test data
        $merchant = Merchant::factory()->create();
        $article = Article::factory()->create(['merchant_id' => $merchant->id]);
        Stock::create([
            'merchant_id' => $merchant->id,
            'article_id' => $article->id,
            'stock' => 100,
            'last_action_type' => 'MANUALLY_ADD'
        ]);

        $response = $this->getJson('/api/v1/stocks', $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Stocks retrieved successfully'
                ]);
    }

    public function test_can_create_stock()
    {
        $merchant = Merchant::factory()->create();
        $article = Article::factory()->create(['merchant_id' => $merchant->id]);
        
        $stockData = [
            'merchant_id' => $merchant->id,
            'article_id' => $article->id,
            'stock' => 50,
            'last_action_type' => 'MANUALLY_ADD'
        ];

        $response = $this->postJson('/api/v1/stocks', $stockData, $this->headers);

        $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'message' => 'Stock created successfully'
                ]);

        $this->assertDatabaseHas('stocks', [
            'merchant_id' => $merchant->id,
            'article_id' => $article->id,
            'stock' => 50
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

<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Merchant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ArticleTest extends TestCase
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

    public function test_can_list_articles()
    {
        // Create test articles
        Article::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/articles', $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Articles retrieved successfully'
                ])
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'price',
                            'is_active',
                            'created_at',
                            'updated_at'
                        ]
                    ],
                    'meta',
                    'links'
                ]);
    }

    public function test_can_create_article()
    {
        $merchant = Merchant::factory()->create();
        
        $articleData = [
            'name' => 'Test Article',
            'price' => 29.99,
            'photo_url' => 'https://example.com/test.jpg',
            'merchant_id' => $merchant->id,
            'is_active' => true
        ];

        $response = $this->postJson('/api/v1/articles', $articleData, $this->headers);

        $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'message' => 'Article created successfully'
                ]);

        $this->assertDatabaseHas('articles', [
            'name' => 'Test Article',
            'price' => 29.99,
            'merchant_id' => $merchant->id
        ]);
    }

    public function test_can_show_article()
    {
        $article = Article::factory()->create();

        $response = $this->getJson("/api/v1/articles/{$article->id}", $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Article retrieved successfully',
                    'data' => [
                        'id' => $article->id,
                        'name' => $article->name,
                        'price' => $article->price
                    ]
                ]);
    }

    public function test_can_update_article()
    {
        $article = Article::factory()->create();
        
        $updateData = [
            'name' => 'Updated Article Name',
            'price' => 39.99,
            'is_active' => false
        ];

        $response = $this->putJson("/api/v1/articles/{$article->id}", $updateData, $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Article updated successfully'
                ]);

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'name' => 'Updated Article Name',
            'price' => 39.99,
            'is_active' => false
        ]);
    }

    public function test_can_delete_article()
    {
        $article = Article::factory()->create();

        $response = $this->deleteJson("/api/v1/articles/{$article->id}", [], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Article deleted successfully'
                ]);

        $this->assertSoftDeleted('articles', ['id' => $article->id]);
    }

    public function test_can_filter_articles_by_merchant()
    {
        $merchant1 = Merchant::factory()->create();
        $merchant2 = Merchant::factory()->create();
        
        Article::factory()->count(2)->create(['merchant_id' => $merchant1->id]);
        Article::factory()->count(3)->create(['merchant_id' => $merchant2->id]);

        $response = $this->getJson("/api/v1/articles?merchant_id={$merchant1->id}", $this->headers);

        $response->assertStatus(200)
                ->assertJsonCount(2, 'data');
    }

    public function test_can_filter_articles_by_active_status()
    {
        Article::factory()->count(2)->active()->create();
        Article::factory()->count(3)->inactive()->create();

        $response = $this->getJson('/api/v1/articles?is_active=1', $this->headers);

        $response->assertStatus(200)
                ->assertJsonCount(2, 'data');
    }

    public function test_article_creation_requires_unique_name()
    {
        $existingArticle = Article::factory()->create(['name' => 'Unique Article']);

        $response = $this->postJson('/api/v1/articles', [
            'name' => 'Unique Article', // Duplicate name
            'price' => 29.99
        ], $this->headers);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation Error'
                ])
                ->assertJsonValidationErrors(['name']);
    }

    public function test_article_creation_requires_valid_data()
    {
        $response = $this->postJson('/api/v1/articles', [
            'name' => '', // Invalid: empty name
            'price' => -10, // Invalid: negative price
            'merchant_id' => 999999 // Invalid: non-existent merchant
        ], $this->headers);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation Error'
                ])
                ->assertJsonValidationErrors(['name', 'price', 'merchant_id']);
    }

    public function test_article_not_found_returns_404()
    {
        $response = $this->getJson('/api/v1/articles/999999', $this->headers);

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Article not found'
                ]);
    }

    public function test_unauthorized_access_without_token()
    {
        $headers = [
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret')
        ];

        $response = $this->getJson('/api/v1/articles', $headers);

        $response->assertStatus(401);
    }

    public function test_forbidden_access_without_privileges()
    {
        // Create user without article privileges
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

        // User role should have articles.view privilege, so let's test create instead
        $response = $this->postJson('/api/v1/articles', [
            'name' => 'Test Article',
            'price' => 29.99
        ], $headers);

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
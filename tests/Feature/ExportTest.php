<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ExportToken;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $superAdmin;
    protected $regularUser;
    protected $adminToken;
    protected $userToken;
    protected $adminHeaders;
    protected $userHeaders;

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

        // Create regular user with limited privileges
        $userRole = Role::where('name', 'User')->first();
        $this->regularUser = User::create([
            'first_name' => 'Regular',
            'last_name' => 'User',
            'email' => 'user@test.com',
            'password' => bcrypt('password123'),
            'role_id' => $userRole->id,
            'status' => 'APPROVED'
        ]);

        // Create tokens and headers
        $this->adminToken = $this->superAdmin->createToken('Admin Token')->plainTextToken;
        $this->userToken = $this->regularUser->createToken('User Token')->plainTextToken;
        
        $this->adminHeaders = [
            'Authorization' => 'Bearer ' . $this->adminToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ];

        $this->userHeaders = [
            'Authorization' => 'Bearer ' . $this->userToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ];

        // Set up storage for testing
        Storage::fake('local');
    }

    public function test_can_generate_users_export()
    {
        // Create some test users
        User::factory()->count(5)->create();

        $response = $this->postJson('/api/v1/exports/generate', [
            'type' => 'users'
        ], $this->adminHeaders);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Export generated successfully'
                ])
                ->assertJsonStructure([
                    'data' => [
                        'download_url',
                        'expires_at',
                        'file_name',
                        'type'
                    ]
                ]);

        $this->assertEquals('users', $response->json('data.type'));
        $this->assertStringContainsString('users_export_', $response->json('data.file_name'));
    }

    public function test_can_generate_merchants_export_with_filters()
    {
        // Create test merchants
        Merchant::factory()->count(3)->create(['status' => 'APPROVED']);
        Merchant::factory()->count(2)->create(['status' => 'PENDING']);

        $response = $this->postJson('/api/v1/exports/generate', [
            'type' => 'merchants',
            'status' => 'APPROVED',
            'from_date' => '2024-01-01',
            'to_date' => '2024-12-31'
        ], $this->adminHeaders);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Export generated successfully'
                ]);

        $this->assertEquals('merchants', $response->json('data.type'));
    }

    public function test_can_generate_orders_export_with_merchant_filter()
    {
        $merchant = Merchant::factory()->create();
        Order::factory()->count(3)->create(['merchant_id' => $merchant->id]);
        Order::factory()->count(2)->create(); // Different merchant

        $response = $this->postJson('/api/v1/exports/generate', [
            'type' => 'orders',
            'merchant_id' => $merchant->id
        ], $this->adminHeaders);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Export generated successfully'
                ]);

        $this->assertEquals('orders', $response->json('data.type'));
    }

    public function test_can_generate_articles_export()
    {
        Article::factory()->count(5)->create(['is_active' => true]);
        Article::factory()->count(2)->create(['is_active' => false]);

        $response = $this->postJson('/api/v1/exports/generate', [
            'type' => 'articles',
            'is_active' => true
        ], $this->adminHeaders);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Export generated successfully'
                ]);

        $this->assertEquals('articles', $response->json('data.type'));
    }

    public function test_can_generate_users_csv_export()
    {
        User::factory()->count(2)->create();

        $response = $this->postJson('/api/v1/exports/generate', [
            'type' => 'users',
            'format' => 'csv',
        ], $this->adminHeaders);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Export generated successfully',
            ]);

        $this->assertStringEndsWith('.csv', $response->json('data.file_name'));
        $this->assertEquals('csv', $response->json('data.format'));
    }

    public function test_can_generate_users_pdf_export_and_download()
    {
        User::factory()->count(2)->create();

        $generateResponse = $this->postJson('/api/v1/exports/generate', [
            'type' => 'users',
            'format' => 'pdf',
        ], $this->adminHeaders);

        $generateResponse->assertStatus(200);
        $this->assertStringEndsWith('.pdf', $generateResponse->json('data.file_name'));
        $this->assertEquals('pdf', $generateResponse->json('data.format'));

        $token = basename((string) $generateResponse->json('data.download_url'));
        $downloadResponse = $this->get("/api/v1/exports/download/{$token}");

        $downloadResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $downloadResponse->headers->get('Content-Type'));
    }

    public function test_export_requires_proper_privileges()
    {
        $response = $this->postJson('/api/v1/exports/generate', [
            'type' => 'users'
        ], $this->userHeaders);

        $response->assertStatus(403)
                ->assertJson([
                    'success' => false,
                    'message' => 'Insufficient privileges to export users'
                ]);
    }

    public function test_export_validation_fails_for_invalid_type()
    {
        $response = $this->postJson('/api/v1/exports/generate', [
            'type' => 'invalid_type'
        ], $this->adminHeaders);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation Error'
                ])
                ->assertJsonValidationErrors(['type']);
    }

    public function test_export_validation_fails_for_invalid_date_range()
    {
        $response = $this->postJson('/api/v1/exports/generate', [
            'type' => 'users',
            'from_date' => '2024-12-31',
            'to_date' => '2024-01-01' // to_date before from_date
        ], $this->adminHeaders);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation Error'
                ])
                ->assertJsonValidationErrors(['to_date']);
    }

    public function test_can_download_export_with_valid_token()
    {
        // First generate an export
        $generateResponse = $this->postJson('/api/v1/exports/generate', [
            'type' => 'users'
        ], $this->adminHeaders);

        $generateResponse->assertStatus(200);
        $downloadUrl = $generateResponse->json('data.download_url');
        $token = basename($downloadUrl);

        // Download the export
        $response = $this->get("/api/v1/exports/download/{$token}");

        $response->assertStatus(200);
        $this->assertEquals(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_cannot_download_with_invalid_token()
    {
        $response = $this->get('/api/v1/exports/download/invalid_token');

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Invalid download token'
                ]);
    }

    public function test_cannot_download_with_used_token()
    {
        // Create a fake file
        Storage::disk('local')->put('exports/test.xlsx', 'fake excel content');
        
        // Create and use a token
        $exportToken = ExportToken::createToken(
            $this->superAdmin->id,
            'users',
            'exports/test.xlsx'
        );
        $exportToken->markAsUsed();

        $response = $this->get("/api/v1/exports/download/{$exportToken->token}");

        $response->assertStatus(410)
                ->assertJson([
                    'success' => false,
                    'message' => 'Download token has already been used'
                ]);
    }

    public function test_cannot_download_with_expired_token()
    {
        // Create a fake file
        Storage::disk('local')->put('exports/expired_test.xlsx', 'fake excel content');
        
        // Create an expired token
        $exportToken = ExportToken::create([
            'token' => ExportToken::generateToken(),
            'user_id' => $this->superAdmin->id,
            'export_type' => 'users',
            'file_path' => 'exports/expired_test.xlsx',
            'parameters' => [],
            'expires_at' => now()->subHour(), // Expired
            'used' => false
        ]);

        $response = $this->get("/api/v1/exports/download/{$exportToken->token}");

        $response->assertStatus(410)
                ->assertJson([
                    'success' => false,
                    'message' => 'Download token has expired'
                ]);
    }

    public function test_can_get_export_history()
    {
        // Create some export tokens for the user
        ExportToken::createToken($this->superAdmin->id, 'users', 'exports/users1.xlsx');
        ExportToken::createToken($this->superAdmin->id, 'merchants', 'exports/merchants1.xlsx');

        $response = $this->getJson('/api/v1/exports/history', $this->adminHeaders);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Export history retrieved successfully'
                ])
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'export_type',
                            'parameters',
                            'used',
                            'expires_at',
                            'used_at',
                            'created_at'
                        ]
                    ]
                ]);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_export_history_is_user_specific()
    {
        // Create export tokens for different users
        ExportToken::createToken($this->superAdmin->id, 'users', 'exports/admin_users.xlsx');
        ExportToken::createToken($this->regularUser->id, 'articles', 'exports/user_articles.xlsx');

        $response = $this->getJson('/api/v1/exports/history', $this->adminHeaders);

        $response->assertStatus(200);
        
        // Should only see admin's exports
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('users', $response->json('data.0.export_type'));
    }

    public function test_export_token_model_methods()
    {
        $token = ExportToken::createToken(
            $this->superAdmin->id,
            'users',
            'exports/test.xlsx',
            ['status' => 'APPROVED']
        );

        // Test isValid method
        $this->assertTrue($token->isValid());

        // Test markAsUsed method
        $token->markAsUsed();
        $this->assertFalse($token->isValid());
        $this->assertTrue($token->used);
        $this->assertNotNull($token->used_at);

        // Test generateToken method
        $token1 = ExportToken::generateToken();
        $token2 = ExportToken::generateToken();
        $this->assertNotEquals($token1, $token2);
        $this->assertEquals(64, strlen($token1));
    }

    public function test_export_requires_authentication()
    {
        $response = $this->postJson('/api/v1/exports/generate', [
            'type' => 'users'
        ]);

        $response->assertStatus(401);
    }

    public function test_export_with_authentication_does_not_require_legacy_api_headers()
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->adminToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $response = $this->postJson('/api/v1/exports/generate', [
            'type' => 'users'
        ], $headers);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Export generated successfully',
            ]);
    }

    protected function tearDown(): void
    {
        // Clean up database after each test
        if (isset($this->superAdmin)) {
            $this->superAdmin->tokens()->delete();
        }
        
        if (isset($this->regularUser)) {
            $this->regularUser->tokens()->delete();
        }
        
        parent::tearDown();
    }
}

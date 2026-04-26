<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Cart;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Privilege;
use App\Models\Role;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ComprehensiveApiTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $superAdmin;
    protected $defaultMerchant;
    protected $defaultUserRole;
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

        $this->defaultMerchant = Merchant::factory()->approved()->create();
        $this->defaultUserRole = Role::where('name', 'User')->first();

        // Create token and headers
        $this->token = $this->superAdmin->createToken('Test Token')->plainTextToken;
        $this->headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ];
    }

    public function test_complete_business_flow()
    {
        // 1. Create a merchant
        $merchantData = [
            'name' => 'Test Store',
            'address' => '123 Test Street',
            'type' => 'PointOfSell',
            'status' => 'APPROVED'
        ];

        $merchantResponse = $this->postJson('/api/v1/merchants', $merchantData, $this->headers);
        $merchantResponse->assertStatus(201);
        $merchantId = $merchantResponse->json('data.id');

        // 2. Create articles
        $article1Data = [
            'name' => 'Product 1',
            'price' => 29.99,
            'merchant_id' => $merchantId,
            'is_active' => true
        ];

        $article1Response = $this->postJson('/api/v1/articles', $article1Data, $this->headers);
        $article1Response->assertStatus(201);
        $article1Id = $article1Response->json('data.id');

        $article2Data = [
            'name' => 'Product 2',
            'price' => 49.99,
            'merchant_id' => $merchantId,
            'is_active' => true
        ];

        $article2Response = $this->postJson('/api/v1/articles', $article2Data, $this->headers);
        $article2Response->assertStatus(201);
        $article2Id = $article2Response->json('data.id');

        // 3. Create stock entries
        $stock1Data = [
            'merchant_id' => $merchantId,
            'article_id' => $article1Id,
            'stock' => 100,
            'last_action_type' => 'MANUALLY_ADD'
        ];

        $stock1Response = $this->postJson('/api/v1/stocks', $stock1Data, $this->headers);
        $stock1Response->assertStatus(201);
        $stock1Id = $stock1Response->json('data.id');

        $stock2Data = [
            'merchant_id' => $merchantId,
            'article_id' => $article2Id,
            'stock' => 50,
            'last_action_type' => 'MANUALLY_ADD'
        ];

        $stock2Response = $this->postJson('/api/v1/stocks', $stock2Data, $this->headers);
        $stock2Response->assertStatus(201);
        $stock2Id = $stock2Response->json('data.id');

        // 4. Test stock operations
        $addStockResponse = $this->postJson("/api/v1/stocks/{$stock1Id}/add", [
            'quantity' => 25,
            'action_type' => 'MANUALLY_ADD'
        ], $this->headers);
        $addStockResponse->assertStatus(200);

        $withdrawStockResponse = $this->postJson("/api/v1/stocks/{$stock1Id}/withdraw", [
            'quantity' => 10,
            'action_type' => 'MANUALLY_WITHDRAW'
        ], $this->headers);
        $withdrawStockResponse->assertStatus(200);

        // 5. Check stock history
        $historyResponse = $this->getJson("/api/v1/stocks/{$stock1Id}/history", $this->headers);
        $historyResponse->assertStatus(200);

        // 6. Create an order
        $orderData = [
            'merchant_id' => $merchantId,
            'status' => 'INIT'
        ];

        $orderResponse = $this->postJson('/api/v1/orders', $orderData, $this->headers);
        $orderResponse->assertStatus(201);
        $orderId = $orderResponse->json('data.id');

        // 7. Add items to cart
        $cart1Data = [
            'article_id' => $article1Id,
            'quantity' => 2,
            'order_id' => $orderId
        ];

        $cart1Response = $this->postJson('/api/v1/carts', $cart1Data, $this->headers);
        $cart1Response->assertStatus(201);

        $cart2Data = [
            'article_id' => $article2Id,
            'quantity' => 1,
            'order_id' => $orderId
        ];

        $cart2Response = $this->postJson('/api/v1/carts', $cart2Data, $this->headers);
        $cart2Response->assertStatus(201);

        // 8. Calculate order amount
        $calculateResponse = $this->postJson("/api/v1/orders/{$orderId}/calculate", [], $this->headers);
        $calculateResponse->assertStatus(200);
        $orderAmount = $calculateResponse->json('data.calculated_amount');

        // 9. Create payment
        $paymentData = [
            'order_id' => $orderId,
            'amount' => $orderAmount,
            'partner_name' => 'Test Gateway',
            'partner_fees' => $orderAmount * 0.03,
            'total_amount' => $orderAmount + ($orderAmount * 0.03),
            'status' => 'INIT',
            'partner_reference' => 'TEST-PAY-001'
        ];

        $paymentResponse = $this->postJson('/api/v1/payments', $paymentData, $this->headers);
        $paymentResponse->assertStatus(201);
        $paymentId = $paymentResponse->json('data.id');

        // 10. Confirm payment
        $confirmResponse = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
            'partner_reference' => 'CONFIRMED-TEST-PAY-001'
        ], $this->headers);
        $confirmResponse->assertStatus(200);

        // 11. Verify order status changed to PAID
        $orderCheckResponse = $this->getJson("/api/v1/orders/{$orderId}", $this->headers);
        $orderCheckResponse->assertStatus(200)
                          ->assertJson([
                              'data' => [
                                  'status' => 'PAID'
                              ]
                          ]);

        $this->assertTrue(true, 'Complete business flow test passed');
    }

    public function test_role_and_privilege_management()
    {
        // 1. Create a new role
        $roleData = [
            'name' => 'Test Role',
            'description' => 'A test role for testing',
            'is_active' => true
        ];

        $roleResponse = $this->postJson('/api/v1/roles', $roleData, $this->headers);
        $roleResponse->assertStatus(201);
        $roleId = $roleResponse->json('data.id');

        // 2. Create a new privilege
        $privilegeData = [
            'nom' => 'test.privilege',
            'description' => 'A test privilege',
            'is_active' => true
        ];

        $privilegeResponse = $this->postJson('/api/v1/privileges', $privilegeData, $this->headers);
        $privilegeResponse->assertStatus(201);
        $privilegeId = $privilegeResponse->json('data.id');

        // 3. Attach privilege to role
        $attachResponse = $this->postJson("/api/v1/roles/{$roleId}/privileges", [
            'privilege_ids' => [$privilegeId]
        ], $this->headers);
        $attachResponse->assertStatus(200);

        // 4. Verify privilege is attached
        $roleCheckResponse = $this->getJson("/api/v1/roles/{$roleId}", $this->headers);
        $roleCheckResponse->assertStatus(200);
        
        $privileges = $roleCheckResponse->json('data.privileges');
        $this->assertCount(1, $privileges);
        $this->assertEquals($privilegeId, $privileges[0]['id']);

        // 5. Create user with this role
        $userData = [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'testuser@example.com',
            'password' => 'password123',
            'role_id' => $roleId,
            'merchant_ids' => [$this->defaultMerchant->id],
            'status' => 'APPROVED'
        ];

        $userResponse = $this->postJson('/api/v1/users', $userData, $this->headers);
        $userResponse->assertStatus(201);

        // 6. Detach privilege from role
        $detachResponse = $this->deleteJson("/api/v1/roles/{$roleId}/privileges", [
            'privilege_ids' => [$privilegeId]
        ], $this->headers);
        $detachResponse->assertStatus(200);

        $this->assertTrue(true, 'Role and privilege management test passed');
    }

    public function test_user_management_flow()
    {
        // 1. List users
        $listResponse = $this->getJson('/api/v1/users', $this->headers);
        $listResponse->assertStatus(200);

        // 2. Create user
        $userData = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'password' => 'password123',
            'role_id' => $this->defaultUserRole->id,
            'merchant_ids' => [$this->defaultMerchant->id],
            'status' => 'PENDING'
        ];

        $createResponse = $this->postJson('/api/v1/users', $userData, $this->headers);
        $createResponse->assertStatus(201);
        $userId = $createResponse->json('data.id');

        // 3. Update user status
        $updateResponse = $this->putJson("/api/v1/users/{$userId}", [
            'status' => 'APPROVED'
        ], $this->headers);
        $updateResponse->assertStatus(200);

        // 4. Show user
        $showResponse = $this->getJson("/api/v1/users/{$userId}", $this->headers);
        $showResponse->assertStatus(200)
                    ->assertJson([
                        'data' => [
                            'status' => 'APPROVED'
                        ]
                    ]);

        // 5. Filter users by status
        $filterResponse = $this->getJson('/api/v1/users?status=APPROVED', $this->headers);
        $filterResponse->assertStatus(200);

        $this->assertTrue(true, 'User management flow test passed');
    }

    public function test_api_requires_authentication()
    {
        // No auth token should be rejected
        $response = $this->getJson('/api/v1/users');
        $response->assertStatus(401);

        // Legacy API headers must not bypass auth requirement
        $invalidHeaders = [
            'X-App-Key' => 'invalid_key',
            'X-App-Secret' => 'invalid_secret'
        ];
        $response = $this->getJson('/api/v1/users', $invalidHeaders);
        $response->assertStatus(401);
    }

    public function test_privilege_based_access_control()
    {
        // Test that users without proper privileges get 403 responses
        // Create a user with no role (should have no privileges)
        $limitedUser = User::create([
            'first_name' => 'Limited',
            'last_name' => 'User',
            'email' => 'limited@test.com',
            'password' => bcrypt('password123'),
            'role_id' => null, // No role = no privileges
            'status' => 'APPROVED'
        ]);

        $limitedToken = $limitedUser->createToken('Limited Token')->plainTextToken;
        $limitedHeaders = [
            'Authorization' => 'Bearer ' . $limitedToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        // User should NOT be able to create articles (no privileges)
        $createResponse = $this->postJson('/api/v1/articles', [
            'name' => 'Test Article',
            'price' => 29.99
        ], $limitedHeaders);
        $createResponse->assertStatus(403);

        // User should NOT be able to create users (no privileges)
        $createUserResponse = $this->postJson('/api/v1/users', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'password123'
        ], $limitedHeaders);
        $createUserResponse->assertStatus(403);

        // User should NOT be able to view merchants (no privileges)
        $viewMerchantsResponse = $this->getJson('/api/v1/merchants', $limitedHeaders);
        $viewMerchantsResponse->assertStatus(403);

        $this->assertTrue(true, 'Privilege-based access control test passed');
    }

    public function test_all_endpoints_return_proper_json_structure()
    {
        // Test that all endpoints return consistent JSON structure
        $endpoints = [
            '/api/v1/users',
            '/api/v1/roles',
            '/api/v1/privileges',
            '/api/v1/merchants',
            '/api/v1/articles',
            '/api/v1/stocks',
            '/api/v1/orders',
            '/api/v1/carts',
            '/api/v1/payments'
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint, $this->headers);
            
            $response->assertStatus(200)
                    ->assertJsonStructure([
                        'success',
                        'message',
                        'data',
                        'meta',
                        'links'
                    ])
                    ->assertJson([
                        'success' => true
                    ]);
        }

        $this->assertTrue(true, 'All endpoints return proper JSON structure');
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

<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class PaymentTest extends TestCase
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

    public function test_can_list_payments()
    {
        Payment::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/payments', $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Payments retrieved successfully'
                ]);
    }

    public function test_can_create_payment()
    {
        $order = Order::factory()->create();
        
        $paymentData = [
            'order_id' => $order->id,
            'amount' => 299.99,
            'partner_name' => 'Test Gateway',
            'partner_fees' => 8.99,
            'total_amount' => 308.98,
            'status' => 'INIT',
            'partner_reference' => 'TEST-PAY-001'
        ];

        $response = $this->postJson('/api/v1/payments', $paymentData, $this->headers);

        $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'message' => 'Payment created successfully'
                ]);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'amount' => 299.99,
            'partner_reference' => 'TEST-PAY-001'
        ]);
    }

    public function test_can_show_payment()
    {
        $payment = Payment::factory()->create();

        $response = $this->getJson("/api/v1/payments/{$payment->id}", $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Payment retrieved successfully'
                ]);
    }

    public function test_can_update_payment()
    {
        $payment = Payment::factory()->create(['status' => 'INIT']);
        
        $updateData = [
            'status' => 'PAID',
            'partner_reference' => 'UPDATED-REF-001'
        ];

        $response = $this->putJson("/api/v1/payments/{$payment->id}", $updateData, $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Payment updated successfully'
                ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'PAID',
            'partner_reference' => 'UPDATED-REF-001'
        ]);
    }

    public function test_can_delete_payment()
    {
        $payment = Payment::factory()->create();

        $response = $this->deleteJson("/api/v1/payments/{$payment->id}", [], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Payment deleted successfully'
                ]);

        $this->assertSoftDeleted('payments', ['id' => $payment->id]);
    }

    public function test_can_confirm_payment()
    {
        $order = Order::factory()->create(['status' => 'INIT', 'amount' => 100.00]);
        $payment = Payment::factory()->pending()->create([
            'order_id' => $order->id,
            'amount' => 100.00,  // Match order amount exactly
            'total_amount' => 103.00
        ]);

        $response = $this->postJson("/api/v1/payments/{$payment->id}/confirm", [
            'partner_reference' => 'CONFIRMED-123',
            'callback_data' => ['confirmation_code' => 'CONF123']
        ], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Payment confirmed successfully'
                ]);

        // Verify payment status changed
        $payment->refresh();
        $this->assertEquals('PAID', $payment->status);
        
        // Verify order status changed
        $order->refresh();
        $this->assertEquals('PAID', $order->status);
    }

    public function test_cannot_confirm_already_paid_payment()
    {
        $payment = Payment::factory()->paid()->create();

        $response = $this->postJson("/api/v1/payments/{$payment->id}/confirm", [], $this->headers);

        $response->assertStatus(400)
                ->assertJson([
                    'success' => false,
                    'message' => 'Payment is already confirmed'
                ]);
    }

    public function test_payment_creation_requires_valid_data()
    {
        $response = $this->postJson('/api/v1/payments', [
            'order_id' => 999999,  // Invalid: non-existent order
            'amount' => -100,      // Invalid: negative amount
            'total_amount' => -150 // Invalid: negative total
        ], $this->headers);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation Error'
                ])
                ->assertJsonValidationErrors(['order_id', 'amount', 'total_amount']);
    }

    public function test_can_filter_payments_by_status()
    {
        Payment::factory()->count(2)->paid()->create();
        Payment::factory()->count(3)->pending()->create();

        $response = $this->getJson('/api/v1/payments?status=PAID', $this->headers);

        $response->assertStatus(200)
                ->assertJsonCount(2, 'data');
    }

    public function test_payment_not_found_returns_404()
    {
        $response = $this->getJson('/api/v1/payments/999999', $this->headers);

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Payment not found'
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

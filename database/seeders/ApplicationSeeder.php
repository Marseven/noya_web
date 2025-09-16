<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Cart;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Privilege;
use App\Models\Role;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ApplicationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create roles and privileges first
        $this->call(RolesAndPrivilegesSeeder::class);

        // Get roles
        $superAdminRole = Role::where('name', 'Super Admin')->first();
        $adminRole = Role::where('name', 'Admin')->first();
        $managerRole = Role::where('name', 'Manager')->first();
        $userRole = Role::where('name', 'User')->first();

        // Create test users
        $testUser = User::firstOrCreate(
            ['email' => 'user@test.com'],
            [
                'first_name' => 'Test',
                'last_name' => 'User',
                'email' => 'user@test.com',
                'password' => Hash::make('password123'),
                'role_id' => $userRole->id,
                'status' => 'APPROVED',
                'email_verified_at' => now()
            ]
        );

        $testManager = User::firstOrCreate(
            ['email' => 'manager@test.com'],
            [
                'first_name' => 'Test',
                'last_name' => 'Manager',
                'email' => 'manager@test.com',
                'password' => Hash::make('password123'),
                'role_id' => $managerRole->id,
                'status' => 'APPROVED',
                'email_verified_at' => now()
            ]
        );

        // Create test merchants
        $parentMerchant = Merchant::firstOrCreate(
            ['name' => 'Parent Merchant'],
            [
                'name' => 'Parent Merchant',
                'address' => '123 Main Street, City',
                'tel' => '+1234567890',
                'email' => 'parent@merchant.com',
                'status' => 'APPROVED',
                'type' => 'Distributor',
                'lat' => 40.7128,
                'long' => -74.0060
            ]
        );

        $childMerchant = Merchant::firstOrCreate(
            ['name' => 'Child Merchant'],
            [
                'name' => 'Child Merchant',
                'address' => '456 Second Street, City',
                'tel' => '+1234567891',
                'email' => 'child@merchant.com',
                'merchant_parent_id' => $parentMerchant->id,
                'status' => 'APPROVED',
                'type' => 'PointOfSell',
                'lat' => 40.7589,
                'long' => -73.9851
            ]
        );

        // Attach users to merchants
        $parentMerchant->users()->syncWithoutDetaching([$testManager->id]);
        $childMerchant->users()->syncWithoutDetaching([$testUser->id]);

        // Create test articles
        $article1 = Article::firstOrCreate(
            ['name' => 'Test Product 1'],
            [
                'name' => 'Test Product 1',
                'price' => 29.99,
                'photo_url' => 'https://example.com/product1.jpg',
                'merchant_id' => $parentMerchant->id,
                'is_active' => true
            ]
        );

        $article2 = Article::firstOrCreate(
            ['name' => 'Test Product 2'],
            [
                'name' => 'Test Product 2',
                'price' => 49.99,
                'photo_url' => 'https://example.com/product2.jpg',
                'merchant_id' => $parentMerchant->id,
                'is_active' => true
            ]
        );

        $article3 = Article::firstOrCreate(
            ['name' => 'Test Product 3'],
            [
                'name' => 'Test Product 3',
                'price' => 19.99,
                'photo_url' => 'https://example.com/product3.jpg',
                'merchant_id' => $childMerchant->id,
                'is_active' => true
            ]
        );

        // Create test stocks
        $stock1 = Stock::firstOrCreate(
            ['merchant_id' => $parentMerchant->id, 'article_id' => $article1->id],
            [
                'merchant_id' => $parentMerchant->id,
                'article_id' => $article1->id,
                'stock' => 100,
                'last_action_type' => 'MANUALLY_ADD'
            ]
        );

        $stock2 = Stock::firstOrCreate(
            ['merchant_id' => $parentMerchant->id, 'article_id' => $article2->id],
            [
                'merchant_id' => $parentMerchant->id,
                'article_id' => $article2->id,
                'stock' => 50,
                'last_action_type' => 'MANUALLY_ADD'
            ]
        );

        $stock3 = Stock::firstOrCreate(
            ['merchant_id' => $childMerchant->id, 'article_id' => $article3->id],
            [
                'merchant_id' => $childMerchant->id,
                'article_id' => $article3->id,
                'stock' => 75,
                'last_action_type' => 'MANUALLY_ADD'
            ]
        );

        // Create test orders
        $order1 = Order::firstOrCreate(
            ['order_number' => 'ORD-TEST-001'],
            [
                'order_number' => 'ORD-TEST-001',
                'amount' => 0, // Will be calculated
                'merchant_id' => $parentMerchant->id,
                'status' => 'INIT'
            ]
        );

        $order2 = Order::firstOrCreate(
            ['order_number' => 'ORD-TEST-002'],
            [
                'order_number' => 'ORD-TEST-002',
                'amount' => 0, // Will be calculated
                'merchant_id' => $childMerchant->id,
                'status' => 'INIT'
            ]
        );

        // Create test cart items
        $cart1 = Cart::firstOrCreate(
            ['article_id' => $article1->id, 'order_id' => $order1->id],
            [
                'article_id' => $article1->id,
                'quantity' => 2,
                'order_id' => $order1->id
            ]
        );

        $cart2 = Cart::firstOrCreate(
            ['article_id' => $article2->id, 'order_id' => $order1->id],
            [
                'article_id' => $article2->id,
                'quantity' => 1,
                'order_id' => $order1->id
            ]
        );

        $cart3 = Cart::firstOrCreate(
            ['article_id' => $article3->id, 'order_id' => $order2->id],
            [
                'article_id' => $article3->id,
                'quantity' => 3,
                'order_id' => $order2->id
            ]
        );

        // Calculate order amounts
        $order1->calculateAmount();
        $order2->calculateAmount();

        // Create test payments
        $payment1 = Payment::firstOrCreate(
            ['order_id' => $order1->id, 'partner_reference' => 'PAY-TEST-001'],
            [
                'order_id' => $order1->id,
                'amount' => $order1->amount,
                'partner_name' => 'Test Payment Gateway',
                'partner_fees' => $order1->amount * 0.03, // 3% fee
                'total_amount' => $order1->amount + ($order1->amount * 0.03),
                'status' => 'INIT',
                'partner_reference' => 'PAY-TEST-001',
                'callback_data' => [
                    'gateway' => 'test',
                    'transaction_id' => 'TXN-TEST-001'
                ]
            ]
        );

        $payment2 = Payment::firstOrCreate(
            ['order_id' => $order2->id, 'partner_reference' => 'PAY-TEST-002'],
            [
                'order_id' => $order2->id,
                'amount' => $order2->amount,
                'partner_name' => 'Test Payment Gateway',
                'partner_fees' => $order2->amount * 0.03, // 3% fee
                'total_amount' => $order2->amount + ($order2->amount * 0.03),
                'status' => 'PAID',
                'partner_reference' => 'PAY-TEST-002',
                'callback_data' => [
                    'gateway' => 'test',
                    'transaction_id' => 'TXN-TEST-002'
                ]
            ]
        );

        // Mark second payment as paid to update order status
        if ($payment2->status === 'PAID') {
            $order2->status = 'PAID';
            $order2->save();
        }

        $this->command->info('Application test data seeded successfully!');
        $this->command->info('Test Users:');
        $this->command->info('- Super Admin: admin@noyaweb.com / password123');
        $this->command->info('- Test Manager: manager@test.com / password123');
        $this->command->info('- Test User: user@test.com / password123');
        $this->command->info('');
        $this->command->info('Test Data Created:');
        $this->command->info('- 2 Merchants (Parent and Child)');
        $this->command->info('- 3 Articles');
        $this->command->info('- 3 Stock entries');
        $this->command->info('- 2 Orders with cart items');
        $this->command->info('- 2 Payments (1 pending, 1 paid)');
    }
}
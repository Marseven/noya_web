<?php

namespace App\Http\Controllers\API\V1;

/**
 * @OA\Schema(
 *     schema="User",
 *     type="object",
 *     title="User",
 *     description="User model",
 *     @OA\Property(property="id", type="integer", format="int64", example=1),
 *     @OA\Property(property="first_name", type="string", example="John"),
 *     @OA\Property(property="last_name", type="string", example="Doe"),
 *     @OA\Property(property="full_name", type="string", example="John Doe"),
 *     @OA\Property(property="email", type="string", format="email", example="user@example.com"),
 *     @OA\Property(property="status", type="string", enum={"PENDING", "BLOCKED", "APPROVED", "SUSPENDED"}, example="APPROVED"),
 *     @OA\Property(property="email_verified_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="google_2fa_active", type="boolean", example=false),
 *     @OA\Property(property="is_2fa_enabled", type="boolean", example=false),
 *     @OA\Property(property="role", type="object"),
 *     @OA\Property(property="merchants", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */

/**
 * @OA\Schema(
 *     schema="Role",
 *     type="object",
 *     title="Role",
 *     description="Role model",
 *     @OA\Property(property="id", type="integer", format="int64", example=1),
 *     @OA\Property(property="name", type="string", example="Admin"),
 *     @OA\Property(property="description", type="string", example="Administrator role"),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="privileges", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="users_count", type="integer", example=5),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */

/**
 * @OA\Schema(
 *     schema="Privilege",
 *     type="object",
 *     title="Privilege",
 *     description="Privilege model",
 *     @OA\Property(property="id", type="integer", format="int64", example=1),
 *     @OA\Property(property="nom", type="string", example="users.create"),
 *     @OA\Property(property="description", type="string", example="Create users"),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="roles", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */

/**
 * @OA\Schema(
 *     schema="Merchant",
 *     type="object",
 *     title="Merchant",
 *     description="Merchant model",
 *     @OA\Property(property="id", type="integer", format="int64", example=1),
 *     @OA\Property(property="name", type="string", example="ABC Store"),
 *     @OA\Property(property="address", type="string", example="123 Main St"),
 *     @OA\Property(property="entity_file", type="string", nullable=true),
 *     @OA\Property(property="other_document_file", type="string", nullable=true),
 *     @OA\Property(property="tel", type="string", example="+1234567890"),
 *     @OA\Property(property="email", type="string", format="email", example="merchant@example.com"),
 *     @OA\Property(property="status", type="string", enum={"PENDING", "BLOCKED", "APPROVED", "SUSPENDED"}, example="APPROVED"),
 *     @OA\Property(property="type", type="string", enum={"Distributor", "Wholesaler", "Subwholesaler", "PointOfSell"}, example="PointOfSell"),
 *     @OA\Property(property="lat", type="number", format="float", example=40.7128),
 *     @OA\Property(property="long", type="number", format="float", example=-74.0060),
 *     @OA\Property(property="parent", type="object"),
 *     @OA\Property(property="children", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="users", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="articles", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="stocks", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="orders", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */

/**
 * @OA\Schema(
 *     schema="Article",
 *     type="object",
 *     title="Article",
 *     description="Article model",
 *     @OA\Property(property="id", type="integer", format="int64", example=1),
 *     @OA\Property(property="name", type="string", example="Product Name"),
 *     @OA\Property(property="price", type="number", format="float", example=29.99),
 *     @OA\Property(property="photo_url", type="string", nullable=true),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="merchant", type="object"),
 *     @OA\Property(property="stocks", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="stock_histories", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="carts", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */

/**
 * @OA\Schema(
 *     schema="Stock",
 *     type="object",
 *     title="Stock",
 *     description="Stock model",
 *     @OA\Property(property="id", type="integer", format="int64", example=1),
 *     @OA\Property(property="stock", type="integer", example=100),
 *     @OA\Property(property="last_action_type", type="string", enum={"MANUALLY_ADD", "MANUALLY_WITHDRAW", "AUTO_ADD", "AUTO_WITHDRAW"}, example="MANUALLY_ADD"),
 *     @OA\Property(property="merchant", type="object"),
 *     @OA\Property(property="article", type="object"),
 *     @OA\Property(property="histories", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */

/**
 * @OA\Schema(
 *     schema="StockHistory",
 *     type="object",
 *     title="StockHistory",
 *     description="Stock History model",
 *     @OA\Property(property="id", type="integer", format="int64", example=1),
 *     @OA\Property(property="action_type", type="string", enum={"MANUALLY_ADD", "MANUALLY_WITHDRAW", "AUTO_ADD", "AUTO_WITHDRAW"}, example="MANUALLY_ADD"),
 *     @OA\Property(property="last_stock", type="integer", example=50),
 *     @OA\Property(property="new_stock", type="integer", example=100),
 *     @OA\Property(property="difference", type="integer", example=50),
 *     @OA\Property(property="stock", type="object"),
 *     @OA\Property(property="article", type="object"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */

/**
 * @OA\Schema(
 *     schema="Order",
 *     type="object",
 *     title="Order",
 *     description="Order model",
 *     @OA\Property(property="id", type="integer", format="int64", example=1),
 *     @OA\Property(property="order_number", type="string", example="ORD-ABC123"),
 *     @OA\Property(property="amount", type="number", format="float", example=299.99),
 *     @OA\Property(property="status", type="string", enum={"INIT", "PAID", "PARTIALY_PAID", "CANCELLED", "REJECTED", "DELIVERED"}, example="INIT"),
 *     @OA\Property(property="total_paid_amount", type="number", format="float", example=0.00),
 *     @OA\Property(property="is_fully_paid", type="boolean", example=false),
 *     @OA\Property(property="is_partially_paid", type="boolean", example=false),
 *     @OA\Property(property="merchant", type="object"),
 *     @OA\Property(property="carts", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="payments", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */

/**
 * @OA\Schema(
 *     schema="Cart",
 *     type="object",
 *     title="Cart",
 *     description="Cart model",
 *     @OA\Property(property="id", type="integer", format="int64", example=1),
 *     @OA\Property(property="quantity", type="integer", example=2),
 *     @OA\Property(property="total_price", type="number", format="float", example=59.98),
 *     @OA\Property(property="article", type="object"),
 *     @OA\Property(property="order", type="object"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */

/**
 * @OA\Schema(
 *     schema="Payment",
 *     type="object",
 *     title="Payment",
 *     description="Payment model",
 *     @OA\Property(property="id", type="integer", format="int64", example=1),
 *     @OA\Property(property="amount", type="number", format="float", example=299.99),
 *     @OA\Property(property="partner_name", type="string", example="PayPal"),
 *     @OA\Property(property="partner_fees", type="number", format="float", example=8.99),
 *     @OA\Property(property="total_amount", type="number", format="float", example=308.98),
 *     @OA\Property(property="status", type="string", enum={"PAID", "INIT"}, example="INIT"),
 *     @OA\Property(property="partner_reference", type="string", example="PP-123456789"),
 *     @OA\Property(property="callback_data", type="object"),
 *     @OA\Property(property="order", type="object"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */

class Schemas
{
    // This class is just for holding Swagger schema definitions
}

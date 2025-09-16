# NOYA WEB API

A comprehensive Laravel API application with robust authentication, authorization, and business logic for managing users, merchants, articles, stocks, orders, and payments.

## Features

- **Laravel 12** - Latest version with modern features
- **API Authentication** - Laravel Sanctum with Bearer tokens
- **Application Security** - API key and secret validation middleware
- **Role-Based Access Control** - Comprehensive privilege system
- **Google 2FA** - Two-factor authentication support
- **Swagger Documentation** - Complete API documentation with L5-Swagger
- **Soft Deletes** - All models support soft deletion
- **Comprehensive Testing** - Unit and feature tests
- **Database Relationships** - Well-structured relational database design

## Database Schema

The application includes the following main entities:

- **Users** - System users with roles and 2FA support
- **Roles & Privileges** - Fine-grained permission system
- **Merchants** - Business entities with hierarchical structure
- **Articles** - Products/items managed by merchants
- **Stocks** - Inventory management with history tracking
- **Orders** - Order management with multiple statuses
- **Carts** - Shopping cart functionality
- **Payments** - Payment processing with partner integration

## Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd NOYA_WEB
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Environment setup**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Configure environment variables**
   ```env
   # Database
   DB_CONNECTION=sqlite
   # Or use MySQL/PostgreSQL
   
   # API Credentials
   API_KEY=noya_web_api_key_2024
   API_SECRET=noya_web_api_secret_2024_secure
   
   # Sanctum Configuration
   SANCTUM_STATEFUL_DOMAINS=localhost,127.0.0.1
   SANCTUM_GUARD=web
   ```

5. **Run migrations and seeders**
   ```bash
   php artisan migrate
   php artisan db:seed --class=RolesAndPrivilegesSeeder
   ```

6. **Generate Swagger documentation**
   ```bash
   php artisan l5-swagger:generate
   ```

7. **Start the development server**
   ```bash
   php artisan serve
   ```

## API Documentation

Access the interactive API documentation at: `http://localhost:8000/api/documentation`

## Authentication

### API Credentials
All API requests require the following headers:
```
X-App-Key: noya_web_api_key_2024
X-App-Secret: noya_web_api_secret_2024_secure
```

### User Authentication
Protected endpoints require a Bearer token:
```
Authorization: Bearer {token}
```

### Default Admin User
- **Email**: admin@noyaweb.com
- **Password**: password123

## API Endpoints

### Authentication
- `POST /api/v1/auth/login` - User login
- `POST /api/v1/auth/register` - User registration
- `POST /api/v1/auth/logout` - User logout
- `GET /api/v1/auth/profile` - Get user profile
- `PUT /api/v1/auth/profile` - Update user profile

### 2FA Setup
- `POST /api/v1/auth/setup-2fa` - Generate 2FA QR code
- `POST /api/v1/auth/confirm-2fa` - Confirm 2FA setup
- `POST /api/v1/auth/verify-2fa` - Verify 2FA during login

### User Management
- `GET /api/v1/users` - List users
- `POST /api/v1/users` - Create user
- `GET /api/v1/users/{id}` - Get user
- `PUT /api/v1/users/{id}` - Update user
- `DELETE /api/v1/users/{id}` - Delete user

### Role Management
- `GET /api/v1/roles` - List roles
- `POST /api/v1/roles` - Create role
- `GET /api/v1/roles/{id}` - Get role
- `PUT /api/v1/roles/{id}` - Update role
- `DELETE /api/v1/roles/{id}` - Delete role
- `POST /api/v1/roles/{id}/privileges` - Attach privileges
- `DELETE /api/v1/roles/{id}/privileges` - Detach privileges

### Privilege Management
- `GET /api/v1/privileges` - List privileges
- `POST /api/v1/privileges` - Create privilege
- `GET /api/v1/privileges/{id}` - Get privilege
- `PUT /api/v1/privileges/{id}` - Update privilege
- `DELETE /api/v1/privileges/{id}` - Delete privilege

## Privilege System

The application uses a comprehensive privilege-based authorization system:

### User Privileges
- `users.view` - View users
- `users.create` - Create users
- `users.update` - Update users
- `users.delete` - Delete users
- `users.manage_roles` - Manage user roles
- `users.change_status` - Change user status

### Role Privileges
- `roles.view` - View roles
- `roles.create` - Create roles
- `roles.update` - Update roles
- `roles.delete` - Delete roles
- `roles.manage_privileges` - Manage role privileges

### Merchant Privileges
- `merchants.view` - View merchants
- `merchants.create` - Create merchants
- `merchants.update` - Update merchants
- `merchants.delete` - Delete merchants
- `merchants.manage_users` - Manage merchant users
- `merchants.change_status` - Change merchant status

## Testing

Run the test suite:
```bash
php artisan test
```

Run specific test classes:
```bash
php artisan test --filter=AuthTest
php artisan test --filter=UserTest
```

## Architecture

### Controllers
- **BaseController** - Uniform API response formatting
- **API/V1 Controllers** - Versioned API controllers with Swagger annotations
- **Middleware** - API credentials validation and privilege checking

### Models
- **Eloquent Models** - With relationships and business logic
- **Soft Deletes** - All models support soft deletion
- **Scopes** - Query scopes for common filters

### Resources
- **API Resources** - Consistent data transformation
- **Resource Collections** - Paginated responses

### Policies
- **Model Policies** - Authorization logic based on privileges
- **Middleware** - Automatic privilege checking

## Security Features

1. **API Key Authentication** - Application-level security
2. **Bearer Token Authentication** - User-level security with Sanctum
3. **Role-Based Access Control** - Fine-grained permissions
4. **Google 2FA** - Two-factor authentication
5. **Input Validation** - Comprehensive request validation
6. **SQL Injection Protection** - Eloquent ORM protection
7. **CORS Protection** - Cross-origin request security

## Database Design

### Key Features
- **Soft Deletes** - All tables support soft deletion
- **UnsignedBigInteger** - Proper foreign key types
- **Indexes** - Optimized query performance
- **Constraints** - Data integrity enforcement
- **Enums** - Predefined value sets for status fields

### Relationships
- **Users ↔ Roles** - Many-to-one relationship
- **Roles ↔ Privileges** - Many-to-many relationship
- **Users ↔ Merchants** - Many-to-many relationship
- **Merchants** - Self-referencing hierarchy
- **Articles ↔ Merchants** - Many-to-one relationship
- **Stocks** - Composite relationships with history tracking
- **Orders ↔ Carts** - One-to-many relationship
- **Orders ↔ Payments** - One-to-many relationship

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests for new functionality
5. Ensure all tests pass
6. Submit a pull request

## License

This project is proprietary software. All rights reserved.

## Support

For support and questions, please contact the development team.
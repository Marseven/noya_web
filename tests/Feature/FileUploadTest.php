<?php

namespace Tests\Feature;

use App\Helpers\StorageHelper;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadTest extends TestCase
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
            'Accept' => 'application/json'
        ];

        // Set up storage for testing
        Storage::fake('local');
    }

    public function test_can_upload_file_to_temp_storage()
    {
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);

        $response = $this->postJson('/api/v1/files/upload', [
            'file' => $file
        ], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'File uploaded successfully'
                ])
                ->assertJsonStructure([
                    'data' => [
                        'url',
                        'original_name',
                        'filename',
                        'extension',
                        'mime_type',
                        'size',
                        'uploaded_at'
                    ]
                ]);

        $data = $response->json('data');
        $this->assertEquals('test.jpg', $data['original_name']);
        $this->assertEquals('jpg', $data['extension']);
        $this->assertStringStartsWith('temp/', $data['url']);
    }

    public function test_can_upload_file_with_validation_rules()
    {
        $file = UploadedFile::fake()->image('test.png', 50, 50);

        $response = $this->postJson('/api/v1/files/upload', [
            'file' => $file,
            'max_size' => 1024 * 1024, // 1MB
            'allowed_extensions' => ['png', 'jpg']
        ], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'File uploaded successfully'
                ]);
    }

    public function test_file_upload_validation_fails_for_invalid_extension()
    {
        $file = UploadedFile::fake()->create('test.txt', 100);

        $response = $this->postJson('/api/v1/files/upload', [
            'file' => $file,
            'allowed_extensions' => ['jpg', 'png']
        ], $this->headers);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation Error'
                ]);
    }

    public function test_file_upload_validation_fails_for_large_file()
    {
        $file = UploadedFile::fake()->create('large.jpg', 2048); // 2MB

        $response = $this->postJson('/api/v1/files/upload', [
            'file' => $file,
            'max_size' => 1024 * 1024 // 1MB limit
        ], $this->headers);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation Error'
                ]);
    }

    public function test_can_move_file_from_temp_to_permanent()
    {
        // First upload a file
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);
        $uploadResponse = $this->postJson('/api/v1/files/upload', [
            'file' => $file
        ], $this->headers);

        $tempUrl = $uploadResponse->json('data.url');

        // Then move it to permanent location
        $response = $this->postJson('/api/v1/files/move', [
            'temp_url' => $tempUrl,
            'category' => 'articles',
            'entity_id' => 1
        ], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'File moved successfully'
                ])
                ->assertJsonStructure([
                    'data' => [
                        'url',
                        'access_url'
                    ]
                ]);

        $data = $response->json('data');
        $this->assertStringStartsWith('articles/1/', $data['url']);
    }

    public function test_can_get_file_info()
    {
        // Upload and move a file first
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);
        $uploadResponse = $this->postJson('/api/v1/files/upload', [
            'file' => $file
        ], $this->headers);

        $tempUrl = $uploadResponse->json('data.url');

        $moveResponse = $this->postJson('/api/v1/files/move', [
            'temp_url' => $tempUrl,
            'category' => 'articles',
            'entity_id' => 1
        ], $this->headers);

        $fileUrl = $moveResponse->json('data.url');

        // Get file info
        $response = $this->getJson('/api/v1/files/info?url=' . urlencode($fileUrl), $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'File information retrieved'
                ])
                ->assertJsonStructure([
                    'data' => [
                        'exists',
                        'size',
                        'last_modified',
                        'mime_type',
                        'access_url'
                    ]
                ]);

        $this->assertTrue($response->json('data.exists'));
    }

    public function test_can_delete_file()
    {
        // Upload and move a file first
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);
        $uploadResponse = $this->postJson('/api/v1/files/upload', [
            'file' => $file
        ], $this->headers);

        $tempUrl = $uploadResponse->json('data.url');

        $moveResponse = $this->postJson('/api/v1/files/move', [
            'temp_url' => $tempUrl,
            'category' => 'articles',
            'entity_id' => 1
        ], $this->headers);

        $fileUrl = $moveResponse->json('data.url');

        // Delete the file
        $response = $this->deleteJson('/api/v1/files', [
            'url' => $fileUrl
        ], $this->headers);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'File deleted successfully'
                ]);
    }

    public function test_can_serve_file()
    {
        // Upload and move a file first
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);
        $uploadResponse = $this->postJson('/api/v1/files/upload', [
            'file' => $file
        ], $this->headers);

        $tempUrl = $uploadResponse->json('data.url');

        $moveResponse = $this->postJson('/api/v1/files/move', [
            'temp_url' => $tempUrl,
            'category' => 'articles',
            'entity_id' => 1
        ], $this->headers);

        $fileUrl = $moveResponse->json('data.url');

        // Serve the file (no authentication required)
        $response = $this->get('/api/files/' . $fileUrl);

        $response->assertStatus(200);
        $this->assertEquals('image/jpeg', $response->headers->get('Content-Type'));
    }

    public function test_storage_helper_validates_files_correctly()
    {
        $validFile = UploadedFile::fake()->image('test.jpg', 100, 100);
        $errors = StorageHelper::validateFile($validFile);
        $this->assertEmpty($errors);

        $invalidFile = UploadedFile::fake()->create('test.exe', 100);
        $errors = StorageHelper::validateFile($invalidFile);
        $this->assertNotEmpty($errors);
    }

    public function test_storage_helper_generates_unique_paths()
    {
        $path1 = StorageHelper::generatePath('articles', 1, 'test.jpg');
        $path2 = StorageHelper::generatePath('articles', 1, 'test.jpg');
        
        $this->assertNotEquals($path1, $path2);
        $this->assertStringStartsWith('articles/1/', $path1);
        $this->assertStringStartsWith('articles/1/', $path2);
        $this->assertStringEndsWith('.jpg', $path1);
        $this->assertStringEndsWith('.jpg', $path2);
    }

    public function test_file_upload_requires_authentication()
    {
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);

        $headers = [
            'X-App-Key' => config('app.api_key'),
            'X-App-Secret' => config('app.api_secret')
        ];

        $response = $this->postJson('/api/v1/files/upload', [
            'file' => $file
        ], $headers);

        $response->assertStatus(401);
    }

    public function test_file_upload_requires_api_credentials()
    {
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);

        $headers = [
            'Authorization' => 'Bearer ' . $this->token
        ];

        $response = $this->postJson('/api/v1/files/upload', [
            'file' => $file
        ], $headers);

        $response->assertStatus(401);
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
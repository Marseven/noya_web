<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ExportToken extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'token',
        'user_id',
        'export_type',
        'file_path',
        'parameters',
        'expires_at',
        'used',
        'used_at'
    ];

    protected $casts = [
        'parameters' => 'array',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'used' => 'boolean'
    ];

    /**
     * Relationship with User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate a unique token
     */
    public static function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (self::where('token', $token)->exists());

        return $token;
    }

    /**
     * Create a new export token
     */
    public static function createToken(int $userId, string $exportType, string $filePath, array $parameters = [], int $expiresInMinutes = 60): self
    {
        return self::create([
            'token' => self::generateToken(),
            'user_id' => $userId,
            'export_type' => $exportType,
            'file_path' => $filePath,
            'parameters' => $parameters,
            'expires_at' => Carbon::now()->addMinutes($expiresInMinutes)
        ]);
    }

    /**
     * Check if token is valid for use
     */
    public function isValid(): bool
    {
        return !$this->used && 
               !$this->trashed() && 
               $this->expires_at->isFuture();
    }

    /**
     * Mark token as used
     */
    public function markAsUsed(): void
    {
        $this->update([
            'used' => true,
            'used_at' => now()
        ]);
    }

    /**
     * Scope for valid tokens
     */
    public function scopeValid($query)
    {
        return $query->where('used', false)
                    ->where('expires_at', '>', now());
    }

    /**
     * Scope for expired tokens
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Clean up expired tokens
     */
    public static function cleanupExpired(): int
    {
        return self::expired()->delete();
    }
}
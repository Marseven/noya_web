<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_url,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'status' => $this->status,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'google_2fa_active' => $this->google_2fa_active,
            'is_2fa_enabled' => $this->is2FAEnabled(),
            'role' => new RoleResource($this->whenLoaded('role')),
            'merchants' => MerchantResource::collection($this->whenLoaded('merchants')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

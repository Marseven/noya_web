<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantResource extends JsonResource
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
            'name' => $this->name,
            'address' => $this->address,
            'entity_file' => $this->entity_file,
            'other_document_file' => $this->other_document_file,
            'tel' => $this->tel,
            'email' => $this->email,
            'merchant_parent_id' => $this->merchant_parent_id,
            'status' => $this->status,
            'type' => $this->type,
            'lat' => $this->lat,
            'long' => $this->long,
            'parent' => new MerchantResource($this->whenLoaded('parent')),
            'children' => MerchantResource::collection($this->whenLoaded('children')),
            'users' => UserResource::collection($this->whenLoaded('users')),
            'articles' => ArticleResource::collection($this->whenLoaded('articles')),
            'stocks' => StockResource::collection($this->whenLoaded('stocks')),
            'orders' => OrderResource::collection($this->whenLoaded('orders')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

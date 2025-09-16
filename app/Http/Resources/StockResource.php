<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockResource extends JsonResource
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
            'stock' => $this->stock,
            'last_action_type' => $this->last_action_type,
            'merchant' => new MerchantResource($this->whenLoaded('merchant')),
            'article' => new ArticleResource($this->whenLoaded('article')),
            'histories' => StockHistoryResource::collection($this->whenLoaded('histories')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
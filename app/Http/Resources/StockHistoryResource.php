<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockHistoryResource extends JsonResource
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
            'action_type' => $this->action_type,
            'last_stock' => $this->last_stock,
            'new_stock' => $this->new_stock,
            'difference' => $this->new_stock - $this->last_stock,
            'stock' => new StockResource($this->whenLoaded('stock')),
            'article' => new ArticleResource($this->whenLoaded('article')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
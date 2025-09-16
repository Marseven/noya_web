<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleResource extends JsonResource
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
            'price' => $this->price,
            'photo_url' => $this->photo_url,
            'is_active' => $this->is_active,
            'merchant' => new MerchantResource($this->whenLoaded('merchant')),
            'stocks' => StockResource::collection($this->whenLoaded('stocks')),
            'stock_histories' => StockHistoryResource::collection($this->whenLoaded('stockHistories')),
            'carts' => CartResource::collection($this->whenLoaded('carts')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
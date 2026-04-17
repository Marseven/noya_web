<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
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
            'order_number' => $this->order_number,
            'amount' => $this->amount,
            'status' => $this->status,
            'source_merchant_id' => $this->source_merchant_id,
            'destination_merchant_id' => $this->destination_merchant_id,
            'merchant_id' => $this->merchant_id,
            'total_paid_amount' => $this->getTotalPaidAmount(),
            'is_fully_paid' => $this->isFullyPaid(),
            'is_partially_paid' => $this->isPartiallyPaid(),
            'merchant' => new MerchantResource($this->whenLoaded('merchant')),
            'source_merchant' => new MerchantResource($this->whenLoaded('sourceMerchant')),
            'destination_merchant' => new MerchantResource($this->whenLoaded('destinationMerchant')),
            'carts' => CartResource::collection($this->whenLoaded('carts')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'delivery_histories' => DeliveryHistoryResource::collection($this->whenLoaded('deliveryHistories')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

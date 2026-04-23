<?php

namespace App\Http\Controllers\API\V1\Concerns;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait InteractsWithMerchantScope
{
    protected function isSuperAdmin(Request $request): bool
    {
        $roleName = strtolower((string) ($request->user()?->role?->name ?? ''));
        return str_contains($roleName, 'super admin');
    }

    /**
     * Return merchants directly attached to the user.
     * Super admins can access every actor.
     *
     * @return array<int>
     */
    protected function directMerchantIds(Request $request): array
    {
        $cacheKey = 'merchant_scope.direct_ids';
        if ($request->attributes->has($cacheKey)) {
            return $request->attributes->get($cacheKey);
        }

        $user = $request->user();
        if (!$user) {
            $request->attributes->set($cacheKey, []);
            return [];
        }

        if ($this->isSuperAdmin($request)) {
            $all = Merchant::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $request->attributes->set($cacheKey, $all);
            return $all;
        }

        $directIds = $user->merchants()
            ->pluck('merchants.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $request->attributes->set($cacheKey, $directIds);
        return $directIds;
    }

    /**
     * Return all merchants in the user's accessible scope.
     * Non-super-admin users can access their actor plus descendant actors.
     *
     * @return array<int>
     */
    protected function accessibleMerchantIds(Request $request): array
    {
        $cacheKey = 'merchant_scope.accessible_ids';
        if ($request->attributes->has($cacheKey)) {
            return $request->attributes->get($cacheKey);
        }

        $directIds = $this->directMerchantIds($request);
        if (empty($directIds)) {
            $request->attributes->set($cacheKey, []);
            return [];
        }

        if ($this->isSuperAdmin($request)) {
            $request->attributes->set($cacheKey, $directIds);
            return $directIds;
        }

        $expanded = $this->expandWithDescendantMerchants($directIds);
        $request->attributes->set($cacheKey, $expanded);

        return $expanded;
    }

    protected function primaryDirectMerchantId(Request $request): ?int
    {
        $directIds = $this->directMerchantIds($request);
        if (empty($directIds)) {
            return null;
        }

        return (int) $directIds[0];
    }

    protected function applyMerchantScope(Builder $query, Request $request, string $merchantColumn = 'merchant_id'): void
    {
        if ($this->isSuperAdmin($request)) {
            return;
        }

        $merchantIds = $this->accessibleMerchantIds($request);
        if (empty($merchantIds)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn($merchantColumn, $merchantIds);
    }

    protected function hasMerchantScopeAccess(Request $request, ?int $merchantId): bool
    {
        if ($merchantId === null) {
            return true;
        }

        if ($this->isSuperAdmin($request)) {
            return true;
        }

        return in_array($merchantId, $this->accessibleMerchantIds($request), true);
    }

    /**
     * Check whether a merchant is directly attached to the current user scope.
     * For non-super-admin users, these direct actors are considered root scope actors.
     */
    protected function isDirectMerchantScope(Request $request, ?int $merchantId): bool
    {
        if ($merchantId === null) {
            return false;
        }

        if ($this->isSuperAdmin($request)) {
            return false;
        }

        return in_array((int) $merchantId, $this->directMerchantIds($request), true);
    }

    /**
     * Expand root merchant IDs with all descendants.
     *
     * @param array<int> $rootIds
     * @return array<int>
     */
    protected function expandWithDescendantMerchants(array $rootIds): array
    {
        $resolved = collect($rootIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $frontier = $resolved;

        while (!empty($frontier)) {
            $children = Merchant::query()
                ->whereIn('merchant_parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $newIds = array_values(array_diff($children, $resolved));
            if (empty($newIds)) {
                break;
            }

            $resolved = array_values(array_unique(array_merge($resolved, $newIds)));
            $frontier = $newIds;
        }

        return $resolved;
    }
}

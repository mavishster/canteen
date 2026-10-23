<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * Builds the top menu for one person. A page is listed only if that person may open it:
 * the allowed roles are read from the route's own `role:` middleware (the same rule that would answer 403).
 */
class Menu
{
    /** @return array<int, array<string, mixed>> links ['type' => 'link'] and dropdowns ['type' => 'group', 'items' => [...]] */
    public function for(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $entries = [];

        foreach ((array) config('menu.items', []) as $item) {
            $name = $item['route'] ?? null;
            $route = $name ? Route::getRoutes()->getByName($name) : null;

            if (! $route || str_contains($route->uri(), '{') || ! $this->allowed($route, $name, $user)) {
                continue;
            }

            $link = [
                'label' => __($item['label']),
                'url' => route($name),
                'active' => request()->routeIs($name, $name . '.*'),
            ];

            $group = $item['group'] ?? $this->groupOf($name);

            if ($group === null) {
                $entries[] = ['type' => 'link'] + $link;

                continue;
            }

            $entries[$group] ??= ['type' => 'group', 'label' => __($group), 'items' => [], 'active' => false];
            $entries[$group]['items'][] = $link;
            $entries[$group]['active'] = $entries[$group]['active'] || $link['active'];
        }

        // A dropdown with a single link is just that link
        return array_values(array_map(
            fn (array $entry) => $entry['type'] === 'group' && count($entry['items']) === 1 ? ['type' => 'link'] + $entry['items'][0] : $entry,
            $entries
        ));
    }

    private function groupOf(string $name): ?string
    {
        return match (true) {
            str_starts_with($name, 'admin.') => 'Administration',
            str_starts_with($name, 'manager.') => 'Reports',
            default => null,
        };
    }

    private function allowed(LaravelRoute $route, string $name, User $user): bool
    {
        // A super-admin belongs to no school: only the super-admin pages work for them
        if ($user->school_id === null && ! str_starts_with($name, 'super.')) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! preg_match('/^(?:role|.*RoleMiddleware):(.+)$/', $middleware, $m)) {
                continue;
            }

            $roles = explode('|', explode(',', $m[1])[0]);

            if (! $user->hasAnyRole($roles)) {
                return false;
            }
        }

        return true;
    }
}

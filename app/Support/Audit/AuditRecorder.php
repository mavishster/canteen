<?php

namespace App\Support\Audit;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Card;
use App\Models\Category;
use App\Models\GuardianLink;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\MealType;
use App\Models\Product;
use App\Models\SaleRefund;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentBan;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Writes the audit log. Two ways in:
 *  - modelEvent(): called for every create / update / delete of the models listed in registry()
 *  - log(): for things that are not a model change (sign-ins, staff roles, exports)
 * A failure here is reported but never stops the action that is being recorded.
 */
class AuditRecorder
{
    /** @var array<int, School|null> */
    private array $schools = [];

    private static ?array $registry = null;

    // ------------------------------------------------------------- explicit entries

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    public static function log(
        string $action,
        string $entity,
        string $label,
        string $summary,
        array $before = [],
        array $after = [],
        ?int $schoolId = null,
        ?Model $subject = null,
        ?User $actor = null,
        ?string $actorName = null,
    ): void {
        try {
            app(self::class)->record($action, $entity, $label, $summary, $before, $after, $schoolId, $subject, $actor, $actorName);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function record(
        string $action,
        string $entity,
        string $label,
        string $summary,
        array $before = [],
        array $after = [],
        ?int $schoolId = null,
        ?Model $subject = null,
        ?User $actor = null,
        ?string $actorName = null,
    ): void {
        $actor ??= Auth::user();

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $actor?->id,
            'actor_name' => $actorName ?? $actor?->name ?? 'System',
            'actor_role' => $actor ? ($actor->getRoleNames()->first() ?: null) : null,
            'action' => $action,
            'entity' => $entity,
            'subject_id' => $subject?->getKey(),
            'subject_label' => mb_substr($label, 0, 255),
            'summary' => $summary,
            'before' => $before ?: null,
            'after' => $after ?: null,
            'ip' => request()?->ip(),
        ]);
    }

    // ------------------------------------------------------------- model events

    public function modelEvent(string $event, Model $model): void
    {
        $entry = self::registry()[$model::class] ?? null;

        if (! $entry || ! in_array($event, $entry['events'], true)) {
            return;
        }

        // Work done by the system with no person behind it (an import, a sync) is not an admin action
        if (($entry['skip_system'] ?? false) && Auth::user() === null) {
            return;
        }

        [$before, $after] = $this->snapshots($event, $model, $entry);

        if ($event === 'updated' && $after === [] && ! isset($entry['build'])) {
            return;   // nothing worth recording changed (for example only an account balance)
        }

        $schoolId = $model instanceof School ? $model->id : ($model->school_id ?? null);
        $currency = $this->currency($schoolId);

        $items = isset($entry['build'])
            ? $this->{'build' . ucfirst($entry['build'])}($event, $model, $before, $after, $entry, $currency)
            : $this->defaultBuild($event, $model, $before, $after, $entry, $currency);

        foreach ($items as $item) {
            $this->record(
                $item['action'],
                $entry['entity'],
                (string) $entry['label']($model),
                $item['summary'],
                $item['before'],
                $item['after'],
                $schoolId,
                $model,
            );
        }
    }

    /** @return array<class-string, array<string, mixed>> */
    public static function registry(): array
    {
        return self::$registry ??= [
            Product::class => [
                'entity' => 'product',
                'events' => ['created', 'updated', 'deleted'],
                'label' => fn ($m) => $m->name,
                'fields' => ['name', 'price', 'is_active', 'category_id', 'track_stock'],
                'money' => ['price'],
                'resolve' => ['category_id' => fn ($id) => Category::withoutGlobalScopes()->find($id)?->name],
            ],
            Category::class => [
                'entity' => 'category',
                'events' => ['created', 'updated', 'deleted'],
                'label' => fn ($m) => $m->name,
                'fields' => ['name', 'is_active'],
            ],
            MealType::class => [
                'entity' => 'meal',
                'events' => ['created', 'updated', 'deleted'],
                'label' => fn ($m) => $m->name,
                'fields' => ['name', 'is_active', 'service_starts_at', 'service_ends_at', 'available_days'],
            ],
            MealPlan::class => [
                'entity' => 'meal_plan',
                'events' => ['created', 'updated', 'deleted'],
                'label' => fn ($m) => $m->name,
                'fields' => ['name', 'price', 'is_active', 'duration_months', 'min_grade', 'max_grade'],
                'money' => ['price'],
            ],
            School::class => [
                'entity' => 'school',
                'events' => ['updated'],
                'label' => fn ($m) => $m->name,
                'fields' => ['name', 'settings'],
                'build' => 'school',
            ],
            Account::class => [
                'entity' => 'limits',
                'entity_label' => 'spending limits of',
                'events' => ['updated'],
                'label' => fn ($m) => Student::withoutGlobalScopes()->find($m->student_id)?->name ?? 'student',
                'fields' => ['daily_limit', 'weekly_limit', 'low_balance_threshold'],
                'money' => ['daily_limit', 'weekly_limit', 'low_balance_threshold'],
                'action_updated' => 'limits.changed',
            ],
            Student::class => [
                'entity' => 'student',
                'events' => ['created', 'updated', 'deleted'],
                'skip_system' => true,
                'label' => fn ($m) => $m->name,
                'fields' => ['student_code', 'name', 'grade', 'is_active'],
            ],
            Card::class => [
                'entity' => 'card',
                'events' => ['created', 'updated'],
                'skip_system' => true,
                'label' => fn ($m) => Student::withoutGlobalScopes()->find($m->student_id)?->name ?? 'student',
                'fields' => ['status'],
                'build' => 'card',
            ],
            StudentBan::class => [
                'entity' => 'ban',
                'events' => ['created', 'deleted'],
                'label' => fn ($m) => Student::withoutGlobalScopes()->find($m->student_id)?->name ?? 'student',
                'fields' => ['product_id', 'category_id'],
                'build' => 'ban',
            ],
            GuardianLink::class => [
                'entity' => 'parent_link',
                'events' => ['created', 'deleted'],
                'skip_system' => true,
                'label' => fn ($m) => Student::withoutGlobalScopes()->find($m->student_id)?->name ?? 'student',
                'fields' => ['user_id', 'student_id'],
                'build' => 'link',
            ],
            SaleRefund::class => [
                'entity' => 'refund',
                'events' => ['created', 'updated'],
                'label' => fn ($m) => 'Sale #' . $m->sale_id,
                'fields' => ['status', 'reason', 'decision_note'],
                'build' => 'refund',
            ],
            MealSubscription::class => [
                'entity' => 'meal_plan',
                'events' => ['created'],
                'label' => fn ($m) => MealPlan::withoutGlobalScopes()->find($m->meal_plan_id)?->name ?? 'meal plan',
                'fields' => ['valid_from', 'expires_on', 'price'],
                'money' => ['price'],
                'build' => 'subscription',
            ],
            User::class => [
                'entity' => 'user',
                'events' => ['created', 'updated'],
                'label' => fn ($m) => $m->name,
                'fields' => ['name', 'email', 'school_id'],
                'build' => 'user',
            ],
        ];
    }

    // ------------------------------------------------------------------ builders

    /** @return array<int, array{action: string, summary: string, before: array, after: array}> */
    private function defaultBuild(string $event, Model $model, array $before, array $after, array $entry, string $currency): array
    {
        $label = $entry['label']($model);
        $name = $entry['entity_label'] ?? str_replace('_', ' ', $entry['entity']);

        if ($event === 'created') {
            $extras = [];

            foreach ($entry['money'] ?? [] as $field) {
                if (($after[$field] ?? null) !== null) {
                    $extras[] = $this->fieldLabel($field) . ' ' . $this->show($field, $after[$field], $entry, $currency);
                }
            }

            return [[
                'action' => "{$entry['entity']}.created",
                'summary' => ucfirst("created {$name} “{$label}”") . ($extras ? ' (' . implode(', ', $extras) . ')' : ''),
                'before' => [],
                'after' => $after,
            ]];
        }

        if ($event === 'deleted') {
            return [[
                'action' => "{$entry['entity']}.deleted",
                'summary' => ucfirst("deleted {$name} “{$label}”"),
                'before' => $before,
                'after' => [],
            ]];
        }

        if ($after === []) {
            return [];
        }

        $parts = [];
        foreach ($after as $field => $value) {
            $parts[] = $this->fieldLabel($field) . ': ' . $this->show($field, $before[$field] ?? null, $entry, $currency)
                . ' → ' . $this->show($field, $value, $entry, $currency);
        }

        return [[
            'action' => $entry['action_updated'] ?? "{$entry['entity']}.updated",
            'summary' => ucfirst("{$name} “{$label}”: ") . implode('; ', $parts),
            'before' => $before,
            'after' => $after,
        ]];
    }

    private function buildSchool(string $event, Model $model, array $before, array $after, array $entry, string $currency): array
    {
        $parts = [];
        $beforeOut = [];
        $afterOut = [];

        if (array_key_exists('name', $after)) {
            $beforeOut['name'] = $before['name'] ?? null;
            $afterOut['name'] = $after['name'];
            $parts[] = 'name: “' . ($before['name'] ?? '—') . '” → “' . $after['name'] . '”';
        }

        if (array_key_exists('settings', $after)) {
            $old = Arr::dot((array) ($before['settings'] ?? []));
            $new = Arr::dot((array) ($after['settings'] ?? []));

            foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
                if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                    $beforeOut["settings.{$key}"] = $old[$key] ?? null;
                    $afterOut["settings.{$key}"] = $new[$key] ?? null;
                    $parts[] = "settings.{$key}: " . $this->showSetting($key, $old[$key] ?? null, $currency)
                        . ' → ' . $this->showSetting($key, $new[$key] ?? null, $currency);
                }
            }
        }

        if (! $parts) {
            return [];
        }

        return [[
            'action' => 'school.settings_changed',
            'summary' => 'School “' . $model->name . '”: ' . implode('; ', $parts),
            'before' => $beforeOut,
            'after' => $afterOut,
        ]];
    }

    private function buildCard(string $event, Model $model, array $before, array $after, array $entry, string $currency): array
    {
        $student = $entry['label']($model);
        $mask = '…' . substr((string) $model->uid, -4);   // never the full card number

        if ($event === 'created') {
            return [['action' => 'card.bound', 'summary' => "Card {$mask} bound to “{$student}”", 'before' => [], 'after' => $after]];
        }

        if (isset($after['status'])) {
            $action = match ($after['status']) {
                'blocked' => 'card.blocked',
                'active' => 'card.unblocked',
                'retired' => 'card.retired',
                default => 'card.updated',
            };

            return [[
                'action' => $action,
                'summary' => "Card {$mask} of “{$student}”: " . ($before['status'] ?? '—') . ' → ' . $after['status'],
                'before' => $before,
                'after' => $after,
            ]];
        }

        return [];
    }

    private function buildBan(string $event, Model $model, array $before, array $after, array $entry, string $currency): array
    {
        $student = $entry['label']($model);
        $what = $model->category_id
            ? 'category “' . (Category::withoutGlobalScopes()->find($model->category_id)?->name ?? '?') . '”'
            : 'product “' . (Product::withoutGlobalScopes()->find($model->product_id)?->name ?? '?') . '”';

        return $event === 'created'
            ? [['action' => 'ban.added', 'summary' => "Ban added for “{$student}”: {$what}", 'before' => [], 'after' => $after]]
            : [['action' => 'ban.removed', 'summary' => "Ban removed for “{$student}”: {$what}", 'before' => $before, 'after' => []]];
    }

    private function buildLink(string $event, Model $model, array $before, array $after, array $entry, string $currency): array
    {
        $student = $entry['label']($model);
        $parent = User::find($model->user_id)?->name ?? 'parent';

        return $event === 'created'
            ? [['action' => 'parent_link.added', 'summary' => "Parent “{$parent}” linked to “{$student}”", 'before' => [], 'after' => $after]]
            : [['action' => 'parent_link.removed', 'summary' => "Parent “{$parent}” unlinked from “{$student}”", 'before' => $before, 'after' => []]];
    }

    private function buildRefund(string $event, Model $model, array $before, array $after, array $entry, string $currency): array
    {
        if ($event === 'created') {
            return [[
                'action' => 'refund.requested',
                'summary' => "Refund requested for sale #{$model->sale_id}: " . mb_substr((string) $model->reason, 0, 120),
                'before' => [],
                'after' => $after,
            ]];
        }

        if (isset($after['status'])) {
            $note = filled($model->decision_note) ? ' (' . mb_substr((string) $model->decision_note, 0, 120) . ')' : '';

            return [[
                'action' => 'refund.' . $after['status'],
                'summary' => "Refund for sale #{$model->sale_id} {$after['status']}{$note}",
                'before' => ['status' => $before['status'] ?? null],
                'after' => ['status' => $after['status']],
            ]];
        }

        return [];
    }

    private function buildSubscription(string $event, Model $model, array $before, array $after, array $entry, string $currency): array
    {
        $plan = $entry['label']($model);
        $student = Student::withoutGlobalScopes()->find($model->student_id)?->name ?? 'student';
        $from = $model->valid_from?->toDateString();
        $to = $model->expires_on?->toDateString();

        return [[
            'action' => 'meal_plan.sold',
            'summary' => "Meal plan “{$plan}” sold to “{$student}”: {$from} to {$to}, " . Money::format((int) $model->price, $currency),
            'before' => [],
            'after' => ['valid_from' => $from, 'expires_on' => $to, 'price' => (int) $model->price],
        ]];
    }

    private function buildUser(string $event, Model $model, array $before, array $after, array $entry, string $currency): array
    {
        if ($model->sis_user_id) {
            return [];   // a parent created by the SIS sign-in: not an admin action
        }

        $label = $model->name;
        $items = [];

        if ($event === 'created') {
            $items[] = ['action' => 'user.created', 'summary' => "Account created for “{$label}” ({$model->email})", 'before' => [], 'after' => $after];

            return $items;
        }

        // The password itself is never recorded, not even as a hash
        if ($model->wasChanged('password')) {
            $items[] = ['action' => 'user.password_changed', 'summary' => "Password changed for “{$label}”", 'before' => [], 'after' => []];
        }

        if ($after !== []) {
            $parts = [];
            foreach ($after as $field => $value) {
                $parts[] = $this->fieldLabel($field) . ': ' . $this->show($field, $before[$field] ?? null, $entry, $currency) . ' → ' . $this->show($field, $value, $entry, $currency);
            }

            $items[] = ['action' => 'user.updated', 'summary' => "Account “{$label}”: " . implode('; ', $parts), 'before' => $before, 'after' => $after];
        }

        return $items;
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function snapshots(string $event, Model $model, array $entry): array
    {
        $fields = $entry['fields'] ?? [];
        $current = fn (string $f) => $this->plain($model->getAttribute($f));

        if ($event === 'created') {
            return [[], $fields ? array_combine($fields, array_map($current, $fields)) : []];
        }

        if ($event === 'deleted') {
            return [$fields ? array_combine($fields, array_map($current, $fields)) : [], []];
        }

        $changes = $model->getChanges();
        $before = [];
        $after = [];

        foreach ($fields as $f) {
            if (array_key_exists($f, $changes)) {
                $before[$f] = $this->plain($model->getOriginal($f));
                $after[$f] = $current($f);
            }
        }

        return [$before, $after];
    }

    private function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof \BackedEnum => $value->value,
            default => $value,
        };
    }

    private function show(string $field, mixed $value, array $entry, string $currency): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (in_array($field, $entry['money'] ?? [], true)) {
            return Money::format((int) $value, $currency);
        }

        if (isset($entry['resolve'][$field])) {
            return (string) ($entry['resolve'][$field]($value) ?? $value);
        }

        if (is_bool($value) || str_starts_with($field, 'is_') || $field === 'track_stock') {
            return $value ? 'yes' : 'no';
        }

        if (is_array($value)) {
            return mb_substr((string) json_encode($value, JSON_UNESCAPED_UNICODE), 0, 80);
        }

        return (string) $value;
    }

    private function showSetting(string $key, mixed $value, string $currency): string
    {
        if ($value === null) {
            return '—';
        }

        if (in_array($key, ['till.max_sale', 'topup.min', 'notifications.low_balance_default'], true)) {
            return Money::format((int) $value, $currency);
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return is_array($value) ? (string) json_encode($value) : (string) $value;
    }

    private function fieldLabel(string $field): string
    {
        return str_replace('_', ' ', $field);
    }

    private function currency(?int $schoolId): string
    {
        if ($schoolId === null) {
            return 'USD';
        }

        $this->schools[$schoolId] ??= School::find($schoolId);

        return $this->schools[$schoolId]?->currency ?? 'USD';
    }
}

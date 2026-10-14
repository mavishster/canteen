<?php

namespace App\Http\Controllers\ParentApi;

use App\Models\ParentDevice;
use App\Models\ParentMessage;
use App\Models\Student;
use App\Services\Notifications\LowBalance;
use App\Support\Money;
use Illuminate\Http\Request;

class NotificationController extends ParentBaseController
{
    /** The in-app message feed, newest first, with the unread count. */
    public function messages(Request $request)
    {
        $userId = $request->user()->id;

        $page = ParentMessage::where('user_id', $userId)
            ->latest('id')
            ->paginate(20)
            ->through(fn (ParentMessage $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'title' => $m->title,
                'body' => $m->body,
                'data' => $m->data,
                'read' => $m->read_at !== null,
                'created_at' => $m->created_at->toIso8601String(),
            ]);

        $unread = ParentMessage::where('user_id', $userId)->whereNull('read_at')->count();

        return response()->json(['unread' => $unread] + $page->toArray());
    }

    /** Marks the given messages (ids) as read, or all of them when no ids are sent. */
    public function markRead(Request $request)
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        ParentMessage::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function settings(Request $request)
    {
        $user = $request->user();
        $school = $this->school($request);

        $children = Student::with('account')
            ->whereIn('id', $this->linkedIds($request))
            ->orderBy('name')
            ->get()
            ->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'low_balance_threshold' => $s->account->low_balance_threshold,
                'effective_threshold' => LowBalance::thresholdFor($s->account->low_balance_threshold, $school),
            ])
            ->values();

        return response()->json([
            'topup' => (bool) $user->notify_topup,
            'low_balance' => (bool) $user->notify_low_balance,
            'purchase' => (bool) $user->notify_purchase,
            'sms' => (bool) $user->notify_sms,
            'has_phone' => filled($user->phone),   // the number itself comes from the school and is not shown or changed here
            'children' => $children,
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'topup' => ['sometimes', 'boolean'],
            'low_balance' => ['sometimes', 'boolean'],
            'purchase' => ['sometimes', 'boolean'],
            'sms' => ['sometimes', 'boolean'],
        ]);

        $columns = ['topup' => 'notify_topup', 'low_balance' => 'notify_low_balance', 'purchase' => 'notify_purchase', 'sms' => 'notify_sms'];

        $updates = [];
        foreach ($columns as $key => $column) {
            if (array_key_exists($key, $data)) {
                $updates[$column] = (bool) $data[$key];
            }
        }

        $request->user()->forceFill($updates)->save();

        return $this->settings($request);
    }

    /** The balance below which this child's parents are warned. Empty = the school's default, 0 = no warning. */
    public function setLowBalance(Request $request, int $student)
    {
        $child = $this->child($request, $student);
        $school = $this->school($request);

        $data = $request->validate(['threshold' => ['nullable', 'numeric', 'min:0', 'max:100000000']]);

        $threshold = ($data['threshold'] ?? null) === null || $data['threshold'] === ''
            ? null
            : Money::toMinor($data['threshold'], $school->currency);

        $child->account->forceFill(['low_balance_threshold' => $threshold])->save();

        return response()->json([
            'ok' => true,
            'low_balance_threshold' => $threshold,
            'effective_threshold' => LowBalance::thresholdFor($threshold, $school),
        ]);
    }

    /** The app registers (or refreshes) its push token. A token that moves to another parent's login moves with it. */
    public function registerDevice(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:ios,android'],
        ]);

        $device = ParentDevice::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'], 'last_seen_at' => now()]
        );

        return response()->json(['ok' => true, 'id' => $device->id], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function removeDevice(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        ParentDevice::where('user_id', $request->user()->id)->where('token', $data['token'])->delete();

        return response()->json(['ok' => true]);
    }
}

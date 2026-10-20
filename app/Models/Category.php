<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Category extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sell_days' => 'array',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /** True when the category may be bought only at certain times or on certain days. */
    public function hasSellingHours(): bool
    {
        return ($this->sell_from !== null && $this->sell_to !== null) || ! empty($this->sell_days);
    }

    /**
     * What makes two names "the same category": case, spaces, punctuation and a plural s do not count.
     * "Snacks", "snack", " SNACKS " and "Snack-s" all give "snack".
     */
    public static function normalizedName(string $name): string
    {
        $letters = preg_replace('/[^\p{L}\p{N}]+/u', '', Str::lower($name)) ?? '';

        return Str::singular($letters);
    }
}

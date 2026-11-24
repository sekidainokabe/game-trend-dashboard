<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'steam_appid', 'rawg_id', 'rawg_slug', 'name', 'image_url', 'release_date', 'coming_soon', 'is_free',
    'price_jpy', 'discount_percent', 'metacritic', 'rawg_rating', 'recommendations', 'platforms', 'tracked', 'details_fetched_at',
])]
class Game extends Model
{
    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'coming_soon' => 'boolean',
            'is_free' => 'boolean',
            'rawg_rating' => 'float',
            'platforms' => 'array',
            'tracked' => 'boolean',
            'details_fetched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Genre, $this>
     */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class);
    }
}

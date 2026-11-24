<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['key', 'name'])]
class Genre extends Model
{
    /**
     * 共通のキー（config/genres.php）の一覧から、ジャンルの id を返す。まだない行は作る
     *
     * @param  list<string>  $keys
     * @return list<int>
     */
    public static function idsForKeys(array $keys): array
    {
        $names = config('genres.names');

        return collect($keys)
            ->unique()
            ->filter(fn (string $key) => isset($names[$key]))
            ->map(fn (string $key) => static::firstOrCreate(['key' => $key], ['name' => $names[$key]])->id)
            ->values()
            ->all();
    }

    /**
     * @return BelongsToMany<Game, $this>
     */
    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class);
    }
}

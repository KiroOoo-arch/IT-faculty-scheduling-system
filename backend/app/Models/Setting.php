<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * The midday break the solver keeps clear, in the shape the admin API and
     * the AI engine both use.
     *
     * The defaults are the documented policy (12:00-1:00 PM, enabled) so a
     * database that predates this table still behaves correctly.
     */
    public const LUNCH_START_KEY = 'lunch_start';
    public const LUNCH_END_KEY = 'lunch_end';
    public const LUNCH_ENABLED_KEY = 'lunch_enabled';

    public const LUNCH_START_DEFAULT = '12:00';
    public const LUNCH_END_DEFAULT = '13:00';

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * The midday break as the API and the solver expect it.
     *
     * `enabled` is stored as the string "true"/"false" and read back with an
     * explicit comparison, so a value the admin never set still resolves to the
     * documented default of an enabled 12:00-1:00 PM break.
     *
     * @return array{enabled: bool, start: string, end: string}
     */
    public static function lunch(): array
    {
        $start = static::get(static::LUNCH_START_KEY, static::LUNCH_START_DEFAULT);
        $end = static::get(static::LUNCH_END_KEY, static::LUNCH_END_DEFAULT);

        return [
            'enabled' => static::get(static::LUNCH_ENABLED_KEY, 'true') !== 'false',
            'start' => $start ?: static::LUNCH_START_DEFAULT,
            'end' => $end ?: static::LUNCH_END_DEFAULT,
        ];
    }
}
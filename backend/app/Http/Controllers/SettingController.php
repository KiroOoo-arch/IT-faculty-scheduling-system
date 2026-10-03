<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * The settings the Department Head owns.
     * GET /api/settings
     */
    public function index()
    {
        return response()->json([
            'lunch' => Setting::lunch(),
        ]);
    }

    /**
     * Update the midday break. Admin only (route middleware).
     * PUT /api/settings
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            // `date_format:H:i` is what the section window uses, so a half-hour
            // boundary like 12:30 is accepted here for the same reason.
            'lunch_start' => 'sometimes|date_format:H:i',
            'lunch_end' => 'sometimes|date_format:H:i',
            'lunch_enabled' => 'sometimes|boolean',
        ]);

        $current = Setting::lunch();

        $start = $validated['lunch_start'] ?? $current['start'];
        $end = $validated['lunch_end'] ?? $current['end'];

        // An inverted or empty break would be a silent no-op in the solver, so
        // reject it here where the admin can see the reason.
        if ($end <= $start) {
            return response()->json([
                'message' => 'The break must end after it starts.',
                'errors' => ['lunch_end' => [
                    "The break would run {$start} to {$end}.",
                ]],
            ], 422);
        }

        Setting::put(Setting::LUNCH_START_KEY, $start);
        Setting::put(Setting::LUNCH_END_KEY, $end);
        Setting::put(
            Setting::LUNCH_ENABLED_KEY,
            ($validated['lunch_enabled'] ?? $current['enabled']) ? 'true' : 'false'
        );

        return response()->json([
            'message' => 'Settings updated.',
            'lunch' => Setting::lunch(),
        ]);
    }
}
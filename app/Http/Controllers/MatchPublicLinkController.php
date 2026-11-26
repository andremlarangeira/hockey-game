<?php

namespace App\Http\Controllers;

use App\Models\HockeyMatch;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MatchPublicLinkController extends Controller
{
    public function store(HockeyMatch $match): RedirectResponse
    {
        DB::transaction(function () use ($match): void {
            if ($match->public_token === null)
            {
                $match->public_token = Str::random(40);
            }

            $match->public_enabled_at ??= CarbonImmutable::now();
            $match->save();
        });

        return back()->with('success', 'Link público ativado.');
    }

    public function regenerate(HockeyMatch $match): RedirectResponse
    {
        DB::transaction(function () use ($match): void {
            $match->public_token = Str::random(40);
            $match->public_enabled_at = CarbonImmutable::now();
            $match->save();
        });

        return back()->with('success', 'Link público regenerado.');
    }

    public function destroy(HockeyMatch $match): RedirectResponse
    {
        DB::transaction(function () use ($match): void {
            $match->public_token = null;
            $match->public_enabled_at = null;
            $match->save();
        });

        return back()->with('success', 'Link público desativado.');
    }
}

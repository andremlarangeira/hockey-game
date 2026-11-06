<?php

namespace App\Http\Middleware;

use App\Support\TeamColors;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $team = $request->user();
        $primary = TeamColors::adjustForLightText($team === null ? '#166534' : ($team->primary_color ?? '#166534'));

        return [
            ...parent::share($request),
            'name' => 'Na Quadra',
            'auth' => ['user' => $team],
            'flash' => ['success' => $request->session()->get('success')],
            'team' => $team ? [
                'id' => $team->id,
                'name' => $team->name, 'logo_url' => $team->logo_url,
                'primary_color' => $primary, 'secondary_color' => $team->secondary_color ?? '#84cc16',
                'primary_text' => '#ffffff',
            ] : null,
        ];
    }
}

<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/Security');
    }

    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update(['password' => $request->string('password')->toString()]);

        return back()->with('success', 'Senha atualizada.');
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Team', ['team' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$request->user()->id],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:4096'],
        ]);
        $user = $request->user();
        if ($request->hasFile('logo'))
        {
            if ($user->logo_path !== null && $user->logo_path !== '')
            {
                Storage::disk(config('filesystems.media_disk'))->delete($user->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('logos/'.$user->id, config('filesystems.media_disk'));
        }
        unset($data['logo']);
        $user->update($data);

        return back()->with('success', 'Time atualizado. As cores já estão valendo.');
    }
}

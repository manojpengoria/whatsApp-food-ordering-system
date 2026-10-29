<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(): View
    {
        return view('admin.staff.index', [
            'users' => User::query()->orderByDesc('is_active')->orderBy('role')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_STAFF])],
            'password' => ['required', Password::min(8)],
        ]);
        User::query()->create($data + ['is_active' => true]);

        return back()->with('status', "{$data['name']} can now log in with {$data['email']}.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_STAFF])],
            'password' => ['nullable', Password::min(8)],
        ]);
        $active = $request->boolean('is_active');
        $me = $request->user();

        if ($user->is($me) && ($data['role'] !== User::ROLE_ADMIN || ! $active)) {
            return back()->withErrors(['staff' => 'You cannot remove your own admin access or deactivate yourself.']);
        }
        $losingAdmin = $user->isAdmin() && $user->is_active && ($data['role'] !== User::ROLE_ADMIN || ! $active);
        if ($losingAdmin && User::query()->where('role', User::ROLE_ADMIN)->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['staff' => 'At least one active administrator is needed.']);
        }

        $updates = ['name' => $data['name'], 'role' => $data['role'], 'is_active' => $active];
        if (! empty($data['password'])) {
            $updates['password'] = $data['password'];
        }
        $user->update($updates);

        return back()->with('status', "{$user->name} saved.".(! empty($data['password']) ? ' New password set.' : ''));
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDemoUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            $user = User::query()->oldest('id')->first();

            abort_if($user === null, Response::HTTP_INTERNAL_SERVER_ERROR, 'No demo user is available.');

            Auth::onceUsingId($user->getKey());
        }

        return $next($request);
    }
}

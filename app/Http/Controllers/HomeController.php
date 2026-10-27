<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Send the user to the first area their account can open.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $homeRoute = $request->user()->homeRoute();

        abort_unless($homeRoute, 403, 'Your account has not been given access to any part of the app yet.');

        return redirect()->route($homeRoute);
    }
}

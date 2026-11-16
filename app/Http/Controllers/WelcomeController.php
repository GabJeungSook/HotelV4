<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Queries\WelcomeQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The public welcome page (59), for everyone, signed in or not (APP_FLOW §2). */
final class WelcomeController extends Controller
{
    public function index(Request $request, WelcomeQuery $welcome): Response
    {
        $remembered = $request->cookie(AuthController::BRANCH_COOKIE);

        return Inertia::render('Welcome', $welcome->page(
            $request->integer('branch') ?: null,
            is_numeric($remembered) ? (int) $remembered : null,
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/** The public welcome page (59): a scrollable HOMI product landing, for everyone, signed in or not. */
final class WelcomeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Welcome');
    }
}

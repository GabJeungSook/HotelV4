<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Plain pages for role homes built in later phases (ROADMAP Phase 4): the branch dashboard
 * (61) and owner dashboard (60) in Phase 9, and the kiosk (27) in Phase 7. They carry no figures.
 */
final class PlaceholderController extends Controller
{
    public function dashboard(): Response
    {
        return $this->page('backoffice', 'dashboard', 'Dashboard', 'The branch dashboard (61) is built in Phase 9.');
    }

    public function owner(): Response
    {
        return $this->page('owner', 'owner', 'Dashboard', 'The owner dashboard (60) is built in Phase 9.');
    }

    public function kiosk(): Response
    {
        return $this->page('kiosk', 'kiosk', 'Kiosk', 'The lobby kiosk (27) is built in Phase 7.');
    }

    private function page(string $layout, string $current, string $title, string $text): Response
    {
        return Inertia::render('Placeholder', ['layout' => $layout, 'current' => $current, 'title' => $title, 'text' => $text]);
    }
}

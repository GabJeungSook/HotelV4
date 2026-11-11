<?php

declare(strict_types=1);

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Testing\TestResponse;

it('renders a one-line Blade view to a PDF response', function (): void {
    $response = TestResponse::fromBaseResponse(
        Pdf::loadView('pdf.check', ['name' => config('hotel.name')])->download('check.pdf')
    );

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload('check.pdf');

    expect(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-');
});

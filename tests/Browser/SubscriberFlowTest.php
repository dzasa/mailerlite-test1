<?php

use App\Models\Subscriber;

/*
|--------------------------------------------------------------------------
| Browser tests
|--------------------------------------------------------------------------
|
| These exist because HTTP-level tests cannot see the two failures that
| actually broke this feature in a browser: assets blocked by CORS, and a
| layout that threw on a null user. Both returned HTTP 200 with a blank page.
| Every test here asserts on JavaScript errors, not just status codes.
|
*/

it('renders the subscriber list with no javascript errors', function (): void {
    Subscriber::factory()->create(['email' => 'visible@example.com']);

    visit('/')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertSee('Subscribers')
        ->assertSee('visible@example.com');
});

it('renders the creation form with no javascript errors', function (): void {
    visit('/subscribers/create')
        ->assertNoJavaScriptErrors()
        ->assertSee('Email address')
        ->assertSee('Status');
});

it('renders public pages without the authenticated app shell', function (): void {
    // The regression this guards: subscribers/* resolved to AppLayout, whose
    // sidebar renders auth.user and threw "$props.user is null" on a public
    // page. assertNoJavaScriptErrors alone does NOT catch it — Inertia resolves
    // pages asynchronously, so the failure surfaces as an unhandled promise
    // rejection, which Playwright's pageerror event does not report. Asserting
    // the sidebar is absent is what actually detects the wrong layout.
    foreach (['/', '/subscribers/create'] as $url) {
        visit($url)
            ->assertNoJavaScriptErrors()
            ->assertDontSee('Dashboard')
            ->assertDontSee('Platform');
    }
});

it('links from the list to the creation form', function (): void {
    visit('/')
        ->assertNoJavaScriptErrors()
        ->click('Add subscriber')
        ->assertUrlIs(url('/subscribers/create'))
        ->assertNoJavaScriptErrors();
});

it('creates a subscriber through the form', function (): void {
    $page = visit('/subscribers/create');

    $page->assertNoJavaScriptErrors()
        ->type('email', 'browser@example.com')
        ->select('status', 'subscribed')
        ->press('Create subscriber')
        ->assertSee('processing your subscription')
        ->assertNoJavaScriptErrors();

    // The write is queued, so the row lands via the worker rather than the
    // request. QUEUE_CONNECTION=sync under test means it is already committed.
    expect(Subscriber::query()->where('email', 'browser@example.com')->exists())->toBeTrue();
});

it('shows a duplicate error when submitting the same email twice', function (): void {
    $page = visit('/subscribers/create');

    $page->type('email', 'duplicate@example.com')
        ->press('Create subscriber')
        ->assertSee('processing your subscription');

    $page->type('email', 'duplicate@example.com')
        ->press('Create subscriber')
        ->assertSee('already been taken')
        ->assertNoJavaScriptErrors();

    expect(Subscriber::query()->where('email', 'duplicate@example.com')->count())->toBe(1);
});

it('shows the new subscriber in the list after creating it', function (): void {
    visit('/subscribers/create')
        ->type('email', 'roundtrip@example.com')
        ->press('Create subscriber')
        ->assertSee('processing your subscription');

    visit('/')
        ->assertNoJavaScriptErrors()
        ->assertSee('roundtrip@example.com');
});

it('rejects an invalid email without a javascript error', function (): void {
    visit('/subscribers/create')
        ->type('email', 'not-an-email')
        ->press('Create subscriber')
        ->assertNoJavaScriptErrors();

    expect(Subscriber::query()->count())->toBe(0);
});

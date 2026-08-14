<?php

use Inertia\Testing\AssertableInertia;

it('renders the creation form without authentication', function (): void {
    $this->get('/subscribers/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('subscribers/Create'));
});

it('is reachable by name', function (): void {
    expect(route('subscribers.create'))->toEndWith('/subscribers/create');
});

<?php

use App\Models\Subscriber;
use Inertia\Testing\AssertableInertia;

it('lists subscribers on the home page without authentication', function (): void {
    Subscriber::factory()->create(['email' => 'listed@example.com']);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('subscribers/Index')
            ->has('subscribers.data', 1)
            ->where('subscribers.data.0.email', 'listed@example.com'));
});

it('renders an empty list when there are no subscribers', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('subscribers.data', 0));
});

it('shows the newest subscribers first', function (): void {
    $older = Subscriber::factory()->create(['email' => 'older@example.com']);
    $newer = Subscriber::factory()->create(['email' => 'newer@example.com']);

    expect($newer->id)->toBeGreaterThan($older->id);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('subscribers.data.0.email', 'newer@example.com')
            ->where('subscribers.data.1.email', 'older@example.com'));
});

it('paginates without counting the whole table', function (): void {
    Subscriber::factory()->count(25)->create();

    // simplePaginate exposes next_page_url but deliberately no `total`, because
    // computing it would mean a COUNT(*) over the entire table on every request.
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('subscribers.data', 20)
            ->has('subscribers.next_page_url')
            ->missing('subscribers.total'));
});

it('serves the second page', function (): void {
    Subscriber::factory()->count(25)->create();

    $this->get('/?page=2')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('subscribers.data', 5));
});

it('exposes only the columns the listing needs', function (): void {
    Subscriber::factory()->create();

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('subscribers.data.0', fn (AssertableInertia $row) => $row
                ->hasAll(['id', 'email', 'status', 'created_at'])
                ->etc()));
});

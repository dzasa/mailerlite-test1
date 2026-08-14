<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Browser');

/*
| Browser tests run Chromium inside the app container, where the Vite dev
| server (a separate container) is unreachable. Point the Vite helper at a
| hot file that cannot exist so it falls back to the built manifest in
| public/build — which is also what makes these runs deterministic, with no
| HMR client chatter in the console.
*/
pest()->beforeEach(function (): void {
    // Chromium lives in a Docker volume. If that volume is empty the plugin
    // reports "Playwright was just installed or updated" as an assertion
    // failure on every test, which reads like a broken app rather than a
    // missing dependency. Skip with an actionable message instead.
    $browsers = getenv('PLAYWRIGHT_BROWSERS_PATH') ?: null;

    if ($browsers !== null && glob($browsers.'/chromium-*') === []) {
        $this->markTestSkipped('Chromium is not installed. Run ./start.sh --browser');
    }

    // These tests render from the built manifest rather than the dev server,
    // so a missing build is a missing dependency, not a failing app.
    if (! file_exists(public_path('build/manifest.json'))) {
        $this->markTestSkipped('Assets are not built. Run: docker compose exec vite npm run build');
    }

    Vite::useHotFile('vite-hot-file-disabled-during-tests');
})->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

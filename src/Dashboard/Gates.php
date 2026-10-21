<?php

namespace Folklore\Mediatheque\Dashboard;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * Who can open the dashboard, and act on runs from it (docs/decisions/0017).
 *
 * The application defines `viewMediathequeDashboard` and
 * `operateMediathequeDashboard`. Until it defines one, that one is open in
 * the `local` environment, and closed everywhere else.
 */
final class Gates
{
    public const string VIEW = 'viewMediathequeDashboard';

    public const string OPERATE = 'operateMediathequeDashboard';

    public function __construct(
        private readonly Gate $gate,
        private readonly Application $app,
    ) {}

    public function allows(Request $request, string $ability): bool
    {
        if (! $this->gate->has($ability)) {
            return $this->app->environment('local');
        }

        return $this->gate->forUser($request->user())->allows($ability);
    }
}

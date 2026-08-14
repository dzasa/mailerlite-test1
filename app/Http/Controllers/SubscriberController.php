<?php

namespace App\Http\Controllers;

use App\Models\Subscriber;
use Inertia\Inertia;
use Inertia\Response;

class SubscriberController extends Controller
{
    /**
     * List subscribers, newest first.
     *
     * Uses simplePaginate rather than paginate: the latter issues a
     * COUNT(*) over the whole table for every page view, which is the one
     * query guaranteed to get slower as the table grows. Ordering is by the
     * primary key rather than created_at because only the former is indexed.
     */
    public function index(): Response
    {
        return Inertia::render('subscribers/Index', [
            'subscribers' => Subscriber::query()
                ->select(['id', 'email', 'status', 'created_at'])
                ->orderByDesc('id')
                ->simplePaginate(20),
        ]);
    }
}

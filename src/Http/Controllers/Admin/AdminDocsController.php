<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Admin;

use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Support\AdminDocs;
use Illuminate\Contracts\View\View;

/**
 * Persian documentation hub for the admin panel (§FR-16).
 * Admin-gated like the rest of the panel; read-only.
 */
final class AdminDocsController extends Controller
{
    /** Documentation hub — the full Persian guide index. */
    public function index(): View
    {
        return view('cardpay::admin.docs', [
            'sections' => AdminDocs::all(),
        ]);
    }

    /** One section's guide; unknown keys fall through to a 404 page. */
    public function show(string $section): View
    {
        abort_unless(AdminDocs::get($section) !== null, 404);

        return view('cardpay::admin.doc-guide', [
            'section' => $section,
            'doc' => AdminDocs::get($section),
            'others' => AdminDocs::all(),
        ]);
    }
}

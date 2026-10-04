<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Landing\HeroService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /hero` - the landing carousel, read by whoever is on `/`.
 *
 * ## Why this route carries neither `permission:` nor `tipe:`
 *
 * It is the same shape as `GET /master-spesialisasi` and `GET /dokter`: content a
 * signed-out visitor needs, so the audience is "everyone who can reach the page".
 * A `permission:` there would have to be invented (`hero.kelola` is the *write*
 * grant, and granting the public a write code would be granting them nothing), and
 * `tipe:` would put the front page behind a login. The two gates belong on the admin
 * routes in `routes/api.php`, which is where this module's `hero.kelola` lives.
 *
 * ## What it answers, and what it deliberately does not
 *
 * Only slides that are switched on AND inside their publication window, in strip
 * order. Drafts, expired campaigns and the ordering arithmetic are the operator's
 * view and are served by `AdminHeroController` behind `hero.kelola`.
 *
 * An empty list is a normal answer, not an error: a fresh install has no rows, and
 * the web client falls back to its built-in slides rather than rendering an empty
 * strip. That is why this endpoint reports no `meta` - there is no page to turn.
 */
class HeroController extends Controller
{
    public function __construct(private readonly HeroService $hero) {}

    /**
     * `GET /api/v1/hero`
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            ['hero' => $this->hero->daftarTayang()],
            'Sorotan layanan berhasil dimuat.',
            Response::HTTP_OK,
        );
    }
}

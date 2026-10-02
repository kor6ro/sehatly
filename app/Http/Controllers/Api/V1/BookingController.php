<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Http\Requests\Booking\IndexBookingRequest;
use App\Http\Requests\Booking\IndexDokterBookingRequest;
use App\Http\Requests\Booking\RescheduleBookingRequest;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\SlotTakenException;
use App\Services\Pasien\PasienRecordAccess;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $booking,
        private readonly PasienRecordAccess $access,
    ) {}

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $pasien = $this->access->ownPasien($user);

        try {
            $booking = $this->booking->create($pasien, $user, $request->validated());
        } catch (SlotTakenException $e) {
            return ApiResponse::error($e->getMessage(), $e->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return ApiResponse::success(
            ['booking' => new BookingResource($booking)],
            'Booking berhasil dibuat.',
            Response::HTTP_CREATED,
        );
    }

    public function indexPasien(IndexBookingRequest $request): JsonResponse
    {
        $pasien = $this->access->ownPasien($this->user($request));

        $rows = $this->booking->listPasien($pasien, $request->validated())->withQueryString();

        return ApiResponse::success(
            ['booking' => BookingResource::collection($rows->getCollection())],
            'Daftar booking berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($rows),
        );
    }

    public function indexDokter(IndexDokterBookingRequest $request): JsonResponse
    {
        $dokter = $this->access->ownDokterOrFail($this->user($request));

        $rows = $this->booking->listDokter($dokter, $request->validated())->withQueryString();

        return ApiResponse::success(
            ['booking' => BookingResource::collection($rows->getCollection())],
            'Daftar booking dokter berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($rows),
        );
    }

    public function batalkan(CancelBookingRequest $request, int $id): JsonResponse
    {
        $booking = $this->booking->batalkan(
            $this->user($request),
            $id,
            $request->validated()['alasan_pembatalan'] ?? null,
        );

        return ApiResponse::success(
            ['booking' => new BookingResource($booking)],
            'Booking berhasil dibatalkan.',
        );
    }

    /**
     * `GET /api/v1/booking/{id}/kebijakan` - the uniform cancellation policy.
     *
     * A GET with no query inputs, so it carries no `FormRequest` and follows
     * `GET /api/v1/invoice/{id}`: the input is the path parameter, the route
     * gate is `permission:booking.lihat`, and the service's ownership split
     * answers 404/403. The response is server-computed because the owner's
     * decision (gratis, refund penuh, hybrid execution) is policy and the
     * client must not re-derive it; see `RefundService::kebijakan()`.
     */
    public function kebijakan(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            ['kebijakan' => $this->booking->kebijakan($this->user($request), $id)],
            'Kebijakan pembatalan berhasil dimuat.',
        );
    }

    /**
     * `PUT /api/v1/booking/{id}/jadwal-ulang` - move the same row to a new
     * published slot of the same doctor.
     *
     * The slot refusals are `SlotTakenException`, and they are caught here for
     * the same reason `store()` catches them: the exception is a domain answer
     * (422 with `errors.slot`) and `bootstrap/app.php` would otherwise render
     * it as a sanitised 500. A status refusal is a `ValidationException` and
     * travels through the kernel's 422 untouched.
     */
    public function jadwalUlang(RescheduleBookingRequest $request, int $id): JsonResponse
    {
        try {
            $booking = $this->booking->jadwalUlang($this->user($request), $id, $request->validated());
        } catch (SlotTakenException $e) {
            return ApiResponse::error($e->getMessage(), $e->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return ApiResponse::success(
            ['booking' => new BookingResource($booking)],
            'Jadwal booking berhasil dipindahkan.',
        );
    }

    /**
     * The authenticated `User`, narrowed for the static analyser.
     *
     * Every action here runs behind `auth:sanctum`, so `user()` is never null; the cast is
     * the framework's documented way to say so rather than an unchecked null.
     */
    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}

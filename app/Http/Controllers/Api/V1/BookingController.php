<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Http\Requests\Booking\IndexBookingRequest;
use App\Http\Requests\Booking\IndexDokterBookingRequest;
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

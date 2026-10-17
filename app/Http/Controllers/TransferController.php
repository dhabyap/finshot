<?php

namespace App\Http\Controllers;

use App\Enums\TransferStatus;
use App\Exceptions\TransferApiException;
use App\Http\Requests\StoreTransferRequest;
use App\Http\Resources\TransferResource;
use App\Services\CreateTransfer;
use App\Services\ReceivePartnerCallback;
use App\Services\StartTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TransferController extends Controller
{
    public function store(StoreTransferRequest $request, CreateTransfer $createTransfer): JsonResponse
    {
        try {
            $transfer = $createTransfer->handle($request->validated(), $request->idempotencyKey());
        } catch (\InvalidArgumentException $exception) {
            return $this->error('VALIDATION_ERROR', $exception->getMessage(), 400);
        } catch (ConflictHttpException $exception) {
            return $this->error('CONFLICT', $exception->getMessage(), 409);
        }

        return response()->json(TransferResource::make($transfer), 201);
    }

    public function send(string $transferId, StartTransfer $startTransfer): JsonResponse
    {
        try {
            $transfer = $startTransfer->handle($transferId);
        } catch (TransferApiException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }

        return response()->json(TransferResource::make($transfer));
    }

    public function callback(Request $request, ReceivePartnerCallback $receiveCallback): JsonResponse
    {
        $validator = validator($request->all(), [
            'partnerRef' => ['required', 'string', 'max:100'],
            'eventId' => ['required', 'string', 'max:100'],
            'status' => ['required', 'in:COMPLETED'],
        ]);
        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', $validator->errors()->first(), 400);
        }

        try {
            $transfer = $receiveCallback->handle(
                $validator->validated()['partnerRef'],
                $validator->validated()['eventId'],
            );
        } catch (TransferApiException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }

        return response()->json(TransferResource::make($transfer));
    }

    public function show(string $transferId): JsonResponse
    {
        $transfer = $this->findTransfer($transferId);

        return $transfer
            ? response()->json(TransferResource::make($transfer))
            : $this->error('NOT_FOUND', 'Transfer not found', 404);
    }

    public function cancel(string $transferId): JsonResponse
    {
        $changed = DB::table('transfers')
            ->where('transfer_id', $transferId)
            ->where('status', TransferStatus::Requested->value)
            ->update(['status' => TransferStatus::Cancelled->value, 'updated_at' => now()]);

        if (!$changed) {
            $exists = DB::table('transfers')->where('transfer_id', $transferId)->exists();

            return $exists
                ? $this->error('CONFLICT', 'Only REQUESTED transfers can be cancelled', 409)
                : $this->error('NOT_FOUND', 'Transfer not found', 404);
        }

        return response()->json(TransferResource::make($this->findTransfer($transferId)));
    }

    private function findTransfer(string $transferId): ?object
    {
        return DB::table('transfers')->where('transfer_id', $transferId)->first();
    }

    private function error(string $error, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}

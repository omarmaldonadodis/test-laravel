<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EnrollmentLogResource;
use App\Http\Resources\EnrollmentLogCollection;
use App\Models\EnrollmentLog;
use Illuminate\Http\Request;

class EnrollmentLogController extends Controller
{
    /**
     * Lista paginada de enrollment logs
     */
    public function index(Request $request)
    {
        $logs = EnrollmentLog::query()
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->email, fn($q, $email) => $q->where('customer_email', 'like', "%{$email}%"))
            ->latest()
            ->paginate($request->per_page ?? 15);

        return new EnrollmentLogCollection($logs);
    }

    /**
     * Muestra un enrollment log específico
     */
    public function show(string $id)
    {
        $log = EnrollmentLog::findOrFail($id);
        return new EnrollmentLogResource($log);
    }

    /**
     * Busca por order_id de Medusa
     */
    public function showByOrderId(string $orderId)
    {
        $log = EnrollmentLog::where('medusa_order_id', $orderId)->firstOrFail();
        return new EnrollmentLogResource($log);
    }
}
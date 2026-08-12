<?php

namespace App\Exceptions;

use App\Models\Device;
use RuntimeException;

/**
 * Thrown by DeviceService::registerOrCheckLoginDevice() when the device
 * attempting to log in has never been approved yet (either freshly
 * registered as pending by this very attempt, or already pending from an
 * earlier attempt). No Sanctum token is issued -- Api\AuthController maps
 * this to HTTP 403 with error_code "device_pending" so the mobile client
 * shows a dedicated "menunggu persetujuan admin" message at the login
 * screen itself, distinct from a wrong-password error.
 */
class DevicePendingException extends RuntimeException
{
    public function __construct(public readonly Device $device)
    {
        parent::__construct("Perangkat [{$device->device_id}] menunggu persetujuan admin.");
    }
}

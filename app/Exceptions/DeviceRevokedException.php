<?php

namespace App\Exceptions;

use App\Models\Device;
use RuntimeException;

/**
 * Thrown by DeviceService::registerOrCheckLoginDevice() when the device
 * attempting to log in has been revoked by an admin. No Sanctum token is
 * issued -- Api\AuthController maps this to HTTP 403 with error_code
 * "device_revoked". Also the response shape returned by
 * Api\DeviceController::status() for an already-logged-in device that gets
 * revoked later (checked via GET /api/v1/device/status).
 */
class DeviceRevokedException extends RuntimeException
{
    public function __construct(public readonly Device $device)
    {
        parent::__construct("Perangkat [{$device->device_id}] telah dicabut aksesnya.");
    }
}

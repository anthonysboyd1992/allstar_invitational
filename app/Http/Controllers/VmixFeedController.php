<?php

namespace App\Http\Controllers;

use App\Models\VmixField;
use App\Services\ImageCropper;
use App\Services\VmixFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VmixFeedController extends Controller
{
    public function lineup(VmixFeed $feed): JsonResponse
    {
        return $this->respond($feed->lineup());
    }

    public function driver(VmixFeed $feed): JsonResponse
    {
        return $this->respond($feed->driver());
    }

    public function data(VmixFeed $feed): JsonResponse
    {
        return $this->respond((object) $feed->data());
    }

    /**
     * A fully transparent PNG of the requested size, so empty image slots in vMix stay the right size.
     */
    public function clear(Request $request, ImageCropper $cropper): Response
    {
        $width = (int) min(max($request->integer('w', VmixFeed::ClearDefaultSize), 1), VmixField::MaxDimension);
        $height = (int) min(max($request->integer('h', VmixFeed::ClearDefaultSize), 1), VmixField::MaxDimension);

        return response($cropper->transparent($width, $height))
            ->header('Content-Type', 'image/png')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age=31536000, immutable');
    }

    /**
     * @param  array<string, mixed>|object  $data
     */
    private function respond(array|object $data): JsonResponse
    {
        return response()
            ->json($data, options: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'no-store');
    }
}

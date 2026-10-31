<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpdateBusinessQrSettingsRequest;
use App\Models\Business;
use App\Services\BusinessQrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class BusinessQrController extends Controller
{
    public function __construct(private readonly BusinessQrService $qr)
    {
    }

    /** GET /api/business/qr/settings */
    public function showSettings(Request $request): JsonResponse
    {
        $business = $this->authorizedBusiness($request);

        return response()->json(['data' => $this->settingsPayload($business)]);
    }

    /** PUT /api/business/qr/settings */
    public function updateSettings(UpdateBusinessQrSettingsRequest $request): JsonResponse
    {
        $business = $this->authorizedBusiness($request);
        $business->qr_settings = array_merge($this->qr->settings($business), $request->validated());
        $business->save();

        return response()->json(['data' => $this->settingsPayload($business->refresh())]);
    }

    /** GET /api/business/qr/preview.svg */
    public function preview(Request $request): Response
    {
        $business = $this->authorizedBusiness($request);

        return response($this->qr->svg($business), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** GET /api/business/qr/download/{format} */
    public function download(Request $request, string $format): Response|JsonResponse
    {
        $business = $this->authorizedBusiness($request);
        abort_unless(in_array($format, ['png', 'webp'], true), 404);

        try {
            $contents = $this->qr->raster($business, $format);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'qr_renderer_unavailable',
            ], 503);
        }

        $mime = $format === 'webp' ? 'image/webp' : 'image/png';

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="qr-'.$this->safeSlug($business).'.'.$format.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function authorizedBusiness(Request $request): Business
    {
        $business = $this->businessOrFail($request);
        $this->authorize('manageBranding', $business);

        return $business;
    }

    /** @return array<string, mixed> */
    private function settingsPayload(Business $business): array
    {
        return [
            'settings' => $this->qr->settings($business),
            'target_url' => $this->qr->targetUrl($business),
            'logo_available' => filled($business->logo_path),
            'preview_url' => route('business.qr.preview'),
            'downloads' => [
                'png' => route('business.qr.download', ['format' => 'png']),
                'webp' => route('business.qr.download', ['format' => 'webp']),
            ],
        ];
    }

    private function safeSlug(Business $business): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '-', $business->slug) ?: 'negocio';
    }
}

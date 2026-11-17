<?php

use App\Domain\Support\CatalogItemInUse;
use App\Domain\Support\Conflict;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [__DIR__.'/../routes/health.php', __DIR__.'/../routes/web.php'],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: [
            'rule_form.template', 'rule_form.fallbacks.*',
            'template_form.slots.*.template', 'template_form.slots.*.fallbacks.*',
            'bundle_form.page_templates.*.slots.*.template', 'bundle_form.page_templates.*.slots.*.fallbacks.*',
            'bundle_form.page_templates.*.new_h2_template',
            'new_h2_template', 'source_form.query_rows.*.value',
            'original_text', 'tongji_code',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isAuthenticatedAdmin = fn (Request $request): bool => $request->attributes->get('admin_host') === true
            && $request->is('admin', 'admin/*') && $request->user() !== null;
        $publicUnavailable = function (Request $request) {
            $headers = ['Cache-Control' => 'no-store', 'Retry-After' => '30'];
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => '内容暂不可用，请稍后重试。'], 503, $headers);
            }

            return response('内容暂不可用，请稍后重试。', 503, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
        };
        $exceptions->render(function (Conflict $e, Request $request) use ($isAuthenticatedAdmin, $publicUnavailable) {
            if ($e instanceof CatalogItemInUse) {
                if (! $isAuthenticatedAdmin($request)) {
                    return $publicUnavailable($request);
                }
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Mục đang được sử dụng; gỡ liên kết trước khi xóa.', 'reason' => $e->reason, 'usage' => $e->usage], 409);
                }

                return response()->view('admin.deletions.blocked', ['kind' => $e->kind, 'itemId' => $e->itemId, 'usage' => $e->usage,
                    'site' => null, 'sites' => collect(), 'scope' => '', 'globalManagement' => true, 'managementLabel' => 'Chưa thể xóa'], 409);
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Thao tác bị xung đột. Tải lại dữ liệu và thử lại.', 'reason' => $e->reason], 409);
            }

            return response()->view('admin.conflict', ['reason' => $e->reason], 409);
        });
        $isDeletedReference = fn (Throwable $e): bool => $e instanceof QueryException && $e->getCode() === '23514'
            && str_contains($e->getMessage(), 'CATALOG_REFERENCE_DELETED:');
        $exceptions->dontReportWhen($isDeletedReference);
        $exceptions->render(function (QueryException $e, Request $request) use ($isDeletedReference, $isAuthenticatedAdmin, $publicUnavailable) {
            if (! $isDeletedReference($e)) {
                return null;
            }
            if (! $isAuthenticatedAdmin($request)) {
                return $publicUnavailable($request);
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Cấu hình còn chọn một mục đã xóa. Khôi phục mục đó hoặc chọn mục khác.', 'reason' => 'CATALOG_REFERENCE_DELETED'], 409);
            }

            return response()->view('admin.deletions.reference-conflict', ['site' => null, 'sites' => collect(), 'scope' => '', 'globalManagement' => true, 'managementLabel' => 'Cấu hình đã thay đổi'], 409);
        });
        foreach ([InvalidArgumentException::class, JsonException::class] as $type) {
            $renderValidation = function (Throwable $e, Request $request) use ($type) {
                if (! $e instanceof $type || ! $request->is('admin/*')) {
                    return null;
                }

                return app(ExceptionHandler::class)->render(
                    $request, ValidationException::withMessages(['operation' => $e->getMessage()]),
                );
            };
            $exceptions->render($renderValidation);
        }
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
                || ($e instanceof ValidationException && $request->attributes->has('public_site') && ! $request->attributes->get('admin_host')),
        );
    })->create();

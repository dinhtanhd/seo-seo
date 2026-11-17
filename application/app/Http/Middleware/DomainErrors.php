<?php

namespace App\Http\Middleware;

use App\Domain\Support\Conflict;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DomainErrors
{
    public function handle(Request $request, Closure $next)
    {
        try {
            return $next($request);
        } catch (Conflict $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Cấu hình hoặc dữ liệu vừa thay đổi. Bản đang phục vụ được giữ nguyên; hãy tải lại rồi thử áp dụng.', 'reason' => $e->reason], 409);
            }

            return response()->view('admin.conflict', ['reason' => $e->reason], 409);
        } catch (\InvalidArgumentException|\JsonException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }
    }
}

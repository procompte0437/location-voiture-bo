<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditService
{
    /**
     * Enregistre une action sensible : qui, quoi, quand, où, navigateur, etc.
     * Les lignes d'audit sont immuables (append-only).
     */
    public function log(
        string $action,
        ?Model $entity = null,
        ?array $before = null,
        ?array $after = null,
        ?int $actorId = null,
        ?array $context = null,
    ): AuditLog {
        $request = request();
        $actor = $this->resolveActor($actorId, $request);
        $ua = $request instanceof Request ? (string) $request->userAgent() : '';
        $parsed = $this->parseUserAgent($ua);

        return AuditLog::create([
            'actor_id' => $actor?->id ?? $actorId,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'actor_role' => $actor?->role instanceof \BackedEnum
                ? $actor->role->value
                : ($actor?->role ?? null),
            'action' => $action,
            'http_method' => $request instanceof Request ? $request->method() : null,
            'url' => $request instanceof Request ? Str::limit($request->fullUrl(), 990) : null,
            'route' => $request instanceof Request ? $request->route()?->getName() : null,
            'referer' => $request instanceof Request
                ? Str::limit((string) $request->headers->get('referer'), 990)
                : null,
            'entity_type' => $entity ? $entity::class : ($context['entity_type'] ?? null),
            'entity_id' => $entity?->getKey() ?? ($context['entity_id'] ?? null),
            'before' => $before,
            'after' => $after,
            'ip_address' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $ua !== '' ? $ua : null,
            'browser' => $parsed['browser'],
            'browser_version' => $parsed['browser_version'],
            'platform' => $parsed['platform'],
            'device_type' => $parsed['device_type'],
            'request_id' => $request instanceof Request
                ? ($request->attributes->get('audit_request_id') ?? (string) Str::uuid())
                : (string) Str::uuid(),
            'session_id' => $request instanceof Request && $request->hasSession()
                ? $request->session()->getId()
                : null,
        ]);
    }

    private function resolveActor(?int $actorId, mixed $request): ?User
    {
        if ($request instanceof Request) {
            $user = $request->user('sanctum') ?? $request->user();
            if ($user instanceof User) {
                return $user;
            }
        }

        if ($actorId) {
            return User::query()->find($actorId);
        }

        $authId = auth()->id();

        return $authId ? User::query()->find($authId) : null;
    }

    /**
     * Parse léger du User-Agent (sans dépendance externe).
     *
     * @return array{browser: ?string, browser_version: ?string, platform: ?string, device_type: string}
     */
    public function parseUserAgent(string $ua): array
    {
        if ($ua === '') {
            return [
                'browser' => null,
                'browser_version' => null,
                'platform' => null,
                'device_type' => 'unknown',
            ];
        }

        $deviceType = 'desktop';
        if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit/i', $ua)) {
            $deviceType = 'bot';
        } elseif (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua)) {
            $deviceType = 'tablet';
        } elseif (preg_match('/Mobile|Android|iPhone|iPod|webOS|BlackBerry|IEMobile/i', $ua)) {
            $deviceType = 'mobile';
        }

        $platform = null;
        if (preg_match('/Windows NT 10/i', $ua)) {
            $platform = 'Windows 10/11';
        } elseif (preg_match('/Windows/i', $ua)) {
            $platform = 'Windows';
        } elseif (preg_match('/Mac OS X/i', $ua)) {
            $platform = 'macOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $platform = 'Android';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $platform = 'iOS';
        } elseif (preg_match('/Linux/i', $ua)) {
            $platform = 'Linux';
        }

        $browser = null;
        $browserVersion = null;
        $patterns = [
            'Edge' => '/Edg(?:e|A|iOS)?\/([0-9.]+)/i',
            'Chrome' => '/Chrome\/([0-9.]+)/i',
            'Firefox' => '/Firefox\/([0-9.]+)/i',
            'Safari' => '/Version\/([0-9.]+).*Safari/i',
            'Opera' => '/OPR\/([0-9.]+)/i',
            'Samsung Internet' => '/SamsungBrowser\/([0-9.]+)/i',
        ];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $ua, $matches)) {
                // Chrome pattern also matches Edge; Edge checked first.
                if ($name === 'Chrome' && preg_match('/Edg(?:e|A|iOS)?\//i', $ua)) {
                    continue;
                }
                if ($name === 'Safari' && preg_match('/Chrome\/|Chromium\/|Edg\//i', $ua)) {
                    continue;
                }
                $browser = $name;
                $browserVersion = $matches[1] ?? null;
                break;
            }
        }

        return [
            'browser' => $browser,
            'browser_version' => $browserVersion,
            'platform' => $platform,
            'device_type' => $deviceType,
        ];
    }
}

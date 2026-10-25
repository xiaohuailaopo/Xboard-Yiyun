<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use DomainException;

/** One-time Telegram binding. No subscription or authentication tokens are accepted. */
class ElegantTelegramBinding
{
    private const PREFIX = 'elegant:telegram-binding:';

    public static function issue(int $userId): ?string
    {
        $cache = Cache::store('redis');
        return $cache->lock(self::PREFIX . 'user-lock:' . $userId, 30)->block(5, function () use ($cache, $userId) {
            $user = User::find($userId);
            if (!$user || $user->banned) {
                throw new DomainException('账号不可用');
            }
            if ($user->telegram_id) {
                return null;
            }
            $userKey = self::PREFIX . 'user:' . $userId;
            $old = $cache->get($userKey);
            if (is_string($old)) {
                $cache->forget(self::PREFIX . 'code:' . $old);
            }
            $code = 'eg_' . bin2hex(random_bytes(20));
            $hash = hash('sha256', $code);
            $expires = new \DateTimeImmutable('+5 minutes');
            $cache->put(self::PREFIX . 'code:' . $hash, $userId, $expires);
            $cache->put($userKey, $hash, $expires);
            return $code;
        });
    }

    public static function bind(string $code, $telegramId): User
    {
        if (!preg_match('/\Aeg_[a-f0-9]{40}\z/D', $code)
            || !preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $telegramId)) {
            throw new DomainException('绑定码无效，请返回个人中心重新打开机器人');
        }
        $cache = Cache::store('redis');
        $hash = hash('sha256', $code);
        $codeKey = self::PREFIX . 'code:' . $hash;
        $userId = (int) $cache->get($codeKey, 0);
        if ($userId <= 0) {
            throw new DomainException('绑定码已失效，请返回个人中心重新打开机器人');
        }
        return $cache->lock(self::PREFIX . 'telegram-lock:' . $telegramId, 30)->block(5, function () use ($cache, $hash, $codeKey, $userId, $telegramId) {
            return $cache->lock(self::PREFIX . 'user-lock:' . $userId, 30)->block(5, function () use ($cache, $hash, $codeKey, $userId, $telegramId) {
                $userKey = self::PREFIX . 'user:' . $userId;
                $current = $cache->get($userKey);
                if ((int) $cache->get($codeKey, 0) !== $userId
                    || !is_string($current) || !hash_equals($current, $hash)) {
                    throw new DomainException('绑定码已失效，请返回个人中心重新打开机器人');
                }
                $user = DB::transaction(function () use ($userId, $telegramId) {
                    $user = User::where('id', $userId)->lockForUpdate()->first();
                    if (!$user || $user->banned || $user->telegram_id) {
                        throw new DomainException('账号不可用或已绑定，请先在面板确认绑定状态');
                    }
                    if (User::where('telegram_id', $telegramId)->exists()) {
                        throw new DomainException('此 Telegram 账号已绑定其他账户');
                    }
                    $user->telegram_id = $telegramId;
                    if (!$user->save()) {
                        throw new DomainException('绑定失败，请稍后重试');
                    }
                    return $user;
                });
                $cache->forget($codeKey);
                $cache->forget($userKey);
                return $user;
            });
        });
    }
}

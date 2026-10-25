<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function getBotInfo(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $telegramService = new TelegramService();
        $response = $telegramService->getMe();
        return $this->success([
            'username' => $response->result->username,
            'binding_code' => \App\Services\ElegantTelegramBinding::issue((int) $user->id),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function unbind(Request $request)
    {
        $user = User::where('user_id', $request->user()->id)->first();
    }
}

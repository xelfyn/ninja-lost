<?php

namespace App\Services;

use App\Models\AdminCommand;
use App\Models\Character;

/** Legacy signatures retained; unaudited mass grants must not bypass the shared issuer. */
class AdminCommandService
{
    public function execute(AdminCommand $command, Character $char, array $runtimeParams = []): array
    {
        return ['success' => false, 'message' => 'Command legacy dinonaktifkan. Gunakan Staff Grants dengan alasan, request key, dan supply registry yang tervalidasi.'];
    }
}

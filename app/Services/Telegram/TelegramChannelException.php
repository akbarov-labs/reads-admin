<?php

namespace App\Services\Telegram;

use RuntimeException;

/** A post that could not be read. The message is shown to the admin as is. */
class TelegramChannelException extends RuntimeException {}

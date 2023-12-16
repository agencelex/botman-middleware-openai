<?php declare(strict_types=1);

namespace BotMan\Middleware\OpenAI\MessageResponse;

abstract class AbstractMessageResponse
{
    public function __construct(public readonly string $json){}
}

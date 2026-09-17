<?php
declare(strict_types=1);

namespace App;

/** Exception HTTP : convertie en réponse JSON ou page d'erreur par le routeur. */
final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '', public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}

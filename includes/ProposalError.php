<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/** A suggestion or vote that cannot go ahead, with a message for the member and optional extra data. */
final class ProposalError extends \InvalidArgumentException
{
    /** @var array<string,mixed> */
    public array $data;

    /** @param array<string,mixed> $data */
    public function __construct(string $message, array $data = [])
    {
        parent::__construct($message);
        $this->data = $data;
    }
}

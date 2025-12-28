<?php

namespace Daugt\Access\Entries;

use Daugt\Access\Support\Boolean;
use Statamic\Entries\Entry;

class EntitlementEntry extends Entry
{
    public const COLLECTION = 'entitlements';
    public const USER = 'user';
    public const TARGET = 'target';
    public const VALIDITY_START = 'validity_start';
    public const VALIDITY_END = 'validity_end';
    public const KEEP_UNLOCKED_AFTER_EXPIRY = 'keepUnlockedAfterExpiry';

    public function userId(): ?string
    {
        $value = $this->get(self::USER);

        return $value !== null ? (string) $value : null;
    }

    public function targetId(): ?string
    {
        $value = $this->get(self::TARGET);

        return $value !== null ? (string) $value : null;
    }

    public function validityStart(): mixed
    {
        return $this->get(self::VALIDITY_START);
    }

    public function validityEnd(): mixed
    {
        return $this->get(self::VALIDITY_END);
    }

    public function keepUnlockedAfterExpiry(): bool
    {
        $value = $this->get(self::KEEP_UNLOCKED_AFTER_EXPIRY);

        return Boolean::from($value);
    }
}

<?php

declare(strict_types=1);

namespace Core\Auth\Domain;

/**
 * Holds the auth module's shared numeric rules.
 */
final class AuthRules
{
    /**
     * The widest name and email the users table's columns accept.
     */
    public const MAX_NAME_LENGTH = 255;

    public const MAX_EMAIL_LENGTH = 255;

    /**
     * The password length the web and API registration forms accept.
     */
    public const MIN_PASSWORD_LENGTH = 8;

    public const MAX_PASSWORD_LENGTH = 255;
}

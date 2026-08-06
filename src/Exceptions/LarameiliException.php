<?php

namespace EduLazaro\Larameili\Exceptions;

use RuntimeException;

/**
 * Base exception for Larameili's own error conditions (misconfiguration and
 * misuse). Errors coming from the Meilisearch engine surface as the client's
 * own \Meilisearch\Exceptions\* exceptions and are left to propagate.
 */
class LarameiliException extends RuntimeException
{
}

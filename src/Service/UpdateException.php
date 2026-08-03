<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A self-update that failed, tagged with the step it failed at.
 *
 * The step is what makes a partner's failure diagnosable remotely: it goes into the
 * PrestaShop log and into the Hub update report, so "it didn't work" becomes
 * "download failed on their host" or "the second rename failed".
 */
final class UpdateException extends \RuntimeException
{
    public const STEP_PREFLIGHT = 'preflight';
    public const STEP_LOCK = 'lock';
    public const STEP_STAGING = 'staging';
    public const STEP_DOWNLOAD = 'download';
    public const STEP_CHECKSUM = 'checksum';
    public const STEP_VALIDATE = 'validate';
    public const STEP_EXTRACT = 'extract';
    public const STEP_PROMOTE = 'promote';
    public const STEP_BACKUP = 'backup';
    public const STEP_ACTIVATE = 'activate';
    public const STEP_RESTORE = 'restore';
    public const STEP_UPGRADE = 'upgrade';

    /** @var string */
    private $step;

    public function __construct(string $step, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->step = $step;
    }

    public function getStep(): string
    {
        return $this->step;
    }
}

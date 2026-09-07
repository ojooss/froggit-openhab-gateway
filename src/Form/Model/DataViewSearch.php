<?php

declare(strict_types=1);

namespace App\Form\Model;

use DateTimeImmutable;

/**
 * Backing model for DataViewSearchType — bound by the FormBuilder in
 * DataViewController.
 */
class DataViewSearch
{
    public ?string $sink = null;

    public ?DateTimeImmutable $from = null;

    public ?DateTimeImmutable $to = null;

    public string $collection = 'froggit';
}

<?php

declare(strict_types=1);

namespace App\Module\Communications\Application;

use App\Module\Communications\Domain\Model\MessageTemplateType;
use App\Module\Communications\Domain\Model\OrganizationMessageTemplate;

interface MessageTemplateStore
{
    /** @return list<OrganizationMessageTemplate> */
    public function all(): array;

    public function find(MessageTemplateType $type): ?OrganizationMessageTemplate;

    public function save(OrganizationMessageTemplate $template): void;

    public function remove(OrganizationMessageTemplate $template): void;
}

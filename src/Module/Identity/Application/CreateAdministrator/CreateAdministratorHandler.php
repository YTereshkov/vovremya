<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\CreateAdministrator;

use App\Module\Identity\Application\AdministratorPasswordHasher;
use App\Module\Identity\Application\AdministratorProvisioningStore;
use App\Module\Identity\Domain\Exception\DuplicateAdministratorEmail;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use App\Module\Organization\Domain\Model\Organization;

final readonly class CreateAdministratorHandler
{
    public function __construct(
        private AdministratorProvisioningStore $store,
        private AdministratorPasswordHasher $passwordHasher,
    ) {
    }

    public function create(
        string $organizationName,
        string $email,
        #[\SensitiveParameter] string $plainPassword,
        string $timezone,
    ): AdministratorAccount {
        $normalizedEmail = AdministratorAccount::normalizeEmail($email);

        if ($this->store->emailExists($normalizedEmail)) {
            throw new DuplicateAdministratorEmail($normalizedEmail);
        }

        if ('' === $plainPassword) {
            throw new \InvalidArgumentException('Administrator password cannot be empty.');
        }

        $organization = Organization::create($organizationName, $timezone);
        $administrator = AdministratorAccount::create(
            $organization,
            $normalizedEmail,
            $this->passwordHasher->hash($plainPassword),
        );

        $this->store->save($organization, $administrator);

        return $administrator;
    }
}

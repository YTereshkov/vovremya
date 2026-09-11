<?php

declare(strict_types=1);

namespace App\Module\Catalog\Application;

use App\Module\Catalog\Domain\Model\Service;
use App\Module\Identity\Domain\Model\AdministratorAccount;
use Symfony\Component\Uid\Ulid;

final readonly class ServiceCatalog
{
    public function __construct(private ServiceStore $store)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map($this->present(...), $this->store->active());
    }

    public function find(string $id): Service
    {
        try {
            $serviceId = Ulid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new \OutOfBoundsException('Услуга не найдена.');
        }

        return $this->store->findActive($serviceId) ?? throw new \OutOfBoundsException('Услуга не найдена.');
    }

    /** @return array{id: string, name: string, durationMinutes: int, active: bool} */
    public function reference(string $id): array
    {
        try {
            $serviceId = Ulid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new \OutOfBoundsException('Услуга не найдена.');
        }
        $service = $this->store->find($serviceId) ?? throw new \OutOfBoundsException('Услуга не найдена.');

        return [
            'id' => $service->id()->toRfc4122(),
            'name' => $service->name(),
            'durationMinutes' => $service->defaultDurationMinutes(),
            'active' => null === $service->deletedAt(),
        ];
    }

    public function create(AdministratorAccount $actor, string $name, int $default, ?int $minimum, ?int $maximum, ?string $confirmationTemplate = null): Service
    {
        $service = Service::create($actor->organization(), $name, $default, $minimum, $maximum);
        $service->changeConfirmationTemplate($confirmationTemplate);
        $this->store->save($service);

        return $service;
    }

    public function update(Service $service, string $name, int $default, ?int $minimum, ?int $maximum, ?string $confirmationTemplate = null): void
    {
        $service->change($name, $default, $minimum, $maximum);
        $service->changeConfirmationTemplate($confirmationTemplate);
        $this->store->save($service);
    }

    public function delete(Service $service): void
    {
        $service->delete();
        $this->store->save($service);
    }

    /** @return array<string, mixed> */
    public function present(Service $service): array
    {
        return [
            'id' => $service->id()->toRfc4122(),
            'name' => $service->name(),
            'defaultDurationMinutes' => $service->defaultDurationMinutes(),
            'minimumDurationMinutes' => $service->minimumDurationMinutes(),
            'maximumDurationMinutes' => $service->maximumDurationMinutes(),
            'confirmationTemplate' => $service->confirmationTemplate(),
        ];
    }
}

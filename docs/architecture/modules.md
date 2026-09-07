# Modular monolith boundaries

Vovremya is one Symfony application. Modules are code boundaries inside the
same deployment unit and database; they are not bundles or microservices.

## Module ownership

- `Identity`: administrator accounts and authentication.
- `Organization`: tenant configuration and organization-level policies.
- `Workforce`: specialists, working hours, lunches, and availability exceptions.
- `Catalog`: services and service-level settings.
- `Clients`: clients, contact persons, and communication preferences.
- `Scheduling`: appointments, recurring schedules, availability, and transfers.
- `Waiting`: waiting entries, free-window offers, and permanent places.
- `Communications`: channels, templates, notifications, and webhook ingestion.
- `Reporting`: read-only statistics and projections.

`Shared` contains only stable technical or domain primitives used by several
modules. Business behavior belongs to its owning module.

## Layers

Each module may contain these layers when needed:

- `Domain`: entities, value objects, policies, and domain services.
- `Application`: use cases, transaction orchestration, and public contracts.
- `Infrastructure`: Doctrine repositories, queues, caches, and provider adapters.
- `UI/Http`: JSON controllers and HTTP-specific DTO mapping.

Dependency direction is `UI/Http -> Application -> Domain`.
`Infrastructure` implements contracts declared by `Application` or `Domain`.
Domain code must not depend on Symfony controllers, Doctrine repositories,
Redis, Messenger, or external provider SDKs.

Cross-module calls use an owning module's Application contracts. Direct access
to another module's Doctrine repository is not allowed. Reporting remains
read-only and cannot become a second owner of business state.

## Persistence conventions

- Doctrine mappings live in `Module/<Name>/Domain/Model`.
- Aggregate identifiers are generated in the application with Symfony ULID.
- Concrete timestamps use UTC `timestamptz`; recurrence keeps local date/time
  together with the organization timezone.
- Tenant-owned tables include `organization_id` and tenant-aware constraints.
- Migrations stay centralized in `migrations/` and are always reviewed as one
  application schema change.

## Tenant isolation

Tenant-owned entities implement `OrganizationOwned` and expose their immutable
organization identifier. Application repository contracts never accept an
organization identifier from an HTTP request: infrastructure implementations
take it from `OrganizationContext` and include it in every identifier lookup.
Foreign identifiers that belong to another organization are treated as not
found.

Global repository lookups are limited to authentication by globally unique
email and organization-existence checks. They cannot return tenant-owned
business data to an application use case.

Symfony voters provide a second authorization check for reads and changes.
Associations between tenant-owned entities must pass `OrganizationIsolation`
and use composite database foreign keys containing both `organization_id` and
the referenced identifier. Tenant-owned tables expose a unique
`(organization_id, id)` key for those references. Many-to-many relationships
between tenant aggregates use an explicit tenant-owned association entity.

Asynchronous business messages implement `OrganizationAwareMessage`. Messenger
middleware rejects tenant switches, verifies that the organization still
exists, opens the organization scope for the handler, and always clears that
scope afterward. Handlers still use scoped repositories and never trust a
resource identifier carried by a message on its own.

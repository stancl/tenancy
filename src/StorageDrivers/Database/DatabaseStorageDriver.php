<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers\Database;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\StorageDriver;
use Stancl\Tenancy\Exceptions\DomainOccupiedByOtherTenantException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Exceptions\TenantWithThisIdAlreadyExistsException;
use Stancl\Tenancy\StorageDrivers\Database\DomainModel as Domains;
use Stancl\Tenancy\StorageDrivers\Database\TenantModel as Tenants;
use Stancl\Tenancy\Tenant;

class DatabaseStorageDriver implements StorageDriver
{
    // todo write tests verifying that data is decoded and added to the array

    /** @var Application */
    protected $app;

    /** @var Tenant The default tenant. */
    protected $tenant;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function findByDomain(string $domain): Tenant
    {
        $id = $this->getTenantIdByDomain($domain);
        if (! $id) {
            throw new TenantCouldNotBeIdentifiedException($domain);
        }

        return $this->findById($id);
    }

    public function findById(string $id): Tenant
    {
        return Tenant::fromStorage(Tenants::find($id)->decoded())
            ->withDomains(Domains::where('tenant_id', $id)->get()->only('domain')->toArray());
    }

    public function ensureTenantCanBeCreated(Tenant $tenant): void
    {
        // todo test this
        if (Tenants::find($tenant->id)) {
            throw new TenantWithThisIdAlreadyExistsException($tenant->id);
        }

        if (Domains::whereIn('domain', [$tenant->domains])->exists()) {
            throw new DomainOccupiedByOtherTenantException();
        }
    }

    public function withDefaultTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getTenantIdByDomain(string $domain): ?string
    {
        return Domains::where('domain', $domain)->first()->tenant_id ?? null;
    }

    public function createTenant(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant) {
            Tenants::create(['id' => $tenant->id, 'data' => '{}'])->toArray();

            $domainData = [];
            foreach ($tenant->domains as $domain) {
                $domainData[] = ['domain' => $domain, 'tenant_id' => $tenant->id];
            }
            Domains::create($domainData);
        });
    }

    public function updateTenant(Tenant $tenant): void
    {
        Tenant::find($tenant->id)->putMany($tenant->data);
        // todo update domains
    }

    public function deleteTenant(Tenant $tenant): void
    {
        Tenants::find($tenant->id)->delete();
        Domains::where('tenant_id', $tenant->id)->delete();
    }

    /**
     * Get all tenants.
     *
     * @param string[] $ids
     * @return Tenant[]
     */
    public function all(array $ids = []): array
    {
        return Tenants::getAllTenants($ids)->toArray();
    }

    /**
     * Get the current tenant.
     *
     * @return Tenant
     */
    protected function tenant()
    {
        return $this->tenant ?? $this->app[Tenant::class];
    }

    public function get(string $key, Tenant $tenant = null)
    {
        $tenant = $tenant ?? $this->tenant();

        return Tenants::find($tenant->id)->get($key);
    }

    public function getMany(array $keys, Tenant $tenant = null): array
    {
        $tenant = $tenant ?? $this->tenant();

        return Tenants::find($tenant->id)->getMany($keys);
    }

    public function put(string $key, $value, Tenant $tenant = null): void
    {
        $tenant = $tenant ?? $this->tenant();
        Tenants::find($tenant->id)->put($key, $value);
    }

    public function putMany(array $kvPairs, Tenant $tenant = null): void
    {
        $tenant = $tenant ?? $this->tenant();
        Tenants::find($tenant->id)->putMany($kvPairs);
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateTenants extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('tenants')
            ->addColumn('slug', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('webhook_secret', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('flags_version', 'integer', ['default' => 1, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['slug'], ['unique' => true])
            ->create();
    }
}

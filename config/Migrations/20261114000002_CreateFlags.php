<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateFlags extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('flags')
            ->addColumn('tenant_id', 'integer', ['null' => false])
            ->addColumn('code', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('enabled', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('rules', 'json', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['tenant_id', 'code'], ['unique' => true])
            ->addForeignKey('tenant_id', 'tenants', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}

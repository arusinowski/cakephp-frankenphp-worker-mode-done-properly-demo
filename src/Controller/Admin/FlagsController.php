<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;

/**
 * Read-only admin page: every tenant with its flags. No login: this is a demo app.
 */
class FlagsController extends AppController
{
    /**
     * @return void
     */
    public function index(): void
    {
        $tenants = $this->fetchTable('Tenants')->find()
            ->contain(['Flags' => fn($query) => $query->orderBy(['Flags.code' => 'ASC'])])
            ->orderBy(['Tenants.slug' => 'ASC'])
            ->all();

        $this->set(compact('tenants'));
    }
}

<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property bool $enabled
 * @property array<string, mixed>|null $rules
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \App\Model\Entity\Tenant $tenant
 */
class Flag extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'tenant_id' => true,
        'code' => true,
        'enabled' => true,
        'rules' => true,
    ];
}

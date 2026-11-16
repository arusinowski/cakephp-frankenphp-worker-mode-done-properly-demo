<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $webhook_secret
 * @property int $flags_version
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property array<\App\Model\Entity\Flag> $flags
 */
class Tenant extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'slug' => true,
        'name' => true,
        'webhook_secret' => true,
    ];

    /**
     * @var list<string>
     */
    protected array $_hidden = ['webhook_secret'];
}

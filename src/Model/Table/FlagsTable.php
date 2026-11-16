<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Flags\RuleCompiler;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * @property \App\Model\Table\TenantsTable&\Cake\ORM\Association\BelongsTo $Tenants
 * @extends \Cake\ORM\Table<array{Timestamp: \Cake\ORM\Behavior\TimestampBehavior}>
 */
class FlagsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('flags');
        $this->setDisplayField('code');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->belongsTo('Tenants', ['joinType' => 'INNER']);
        // SQLite (tests) reports JSON columns as text; MariaDB as JSON. Pin the type for both.
        $this->getSchema()->setColumnType('rules', 'json');
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->integer('tenant_id')->requirePresence('tenant_id', 'create')
            ->scalar('code')->maxLength('code', 64)->requirePresence('code', 'create')->notEmptyString('code')
            ->boolean('enabled')->requirePresence('enabled', 'create')
            ->add('rules', 'valid', [
                'rule' => function (mixed $value): string|bool {
                    $errors = (new RuleCompiler())->validate($value);

                    return $errors === [] ? true : $errors[0];
                },
            ])
            // Only null skips the rules check; '' must fail (it would be stored as the JSON string "").
            ->allowEmptyFor('rules', Validator::EMPTY_NULL);
    }

    /**
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['tenant_id', 'code']));
        $rules->add($rules->existsIn('tenant_id', 'Tenants'));

        return $rules;
    }
}

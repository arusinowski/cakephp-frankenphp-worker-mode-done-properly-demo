<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Tenant> $tenants
 */
$this->assign('title', 'Feature flags');
?>
<h1>Feature flags</h1>
<?php foreach ($tenants as $tenant) : ?>
    <section class="content">
        <h2><?= h($tenant->name) ?> <small>(<?= h($tenant->slug) ?>, version <?= $tenant->flags_version ?>)</small></h2>
        <table>
            <thead>
                <tr>
                    <th>Flag</th>
                    <th>Enabled</th>
                    <th>Rules</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tenant->flags as $flag) : ?>
                    <tr>
                        <td><code><?= h($flag->code) ?></code></td>
                        <td><?= $flag->enabled ? 'yes' : 'no' ?></td>
                        <td><?= $flag->rules === null ? 'everyone' : '<code>' . h(json_encode($flag->rules)) . '</code>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endforeach; ?>
